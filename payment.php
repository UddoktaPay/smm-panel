if ($method_name == 'uddoktapay') {

    $response = file_get_contents('php://input');
    
    if (!empty($response)) {
    
        // Decode response data
        $data     = json_decode($response, true);
    
        $apiKey =  trim($extras['api_key']);
        $signature = trim($_SERVER['HTTP_RT_UDDOKTAPAY_API_KEY']);
    
        // Validate Signature
        if ($apiKey !== $signature) {
            die('Invalid API Signature.');
        }
    
        if (!isset($data['status']) && !isset($data['metadata']['invoice_id'])) {
            die('Invalid Response.');
        }
    
        if (isset($data['status']) && $data['status'] == 'COMPLETED') {
            if (countRow(['table' => 'payments', 'where' => ['client_id' => $data['metadata']['user_id'], 'payment_method' => 31, 'payment_status' => 1, 'payment_delivery' => 1, 'payment_extra' => $data['metadata']['txnid']]])) {
                if ($data['status'] == 'COMPLETED') {
                    $payment = $conn->prepare('SELECT * FROM payments INNER JOIN clients ON clients.client_id=payments.client_id WHERE payments.payment_extra=:extra ');
                    $payment->execute(['extra' => $data['metadata']['txnid']]);
                    $payment = $payment->fetch(PDO::FETCH_ASSOC);
                    $payment_bonus = $conn->prepare('SELECT * FROM payments_bonus WHERE bonus_method=:method && bonus_from<=:from ORDER BY bonus_from DESC LIMIT 1');
                    $payment_bonus->execute(['method' => $method['id'], 'from' => $payment['payment_amount']]);
                    $payment_bonus = $payment_bonus->fetch(PDO::FETCH_ASSOC);
                    if ($payment_bonus) {
                        $amount = $payment['payment_amount'] + (($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100);
                        $bonus_amount = ($payment['payment_amount'] * $payment_bonus['bonus_amount']) / 100;
                    } else {
                        $amount = $payment['payment_amount'];
                    }
                    $conn->beginTransaction();
                    $update = $conn->prepare('UPDATE payments SET client_balance=:balance, payment_status=:status, payment_delivery=:delivery WHERE payment_id=:id ');
                    $update = $update->execute(['balance' => $payment['balance'], 'status' => 3, 'delivery' => 2, 'id' => $payment['payment_id']]);
    
                    $balance = $conn->prepare('UPDATE clients SET balance=:balance WHERE client_id=:id ');
                    $balance = $balance->execute(['id' => $payment['client_id'], 'balance' => $payment['balance'] + $amount]);
    
                    $insert = $conn->prepare('INSERT INTO client_report SET client_id=:c_id, action=:action, report_ip=:ip, report_date=:date ');
                    $insert25 = $conn->prepare("INSERT INTO payments SET client_id=:client_id , client_balance=:client_balance , payment_amount=:payment_amount , payment_method=:payment_method ,
                payment_status=:status, payment_delivery=:delivery , payment_note=:payment_note , payment_create_date=:payment_create_date , payment_extra=:payment_extra , bonus=:bonus");
    
    
    
                    if ($payment_bonus) {
    
                        $insert25->execute(array(
                            "client_id" => $payment['client_id'], "client_balance" => (($payment['balance'] + $amount) - $bonus_amount),
                            "payment_amount" => $bonus_amount, "payment_method" =>  1, 'status' => 3, 'delivery' => 2, "payment_note" => "Bonus added", "payment_create_date" => date('Y-m-d H:i:s'), "payment_extra" => "Bonus added for previous payment",
                            "bonus" => 1
                        ));
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'] . ' and included %' . $payment_bonus['bonus_amount'] . ' bonus.', 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    } else {
                        $insert = $insert->execute(['c_id' => $payment['client_id'], 'action' => 'New ' . $amount . ' ' . $settings["currency"] . ' payment has been made with ' . $method['method_name'], 'ip' => GetIP(), 'date' => date('Y-m-d H:i:s')]);
                    }
                    if ($update && $balance) {
                        $conn->commit();
                        echo 'OK';
                    } else {
                        $conn->rollBack();
                        echo 'NO';
                    }
                } else {
                    $update = $conn->prepare('UPDATE payments SET payment_status=:payment_status WHERE client_id=:client_id, payment_method=:payment_method, payment_delivery=:payment_delivery, payment_extra=:payment_extra');
                    $update = $update->execute(['payment_status' => 2, 'client_id' => $data['metadata']['user_id'], 'payment_method' => 31, 'payment_delivery' => 1, 'payment_extra' => $data['metadata']['txnid']]);
                }
            }
        }
    }
    
    exit('Invalid Request');
}
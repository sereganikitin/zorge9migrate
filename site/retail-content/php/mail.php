<?php

// Домен берём из заголовка запроса, чтобы работало на любом домене без правок.
// Sanitize: HTTP_HOST контролируется клиентом, оставляем только безопасные символы.
$host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost');

$sendto   = "moreshkina@stmichael.ru"; // почта, на которую будет приходить письмо
$username = $_POST['name'];   // сохраняем в переменную данные полученные из поля c именем
$usertel = $_POST['phone']; // сохраняем в переменную данные полученные из поля c телефонным номером

// Формирование заголовка письма
$subject  = "Сообщение с сайта " . $host . "/promo";
//$headers  = "From: " . strip_tags($username) . "\r\n";
$headers .= "From: Promo <info@" . $host . ">\r\n"; // от кого письмо
$headers .= "Reply-To: ". strip_tags($username) . "\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/html;charset=utf-8 \r\n";

// Формирование тела письма
$msg  = "<html><body style='font-family:Arial,sans-serif;'>";
$msg .= "<h2 style='font-weight:bold;border-bottom:1px dotted #ccc;'>Cообщение с сайта " . $host . "/promo</h2>\r\n";
$msg .= "<p><strong>От кого:</strong> ".$username."</p>\r\n";
$msg .= "<p><strong>Телефон:</strong> ".$usertel."</p>\r\n";
$msg .= "</body></html>";

mail($sendto, $subject, $msg, $headers);


$array = array(
    'routeKey' => 'key1',
    'phone' => $_POST['phone']
);
$payload = json_encode($array);

$ch = curl_init('http://api.calltouch.ru/widget-service/v1/api/widget-request/user-form/create');
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Access-Token: GG51U0sOoLW1OIA5tzAjqwiEDssHaIGewnfbhczuB7w6B', 'Content-Type:application/json']);

$html = curl_exec($ch);
curl_close($ch);

echo $html;

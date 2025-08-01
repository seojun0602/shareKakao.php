<?
require_once './login/Client.php';
$client = new Client();
/*
$cred = new Cred();
알아서 Cred 클래스 잘 구현하세요.
본 기기의 Authorization임.
$client->setCred($cred);
*/

$chatId = 18398338829933618;
$content = "test.";
$client->sendMessage($chatId, $content);
?>
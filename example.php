<?
require_once './login/Client.php';
$client = new Client();
/*
$cred = new Cred();
$client->setCred($cred);
*/

$chatId = 18398338829933618;
$content = "test.";
$client->sendMessage($chatId, $content);
?>
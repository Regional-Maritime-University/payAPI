<?php
require_once('bootstrap.php');

use Src\Controller\PaymentController;

$redis = new Redis();
$redis->connect('localhost', 6379);

$message = $redis->lpop($queueName);
if ($message !== false) {
    $pay = new PaymentController();
    $data = $pay->processTransaction($transaction_id);
} else {
    // If the queue is empty, wait for a while
    sleep(1);
}

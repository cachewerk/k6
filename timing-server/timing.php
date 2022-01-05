<?php

$redis = new Redis;
$redis->connect('localhost', 6379);

$input = file_get_contents('php://input');
$decoded = json_decode($input, true);

if ( ! is_array($decoded) || count($decoded) != 2) {
    http_response_code(400);
    echo 'Invalid data';
    exit;
}

[$url, $ms] = [$decoded['url'], $decoded['ms']];

$redis->zIncrBy('urls:count', 1, $url);
$redis->zIncrBy('urls:timing', $ms, $url);

$redis->rPush("raw:$url", $ms);

echo json_encode([
    'status' => 'success',
    'message' => 'Data stored successfully'
]);

<?php

$scenario = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'))[0];
$method = $_SERVER['REQUEST_METHOD'];
$params = $method === 'POST' ? $_POST : $_GET;

if($scenario === 'fallback' && $method === 'POST'){
	http_response_code(400);
	header('Content-Type: application/json');
	echo json_encode(['message'=>'failed to parse request params']);
	return;
}
if($scenario === 'conflict' && $method === 'POST'){
	http_response_code(400);
	header('Content-Type: application/json');
	echo json_encode(['message'=>'order id conflict', 'status_code'=>10002]);
	return;
}

$expected = [
	'pid'=>'1001',
	'type'=>$scenario === 'selector' ? 'usdc.base' : ($scenario === 'alipay' ? 'alipay' : 'usdt.tron'),
	'currency'=>$scenario === 'usd' ? 'usd' : 'cny',
	'money'=>'100.2',
	'out_trade_no'=>'202611230000000001',
	'name'=>'Test item & details',
	'notify_url'=>'https://merchant.example/pay/notify/202611230000000001/',
	'return_url'=>'https://merchant.example/pay/return/202611230000000001/',
	'sign_type'=>'MD5',
];
$valid = ($scenario === 'fallback' || $method === 'POST');
foreach($expected as $key=>$value){
	$valid = $valid && isset($params[$key]) && $params[$key] === $value;
}
$signed = $params;
unset($signed['sign'], $signed['sign_type']);
ksort($signed, SORT_STRING);
$pairs = [];
foreach($signed as $key=>$value){
	if($value !== '') $pairs[] = $key.'='.$value;
}
$valid = $valid && isset($params['sign']) && hash_equals(md5(implode('&', $pairs).'fixture-secret'), $params['sign']);
if(!$valid){
	http_response_code(400);
	header('Content-Type: application/json');
	echo json_encode(['message'=>'fixture rejected request fields or MD5 signature']);
	return;
}

$location = '/pay/checkout-counter/fixture-trade';
if($scenario === 'absolute') $location = 'http://127.0.0.1:8000'.$location.'?from=api';
if($scenario === 'public') $location = 'https://checkout.example'.$location;
if($scenario === 'private') $location = 'http://10.0.0.1'.$location;
if($scenario === 'protocol') $location = '//checkout.example'.$location;
if($scenario === 'relative') $location = 'pay/checkout-counter/fixture-trade';
header('Location: '.$location, true, 302);

<?php

namespace lib {
	class Payment {
		static public function lockPayData($tradeNo, $callback){
			return $callback();
		}
	}
}

namespace {
	set_error_handler(function($severity, $message, $file, $line){
		if(error_reporting() & $severity) throw new \ErrorException($message, 0, $severity, $file, $line);
	});
	$input = json_decode(stream_get_contents(STDIN), true);
	define('TRADE_NO', $input['order_id']);
	require dirname(__DIR__, 2).'/plugins/epusdt/epusdt_plugin.php';
	$channel = array_merge([
		'appurl'=>$input['gateway'], 'appid'=>'1', 'appkey'=>'test-secret-token',
	], $input['channel'] ?? []);
	$order = ['typename'=>'usdt', 'name'=>"Item 'quoted' & details + ".chr(0xe4).chr(0xb8).chr(0xad), 'realmoney'=>'100.20'];
	$siteurl = 'https://93.184.216.34/';
	$conf = ['localurl'=>$siteurl];
	$notifications = [];
	function processNotify($order, $tradeNo){
		$GLOBALS['notifications'][] = $tradeNo;
	}
	$_GET = $input['callback'] ?? [];
	$_POST = [];
	$action = $input['action'] ?? 'submit';
	if(!in_array($action, ['submit', 'mapi', 'notify', 'return'], true)) exit(2);
	$result = \epusdt_plugin::$action();
	echo json_encode(['result'=>$result, 'notifications'=>$notifications], JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
}

<?php

namespace lib {
	class Payment {
		static public function lockPayData($tradeNo, $callback){
			return $callback();
		}
	}
}

namespace {
	define('TRADE_NO', '202611230000000001');
	require getenv('EPUSDT_PLUGIN_FILE') ?: dirname(__DIR__).'/plugins/epusdt/epusdt_plugin.php';
	$passed = 0;
	$failed = 0;
	$notifications = [];
	$processes = [];
	$tempDir = sys_get_temp_dir().'/epay-epusdt-tests-'.bin2hex(random_bytes(6));
	mkdir($tempDir, 0700);
	set_error_handler(function($severity, $message, $file, $line){
		if(!(error_reporting() & $severity)) return false;
		throw new \ErrorException($message, 0, $severity, $file, $line);
	});

	function processNotify($order, $tradeNo){
		$GLOBALS['notifications'][] = $tradeNo;
	}

	function check($name, $test){
		try{
			$test();
			$GLOBALS['passed']++;
			echo 'PASS '.$name.PHP_EOL;
		}catch(\Throwable $error){
			$GLOBALS['failed']++;
			echo 'FAIL '.$name.': '.$error->getMessage().PHP_EOL;
		}
	}

	function same($expected, $actual){
		if($expected !== $actual){
			throw new \RuntimeException('expected '.var_export($expected, true).', got '.var_export($actual, true));
		}
	}

	function signCallback($params){
		unset($params['sign'], $params['sign_type']);
		ksort($params, SORT_STRING);
		$pairs = [];
		foreach($params as $key=>$value){
			if($value !== '' && $value !== null) $pairs[] = $key.'='.$value;
		}
		return md5(implode('&', $pairs).'fixture-secret');
	}

	function resetState($url = 'https://gateway.example'){
		$GLOBALS['channel'] = ['appurl'=>$url, 'appid'=>'1001', 'appkey'=>'fixture-secret', 'appswitch'=>'https://checkout.example'];
		$GLOBALS['order'] = ['typename'=>'usdt', 'name'=>'Test item & details', 'realmoney'=>'100.20'];
		$GLOBALS['siteurl'] = 'https://merchant.example/';
		$GLOBALS['conf'] = ['localurl'=>'https://merchant.example/'];
		$GLOBALS['notifications'] = [];
		$_GET = [];
		$_POST = [];
	}

	function callbackData($changes = []){
		$params = array_merge([
			'pid'=>'1001', 'trade_no'=>'202611230000000099', 'out_trade_no'=>TRADE_NO,
			'type'=>'usdt.tron', 'name'=>'Test item & details', 'money'=>'100.2000',
			'trade_status'=>'TRADE_SUCCESS', 'sign_type'=>'MD5',
		], $changes);
		$params['sign'] = signCallback($params);
		return $params;
	}

	function freePort(){
		$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
		if(!$socket) throw new \RuntimeException('cannot reserve test port: '.$error);
		$port = (int)substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
		fclose($socket);
		return $port;
	}

	function startServer($command, $port){
		$log = $GLOBALS['tempDir'].'/server-'.count($GLOBALS['processes']).'.log';
		$process = proc_open($command, [0=>['pipe', 'r'], 1=>['file', $log, 'a'], 2=>['file', $log, 'a']], $pipes);
		if(!is_resource($process)) throw new \RuntimeException('cannot start fixture server');
		fclose($pipes[0]);
		$GLOBALS['processes'][] = $process;
		for($attempt = 0; $attempt < 100; $attempt++){
			$socket = @stream_socket_client('tcp://127.0.0.1:'.$port, $errno, $error, 0.1);
			if($socket){ fclose($socket); return; }
			if(!proc_get_status($process)['running']) break;
			usleep(20000);
		}
		throw new \RuntimeException('fixture server did not start: '.file_get_contents($log));
	}

	try{
		check('official EPay MD5 example stays compatible', function(){
			$method = new \ReflectionMethod('epusdt_plugin', 'makeSign');
			$method->setAccessible(true);
			$params = ['money'=>'100', 'name'=>'VIP', 'notify_url'=>'https://merchant.example/notify', 'out_trade_no'=>'ORD202605230001', 'pid'=>'1000', 'return_url'=>'https://merchant.example/return', 'type'=>'alipay', 'sign_type'=>'MD5'];
			same('b865b0acbb2b01554c35a1bd33351452', $method->invoke(null, $params, 'epusdt_secret_key'));
		});

		foreach(['GET', 'POST'] as $transport){
			check($transport.' callback accepts official four-decimal amount', function() use($transport){
				resetState();
				if($transport === 'POST') $_POST = callbackData(); else $_GET = callbackData();
				same(['type'=>'html', 'data'=>'success'], \epusdt_plugin::notify());
				same(['202611230000000099'], $GLOBALS['notifications']);
			});
		}
		foreach([
			'wrong PID'=>['pid'=>'1000'],
			'missing trade ID'=>['trade_no'=>''],
			'wrong merchant order'=>['out_trade_no'=>'another-order'],
			'unpaid status'=>['trade_status'=>'WAIT_BUYER_PAY'],
			'wrong amount'=>['money'=>'100.21'],
			'rounded amount mismatch'=>['money'=>'100.204'],
			'nonnumeric amount suffix'=>['money'=>'100.20junk'],
			'exponent amount'=>['money'=>'1.002e2'],
			'non-MD5 algorithm'=>['sign_type'=>'HMAC-SHA256'],
		] as $name=>$changes){
			check('callback rejects '.$name, function() use($changes){
				resetState();
				$_GET = callbackData($changes);
				same(['type'=>'html', 'data'=>'fail'], \epusdt_plugin::notify());
				same([], $GLOBALS['notifications']);
			});
		}
		check('callback rejects forged signature', function(){
			resetState(); $_GET = callbackData(); $_GET['sign'] = str_repeat('0', 32);
			same('fail', \epusdt_plugin::notify()['data']); same([], $GLOBALS['notifications']);
		});
		foreach(['sign', 'money', 'name'] as $field){
			check('callback rejects array in '.$field.' without PHP errors', function() use($field){
				resetState(); $_GET = callbackData(); $_GET[$field] = ['invalid'];
				same('fail', \epusdt_plugin::notify()['data']); same([], $GLOBALS['notifications']);
			});
		}
		check('callback rejects empty verification key', function(){
			resetState(); $_GET = callbackData(); $GLOBALS['channel']['appkey'] = '';
			same('fail', \epusdt_plugin::notify()['data']);
		});
		check('numeric PID callback supports leading zeros in configuration', function(){
			resetState(); $GLOBALS['channel']['appid'] = '001001'; $_GET = callbackData();
			same('success', \epusdt_plugin::notify()['data']);
		});
		check('synchronous return never settles an order', function(){
			resetState(); $_GET = callbackData();
			same(['type'=>'page', 'page'=>'return'], \epusdt_plugin::return());
			same([], $GLOBALS['notifications']);
		});

		$port = freePort();
		startServer([PHP_BINARY, '-S', '127.0.0.1:'.$port, __DIR__.'/fixture.php'], $port);
		$base = 'http://127.0.0.1:'.$port;
		foreach(['normal', 'selector', 'usd', 'alipay', 'fallback', 'absolute', 'relative', 'protocol'] as $scenario){
			check('HTTP order flow: '.$scenario, function() use($base, $scenario){
				resetState($base.'/'.$scenario);
				if($scenario === 'selector') $GLOBALS['channel']['appselector'] = ' USDC.Base ';
				if($scenario === 'usd') $GLOBALS['channel']['appcurrency'] = ' USD ';
				if($scenario === 'alipay') $GLOBALS['channel']['appselector'] = 'alipay';
				$expected = 'https://checkout.example/pay/checkout-counter/fixture-trade';
				if($scenario === 'absolute') $expected .= '?from=api';
				same(['type'=>'jump', 'url'=>$expected], \epusdt_plugin::submit());
			});
		}
		check('HTTP business conflict is surfaced without GET retry', function() use($base){
			resetState($base.'/conflict'); $result = \epusdt_plugin::submit();
			same('error', $result['type']);
			if(strpos($result['msg'], 'order id conflict') === false) throw new \RuntimeException('gateway error was lost');
		});
		check('absolute private checkout address is rejected', function() use($base){
			resetState($base.'/private'); $GLOBALS['channel']['appswitch'] = '';
			same('error', \epusdt_plugin::submit()['type']);
		});
		check('loopback API requires a public cashier URL', function() use($base){
			resetState($base.'/normal'); $GLOBALS['channel']['appswitch'] = '';
			same('error', \epusdt_plugin::submit()['type']);
		});
		foreach(['/payments/epay/v1/order/create-transaction', '/payments/epay/v1/order/create-transaction/submit.php', '/submit.php', '/mapi.php'] as $suffix){
			check('API URL configuration accepts '.$suffix, function() use($base, $suffix){
				resetState($base.'/normal'.$suffix);
				same('jump', \epusdt_plugin::mapi()['type']);
			});
		}
		foreach(['appid'=>'merchant-name', 'appkey'=>'', 'appselector'=>'usdt-tron', 'appcurrency'=>'invalid', 'appurl'=>'https://gateway.example?x=1', 'appswitch'=>'javascript:alert(1)'] as $field=>$value){
			check('invalid configuration returns an error: '.$field, function() use($base, $field, $value){
				resetState($base.'/normal'); $GLOBALS['channel'][$field] = $value;
				same('error', \epusdt_plugin::submit()['type']);
			});
		}

		check('HTTPS rejects an untrusted certificate', function(){
			$key = openssl_pkey_new(['private_key_bits'=>2048]);
			$csr = openssl_csr_new(['commonName'=>'localhost'], $key);
			$cert = openssl_csr_sign($csr, null, $key, 1);
			openssl_pkey_export($key, $privatePem); openssl_x509_export($cert, $certPem);
			file_put_contents($GLOBALS['tempDir'].'/key.pem', $privatePem);
			chmod($GLOBALS['tempDir'].'/key.pem', 0600);
			file_put_contents($GLOBALS['tempDir'].'/cert.pem', $certPem);
			$port = freePort();
			startServer(['openssl', 's_server', '-accept', '127.0.0.1:'.$port, '-cert', $GLOBALS['tempDir'].'/cert.pem', '-key', $GLOBALS['tempDir'].'/key.pem', '-www', '-quiet'], $port);
			resetState('https://127.0.0.1:'.$port); $result = \epusdt_plugin::submit();
			same('error', $result['type']);
			if(stripos($result['msg'], 'SSL certificate') === false) throw new \RuntimeException('TLS certificate error was not reported');
		});
	}finally{
		foreach($processes as $process){ proc_terminate($process); proc_close($process); }
		foreach(glob($tempDir.'/*') as $file) unlink($file);
		rmdir($tempDir);
	}
	echo PHP_EOL.$passed.' passed, '.$failed.' failed'.PHP_EOL;
	exit($failed === 0 ? 0 : 1);
}

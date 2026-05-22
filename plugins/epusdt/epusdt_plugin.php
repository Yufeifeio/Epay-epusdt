<?php

class epusdt_plugin
{
	static public $info = [
		'name'        => 'epusdt',
		'showname'    => 'Epusdt',
		'author'      => '鱼肥肥',
		'link'        => 'https://t.me/pyufc',
		'types'       => ['usdt'],
		'inputs' => [
			'appurl' => [
				'name' => 'API地址',
				'type' => 'input',
				'note' => '支持填写站点根地址，或易支付兼容接口完整地址。例如：https://demo.com/ 或 https://demo.com/payments/epay/v1/order/create-transaction/submit.php',
			],
			'appid' => [
				'name' => 'PID',
				'type' => 'input',
				'note' => 'Epusdt API Key 的 PID，例如 1001',
			],
			'appkey' => [
				'name' => '密钥',
				'type' => 'input',
				'note' => 'Epusdt API Key 的 secret_key',
			],
			'appswitch' => [
				'name' => '收银台域名',
				'type' => 'input',
				'note' => '可留空。用于将本机接口返回的 /pay/checkout-counter/... 拼接成外网可访问地址，例如 https://422.gmwallet.top/',
			],
		],
		'select' => null,
		'note' => '支付方式请新增为 usdt。插件使用 Epusdt 的易支付兼容接口下单，并兼容 GET/POST 异步回调。',
		'bindwxmp' => false,
		'bindwxa' => false,
	];

	static public function submit(){
		global $channel, $order, $siteurl, $conf;

		try{
			$jumpUrl = \lib\Payment::lockPayData(TRADE_NO, function() use($channel, $order, $siteurl, $conf) {
				return self::createOrder($channel, $order, $siteurl, $conf);
			});
			return ['type'=>'jump', 'url'=>$jumpUrl];
		}catch(Exception $ex){
			return ['type'=>'error', 'msg'=>$ex->getMessage()];
		}
	}

	static public function mapi(){
		return self::submit();
	}

	static public function notify(){
		global $channel, $order;

		$data = !empty($_POST) ? $_POST : $_GET;
		if(empty($data)) {
			return ['type'=>'html', 'data'=>'fail'];
		}

		if(!self::verifySign($data, $channel['appkey'])){
			return ['type'=>'html', 'data'=>'fail'];
		}

		$outTradeNo = isset($data['out_trade_no']) ? trim($data['out_trade_no']) : '';
		$tradeNo = isset($data['trade_no']) ? trim($data['trade_no']) : '';
		$money = isset($data['money']) ? trim($data['money']) : '';
		$tradeStatus = isset($data['trade_status']) ? trim($data['trade_status']) : '';

		if($tradeStatus === 'TRADE_SUCCESS' && $outTradeNo === TRADE_NO && round((float)$money, 2) === round((float)$order['realmoney'], 2)){
			processNotify($order, $tradeNo);
			return ['type'=>'html', 'data'=>'success'];
		}

		return ['type'=>'html', 'data'=>'fail'];
	}

	static public function return(){
		return ['type'=>'page', 'page'=>'return'];
	}

	static private function createOrder($channel, $order, $siteurl, $conf){
		$gateway = self::resolveGatewayConfig($channel['appurl']);
		$apiUrl = $gateway['submit_url'];
		$returnUrl = $siteurl.'pay/return/'.TRADE_NO.'/';
		$params = [
			'pid' => trim($channel['appid']),
			'type' => $order['typename'],
			'notify_url' => $conf['localurl'].'pay/notify/'.TRADE_NO.'/',
			'return_url' => $returnUrl,
			'out_trade_no' => TRADE_NO,
			'name' => $order['name'],
			'money' => strval(round($order['realmoney'], 2)),
		];
		$params['sign'] = self::makeSign($params, $channel['appkey']);
		$params['sign_type'] = 'MD5';

		$response = self::requestWithHeaders($apiUrl.'?'.http_build_query($params));
		$headers = $response['headers'];
		$statusCode = $response['status'];
		$location = '';
		foreach($headers as $line){
			if(stripos($line, 'Location:') === 0){
				$location = trim(substr($line, 9));
				break;
			}
		}

		if($statusCode < 300 || $statusCode >= 400 || empty($location)){
			$body = trim($response['body']);
			$json = json_decode($body, true);
			if(isset($json['message']) && $json['message']){
				throw new Exception('Epusdt下单失败：'.$json['message']);
			}
			throw new Exception('Epusdt下单失败，未返回支付跳转地址');
		}

		return self::buildPublicCheckoutUrl($location, $channel['appswitch'], $gateway['checkout_base']);
	}

	static private function buildPublicCheckoutUrl($location, $publicBaseUrl, $checkoutBase){
		if(stripos($location, 'http://') === 0 || stripos($location, 'https://') === 0){
			return $location;
		}
		$base = trim($publicBaseUrl);
		if(empty($base)) {
			$base = $checkoutBase;
		} else {
			$base = self::deriveCheckoutBase($base);
		}
		if(empty($base)){
			throw new Exception('Epusdt收银台域名未配置，请填写可外网访问的收银台域名');
		}
		if(self::isLocalBaseUrl($base)){
			throw new Exception('Epusdt收银台域名未配置，请填写可外网访问的收银台域名');
		}
		return self::joinUrl($base, $location);
	}

	static private function resolveGatewayConfig($url){
		$url = self::normalizeUrl($url);
		$path = parse_url($url, PHP_URL_PATH);
		$path = rtrim((string)$path, '/');
		if($path && preg_match('#/payments/epay/v1/order/create-transaction/submit\.php$#i', $path)){
			$submitUrl = $url;
		}elseif($path && preg_match('#/payments/epay/v1/order/create-transaction$#i', $path)){
			$submitUrl = $url.'/submit.php';
		}elseif($path && preg_match('#/mapi\.php$#i', $path)){
			$submitUrl = preg_replace('#/mapi\.php$#i', '/submit.php', $url);
		}elseif($path && preg_match('#/submit\.php$#i', $path)){
			$submitUrl = $url;
		}else{
			$submitUrl = rtrim($url, '/').'/payments/epay/v1/order/create-transaction/submit.php';
		}
		return [
			'submit_url' => $submitUrl,
			'checkout_base' => self::deriveCheckoutBase($url),
		];
	}

	static private function deriveCheckoutBase($url){
		$url = self::normalizeUrl($url);
		$parts = parse_url($url);
		if(empty($parts['scheme']) || empty($parts['host'])){
			return $url;
		}
		$origin = $parts['scheme'].'://'.$parts['host'];
		if(isset($parts['port'])){
			$origin .= ':'.$parts['port'];
		}
		$path = isset($parts['path']) ? rtrim($parts['path'], '/') : '/';
		if(empty($path) || $path === '/'){
			return $origin.'/';
		}
		$markers = [
			'/payments/epay/v1/order/create-transaction/submit.php',
			'/payments/epay/v1/order/create-transaction',
			'/mapi.php',
			'/submit.php',
		];
		foreach($markers as $marker){
			if(self::endsWith(strtolower($path), strtolower($marker))){
				$prefix = substr($path, 0, -strlen($marker));
				return $origin.(empty($prefix) ? '/' : rtrim($prefix, '/').'/');
			}
		}
		return $origin.rtrim($path, '/').'/';
	}

	static private function normalizeUrl($url){
		$url = trim($url);
		if(empty($url)) throw new Exception('Epusdt接口地址未配置');
		if(stripos($url, 'http://') !== 0 && stripos($url, 'https://') !== 0){
			throw new Exception('Epusdt接口地址格式错误，必须以 http:// 或 https:// 开头');
		}
		return rtrim($url, '/');
	}

	static private function joinUrl($base, $path){
		$path = trim($path);
		if(empty($path)){
			return rtrim($base, '/').'/';
		}
		if(strpos($path, '//') === 0){
			$scheme = parse_url($base, PHP_URL_SCHEME);
			return ($scheme ? $scheme : 'https').':'.$path;
		}
		if(substr($path, 0, 1) === '/'){
			$parts = parse_url($base);
			if(empty($parts['scheme']) || empty($parts['host'])){
				return $path;
			}
			$origin = $parts['scheme'].'://'.$parts['host'];
			if(isset($parts['port'])){
				$origin .= ':'.$parts['port'];
			}
			return $origin.$path;
		}
		return rtrim($base, '/').'/'.ltrim($path, '/');
	}

	static private function endsWith($haystack, $needle){
		if($needle === '') return true;
		$length = strlen($needle);
		return substr($haystack, -$length) === $needle;
	}

	static private function isLocalBaseUrl($url){
		$host = parse_url($url, PHP_URL_HOST);
		if(empty($host)) return false;
		if(in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) return true;
		return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
			&& filter_var($host, FILTER_VALIDATE_IP) !== false;
	}

	static private function requestWithHeaders($url){
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Accept: */*',
			'Accept-Language: zh-CN,zh;q=0.8',
			'Connection: close',
		]);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		$response = curl_exec($ch);
		if($response === false){
			$error = curl_error($ch);
			curl_close($ch);
			throw new Exception('请求Epusdt失败：'.$error);
		}
		$headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
		$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		$headerText = substr($response, 0, $headerSize);
		$body = substr($response, $headerSize);

		return [
			'status' => $statusCode,
			'headers' => preg_split("/\\r\\n|\\n|\\r/", trim($headerText)),
			'body' => $body,
		];
	}

	static private function makeSign($params, $key){
		ksort($params);
		$pairs = [];
		foreach($params as $k => $v){
			if($k === 'sign' || $k === 'sign_type' || $v === '' || $v === null) continue;
			$pairs[] = $k.'='.$v;
		}
		return md5(implode('&', $pairs).$key);
	}

	static private function verifySign($params, $key){
		if(empty($params['sign'])) return false;
		return hash_equals(self::makeSign($params, $key), (string)$params['sign']);
	}
}

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
				'note' => '填写站点根地址或完整接口地址',
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
				'note' => '外网收银台域名，可留空',
			],
			'appselector' => [
				'name' => '支付资产',
				'type' => 'input',
				'note' => '填写 usdt.tron 等 token.network；alipay 使用网关默认资产或多链收银台；默认 usdt.tron',
			],
			'appcurrency' => [
				'name' => '订单法币',
				'type' => 'input',
				'note' => '须与易支付订单计价币种一致，默认 cny；例如 cny、usd',
			],
		],
		'select' => null,
		'note' => '支付方式请新增为 usdt。插件使用 Epusdt 的易支付兼容接口下单，默认提交 type=usdt.tron，并兼容 GET/POST 异步回调。',
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
		if(empty($data) || !self::verifySign($data, $channel['appkey'])) {
			return ['type'=>'html', 'data'=>'fail'];
		}

		foreach(['pid', 'out_trade_no', 'trade_no', 'money', 'trade_status'] as $field){
			if(!isset($data[$field]) || !is_string($data[$field]) || trim($data[$field]) === ''){
				return ['type'=>'html', 'data'=>'fail'];
			}
		}

		$pid = trim($data['pid']);
		$expectedPid = trim((string)$channel['appid']);
		if(!preg_match('/^[0-9]+$/D', $pid) || !preg_match('/^[0-9]+$/D', $expectedPid)
			|| ltrim($pid, '0') !== ltrim($expectedPid, '0')){
			return ['type'=>'html', 'data'=>'fail'];
		}
		$outTradeNo = trim($data['out_trade_no']);
		$tradeNo = trim($data['trade_no']);
		$money = self::normalizeMoney($data['money']);
		$expectedMoney = self::normalizeMoney($order['realmoney']);
		$tradeStatus = trim($data['trade_status']);

		if($tradeStatus === 'TRADE_SUCCESS' && $outTradeNo === TRADE_NO
			&& $money !== null && $expectedMoney !== null && $money === $expectedMoney){
			processNotify($order, $tradeNo);
			return ['type'=>'html', 'data'=>'success'];
		}

		return ['type'=>'html', 'data'=>'fail'];
	}

	static public function return(){
		return ['type'=>'page', 'page'=>'return'];
	}

	static private function createOrder($channel, $order, $siteurl, $conf){
		$pid = trim((string)$channel['appid']);
		if(!preg_match('/^[0-9]+$/D', $pid) || ltrim($pid, '0') === ''){
			throw new Exception('Epusdt PID 必须为正整数');
		}
		if(!is_string($channel['appkey']) || $channel['appkey'] === ''){
			throw new Exception('Epusdt密钥未配置');
		}
		$gateway = self::resolveGatewayConfig($channel['appurl']);
		$apiUrl = $gateway['submit_url'];
		$returnUrl = $siteurl.'pay/return/'.TRADE_NO.'/';
		$params = [
			'pid' => $pid,
			'type' => self::resolvePaySelector($channel, $order['typename']),
			'currency' => self::resolveCurrency($channel),
			'notify_url' => $conf['localurl'].'pay/notify/'.TRADE_NO.'/',
			'return_url' => $returnUrl,
			'out_trade_no' => TRADE_NO,
			'name' => $order['name'],
			'money' => strval(round($order['realmoney'], 2)),
		];
		$params['sign'] = self::makeSign($params, $channel['appkey']);
		$params['sign_type'] = 'MD5';

		$response = self::requestWithHeaders($apiUrl, 'POST', $params);
		if(self::isParseRequestParamsError($response)){
			$response = self::requestWithHeaders($apiUrl.'?'.http_build_query($params));
		}
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
			if(is_array($json)){
				foreach(['message', 'msg', 'error'] as $field){
					if(!empty($json[$field]) && is_string($json[$field])){
						throw new Exception('Epusdt下单失败：'.$json[$field]);
					}
				}
			}
			if(self::isParseRequestParamsError($response)){
				throw new Exception('Epusdt下单失败：failed to parse request params');
			}
			throw new Exception('Epusdt下单失败，未返回支付跳转地址（HTTP '.$statusCode.'）');
		}

		return self::buildPublicCheckoutUrl($location, $channel['appswitch'], $gateway['checkout_base']);
	}

	static private function buildPublicCheckoutUrl($location, $publicBaseUrl, $checkoutBase){
		$location = trim($location);
		$base = trim($publicBaseUrl);
		$absolute = stripos($location, 'http://') === 0 || stripos($location, 'https://') === 0
			|| strpos($location, '//') === 0;
		if($base === '' && $absolute){
			$url = self::joinUrl($checkoutBase, $location);
		}elseif($base === '') {
			$base = $checkoutBase;
			$url = self::joinUrl($base, $location);
		} else {
			$base = self::deriveCheckoutBase($base);
			if($absolute){
				$parts = parse_url($location);
				if($parts === false){
					throw new Exception('Epusdt返回的收银台地址格式错误');
				}
				$location = isset($parts['path']) ? $parts['path'] : '/';
				if(isset($parts['query'])) $location .= '?'.$parts['query'];
				if(isset($parts['fragment'])) $location .= '#'.$parts['fragment'];
			}
			$url = self::joinUrl($base, $location);
		}
		self::validateHttpUrl($url);
		if(self::isLocalBaseUrl($url)){
			throw new Exception('Epusdt收银台域名未配置，请填写可外网访问的收银台域名');
		}
		return $url;
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
		$parts = self::validateHttpUrl($url);
		if(isset($parts['query']) || isset($parts['fragment'])){
			throw new Exception('Epusdt接口地址不能包含查询参数或锚点');
		}
		return rtrim($url, '/');
	}

	static private function validateHttpUrl($url){
		$parts = parse_url($url);
		if($parts === false || empty($parts['scheme']) || empty($parts['host'])
			|| !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
			|| isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)){
			throw new Exception('Epusdt地址格式错误，必须为有效的 http:// 或 https:// 地址');
		}
		return $parts;
	}

	static private function joinUrl($base, $path){
		$path = trim($path);
		if(preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)){
			self::validateHttpUrl($path);
			return $path;
		}
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
		$host = strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]'));
		if(empty($host)) return false;
		if($host === 'localhost' || self::endsWith($host, '.localhost')) return true;
		return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
			&& filter_var($host, FILTER_VALIDATE_IP) !== false;
	}

	static private function requestWithHeaders($url, $method = 'GET', $postFields = null){
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
		curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		$headers = [
			'Accept: */*',
			'Accept-Language: zh-CN,zh;q=0.8',
			'Connection: close',
		];
		if(strtoupper($method) === 'POST'){
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postFields) ? http_build_query($postFields) : (string)$postFields);
			$headers[] = 'Content-Type: application/x-www-form-urlencoded; charset=UTF-8';
			$headers[] = 'Expect:';
		}
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
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

	static private function isParseRequestParamsError($response){
		$body = trim(isset($response['body']) ? $response['body'] : '');
		if($body === '') return false;
		if(stripos($body, 'failed to parse request params') !== false) return true;
		$json = json_decode($body, true);
		if(!is_array($json)) return false;
		foreach(['message', 'msg', 'error'] as $key){
			if(!empty($json[$key]) && stripos((string)$json[$key], 'failed to parse request params') !== false){
				return true;
			}
		}
		return false;
	}

	static private function makeSign($params, $key){
		ksort($params, SORT_STRING);
		$pairs = [];
		foreach($params as $k => $v){
			if($k === 'sign' || $k === 'sign_type' || $v === '' || $v === null) continue;
			$pairs[] = $k.'='.$v;
		}
		return md5(implode('&', $pairs).$key);
	}

	static private function normalizeMoney($money){
		$money = trim((string)$money);
		if(!preg_match('/^([0-9]+)(?:\.([0-9]{1,2})0*)?$/D', $money, $matches)) return null;
		$whole = ltrim($matches[1], '0');
		return ($whole === '' ? '0' : $whole).'.'.str_pad(isset($matches[2]) ? $matches[2] : '', 2, '0');
	}

	static private function resolveCurrency($channel){
		$currency = isset($channel['appcurrency']) ? strtolower(trim($channel['appcurrency'])) : '';
		if($currency === '') return 'cny';
		if(!preg_match('/^[a-z]{3}$/D', $currency)){
			throw new Exception('Epusdt订单法币配置错误，请填写 cny、usd 这类三字母币种');
		}
		return $currency;
	}

	static private function resolvePaySelector($channel, $typename='usdt'){
		$selector = isset($channel['appselector']) ? strtolower(trim($channel['appselector'])) : '';
		if($selector === ''){
			return self::buildTypeSelector($typename);
		}
		if($selector === 'alipay'){
			return $selector;
		}
		if(!preg_match('/^[a-z0-9]+\\.[a-z0-9_-]+$/', $selector)){
			throw new Exception('Epusdt支付资产配置错误，请填写 usdt.tron 这类 token.network');
		}
		return $selector;
	}

	static private function buildTypeSelector($typename){
		$typename = strtolower(trim((string)$typename));
		if($typename === '') return 'usdt.tron';
		if(strpos($typename, '.') !== false) return $typename;
		return $typename.'.tron';
	}

	static private function verifySign($params, $key){
		if(!is_string($key) || $key === '' || empty($params['sign']) || !is_string($params['sign'])
			|| !preg_match('/^[a-f0-9]{32}$/D', $params['sign'])) return false;
		foreach($params as $value){
			if(!is_scalar($value) && $value !== null) return false;
		}
		if(isset($params['sign_type']) && $params['sign_type'] !== 'MD5') return false;
		return hash_equals(self::makeSign($params, $key), (string)$params['sign']);
	}
}

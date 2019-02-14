<?php
// declare(strict_types=1);

// 接口名称
// 接口url
// 接口发送的数据
// 微信返回的数据
// 错误类型
// 错误的详细信息，哪个文件，第几行，第几列


// 请求成功
// json解释成功
// 没有错误代码
// 存在关键字段

// POST
// GET
// multipart/form-data;
// application/json;charset=UTF-8
// application/x-www-form-urlencoded

namespace app\simple\lib;

// use think\Db;

class WechatException extends \Exception
{
	private $errinfo;

	function __construct(array $errinfo, int $code = 0, Throwable $previous = null)
	{
		$message = $errinfo['msg'];
		$this->errinfo = $errinfo;
		$this->errinfo['trace'] = $this->__toString();
		parent::__construct($message, $code, $previous);
	}

	public function getWechatExceptionMsg() : array
	{
		return $this->errinfo;
	}
}

class RequestException extends WechatException
{
	function __construct(array $errinfo, int $code = 0, Throwable $previous = NULL)
	{
		$errinfo['msg'] = empty($errinfo['msg']) ? '请求失败' : $errinfo['msg'];
		parent::__construct($errinfo, $code, $previous);
	}
}

class JsonException extends WechatException
{
	function __construct(array $errinfo, int $code = 0, Throwable $previous = NULL)
	{
		$errinfo['msg'] = empty($errinfo['msg']) ? 'json 解释错误' : $errinfo['msg'];
		parent::__construct($errinfo, $code, $previous);
	}
}

class HasErrorCodeException extends WechatException
{
	function __construct(array $errinfo, int $code = 0, Throwable $previous = NULL)
	{
		$errinfo['msg'] = empty($errinfo['msg']) ? '返回码不为 0' : $errinfo['msg'];
		parent::__construct($errinfo, $code, $previous);
	}
}

class LackKeyParametersException extends WechatException
{
	function __construct(array $errinfo, int $code = 0, Throwable $previous = NULL)
	{
		$errinfo['msg'] = empty($errinfo['msg']) ? '缺少关键参数'.$errinfo['field'] : $errinfo['msg'];
		parent::__construct($errinfo, $code, $previous);
	}
}

class CacheException extends WechatException
{
}

class OtherException extends WechatException
{
}

class wechatlib
{

	# region 类相关

		private $token;
		private $app_id;
		private $app_secret;
		private $gh_id;
		private $temp_path;
		private $cache_type;
		private $log_path;

		function __construct($conf)
		{
			$this->token = empty($conf['token']) ? '' : $conf['token'];
			$this->app_id = empty($conf['app_id']) ? '' : $conf['app_id'];
			$this->app_secret = empty($conf['app_secret']) ? '' : $conf['app_secret'];
			$this->gh_id = empty($conf['gh_id']) ? '' : $conf['gh_id'];
			$this->temp_path = empty($conf['temp_path']) ? 'wecache' : $conf['temp_path'];
			$this->cache_type = empty($conf['cache_type']) ? 'file' : $conf['cache_type'];
			$this->log_path = empty($conf['log_path']) ? '' : $conf['log_path'];

			if ($this->cache_type === 'file')
			{
				$dir = $this->temp_path;
				if (!is_dir($dir))
				{
					mkdir($dir, 0777, true);
				}
			}
		}

		public function get_appid() : string
		{
			return $this->app_id;
		}

	# endregion 类相关

	# region 基础

		/**
		 * 验证 Token
		 */
		public function check_token() : bool
		{
			$signature = empty($_GET["signature"]) ? '' : $_GET["signature"]; // 微信加密签名
			$timestamp = empty($_GET["timestamp"]) ? '' : $_GET["timestamp"]; // 时间戳
			$nonce     = empty($_GET["nonce"]) ? '' : $_GET["nonce"];         // 随机数
			$echoStr   = empty($_GET["echostr"]) ? '' : $_GET["echostr"];     // 随机字符串

			$token = $this->token;
			$tmpArr = array($token, $timestamp, $nonce);
			sort($tmpArr, SORT_STRING);
			$tmpStr = implode( $tmpArr );
			$tmpStr = sha1( $tmpStr );

			if ($tmpStr == $signature)
			{
				if (empty($echoStr))
				{
					return true;
				}
				else
				{
					echo $echoStr;
					exit;
				}
			}
			else
			{
				return false;
			}
		}

		/**
		 * 接收微信的消息
		 */
		public function wechat_massage()
		{
			$postObj = simplexml_load_string(file_get_contents("php://input"), 'SimpleXMLElement', LIBXML_NOCDATA);
			if ( ! $postObj or $postObj->FromUserName === NULL or $postObj->ToUserName === NULL)
			{
				$e = new WechatException(['msg' => '微信消息错误'], 0);
				$this->logger($e);
				throw $e;
			}

			return $postObj;
		}

		/**
		 * 获取 access_token
		 */
		public function get_access_token() : string
		{
			$key = "access_token@".$this->app_id;
			$ret = $this->get_cache($key);
			if ($ret === false || $ret['expire_time'] < time()) {
				$this->del_cache($key);
				$access_token = $this->refresh_get_access_token();
				$key = "access_token@".$this->app_id;
				$value = $access_token;
				$expire_time = time() + 7000;
				$this->set_cache($key, $value, $expire_time);
			} else {
				$access_token = $ret['value'];
			}

			return $access_token;
		}
		private function refresh_get_access_token() : string
		{
			$url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$this->app_id}&secret={$this->app_secret}";
			$response_raw = file_get_contents($url);

			$errinfo = [];
			$errinfo['url'] = $url;
			$errinfo['api'] = '获取 access_token';
			$field = [
				[
					'name' => 'access_token',
					'type' => 'string',
					'empty' => false
				]
			];
			$ret = $this->is_request_success($response_raw, $field, $errinfo);
			$access_token = $ret['access_token'];

			return $access_token;
		}

		/**
		 * 创建菜单
		 */
		public function create_menu(array $data) : array
		{
			$data = json_encode($data, JSON_UNESCAPED_UNICODE);
			$access_token = $this->get_access_token();
			$url = "https://api.weixin.qq.com/cgi-bin/menu/create?access_token=".$access_token;
			$opts = array(
				'http'=>array(
					'method' => "POST",
					'header' => "Content-type: application/json;charset=UTF-8",
					'content' => $data
				)
			);
			$context = stream_context_create($opts);
			$response_raw = file_get_contents($url, false, $context);

			$errinfo = [];
			$errinfo['url'] = $url;
			$errinfo['api'] = '创建菜单';
			$field = [];
			$ret = $this->is_request_success($response_raw, $field, $errinfo);

			return $ret;
		}

		/**
		 * 生成随机字符串
		 */
		private function get_noncestr(int $length = 16) : string
		{
			$chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
			$str = "";
			for ($i = 0; $i < $length; $i++) {
				$str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
			}

			return $str;
		}

		/**
		 * 判断请求是否成功
		 */
		private function is_request_success(string $response_raw, array $field, array $errinfo) : array
		{
			$errinfo['response_raw'] = $response_raw;
			if ($response_raw === false) {
				$e = new RequestException($errinfo);
				$this->logger($e);
				throw $e;
			}

			$response_json = json_decode($response_raw, true);
			if ($response_json === null) {
				$errinfo['json_last_error_msg'] = json_last_error_msg();
				$e = new JsonException($errinfo);
				$this->logger($e);
				throw $e;
			}

			$errinfo['response_json'] = $response_json;
			if ($this->has_error_code($response_json)) {
				$e = new HasErrorCodeException($errinfo);
				$this->logger($e);
				throw $e;
			}

			$this->has_field($response_json, $field, $errinfo);

			return $response_json;
		}

		/**
		 * 判断返回值里是否有错误代码
		 */
		private function has_error_code(array $tag) : bool
		{
			if (isset($tag['errcode']) && $tag['errcode'] !== 0) {
				return true;
			}
			return false;
		}

		/**
		 * 判断返回的数据里是否有包含必要字段
		 */
		private function has_field(array $tag, array $field, array $errinfo) : void
		{
			foreach ($field as $value) {
				$errinfo['field'] = $value['name'];
				if (isset($tag[$value['name']])) {
					$tag_item = $tag[$value['name']];
					$type = $value['type'];
					$tag_item_type;
					if ($type === 'number') {
						if (!(is_int($tag_item) || is_float($tag_item))) {
							$e = new LackKeyParametersException($errinfo);
							$this->logger($e);
							throw $e;
						}
						if ($value['empty'] === false && $tag_item === 0) {
							$e = new LackKeyParametersException($errinfo);
							$this->logger($e);
							throw $e;
						}
					} else if ($type === 'string') {
						if (!is_string($tag_item)) {
							$e = new LackKeyParametersException($errinfo);
							$this->logger($e);
							throw $e;
						}
						if ($value['empty'] === false && $tag_item === '') {
							$e = new LackKeyParametersException($errinfo);
							$this->logger($e);
							throw $e;
						}
					} else if ($type === 'array') {
						if (!is_array($tag_item)) {
							$e = new LackKeyParametersException($errinfo);
							$this->logger($e);
							throw $e;
						}
						if ($value['empty'] === false && count($tag_item) === 0) {
							throw new LackKeyParametersException($errinfo);
						}
					} else if ($type === 'boolean') {
						if (!is_bool($tag_item)) {
							$e = new LackKeyParametersException($errinfo);
							$this->logger($e);
							throw $e;
						}
						if ($value['empty'] === false && $tag_item === false) {
							$e = new LackKeyParametersException($errinfo);
							$this->logger($e);
							throw $e;
						}
					} else if ($type === 'null') {
						if ($tag_item !== null) {
							$e = new LackKeyParametersException($errinfo);
							$this->logger($e);
							throw $e;
						}
					}
				} else {
					$e = new LackKeyParametersException($errinfo);
					$this->logger($e);
					throw $e;
				}
			}
		}

	# endregion 基础

	# region 被动回复消息

		/**
		 * 回复文本消息
		 */
		public function transmit_text($object, string $content) : string
		{
			if ( ! isset($content) or empty($content))
			{
				return "";
			}

			$xmlTpl = "<xml>
						<ToUserName><![CDATA[%s]]></ToUserName>
						<FromUserName><![CDATA[%s]]></FromUserName>
						<CreateTime>%s</CreateTime>
						<MsgType><![CDATA[text]]></MsgType>
						<Content><![CDATA[%s]]></Content>
					</xml>";
			$result = sprintf($xmlTpl, $object->FromUserName, $object->ToUserName, time(), $content);

			return $result;
		}

		/**
		 * 回复图文消息
		 */
		public function transmitNews($object, array $newsArray) : string
		{
			if (!is_array($newsArray)) {
				return "";
			}
			$itemTpl = "<item>
							<Title><![CDATA[%s]]></Title>
							<Description><![CDATA[%s]]></Description>
							<PicUrl><![CDATA[%s]]></PicUrl>
							<Url><![CDATA[%s]]></Url>
						</item>";

			$item_str = "";
			foreach ($newsArray as $item) {
				$item_str .= sprintf($itemTpl, $item['Title'], $item['Description'], $item['PicUrl'], $item['Url']);
			}
			$xmlTpl = "<xml>
							<ToUserName><![CDATA[%s]]></ToUserName>
							<FromUserName><![CDATA[%s]]></FromUserName>
							<CreateTime>%s</CreateTime>
							<MsgType><![CDATA[news]]></MsgType>
							<ArticleCount>%s</ArticleCount>
							<Articles>
						$item_str    </Articles>
						</xml>";

			$result = sprintf($xmlTpl, $object->FromUserName, $object->ToUserName, time(), count($newsArray));

			return $result;
		}

		/**
		 * 回复音乐消息
		 */
		public function transmitMusic($object, array $musicArray) : string
		{
			if (!is_array($musicArray)) {
				return "";
			}
			$itemTpl = "<Music>
							<Title><![CDATA[%s]]></Title>
							<Description><![CDATA[%s]]></Description>
							<MusicUrl><![CDATA[%s]]></MusicUrl>
							<HQMusicUrl><![CDATA[%s]]></HQMusicUrl>
						</Music>";

			$item_str = sprintf($itemTpl, $musicArray['Title'], $musicArray['Description'], $musicArray['MusicUrl'], $musicArray['HQMusicUrl']);

			$xmlTpl = "<xml>
							<ToUserName><![CDATA[%s]]></ToUserName>
							<FromUserName><![CDATA[%s]]></FromUserName>
							<CreateTime>%s</CreateTime>
							<MsgType><![CDATA[music]]></MsgType>
							$item_str
						</xml>";

			$result = sprintf($xmlTpl, $object->FromUserName, $object->ToUserName, time());

			return $result;
		}

		/**
		 * 回复图片消息
		 */
		public function transmitImage($object, array $imageArray) : string
		{
			$itemTpl = "<Image>
							<MediaId><![CDATA[%s]]></MediaId>
						</Image>";

			$item_str = sprintf($itemTpl, $imageArray['MediaId']);

			$xmlTpl = "<xml>
							<ToUserName><![CDATA[%s]]></ToUserName>
							<FromUserName><![CDATA[%s]]></FromUserName>
							<CreateTime>%s</CreateTime>
							<MsgType><![CDATA[image]]></MsgType>
							$item_str
						</xml>";

			$result = sprintf($xmlTpl, $object->FromUserName, $object->ToUserName, time());

			return $result;
		}

		/**
		 * 回复语音消息
		 */
		public function transmitVoice($object, array $voiceArray) : string
		{
			$itemTpl = "<Voice>
							<MediaId><![CDATA[%s]]></MediaId>
						</Voice>";

			$item_str = sprintf($itemTpl, $voiceArray['MediaId']);
			$xmlTpl = "<xml>
							<ToUserName><![CDATA[%s]]></ToUserName>
							<FromUserName><![CDATA[%s]]></FromUserName>
							<CreateTime>%s</CreateTime>
							<MsgType><![CDATA[voice]]></MsgType>
							$item_str
						</xml>";

			$result = sprintf($xmlTpl, $object->FromUserName, $object->ToUserName, time());

			return $result;
		}

		/**
		 * 回复视频消息
		 */
		public function transmitVideo($object, array $videoArray) : string
		{
			$itemTpl = "<Video>
							<MediaId><![CDATA[%s]]></MediaId>
							<ThumbMediaId><![CDATA[%s]]></ThumbMediaId>
							<Title><![CDATA[%s]]></Title>
							<Description><![CDATA[%s]]></Description>
						</Video>";

			$item_str = sprintf($itemTpl, $videoArray['MediaId'], $videoArray['ThumbMediaId'], $videoArray['Title'], $videoArray['Description']);

			$xmlTpl = "<xml>
						<ToUserName><![CDATA[%s]]></ToUserName>
						<FromUserName><![CDATA[%s]]></FromUserName>
						<CreateTime>%s</CreateTime>
						<MsgType><![CDATA[video]]></MsgType>
						$item_str
					</xml>";

			$result = sprintf($xmlTpl, $object->FromUserName, $object->ToUserName, time());

			return $result;
		}

	# endregion 被动回复消息

	# region 网页开发

		/**
		 * 获取网页授权的链接
		 */
		public function get_web_authorize_link(string $redirect_uri, string $scope = 'snsapi_base', string $state = '') : string
		{
			$appid = $this->app_id;
			$redirect_uri = urlencode($redirect_uri);
			$url = "https://open.weixin.qq.com/connect/oauth2/authorize?appid={$appid}&redirect_uri={$redirect_uri}&response_type=code&scope={$scope}&state={$state}#wechat_redirect";

			return $url;
		}

		/**
		 * 获取网页授权的 access_token
		 */
		public function get_web_access_token(string $code)
		{
			$url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$this->app_id}&secret={$this->app_secret}&code={$code}&grant_type=authorization_code";
			$response_raw = file_get_contents($url);

			$errinfo = [];
			$errinfo['url'] = $url;
			$errinfo['api'] = '获取 网页授权的 access_token';
			$field = [
				[
					'name' => 'access_token',
					'type' => 'string',
					'empty' => false
				],
				[
					'name' => 'openid',
					'type' => 'string',
					'empty' => false
				]
			];
			$ret = $this->is_request_success($response_raw, $field, $errinfo);

			return $ret;
		}

		/**
		 * 网页授权-获取用户信息
		 */
		public function get_web_user_info(string $web_access_token, string $openid)
		{
			$userinfo_url = "https://api.weixin.qq.com/sns/userinfo?access_token={$web_access_token}&openid={$openid}&lang=zh_CN";
			$response_raw = file_get_contents($userinfo_url);

			$errinfo = [];
			$errinfo['url'] = $url;
			$errinfo['api'] = '获取 网页授权-获取用户信息';
			$field = [];
			$ret = $this->is_request_success($response_raw, $field, $errinfo);

			return $ret;
		}

		/**
		 * 获取 jssdk 的 ticket
		 */
		private function get_jsapi_ticket()
		{
		}
		private function refresh_get_jsapi_ticket()
		{
			$access_token = $this->get_access_token();//获取access_token
			$jsapi_ticket_json = file_get_contents("https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token=".$access_token."&type=jsapi");//获取jsapi_ticket
			$jsapi_ticket_object = json_decode($jsapi_ticket_json);//把JSON编码的字符串转换为object
			$jsapi_ticket = $jsapi_ticket_object->ticket;
			
			return $jsapi_ticket;
		}

		/**
		 * 获取 jssdk 的签名
		 */
		public function get_jssk_signature() : string
		{
			$jsapi_ticket = $this->get_jsapi_ticket();//获取jssdk的ticket
			$noncestr = $this->get_noncestr();// 生成签名的随机字符串
			$timestamp = time();// 生成签名的时间戳
			//$url='http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];// 当前网页的URL
			$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
			$url = $protocol.$_SERVER[HTTP_HOST].$_SERVER[REQUEST_URI];// 当前网页的URL
			$string1 = "jsapi_ticket=".$jsapi_ticket."&noncestr=".$noncestr."&timestamp=".$timestamp."&url=".$url."";//拼接参与签名的字段，顺序不能变
			$signature = sha1($string1);//对string1作sha1加密，签名到此生成完毕
			
			return $signature;
		}

	# endregion 网页开发

	# region 卡劵

		/**
		 * 获取卡劵的 ticket
		 */
		public function get_api_ticket()
		{
		}
		private function refresh_get_api_ticket()
		{
			$access_token = $this->get_access_token();
			$api_ticket_json = file_get_contents("https://api.weixin.qq.com/cgi-bin/ticket/getticket?access_token=".$access_token."&type=wx_card");
			$api_ticket_object = json_decode($api_ticket_json);
			$api_ticket = $api_ticket_object->ticket;

			return $api_ticket;
		}

		/**
		 * 获取卡劵的签名 cardSign
		 */
		public function get_cardSign(string $card_id) : string
		{
			$timestamp = time();
			$noncestr = $this->get_noncestr();
			$api_ticket = $this->get_api_ticket();
			
			$arrdata = array("timestamp" => $timestamp, "noncestr" => $noncestr, "jsapi_ticket" => $api_ticket,"card_id" => $card_id);
			arsort($arrdata, 3);
			$paramstring = "";
			foreach ($arrdata as $key => $value) {
				$paramstring .= $value;
			}
			$sign = sha1($paramstring);
			if (!$sign)
				return false;
			return $sign;
		}

	# endregion 卡劵

	# region 用户管理

		/**
		 * 获取用户信息
		 */
		public function get_user_info(string $openid) : array
		{
			$access_token = $this->get_access_token();
			$url = "https://api.weixin.qq.com/cgi-bin/user/info?access_token={$access_token}&openid={$openid}&lang=zh_CN";
			$response_raw = file_get_contents($url);

			$errinfo = [];
			$errinfo['url'] = $url;
			$errinfo['api'] = '获取 获取用户信息';
			$field = [
				[
					'name' => 'subscribe',
					'type' => 'number',
					'empty' => true
				]
			];
			$ret = $this->is_request_success($response_raw, $field, $errinfo);

			return $ret;
		}

		/**
		 * 通过 openid 获取 unionid
		 */
		public function openid2unionid(string $openid) : string
		{
			$access_token = $this->get_access_token();
			$url = "https://api.weixin.qq.com/cgi-bin/user/info?access_token={$access_token}&openid={$openid}&lang=zh_CN";
			$response_raw = file_get_contents($url);

			$errinfo = [];
			$errinfo['url'] = $url;
			$errinfo['api'] = '通过 openid 获取 unionid';
			$field = [
				[
					'name' => 'unionid',
					'type' => 'string',
					'empty' => false
				]
			];
			$ret = $this->is_request_success($response_raw, $field, $errinfo);

			return $ret['unionid'];
		}

		/**
		 * 获取关注者列表
		 */
		public function get_user_list(string $next_openid = '') : array
		{
			$access_token = $this->get_access_token();
			$url = "https://api.weixin.qq.com/cgi-bin/user/get?access_token=".$access_token."&next_openid=".$next_openid;
			$response_raw = file_get_contents($url);

			$errinfo = [];
			$errinfo['url'] = $url;
			$errinfo['api'] = '获取关注者列表';
			$field = [];
			$ret = $this->is_request_success($response_raw, $field, $errinfo);

			return $ret;
		}

	# endregion 用户管理

	# region 客服消息

		/**
		 * 发送客服消息
		 */
		private function send_custom_message(array $msg) : array
		{
			$access_token = $this->get_access_token();
			$url = "https://api.weixin.qq.com/cgi-bin/message/custom/send?access_token=".$access_token;
			$data = urldecode(json_encode($msg));
			$opts = array(
				'http'=>array(
					'method' => "POST",
					'header' => "Content-type: application/json;charset=UTF-8",
					'content' => $data
				)
			);
			$context = stream_context_create($opts);
			$response_raw = file_get_contents($url, false, $context);

			$errinfo = [];
			$errinfo['url'] = $url;
			$errinfo['api'] = '发送客服消息';
			$field = [];
			$ret = $this->is_request_success($response_raw, $field, $errinfo);

			return $ret;
		}

		/**
		 * 发送客服消息，文本消息
		 */
		public function send_custom_message_text(string $touser, string $content) : array
		{
			$msg = array(
				'touser' => $touser,
				'msgtype' => "text",
				'text' => array(
					'content' => urlencode("$content"),
				)
			);
			return $this->send_custom_message($msg);
		}

		/**
		 * 发送客服消息，图片消息
		 */
		public function send_custom_message_image(string $touser, string $media_id) : array
		{
			$msg = array(
				'touser' => $touser,
				'msgtype' => "image",
				'image' => array(
					'media_id' => "$media_id",
				)
			);
			return $this->send_custom_message($msg);
		}

		/**
		 * 发送客服消息，语音消息
		 */
		public function send_custom_message_voice(string $touser, string $media_id) : array
		{
			$msg = array(
				'touser' => $touser,
				'msgtype' => "voice",
				'voice' => array(
					'media_id' => "$media_id",
				)
			);
			return $this->send_custom_message($msg);
		}

		/**
		 * 发送客服消息，视频消息
		 */
		public function send_custom_message_video(string $touser, array $data) : array
		{
			$msg = array(
				'touser' => $touser,
				'msgtype' => "video",
				'video' => array(
					'media_id' => $data['MediaId'],
					'thumb_media_id' => $data['ThumbMediaId'],
					'title' => urlencode($data['Title']),
					'description' => urlencode($data['Description']),
				)
			);
			return $this->send_custom_message($msg);
		}

		/**
		 * 发送客服消息，音乐消息
		 */
		public function send_custom_message_music(string $touser, array $data) : array
		{
			$msg = array(
				'touser' => $touser,
				'msgtype' => "music",
				'music' => array(
					'title' => $data['Title'],
					'description' => urlencode($data['Description']),
					'musicurl' => $data['MusicUrl'],
					'hqmusicurl' => $data['HQMusicUrl'],
					'thumb_media_id' => $data['Thumb_media_id'],
				)
			);
			return $this->send_custom_message($msg);
		}

		/**
		 * 发送客服消息，发送图文消息（点击跳转到外链）
		 */
		public function send_custom_message_news(string $touser, array $data) : array
		{
			foreach ($data as $key => $value) {
				$articles[$key]['title'] = urlencode($value['Title']);
				$articles[$key]['description'] = urlencode($value['Description']);
				$articles[$key]['url'] = $value['Url'];
				$articles[$key]['picurl'] = $value['PicUrl'];
			}

			$msg = array(
				'touser' => $touser,
				'msgtype' => "news",
				'news' => array(
					'articles' => $articles,
				),
			);

			return $this->send_custom_message($msg);
		}

		/**
		 * 发送客服消息，图文消息（点击跳转到图文消息页面）
		 */
		public function send_custom_message_mpnews($touser, $media_id)
		{
			foreach ($media_id as $key => $value) {
				$temp[$key]['media_id'] = $value;
			}

			$msg = array(
				'touser' => $touser,
				'msgtype' => "mpnews",
				'mpnews' => $temp,
			);

			return $this->send_custom_message($msg);
		}

		/**
		 * 发送客服消息，发送卡券
		 */
		public function send_custom_message_wxcard($touser, $card_id)
		{
			$sgins = $this->get_cardSign($card_id);

			$msg = array(
				'touser' => $touser,
				'msgtype' => "wxcard",
				'wxcard' =>  array(
					'card_id' => $card_id,
					'card_ext' => $sgins,
				),
			);

			return $this->send_custom_message($msg);
		}

	# endregion 客服消息

	# region 二维码

		/**
		 * 创建临时二维码
		 */
		public function create_qrcode(string $scene_str, int $expire_seconds = 604800) : array
		{
			$access_token = $this->get_access_token();
			$url = "https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token={$access_token}";
			$data = '{"expire_seconds": '.$expire_seconds.', "action_name": "QR_STR_SCENE", "action_info": {"scene": {"scene_str": "'.$scene_str.'"}}}';
			$opts = array(
				'http'=>array(
					'method' => "POST",
					'header' => "Content-type: application/json;charset=UTF-8",
					'content' => $data
				)
			);
			$context = stream_context_create($opts);
			$response_raw = file_get_contents($url, false, $context);

			$errinfo = [];
			$errinfo['url'] = $url;
			$errinfo['api'] = '创建临时二维码';
			$field = [
				[
					'name' => 'ticket',
					'type' => 'string',
					'empty' => false
				],
				[
					'name' => 'url',
					'type' => 'string',
					'empty' => false
				]
			];
			$ret = $this->is_request_success($response_raw, $field, $errinfo);

			return $ret;
		}

	# endregion 二维码

	# region 素材管理

		/**
		 * 上传临时素材-图片
		 */
		public function add_temp_material_img($binary, $file_name = '')
		{
			$img_info = getimagesizefromstring($binary);
			if (empty($img_info[2]) or empty($img_info['mime']))
			{
				$errinfo['msg'] = '图片格式错误';
				$errinfo['api'] = '上传临时素材-图片';
				$errinfo['img_info'] = $img_info;
				$e = new OtherException($errinfo);
				$this->logger($e);
				throw $e;
			}

			if ($file_name === '')
			{
				$file_name = time().image_type_to_extension($img_info[2]);
			}
			$file_type = $img_info['mime'];
			$type = 'image';
			return $this->add_temp_material($file_name, $file_type, $type, $binary);
		}

		/**
		 * 上传临时素材
		 */
		public function add_temp_material($file_name, $file_type, $type, $binary)
		{
			// 拼接请求头
			$boundary = time().mt_rand(10000, 99999);
			$br = "-----------------------------".$boundary;
			$nl = "\r\n";

			$data = $br.$nl.'Content-Disposition: form-data; name="media"; filename="'.$file_name.'"'.$nl;
			$data .= "Content-Type: ".$file_type.$nl.$nl;
			$data .= $binary.$nl;
			$data .= $br."--".$nl.$nl;

			$Length = strlen($data);
			$header = array(
				'Content-Length'=>$Length,
				'Content-Type'=>'multipart/form-data; boundary=---------------------------'.$boundary,
			);

			$header_str = '';
			foreach ($header as $k => $v) {
				$header_str .= $k.": ".$v.$nl;
			}
			// 发送请求
			$opts = array(
				'http'=>array(
					'method' => "POST",
					'header' => $header_str,
					'content' => $data
				)
			);
			$context = stream_context_create($opts);
			$access_token = $this->get_access_token();
			$url = "https://api.weixin.qq.com/cgi-bin/media/upload?access_token={$access_token}&type={$type}";
			$response_raw = file_get_contents($url, false, $context);

			$errinfo = [];
			$errinfo['url'] = $url;
			$errinfo['api'] = '上传临时素材';
			$field = [
				[
					'name' => 'media_id',
					'type' => 'string',
					'empty' => false
				]
			];
			$ret = $this->is_request_success($response_raw, $field, $errinfo);

			return $ret;
		}

	# endregion 素材管理

	# region 模板消息

		/**
		 * 发送模板消息
		 */
		public function send_tpl_msg(string $touser, string $template_id, array $data, string $tpl_url = '', string $topcolor = '#FF0000') : array
		{
			$access_token = $this->get_access_token();
			$url = "https://api.weixin.qq.com/cgi-bin/message/template/send?access_token={$access_token}";

			$content = array(
				'touser' => $touser,
				'template_id' => $template_id,
				'topcolor' => $topcolor,
				'data' => $data,
			);
			empty($tpl_url)?:$content['url'] = $tpl_url;

			$content = json_encode($content);
			$opts = array(
				'http'=>array(
					'method' => "POST",
					'header' => "Content-type: application/json;charset=UTF-8",
					'content' => $content
				)
			);
			$context = stream_context_create($opts);
			$response_raw = file_get_contents($url, false, $context);

			$errinfo = [];
			$errinfo['url'] = $url;
			$errinfo['api'] = '发送模板消息';
			$field = [];
			$ret = $this->is_request_success($response_raw, $field, $errinfo);

			return $ret;
		}

	# endregion 模板消息

	# region 请求相关
	# endregion 请求相关

	# region 缓存部分

		private function set_cache(string $name, string $value, int $expire_time) : void
		{
			$cache_file = $this->temp_path."/".$name;
			$binary = [
				'name' => $name,
				'value' => $value,
				'expire_time' => $expire_time,
				'create_time' => time()
			];
			$txt = @serialize($binary);
			$file = @fopen($cache_file, "w");
			if ($file === false) {
				$errinfo['msg'] = '缓存文件打开失败';
				$e = new OtherException($errinfo);
				$this->logger($e);
				throw $e;
			}
			fwrite($file, $txt);
			fclose($file);
		}

		private function get_cache(string $name) : string
		{
			$cache_file = $this->temp_path."/".$name;
			if (!is_file($cache_file)) {
				return false;
			}

			$errinfo['key'] = $name;

			$txt = file_get_contents($cache_file);
			if ($txt === false) {
				$errinfo['msg'] = '缓存文件打开失败';
				$e = new CacheException($errinfo);
				$this->logger($e);
				throw $e;
			}
			$binary = @unserialize($txt);
			if ($binary === false) {
				$errinfo['msg'] = '反序列化失败';
				$e = new CacheException($errinfo);
				$this->logger($e);
				throw $e;
			}

			if (!isset($binary['name']) || !isset($binary['value']) || !isset($binary['expire_time'])) {
				$errinfo['msg'] = '缺少关键参数';
				$errinfo['binary'] = $binary;
				$e = new CacheException($errinfo);
				$this->logger($e);
				throw $e;
			}

			return $binary;
		}

		private function del_cache(string $name)
		{
			$cache_file = $name;
			return @unlink($cache_file);
		}

		// private function set_cache($name, $value, $expire_time)
		// {
		//     $data = array(
		//         'name' => $name,
		//         'value' => $value,
		//         'expire_time' => $expire_time,
		//         'create_time' => time()
		//     );
		//     $ret = Db::name('wechat_cache')->insert($data);
		//     if (!is_int($ret)) {
		//         throw new WechatException();
		//     }
		// }

		// private function get_cache($name)
		// {
		//     $ret = Db::name('wechat_cache')->where('name',$name)->find();
		//     if ($ret === null) {
		//         return false;
		//     }

		//     if (!isset($ret['expire_time']) || !isset($ret['value'])) {
		//         throw new WechatException();
		//     }

		//     return array('value' => $ret['value'], 'expire_time' => $ret['expire_time']);
		// }

		// private function del_cache($name)
		// {
		//     Db::name('wechat_cache')->where('name',$name)->delete();
		// }

	# endregion 缓存部分

	# region 日志相关

		private function logger(WechatException $e) : void
		{
			if (empty($this->log_path))
			{
				return;
			}

			$errinof = $e->getWechatExceptionMsg();
			$describe = $errinof['msg'];
			$data = $errinof;
			$dir = $this->log_path;
			$log_file = date("Y-m-d").".log";
			if ($dir === '/')
			{
				$path = $log_file;
			}
			else
			{
				if (!is_dir($dir))
				{
					mkdir($dir, 0777, true);
				}
				$path = $dir."/".$log_file;
			}
			
			$file = @fopen($path, "a");
			if ( ! $file)
			{
				return;
			}
			$br = "\r\n";
			$txt = "[time]".date('H:i:s').$br;
			$txt .= "[describe]".$describe.$br;
			if ( ! empty($_SERVER['REMOTE_ADDR']))
			{
				$txt .= "[ip]".$_SERVER['REMOTE_ADDR'].$br;
			}
			if ( ! empty($_SERVER['REQUEST_URI']))
			{
				$txt .= "[request_uri]".$_SERVER['REQUEST_URI'].$br;
			}
			if ( ! empty($data))
			{
				$txt .= "[data]".$br;
				$txt .= sprintf("%s", var_export($data, TRUE)).$br;
			}
			$txt .= $br;
			@fwrite($file, $txt);
			@fclose($file);
		}

	# endregion 日志相关
}

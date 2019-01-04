<?php
namespace app\simple\lib;

use think\Db;

class wechatException extends \Exception
{

}

class wechatlib
{

    private $token;
    private $app_id;
    private $app_secret;

    function __construct($conf)
    {
        $this->token = empty($conf['token']) ? '' : $conf['token'];
        $this->app_id = empty($conf['app_id']) ? '' : $conf['app_id'];
        $this->app_secret = empty($conf['app_secret']) ? '' : $conf['app_secret'];
    }

    public function get_appid()
    {
        return $this->app_id;
    }

    /**
     * 验证 Token
     */
    public function check_token()
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
            throw new wechatException('微信消息错误', 0);
        }

        return $postObj;
    }

    /**
     * 获取access_token
     */
    public function get_access_token()
    {
        $key = "access_token@".$this->app_id;
        $ret = $this->get_cache($key);
        if ($ret === false) {
            $access_token = $this->refresh_get_access_token();
        } else if ($ret['expire_time'] < time()) {
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
    private function refresh_get_access_token()
    {
        $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid={$this->app_id}&secret={$this->app_secret}";
        $response_raw = file_get_contents($url);
        if ($response_raw === false) {
            $errinfo = array(
                'msg' => '请求失败',
                'url' => $url,
                'response_raw' => $response_raw
            );
            throw new \wechatException($errinfo);
        }
        $ret = json_decode($response_raw, true);
        if ($ret === null || empty($ret['access_token'])) {
            $errinfo = array(
                'json_last_error_msg' => json_last_error_msg(),
                'response_raw' => $response_raw
            );
            $code = 100001;
            throw new \wechatException($errinfo, $code);
        }
        $access_token = $ret['access_token'];

        return $access_token;
    }

# region 被动回复消息

    /**
     * 回复文本消息
     */
    public function transmit_text($object, $content)
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

    //回复图文消息
    public function transmitNews($object, $newsArray)
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
    public function transmitMusic($object, $musicArray)
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
    public function transmitImage($object, $imageArray)
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
    public function transmitVoice($object, $voiceArray)
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
    public function transmitVideo($object, $videoArray)
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

# region 用户管理

    /**
     * 获取网页授权的access_token
     */
    public function get_web_access_token($code)
    {
        $url = "https://api.weixin.qq.com/sns/oauth2/access_token?appid={$this->app_id}&secret={$this->app_secret}&code={$code}&grant_type=authorization_code";
        $response_raw = file_get_contents($url);
        if ($response_raw === false) {
            $errinfo = array(
                'msg' => '请求失败',
                'url' => $url,
                'response_raw' => $response_raw
            );
            throw new \wechatException($errinfo);
        }

        $ret = json_decode($response_raw, true);
        if ($ret === null) {
            $errinfo = array(
                'msg' => 'json 解释失败',
                'url' => $url,
                'response_raw' => $response_raw,
                'json_last_error_msg' => json_last_error_msg()
            );
            throw new \wechatException($errinfo);
        }

        if (!isset($ret['access_token']) || !isset($ret['openid'])) {
            $errinfo = array(
                'msg' => '响应的结果缺少关键参数',
                'url' => $url,
                'response_raw' => $response_raw,
                'ret' => $ret
            );
            throw new \wechatException($errinfo);
        }

        return $ret;
    }

    /**
     * 获取用户信息
     */
    public function get_user_info($openid)
    {
        $errinfo = array();

        $access_token = $this->get_access_token();
        $url = "https://api.weixin.qq.com/cgi-bin/user/info?access_token={$access_token}&openid={$openid}&lang=zh_CN";
        $errinfo['url'] = $url;
        $response_raw = file_get_contents($url);
        $errinfo['response_raw'] = $response_raw;
        if ($response_raw === false) {
            $errinfo['msg'] = '请求失败';
            throw new \wechatException($errinfo);
        }

        $ret = json_decode($response_raw, true);
        if ($ret === null) {
            $errinfo['msg'] = 'json 解释失败';
            $errinfo['json_last_error_msg'] = json_last_error_msg();
            throw new \wechatException($errinfo);
        }

        if (isset($ret['errcode']) && $ret['errcode'] !== 0) {
            $errinfo['msg'] = '请求错误';
            $errinfo['ret'] = $ret;
            throw new \wechatException($errinfo);
        }

        if (!isset($ret['subscribe'])) {
            $errinfo['msg'] = '响应的结果缺少关键参数';
            $errinfo['ret'] = $ret;
            throw new \wechatException($errinfo);
        }

        return $ret;
    }

# endregion 用户管理

# region 客服消息

    /**
     * 发送客服消息
     */
    private function send_custom_message($msg)
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
        $ret_raw = file_get_contents($url, false, $context);

        $ret = json_decode($ret_raw, TRUE);

        return $ret;
    }

    /**
     * 发送客服消息，文本消息
     */
    public function send_custom_message_text($touser, $content)
    {
        $msg = array(
            'touser' => "$touser",
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
    public function send_custom_message_image($touser, $media_id)
    {
        $msg = array(
            'touser' => "$touser",
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
    public function send_custom_message_voice($touser, $media_id)
    {
        $msg = array(
            'touser' => "$touser",
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
    public function send_custom_message_video($touser, $data)
    {
        $msg = array(
            'touser' => "$touser",
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
    public function send_custom_message_music($touser, $data)
    {
        $msg = array(
            'touser' => "$touser",
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
    public function send_custom_message_news($touser, $data)
    {
        foreach ($data as $key => $value) {
            $articles[$key]['title'] = urlencode($value['Title']);
            $articles[$key]['description'] = urlencode($value['Description']);
            $articles[$key]['url'] = $value['Url'];
            $articles[$key]['picurl'] = $value['PicUrl'];
        }
        
        $msg = array(
            'touser' => "$touser",
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
            'touser' => "$touser",
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
        $sgins=$this->get_cardSign($card_id);
        
        $msg = array(
            'touser' => "$touser",
            'msgtype' => "wxcard",
            'wxcard' =>  array(
                'card_id' => "$card_id",
                'card_ext' => "$sgins",
            ),                         
        );
        
        return $this->send_custom_message($msg);
    }

# endregion 客服消息

# region 二维码

    /**
     * 创建临时二维码
     */
    public function create_qrcode($scene_str)
    {
        $access_token = $this->get_access_token();
        $url = "https://api.weixin.qq.com/cgi-bin/qrcode/create?access_token={$access_token}";
        $data = '{"expire_seconds": 604800, "action_name": "QR_STR_SCENE", "action_info": {"scene": {"scene_str": "'.$scene_str.'"}}}';
        $opts = array(
            'http'=>array(
                'method' => "POST",
                'header' => "Content-type: application/json;charset=UTF-8",
                'content' => $data
            )
        );
        $context = stream_context_create($opts);
        // logger('context', $context);
        $ret_raw = file_get_contents($url, false, $context);
        $ret = json_decode($ret_raw, TRUE);
        if ($ret === NULL)
        {
            // logger('unionid获取失败', array('url' => $url, 'opts' => $opts, 'json_last_error_msg' => json_last_error_msg()));
            return '';
        }
        else if (empty($ret['ticket']) or empty($ret['url']))
        {
            // logger('unionid获取失败', array('url' => $url, 'ret_raw' => $ret_raw, 'json_last_error_msg' => json_last_error_msg()));
            return '';
        }
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
            throw new \Exception("Invalid image file");
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
        $ret = file_get_contents($url, false, $context);
        $err = 'upload temp material.';
        if ($ret === FALSE)
        {
            $err .= ' request fail';
            throw new \Exception($err);
        }
        $ret = json_decode($ret, TRUE);
        if ($ret === NULL)
        {
            $err .= ' json decode fail.';
            $err .= 'json_last_error_msg'.json_last_error_msg();
            throw new \Exception($err);
        }
        if (empty($ret['media_id']))
        {
            throw new \Exception('empty media_id');
        }
        return $ret;
    }

# endregion 素材管理

# region 模板消息

    /**
     * 发送模板消息
     */
    public function send_tpl_msg($touser, $template_id, $data, $tpl_url = '', $topcolor = '#FF0000')
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
        $ret_raw = file_get_contents($url, false, $context);
        return $ret_raw;
    }

# endregion 模板消息

# region 缓存部分

    private function set_cache($name, $value, $expire_time)
    {
        $data = array(
            'name' => $name,
            'value' => $value,
            'expire_time' => $expire_time,
            'create_time' => time()
        );
        $ret = Db::name('wechat_cache')->insert($data);
        if (!is_int($ret)) {
            throw new wechatException();
        }
    }

    private function get_cache($name)
    {
        $ret = Db::name('wechat_cache')->where('name',$name)->find();
        if ($ret === null) {
            return false;
        }

        if (!isset($ret['expire_time']) || !isset($ret['value'])) {
            throw new wechatException();
        }

        return array('value' => $ret['value'], 'expire_time' => $ret['expire_time']);
    }

    private function del_cache($name)
    {
        Db::name('wechat_cache')->where('name',$name)->delete();
    }

# endregion 缓存部分

}

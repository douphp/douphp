<?php

/**
 * DouPHP®
 * ------------------------------------------------------------------------------------
 * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)
 *
 * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-08
 */

namespace Dou\Vendor\Sms\Src;

use Dou\Core\Facade\Session;
use Dou\Core\Service\BaseService;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Check;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 阿里云短信服务实现。
 *
 * 由 {@see \Dou\Vendor\Sms\Sms} 门面创建实例使用，业务侧不直接 `new`。
 */
class Alisms extends BaseService
{
    /** @var string 客户端 */
    protected $client;

    /** @var string 微信小程序需要使用storage储存token */
    protected $storageSmsToken;

    /** @var string 请参阅 https://ak-console.aliyun.com/ 取得您的AK信息 */
    protected $accessKeyId;

    /** @var string 为空时默认跟主图在一个目录 */
    protected $accessKeySecret;

    /** @var string 短信签名，应严格按"签名名称"填写 */
    protected $signName;

    /** @var bool 是否启用https */
    protected $security;

    /**
     * 执行__construct操作。
     *
     * @param string $client 参数client。
     * @param string $storageSmsToken 参数storageSmsToken。
     * @param string $accessKeyId 参数accessKeyId。
     * @param string $accessKeySecret 参数accessKeySecret。
     * @param string $signName 参数signName。
     * @param bool $security 参数security。
     * @return void 返回结果。
     */
    function __construct($client = 'web', $storageSmsToken = '', $accessKeyId = '', $accessKeySecret = '', $signName = '', $security = false) {
        $this->client = $client;
        $this->storageSmsToken = $storageSmsToken;
        $this->accessKeyId = $accessKeyId ? $accessKeyId : Config::get('param.sms_accessKeyId');
        $this->accessKeySecret = $accessKeySecret ? $accessKeySecret : Config::get('param.sms_accessKeySecret');
        $this->signName = $signName ? $signName : Config::get('param.sms_SignName');
        $this->security = $security;
    }

    /**
     * 执行sendSms操作。
     *
     * @param mixed $phone_number 参数phone_number。
     * @param mixed $TemplateCode 参数TemplateCode。
     * @param mixed $TemplateParam 参数TemplateParam。
     * @return mixed 返回结果。
     */
    function sendSms($phone_number, $TemplateCode, $TemplateParam) {
        $params = array ();

        // *** 需用户填写部分 ***
        // 必填：是否启用https
        $security = $this->security;

        // 必填: 请参阅 https://ak-console.aliyun.com/ 取得您的AK信息
        $accessKeyId = $this->accessKeyId;
        $accessKeySecret = $this->accessKeySecret;

        // 必填: 短信接收号码
        $params["PhoneNumbers"] = $phone_number;

        // 必填: 短信签名，应严格按"签名名称"填写，请参考: https://dysms.console.aliyun.com/dysms.htm#/develop/sign
        $params["SignName"] = $this->signName;

        // 必填: 短信模板Code，应严格按"模板CODE"填写, 请参考: https://dysms.console.aliyun.com/dysms.htm#/develop/template
        $params["TemplateCode"] = $TemplateCode;

        // 必填: 下发的参数, 数组形式
        $params['TemplateParam'] = $TemplateParam;

        // 可选: 设置发送短信流水号
        $params['OutId'] = "12345";

        // 可选: 上行短信扩展码, 扩展码字段控制在7位或以下，无特殊需求用户请忽略此字段
        $params['SmsUpExtendCode'] = "1234567";

        // *** 需用户填写部分结束, 以下代码若无必要无需更改 ***
        if(!empty($params["TemplateParam"]) && is_array($params["TemplateParam"])) {
            $params["TemplateParam"] = json_encode($params["TemplateParam"], JSON_UNESCAPED_UNICODE);
        }

        // 此处可能会抛出异常，注意catch
        $content = request()(
            $accessKeyId,
            $accessKeySecret,
            "dysmsapi.aliyuncs.com",
            array_merge($params, array(
                "RegionId" => "cn-hangzhou",
                "Action" => "SendSms",
                "Version" => "2017-05-25",
            )),
            $security
        );

        $code = $content->Code;
        if ($code == 'OK') {
            return 'success';
        } elseif ($code == 'isv.MOBILE_NUMBER_ILLEGAL') {
            return '手机号输入有误，请重新输入';
        } elseif ($code == 'isv.TEMPLATE_MISSING_PARAMETERS') {
            return '模板内容中的变量未全部赋值';
        } elseif ($code == 'isv.SMS_TEMPLATE_ILLEGAL') {
            return '短信模板不存在，或未经审核通过';
        } elseif ($code == 'isv.SMS_SIGNATURE_ILLEGAL	短信签名不合法') {
            return '签名不存在，或未经审核通过';
        } elseif ($code == 'isv.OUT_OF_SERVICE') {
            return '短信账户余额不足';
        } elseif ($code == 'isv.BUSINESS_LIMIT_CONTROL') {
            return '短信发送频率超限';
        } elseif ($code == 'isv.AMOUNT_NOT_ENOUGH') {
            return '阿里云账户余额不足';
        } else {
            Log::error('Sms provider returned error code', array(
                'channel' => 'sms',
                'provider' => 'aliyun',
                'code' => (string) $code,
                'phone' => (string) $phone_number,
                'template' => (string) $TemplateCode,
            ));
            return $code;
        }
    }

    /**
     * 执行smsTokenSet操作。
     *
     * @return mixed 返回结果。
     */
    function smsTokenSet() {
        $sms_token = strtoupper(Str::randomByType('letter.number', 5));

        $temp_id = $this->smsTempId();

        if ($this->client == 'miniprogram' || $this->client == 'api') {
            $data['storage_sms_token'] = md5($sms_token . DOU_SHELL);
            $data['sms_token'] = $sms_token;

            return $data;
        } else {
            $_SESSION[$temp_id] = md5($sms_token . DOU_SHELL);

            return $sms_token;
        }
    }

    /**
     * 执行smsTokenCheck操作。
     *
     * @param mixed $sms_token 参数sms_token。
     * @return string 返回结果。
     */
    function smsTokenCheck($sms_token) {
        if ($this->client == 'miniprogram' || $this->client == 'api') {
            if ($this->storageSmsToken && $sms_token == $this->storageSmsToken) {
                return 'ok'; // 返回令牌验证成功标记
            } else {
                return 'wrong';
            }
        } else {
            $temp_id = $this->smsTempId();
            if (isset($_SESSION[$temp_id]) && $sms_token == $_SESSION[$temp_id]) {
                return 'ok';
            } else {
                return 'wrong';
            }
        }
    }

    /**
     * 执行smsTempId操作。
     *
     * @return mixed 返回结果。
     */
    function smsTempId() {
        $temp_id = substr(md5('sms' . DOU_SHELL) , 0 , 16);
        return $temp_id;
    }

    /**
     * 执行smsOntime操作。
     *
     * @param string $ontime 参数ontime。
     * @param int $timeout 参数timeout。
     * @return bool 返回结果。
     */
    function smsOntime($ontime = '', $timeout = 300) {
        $cur_time = time(); // 当前时间
        if ($cur_time - $ontime < $timeout) { // 当前时间减去发送时间的值与超时时间比较
            return true;
        }
    }

    /**
     * 执行captcha操作。
     *
     * @param mixed $sms_token 参数sms_token。
     * @param mixed $telphone 参数telphone。
     * @return mixed 返回结果。
     */
    function captcha($sms_token, $telphone) {
        if ($this->smsTokenCheck($sms_token)) {
            $telphone = Check::telphone($telphone) ? $telphone : '';

            if (!empty($telphone)) {
                $TemplateCode['code'] = Str::randomByType('number', 4); // 随机验证码
                $msg = $this->sendSms($telphone, Config::get('param.sms_TemplateCode'), $TemplateCode); // 发送短信并返回信息给AJAX

                if ($msg == 'success') {
                    Session::set('sms', $telphone, 'telphone'); // 缓存发送验证码的手机号
                    Session::set('sms', time(), 'ontime');
                    Session::set('sms', md5($TemplateCode['code'] . $telphone . DOU_SHELL), 'code');
                    Session::set('sms', $TemplateCode['code'], 'number');

                    if ($this->client == 'miniprogram' || $this->client == 'api')
                        $response['sms'] = Session::arr('sms');
                }
                $response['msg'] = $msg;
            } else {
                $response['msg'] = lang('user_telphone_cue');
            }
        } else {
            $response['msg'] = lang('illegal');
        }

        return $response;
    }

    /**
     * 执行request操作。
     *
     * @param mixed $accessKeyId 参数accessKeyId。
     * @param mixed $accessKeySecret 参数accessKeySecret。
     * @param mixed $domain 参数domain。
     * @param mixed $params 参数params。
     * @param bool $security 参数security。
     * @param string $method 参数method。
     * @return mixed 返回结果。
     */
    public function request($accessKeyId, $accessKeySecret, $domain, $params, $security=false, $method='POST') {
        $apiParams = array_merge(array (
            "SignatureMethod" => "HMAC-SHA1",
            "SignatureNonce" => uniqid(mt_rand(0,0xffff), true),
            "SignatureVersion" => "1.0",
            "AccessKeyId" => $accessKeyId,
            "Timestamp" => gmdate("Y-m-d\TH:i:s\Z"),
            "Format" => "JSON",
        ), $params);
        ksort($apiParams);

        $sortedQueryStringTmp = "";
        foreach ($apiParams as $key => $value) {
            $sortedQueryStringTmp .= "&" . $this->encode($key) . "=" . $this->encode($value);
        }

        $stringToSign = "{$method}&%2F&" . $this->encode(substr($sortedQueryStringTmp, 1));

        $sign = base64_encode(hash_hmac("sha1", $stringToSign, $accessKeySecret . "&",true));

        $signature = $this->encode($sign);

        $url = ($security ? 'https' : 'http')."://{$domain}/";

        try {
            $content = $this->fetchContent($url, $method, "Signature={$signature}{$sortedQueryStringTmp}");
            return json_decode($content);
        } catch( \Exception $e) { // 如果有抛出错误信息
            Log::error('Sms request exception', array(
                'channel' => 'sms',
                'provider' => 'aliyun',
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => (int) $e->getLine(),
            ));
            return false;
        }
    }

    /**
     * 执行encode操作。
     *
     * @param mixed $str 参数str。
     * @return mixed 返回结果。
     */
    private function encode($str)
    {
        $res = urlencode($str);
        $res = preg_replace("/\+/", "%20", $res);
        $res = preg_replace("/\*/", "%2A", $res);
        $res = preg_replace("/%7E/", "~", $res);
        return $res;
    }

    /**
     * 执行fetchContent操作。
     *
     * @param mixed $url 参数url。
     * @param mixed $method 参数method。
     * @param mixed $body 参数body。
     * @return mixed 返回结果。
     */
    private function fetchContent($url, $method, $body) {
        $ch = curl_init();

        if($method == 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);//post提交方式
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } else {
            $url .= '?'.$body;
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "x-sdk-client" => "php/2.0.0"
        ));

        if(substr($url, 0,5) == 'https') {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }

        $rtn = curl_exec($ch);

        if($rtn === false) {
            // 大多由设置等原因引起，一般无法保障后续逻辑正常执行，
            // 所以这里触发的是E_USER_ERROR，会终止脚本执行，无法被try...catch捕获，需要用户排查环境、网络等故障
            // trigger_error("[CURL_" . curl_errno($ch) . "]: " . curl_error($ch), E_USER_ERROR);
        }
        if (PHP_VERSION_ID < 80000) curl_close($ch);

        return $rtn;
    }
}

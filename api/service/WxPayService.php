<?php

namespace Dou\Api\Service;

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
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

class WxPayService
{
    /** @var string */
    protected $appid;

    /** @var string */
    protected $mch_id;

    /** @var string */
    protected $key;

    /** @var string */
    protected $openid;

    /** @var string */
    protected $out_trade_no;

    /** @var string */
    protected $body;

    /** @var int */
    protected $total_fee;

    /**
     * @param mixed $appid
     * @param mixed $openid
     * @param mixed $mch_id
     * @param mixed $key
     * @param mixed $out_trade_no
     * @param mixed $body
     * @param mixed $total_fee
     */
    public function __construct($appid, $openid, $mch_id, $key, $out_trade_no, $body, $total_fee)
    {
        $this->appid = $appid;
        $this->openid = $openid;
        $this->mch_id = $mch_id;
        $this->key = $key;
        $this->out_trade_no = $out_trade_no;
        $this->body = $body;
        $this->total_fee = $total_fee;
    }

    /**
     * @return mixed
     */
    public function pay()
    {
        return $this->weixinapp();
    }

    /**
     * @return mixed
     */
    private function unifiedorder()
    {
        $url = 'https://api.mch.weixin.qq.com/pay/unifiedorder';
        $notifyUrl = $this->buildNotifyUrl();
        $parameters = array(
            'appid' => $this->appid,
            'mch_id' => $this->mch_id,
            'nonce_str' => $this->createNoncestr(),
            'body' => $this->body,
            'out_trade_no' => $this->out_trade_no,
            'total_fee' => $this->total_fee,
            'spbill_create_ip' => $_SERVER['REMOTE_ADDR'],
            'notify_url' => $notifyUrl,
            'openid' => $this->openid,
            'trade_type' => 'JSAPI'
        );

        $parameters['sign'] = $this->getSign($parameters);
        $xmlData = $this->arrayToXml($parameters);

        return $this->xmlToArray($this->postXmlCurl($xmlData, $url, 60));
    }

    /**
     * @return mixed
     */
    private function buildNotifyUrl()
    {
        if (defined('ROOT_URL')) {
            return rtrim(ROOT_URL, '/') . '/api/wxpay_notify.php';
        }

        $base = dirname(HTTP . $_SERVER['HTTP_HOST'] . $_SERVER['PHP_SELF']) . '/';
        return rtrim($base, '/') . '/wxpay_notify.php';
    }

    /**
     * @param string $xml
     * @param string $url
     * @param int $second
     * @return string
     */
    private static function postXmlCurl($xml, $url, $second = 30)
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_TIMEOUT, $second);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $xml);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        curl_setopt($ch, CURLOPT_TIMEOUT, 40);
        set_time_limit(0);

        $data = curl_exec($ch);
        if ($data) {
            curl_close($ch);
            return $data;
        }

        $error = curl_errno($ch);
        curl_close($ch);
        throw new \WxPayException("curl 出错，错误码:$error");
    }

    /**
     * @param mixed $arr
     * @return mixed
     */
    private function arrayToXml($arr)
    {
        $xml = '<xml>';
        foreach ($arr as $key => $val) {
            if (is_array($val)) {
                $xml .= '<' . $key . '>' . $this->arrayToXml($val) . '</' . $key . '>';
            } else {
                $xml .= '<' . $key . '>' . $val . '</' . $key . '>';
            }
        }
        $xml .= '</xml>';

        return $xml;
    }

    /**
     * @param mixed $xml
     * @return mixed
     */
    private function xmlToArray($xml)
    {
        if (PHP_VERSION_ID < 80000) {
            libxml_disable_entity_loader(true);
        }

        $xmlstring = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        return json_decode(json_encode($xmlstring), true);
    }

    /**
     * @return mixed
     */
    private function weixinapp()
    {
        $unifiedorder = $this->unifiedorder();
        $time = time();
        $parameters = array(
            'appId' => $this->appid,
            'timeStamp' => "$time",
            'nonceStr' => $this->createNoncestr(),
            'package' => 'prepay_id=' . $unifiedorder['prepay_id'],
            'signType' => 'MD5'
        );

        $parameters['paySign'] = $this->getSign($parameters);
        return $parameters;
    }

    /**
     * @param int $length
     * @return mixed
     */
    private function createNoncestr($length = 32)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $str = '';
        for ($i = 0; $i < $length; $i++) {
            $str .= substr($chars, mt_rand(0, strlen($chars) - 1), 1);
        }

        return $str;
    }

    /**
     * @param mixed $obj
     * @return mixed
     */
    private function getSign($obj)
    {
        $parameters = array();
        foreach ($obj as $k => $v) {
            $parameters[$k] = $v;
        }

        ksort($parameters);
        $string = $this->formatBizQueryParaMap($parameters, false);
        $string = $string . '&key=' . $this->key;
        $string = md5($string);

        return strtoupper($string);
    }

    /**
     * @param mixed $paraMap
     * @param mixed $urlencode
     * @return mixed
     */
    private function formatBizQueryParaMap($paraMap, $urlencode)
    {
        $buff = '';
        ksort($paraMap);
        foreach ($paraMap as $k => $v) {
            if ($urlencode) {
                $v = urlencode($v);
            }
            $buff .= $k . '=' . $v . '&';
        }

        $reqPar = '';
        if (strlen($buff) > 0) {
            $reqPar = substr($buff, 0, strlen($buff) - 1);
        }

        return $reqPar;
    }
}

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

namespace Dou\Vendor\Barcode;

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 二维码生成器（统一 output/save 与小程序码能力）
 */
class Barcode
{
    /**
     * 加载 src 内 BarcodeGenerator 并创建实例。
     *
     * @return BarcodeGenerator
     */
    protected function createGenerator()
    {
        require_once LIBRARY_PATH . 'barcode/src/barcodeGenerator.php';
        return new BarcodeGenerator();
    }

    /**
     * 二维码输出/保存。
     *
     * @param mixed $im
     * @param string $mode
     * @param array $options
     * @return mixed
     */
    public function qrCode($im, $mode = 'output', $options = array())
    {
        $text = (string) $im;
        if ($text === '') {
            return false;
        }

        $level = isset($options['level']) ? strtoupper((string) $options['level']) : 'L';
        $size = isset($options['size']) ? intval($options['size']) : 10;
        $margin = isset($options['margin']) ? intval($options['margin']) : 2;

        if ($size <= 0) {
            $size = 10;
        }
        if ($margin < 0) {
            $margin = 2;
        }

        $symbologyMap = array(
            'L' => 'qrl',
            'M' => 'qrm',
            'Q' => 'qrq',
            'H' => 'qrh'
        );
        $symbology = isset($symbologyMap[$level]) ? $symbologyMap[$level] : 'qrl';
        $image = $this->createGenerator()->render_image($symbology, $text, array(
            'sf' => $size,
            'p' => $margin
        ));

        if ($mode === 'output') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: image/png');
            imagepng($image);
            imagedestroy($image);
            exit;
        }

        if ($mode === 'save') {
            $file = isset($options['file']) ? (string) $options['file'] : '';
            if ($file === '') {
                imagedestroy($image);
                return false;
            }
            $saved = imagepng($image, $file);
            imagedestroy($image);
            return $saved ? true : false;
        }

        imagedestroy($image);
        return false;
    }

    /**
     * 条形码输出/保存。
     *
     * @param mixed $im
     * @param string $mode
     * @param array $options
     * @return mixed
     */
    public function barCode($im, $mode = 'output', $options = array())
    {
        $text = (string) $im;
        if ($text === '') {
            return false;
        }

        $symbology = isset($options['symbology']) ? strtolower((string) $options['symbology']) : 'code128';
        $size = isset($options['size']) ? intval($options['size']) : 2;
        $height = isset($options['height']) ? intval($options['height']) : 80;
        $margin = isset($options['margin']) ? intval($options['margin']) : 10;

        if ($size <= 0) {
            $size = 2;
        }
        if ($height <= 0) {
            $height = 80;
        }
        if ($margin < 0) {
            $margin = 10;
        }

        $image = $this->createGenerator()->render_image($symbology, $text, array(
            'sf' => $size,
            'h' => $height,
            'p' => $margin
        ));

        if ($mode === 'output') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: image/png');
            imagepng($image);
            imagedestroy($image);
            exit;
        }

        if ($mode === 'save') {
            $file = isset($options['file']) ? (string) $options['file'] : '';
            if ($file === '') {
                imagedestroy($image);
                return false;
            }
            $saved = imagepng($image, $file);
            imagedestroy($image);
            return $saved ? true : false;
        }

        imagedestroy($image);
        return false;
    }

    /**
     * 微信小程序码输出/保存。
     *
     * @param mixed $userSn
     * @param string $mode
     * @param array $options
     * @return mixed
     */
    public function miniCode($userSn, $mode = 'save', $options = array())
    {
        $scene = isset($options['scene']) ? (string) $options['scene'] : 'user_sn=' . $userSn;
        $page = isset($options['page']) ? (string) $options['page'] : 'pages/user/login_weixin';
        $width = isset($options['width']) ? intval($options['width']) : 430;
        $autoColor = isset($options['auto_color']) ? (bool) $options['auto_color'] : false;
        $lineColor = isset($options['line_color']) && is_array($options['line_color'])
            ? $options['line_color']
            : array('r' => 0, 'g' => 0, 'b' => 0);
        $isHyaline = isset($options['is_hyaline']) ? (bool) $options['is_hyaline'] : false;

        if ($scene === '' || $page === '') {
            return false;
        }
        $appid = Config::get('param.miniprogram_appid', '');
        $appsecret = Config::get('param.miniprogram_appsecret', '');

        $url = "https://api.weixin.qq.com/cgi-bin/token?grant_type=client_credential&appid=$appid&secret=$appsecret";
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        $result = json_decode($response, true);

        if (empty($result['access_token'])) {
            return '获取Access Token失败';
        }
        $accessToken = $result['access_token'];
        $url = "https://api.weixin.qq.com/wxa/getwxacodeunlimit?access_token=$accessToken";

        $data = array(
            'scene' => $scene,
            'page' => $page,
            'width' => $width,
            'auto_color' => $autoColor,
            'line_color' => $lineColor,
            'is_hyaline' => $isHyaline
        );

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        if (PHP_VERSION_ID < 80000) {
            curl_close($ch);
        }
        $image = @imagecreatefromstring($response);
        if ($image === false) {
            $error = json_decode($response, true);
            return !empty($error['errmsg']) ? $error['errmsg'] : '生成二维码失败';
        }

        if ($mode === 'output') {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: image/png');
            imagepng($image);
            imagedestroy($image);
            exit;
        }

        if ($mode === 'save') {
            $file = isset($options['file']) ? (string) $options['file'] : '';
            if ($file === '') {
                imagedestroy($image);
                return false;
            }
            $saved = imagepng($image, $file);
            imagedestroy($image);
            return $saved ? true : false;
        }

        imagedestroy($image);
        return false;
    }
}

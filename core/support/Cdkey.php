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

namespace Dou\Core\Support;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 授权凭据文件（storage/state/cdkey.php）读取器。
 *
 * 该文件的字节内容由官方云端授权接口下发（见 CloudService::copyright()），本地只做解析、
 * 不执行：以文本方式取出 `$_CDKEY` 字符编码表与 `$_PARTNER_AUTHORIZED` 标记，避免把远端
 * 响应当作 PHP 源码 include 进请求生命周期。
 *
 * 文件形态（云端下发，兼容十进制与 0x 十六进制字面量）：
 *   <?php $_CDKEY = array(68, 0x6f, ...); $_PARTNER_AUTHORIZED = true;
 */
class Cdkey
{
    /**
     * 解析授权凭据文件。
     *
     * @param string $file 凭据文件绝对路径
     * @return array 键 code（凭据明文，解析失败为空串）、partner（是否合作伙伴授权）
     */
    public static function read($file)
    {
        $result = array('code' => '', 'partner' => false);

        if (!is_string($file) || $file === '' || !is_file($file)) {
            return $result;
        }

        $content = @file_get_contents($file);
        if (!is_string($content) || $content === '') {
            return $result;
        }

        $result['code'] = Util::fromCharCodes(self::parseCharCodes($content));
        $result['partner'] = self::parsePartnerFlag($content);

        return $result;
    }

    /**
     * 取出 `$_CDKEY` 的字符编码表。
     *
     * @param string $content 凭据文件文本
     * @return array 整数编码数组
     */
    private static function parseCharCodes($content)
    {
        if (!preg_match('/\$_CDKEY\s*=\s*(?:array\s*\(|\[)(.*?)(?:\)|\])\s*;/s', $content, $match)) {
            return array();
        }

        $codes = array();
        foreach (explode(',', $match[1]) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            if (preg_match('/^0x[0-9a-f]+$/i', $item)) {
                $codes[] = hexdec(substr($item, 2));
                continue;
            }
            if (preg_match('/^[0-9]+$/', $item)) {
                $codes[] = (int) $item;
            }
        }

        return $codes;
    }

    /**
     * 判断 `$_PARTNER_AUTHORIZED` 是否为真值。
     *
     * @param string $content 凭据文件文本
     * @return bool
     */
    private static function parsePartnerFlag($content)
    {
        if (!preg_match('/\$_PARTNER_AUTHORIZED\s*=\s*([^;]+);/', $content, $match)) {
            return false;
        }

        $value = strtolower(trim($match[1], " \t\n\r\0\x0B'\""));

        return $value !== '' && $value !== 'false' && $value !== '0' && $value !== 'null';
    }
}

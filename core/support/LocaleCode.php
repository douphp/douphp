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
 * 语言代码形态转换。
 *
 * 语言包目录名（pack）使用下划线小写形（zh_cn / en_us），不同消费场景需要不同形态：
 *  - sign：URL 前缀 / 查询参数使用的连字符小写形（zh-cn / en-us）；
 *  - tag：BCP-47 标签（zh-CN / en-US），供 <html lang> 与 hreflang。
 *
 * 纯静态、无状态，兼容 PHP 5.6+。
 */
class LocaleCode
{
    /**
     * 语言包标识转 URL 用连字符小写形。
     *
     * @param string $pack 语言包目录名（下划线或连字符形式）
     * @return string
     */
    public static function sign($pack)
    {
        return strtolower(str_replace('_', '-', (string) $pack));
    }

    /**
     * 语言包标识转 BCP-47 标签。
     *
     * 语言子标签小写；4 字母脚本子标签 Title case（zh-Hant）；
     * 2 字母 / 3 位数字区域子标签大写（en-US）。
     *
     * @param string $pack 语言包目录名（下划线或连字符形式）
     * @return string
     */
    public static function tag($pack)
    {
        $parts = explode('-', self::sign($pack));
        $tag = array_shift($parts);

        foreach ($parts as $part) {
            $length = strlen($part);
            if ($length === 4) {
                $tag .= '-' . ucfirst(strtolower($part));
            } elseif ($length === 2 || $length === 3) {
                $tag .= '-' . strtoupper($part);
            } else {
                $tag .= '-' . strtolower($part);
            }
        }

        return $tag;
    }
}

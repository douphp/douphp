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

namespace Dou\Core\Service\Attachment;

use Dou\Core\Filesystem\PathNormalizer;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 多语言图片目录换算：语言包作为 images/ 下一级命名空间。
 *
 * 约定：
 *   images/article/        → images/en_us/article/
 *   images/article/icon/   → images/en_us/article/icon/
 *
 * 空值 / 非法包 / 站点默认语言一律返回空串，保持主语言目录与旧行为不变。
 */
class LanguagePath
{
    /**
     * 规范化语言包：仅接受合法且非默认语言的包名。
     *
     * @param mixed $pack
     * @return string
     */
    public static function normalize($pack)
    {
        $pack = (string) $pack;
        if ($pack === '' || !Check::languagePack($pack)) {
            return '';
        }

        $default = (string) Config::get('site.language', '');

        return $default !== '' && $pack === $default ? '' : $pack;
    }

    /**
     * 把站点相对的磁盘根换算为语言目录。
     *
     * @param string $root
     * @param mixed $pack
     * @return string
     */
    public static function root($root, $pack)
    {
        $pack = self::normalize($pack);
        $root = PathNormalizer::normalizeRoot($root);
        if ($pack === '' || $root === '') {
            return $root;
        }

        $prefix = 'images/';
        if (strpos($root, $prefix) === 0) {
            return PathNormalizer::normalizeRoot($prefix . $pack . '/' . substr($root, strlen($prefix)));
        }

        return PathNormalizer::normalizeRoot($pack . '/' . $root);
    }
}

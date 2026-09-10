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

namespace Dou\Core\Service\Ai;

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 图生图（参考图输入）能力：按 config/ai.php 的 image_input 通配判定。
 */
class ImageInputSupport
{
    /**
     * @param string $modelCode 供应商模型代码（如 qwen-image-3.0-pro）
     * @return bool
     */
    public static function supported($modelCode)
    {
        $code = strtolower(trim((string) $modelCode));
        if ($code === '') {
            return false;
        }

        $patterns = Config::get('ai.image_input', array());
        if (!is_array($patterns)) {
            return false;
        }

        foreach ($patterns as $pattern) {
            $pattern = strtolower(trim((string) $pattern));
            if ($pattern === '') {
                continue;
            }
            if ($pattern === $code) {
                return true;
            }
            if (strpos($pattern, '*') !== false && fnmatch($pattern, $code)) {
                return true;
            }
        }

        return false;
    }
}

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

namespace Dou\Core\Service\Language;

use Dou\Core\Support\LanguageValuePurger;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 语言图片附件回收：只删除确属该模块/记录、且已无语言行引用的文件号。
 */
class LanguageImageCleaner
{
    /**
     * @param string $module
     * @param int $itemId
     * @param array $numbers
     * @return void
     */
    public static function purge($module, $itemId, array $numbers)
    {
        LanguageValuePurger::reclaimImages($module, $itemId, $numbers);
    }
}

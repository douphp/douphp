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

use Dou\Core\Facade\DB;
use Dou\Core\Support\Check;

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
        $module = (string) $module;
        $itemId = (int) $itemId;
        if ($module === '' || $itemId <= 0 || empty($numbers)) {
            return;
        }

        foreach (array_unique($numbers) as $number) {
            $number = (string) $number;
            if (!Check::fileNumber($number)) {
                continue;
            }

            $row = DB::table('file')->where('number', $number)->find();
            if (!$row) {
                continue;
            }
            if ((string) $row['module'] !== $module) {
                continue;
            }
            if ((string) $row['item_id'] !== '' && (int) $row['item_id'] !== $itemId) {
                continue;
            }
            $type = isset($row['type']) ? (string) $row['type'] : '';
            if ($type !== '' && !in_array($type, array('language:image', 'main', 'file', 'image'), true)) {
                continue;
            }

            // 仍被任意语言行引用（含并发写入）时不删
            if (DB::table('language_value')->where('value', $number)->find()) {
                continue;
            }

            // 业务主图与会话中的图库首图等其它引用不在此处理
            if (DB::fieldExist($module, 'id') && DB::fieldExist($module, 'image')) {
                $current = DB::table($module)->where('id', $itemId)->value('image');
                if ((string) $current === $number) {
                    continue;
                }
            }

            attachment()->delete($number);
        }
    }
}

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

use Dou\Core\Facade\DB;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 多语言记录删除清理（无状态、三端共用、纯静态）。
 *
 * 语义边界：
 * - 只删 module + item_id 精确匹配的 language_value 行，绝不跨模块；
 * - module 超长时同时匹配历史版本被截断的值（见 storedModules()），否则存量行永远清不到；
 * - 附件回收复用语言图片的安全校验（模块归属、item_id、type、仍被引用、当前主图）；
 * - 不依赖 features.language 开关与语言模块实现类，language_value 表不存在时静默返回，
 *   保证模块关闭 / 卸载 / 未升级场景下删除内容仍能清掉历史语言数据。
 */
class LanguageValuePurger
{
    /** @var int 历史版本 language_value.module 列长度（varchar(20)），用于兼容已截断的存量行 */
    const MODULE_MAX_LENGTH = 20;

    /**
     * 删除指定模块/记录的全部语言行，并回收仅被这些语言行引用的图片。
     *
     * @param string $module
     * @param int|string $itemId
     * @return void
     */
    public static function purge($module, $itemId)
    {
        $module = (string) $module;
        $itemId = (int) $itemId;
        if ($module === '' || $itemId <= 0) {
            return;
        }
        if (DB::fieldExist('language_value', 'id') !== true) {
            return;
        }

        $modules = self::storedModules($module);
        $rows = DB::table('language_value')
            ->field('value, field, type')
            ->whereIn('module', $modules)
            ->where('item_id', $itemId)
            ->select();

        $numbers = array();
        foreach ((array) $rows as $row) {
            $value = isset($row['value']) ? (string) $row['value'] : '';
            if (!Check::fileNumber($value)) {
                continue;
            }
            $field = isset($row['field']) ? (string) $row['field'] : '';
            $type = isset($row['type']) ? (string) $row['type'] : '';
            if (in_array($field, array('image', 'show_img'), true)
                || in_array($type, array('image', 'file', 'language:image'), true)) {
                $numbers[] = $value;
            }
        }

        DB::table('language_value')
            ->whereIn('module', $modules)
            ->where('item_id', $itemId)
            ->delete();

        self::reclaimImages($module, $itemId, $numbers);
    }

    /**
     * 计算 language_value.module 的实际存储值集合。
     *
     * 历史版本 module 为 varchar(20)，超过 20 字符的模块名（如 professional_category）
     * 入库时会被 MySQL 截断为 professional_categor；即使后续把列加宽，存量行仍是截断值，
     * 删除须同时匹配原值与截断值，否则这类模块的语言行永远清理不掉。
     *
     * @param string $module
     * @return array
     */
    private static function storedModules($module)
    {
        $modules = array($module);
        if (strlen($module) > self::MODULE_MAX_LENGTH) {
            $modules[] = substr($module, 0, self::MODULE_MAX_LENGTH);
        }

        return array_values(array_unique($modules));
    }

    /**
     * 回收语言行占用的图片附件。
     *
     * 仅处理确属该模块/记录、type 允许、删除后已无任何语言行引用、且不是主表当前主图的附件；
     * 供删除清理与草稿替换回收共用，避免两处校验规则漂移。
     *
     * @param string $module
     * @param int|string $itemId
     * @param array $numbers
     * @return void
     */
    public static function reclaimImages($module, $itemId, array $numbers)
    {
        $module = (string) $module;
        $itemId = (int) $itemId;
        if ($module === '' || $itemId <= 0 || empty($numbers)) {
            return;
        }
        if (DB::fieldExist('file', 'number') !== true) {
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
            if (DB::fieldExist('language_value', 'value') === true
                && DB::table('language_value')->where('value', $number)->find()) {
                continue;
            }

            // 业务主图与会话中的图库首图等其它引用不在此处理
            if (DB::fieldExist($module, 'id') === true && DB::fieldExist($module, 'image') === true) {
                $current = DB::table($module)->where('id', $itemId)->value('image');
                if ((string) $current === $number) {
                    continue;
                }
            }

            attachment()->delete($number);
        }
    }
}

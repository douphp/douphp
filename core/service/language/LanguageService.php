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

use Dou\Core\Contract\LanguageContract;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 三端通用语言服务：多语言字段读取与字典格式化。
 *
 * 由容器解析 {@see LanguageContract} 后供 language() 取用；后台端使用其子类
 * {@see \Dou\Admin\Service\Language\LanguageAdminService} 扩展后台专属能力。
 */
class LanguageService extends BaseService implements LanguageContract
{
    /**
     * 进程级（等价请求级）翻译值缓存。
     *
     * 结构：cacheKey ("pack|module") => item_id => field => value
     *
     * @var array<string, array<int, array<string, string>>>
     */
    private static $valueCache = array();

    /**
     * 已加载标记：区分「未加载」与「加载后 miss = 空串」两种状态，避免回退查询。
     *
     * 结构：cacheKey ("pack|module") => item_id => field => true
     *
     * @var array<string, array<int, array<string, bool>>>
     */
    private static $loaded = array();

    /**
     * 批量预热多条记录、多字段的多语言值到进程级缓存。
     *
     * 调用约定：列表循环前传入整页 ids 与逗号分隔字段串，一次 IN 查询替代 N×M 次单查；
     * 已加载字段会自动跳过，重复或多次更大字段集叠加调用都安全。
     *
     * @param string $module 模块名（含 _category 则按分类粒度预热）
     * @param array|int|string $ids id 集合（数组或单值，最终去重 intval）
     * @param string $fieldList 字段名列表，逗号分隔
     * @return void
     */
    public function warmup($module, $ids, $fieldList)
    {
        $locale = locale();
        if (!$locale->isActive()) {
            return;
        }
        $module = (string) $module;
        if ($module === '') {
            return;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        $fields = array_values(array_filter(array_map('trim', explode(',', str_replace(' ', '', (string) $fieldList)))));
        if (empty($ids) || empty($fields)) {
            return;
        }

        $cacheKey = $locale->pack() . '|' . $module;

        $missMap = array();
        foreach ($ids as $id) {
            foreach ($fields as $f) {
                if (!isset(self::$loaded[$cacheKey][$id][$f])) {
                    $missMap[$id] = true;
                    break;
                }
            }
        }
        $missIds = array_keys($missMap);
        if (empty($missIds)) {
            return;
        }

        $rows = DB::table('language_value')
            ->field('item_id, field, value')
            ->where('language_pack', $locale->pack())
            ->where('module', $module)
            ->where('item_id', 'IN', $missIds)
            ->where('field', 'IN', $fields)
            ->select();

        foreach ((array) $rows as $r) {
            $rid = (int) $r['item_id'];
            self::$valueCache[$cacheKey][$rid][$r['field']] = (string) $r['value'];
        }
        foreach ($missIds as $id) {
            foreach ($fields as $f) {
                self::$loaded[$cacheKey][$id][$f] = true;
            }
        }
    }

    /**
     * 按字段列表覆写多语言字段值。
     *
     * @param array|mixed $item 单条数据数组，非数组直接返回空数组
     * @param string $module 模块名（含 _category 时取 id 作为 item_id）
     * @param string $field_list 字段名列表，逗号分隔
     * @return array
     */
    public function langBox($item = '', $module = '', $field_list = '')
    {
        if (!is_array($item)) {
            return array();
        }

        if (locale()->isActive()) {
            $field_array = explode(',', preg_replace('# #', '', $field_list));
            $item_id = isset($item['id']) ? $item['id'] : '';

            $this->warmup($module, array($item_id), $field_list);

            foreach ($field_array as $field) {
                $field = trim($field);
                if ($field === '') {
                    continue;
                }
                $raw = isset($item[$field]) ? $item[$field] : '';
                $item[$field] = $this->langValue($raw, $module, $item_id, $field);
            }
        }

        return $item;
    }

    /**
     * 读取 language_value 中的单字段值，未命中返回原始值。
     *
     * @param string $value
     * @param string $module
     * @param string|int $item_id
     * @param string $field
     * @return string
     */
    public function langValue($value = '', $module = '', $item_id = '', $field = '')
    {
        $locale = locale();
        if (!$locale->isActive()) {
            return $value;
        }

        $module = (string) $module;
        $field = (string) $field;
        $item_id = (int) $item_id;
        if ($module === '' || $field === '' || $item_id <= 0) {
            return $value;
        }

        $cacheKey = $locale->pack() . '|' . $module;

        if (!isset(self::$loaded[$cacheKey][$item_id][$field])) {
            $loadedVal = DB::table('language_value')
                ->where('language_pack', $locale->pack())
                ->where('module', $module)
                ->where('item_id', $item_id)
                ->where('field', $field)
                ->value('value');
            self::$valueCache[$cacheKey][$item_id][$field] = (string) $loadedVal;
            self::$loaded[$cacheKey][$item_id][$field] = true;
        }

        $cached = isset(self::$valueCache[$cacheKey][$item_id][$field])
            ? self::$valueCache[$cacheKey][$item_id][$field]
            : '';

        return $cached !== '' ? $cached : $value;
    }

    /**
     * 将逗号分隔的取值列表格式化为 [value, format(语言串), cur] 三元组数组。
     *
     * @param string $prefix 语言键前缀
     * @param string $value_list 逗号分隔值列表
     * @param string $cur 当前选中值
     * @return array
     */
    public function dataListLangFormat($prefix, $value_list, $cur = '')
    {
        $value_lang = array();
        foreach (explode(',', $value_list) as $row) {
            $row = trim($row);
            $langKey = $prefix . $row;
            $value_lang[] = array(
                'value' => $row,
                'format' => lang($langKey),
                'cur' => $row == $cur,
            );
        }

        return $value_lang;
    }

    /**
     * 单值的语言串格式化：返回 [value, format] 二元组。
     *
     * @param string $prefix 语言键前缀
     * @param string $value
     * @return array
     */
    public function dataLangFormat($prefix, $value)
    {
        $langKey = $prefix . $value;

        return array(
            'value' => $value,
            'format' => lang($langKey),
        );
    }

    /**
     * 构造前台语言切换菜单：根据 site.language 与 language 表生成可切换语言列表。
     *
     * @param string $cur_language_pack 当前语言包标识
     * @return array
     */
    public function getLangMenu($cur_language_pack = '')
    {
        if (empty(Config::get('features.language', false))) {
            return array();
        }

        $cur_language_pack = $cur_language_pack ? $cur_language_pack : Config::get('site.language', '');

        if (Config::get('site.language', '')) {
            require(ROOT_PATH . 'languages/' . Config::get('site.language', '') . '/common.lang.php');
            $lang_list_array = array(
                array(
                    'name' => isset($_LANG['cur_language']) ? $_LANG['cur_language'] : 'Default',
                    'language_pack' => Config::get('site.language', ''),
                    'url' => ROOT_URL,
                ),
            );
        } else {
            $lang_list_array = array();
        }

        $language_rows = DB::table('language')->order('sort DESC, id ASC')->select();
        foreach ((array) $language_rows as $row) {
            $lang_list_array[] = array(
                'name' => $row['name'],
                'language_pack' => $row['language_pack'],
            );
        }

        $lang_list = array();
        foreach ($lang_list_array as $row) {
            if ($cur_language_pack != $row['language_pack']) {
                $url = Config::get('site.rewrite', false)
                    ? ROOT_URL . str_replace('_', '-', $row['language_pack'])
                    : ROOT_URL . '?lang=' . $row['language_pack'];

                $lang_list[] = array(
                    'name' => $row['name'],
                    'language_pack' => $row['language_pack'],
                    'url' => isset($row['url']) ? $row['url'] : $url,
                );
            }
        }

        return $lang_list;
    }
}

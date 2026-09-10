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

namespace Dou\Core\Service\Noop;

use Dou\Admin\Contract\AdminLanguageContract;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 语言能力 Null 实现：features.language 关闭、模块缺失或卸载时由本类兜底。
 *
 * 同时满足前台 / API 的 {@see \Dou\Core\Contract\LanguageContract}
 * 与后台的 {@see \Dou\Admin\Contract\AdminLanguageContract}
 * （后者继承前者），保证容器解析 language() 时始终非空，业务调用面无需判空。
 */
class NullLanguageService extends BaseService implements AdminLanguageContract
{
    /**
     * 无语言时不做任何预热（无 language_value 可查）。
     *
     * @param string $module
     * @param array|int|string $ids
     * @param string $fieldList
     * @return void
     */
    public function warmup($module, $ids, $fieldList)
    {
        return;
    }

    /**
     * 无语言时仅返回原始数据；非数组按契约约定返回空数组。
     *
     * @param array|mixed $item
     * @param string $module
     * @param string $fieldList
     * @return array
     */
    public function langBox($item, $module, $fieldList)
    {
        if (!is_array($item)) {
            return array();
        }
        return $item;
    }

    /**
     * 无语言时回退到原始值。
     *
     * @param string $value
     * @param string $module
     * @param string|int $itemId
     * @param string $field
     * @return string
     */
    public function langValue($value, $module, $itemId, $field)
    {
        return $value;
    }

    /**
     * 保持视图层下标契约：按 $valueList 拆分输出 value/format/cur 三元组；
     * format 取 lang($prefix.$value)（无语言串则为空字符串）。
     *
     * @param string $prefix
     * @param string $valueList
     * @param string $cur
     * @return array
     */
    public function dataListLangFormat($prefix, $valueList, $cur = '')
    {
        $rows = array();
        foreach (explode(',', (string) $valueList) as $row) {
            $row = trim($row);
            $langKey = $prefix . $row;
            $rows[] = array(
                'value' => $row,
                'format' => lang($langKey),
                'cur' => $row == $cur,
            );
        }
        return $rows;
    }

    /**
     * 单值格式化：format 取语言串或空。
     *
     * @param string $prefix
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
     * 无多语言模块时返回空菜单。
     *
     * @param string $curLanguagePack
     * @return array
     */
    public function getLangMenu($curLanguagePack = '')
    {
        return array();
    }

    /**
     * Null 实现：不操作 language_value，不删除文件。
     *
     * @param string $module
     * @param string|int $itemId
     * @return void
     */
    public function deleteLang($module = '', $itemId = '')
    {
        return;
    }

    /**
     * 语言关闭时的空按钮结构，避免模板访问 $btn_lang 字段触发 PHP 8 告警。
     *
     * @param string $fieldList 逗号分隔字段名
     * @return array
     */
    public static function emptyLangButtonFields($fieldList)
    {
        $btnLang = array();
        foreach (explode(',', str_replace(' ', '', (string) $fieldList)) as $field) {
            if ($field !== '') {
                $btnLang[$field] = '';
            }
        }

        return $btnLang;
    }

    /**
     * Null 实现：返回各字段空字符串，与 features.language 关闭时视图契约一致。
     *
     * @param string $module
     * @param string|int $itemId
     * @param string $fieldList
     * @return array
     */
    public function buildLangButtons($module, $itemId = '', $fieldList = '')
    {
        return self::emptyLangButtonFields($fieldList);
    }

    /**
     * Null 实现：features.language 关闭或模块缺失时返回空列表，
     * 设置页 Tab、语言切换项模板 foreach 自然不渲染。
     *
     * @param string $curLanguagePack
     * @return array
     */
    public function buildLangList($curLanguagePack = '')
    {
        return array();
    }
}

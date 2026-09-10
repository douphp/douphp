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

namespace Dou\Core\Contract;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 三端共用语言能力契约。
 *
 * 前台 / API 通过容器解析此契约供 language() 取用；
 * 后台再以 {@see \Dou\Admin\Contract\AdminLanguageContract} 扩展专属能力。
 * 当 features.language 关闭或模块文件被卸载时，由 {@see \Dou\Core\Service\Noop\NullLanguageService}
 * 实现本契约提供无害降级，业务调用面无需判空。
 */
interface LanguageContract
{
    /**
     * 批量预热多条记录、多字段的多语言值到进程级缓存。
     *
     * 在列表循环前调用，将整页 N×M 次单查合并为一次 IN 查询；已加载字段自动跳过，
     * 重复调用与字段集叠加均安全。Null 实现可静默忽略。
     *
     * @param string $module 模块名（含 _category 则按分类粒度预热）
     * @param array|int|string $ids id 集合（数组或单值，最终去重 intval）
     * @param string $fieldList 字段名列表，逗号分隔
     * @return void
     */
    public function warmup($module, $ids, $fieldList);

    /**
     * 按字段列表覆写多语言字段值。
     *
     * @param array|mixed $item 单条数据数组，非数组应返回空数组
     * @param string $module 模块名（含 _category 时取 id 作为 item_id）
     * @param string $fieldList 字段名列表，逗号分隔
     * @return array
     */
    public function langBox($item, $module, $fieldList);

    /**
     * 读取 language_value 中的单字段值，未命中返回原始值。
     *
     * @param string $value
     * @param string $module
     * @param string|int $itemId
     * @param string $field
     * @return string
     */
    public function langValue($value, $module, $itemId, $field);

    /**
     * 将逗号分隔的取值列表格式化为 [value, format(语言串), cur] 三元组数组。
     *
     * @param string $prefix 语言键前缀
     * @param string $valueList 逗号分隔值列表
     * @param string $cur 当前选中值
     * @return array
     */
    public function dataListLangFormat($prefix, $valueList, $cur = '');

    /**
     * 单值的语言串格式化：返回 [value, format] 二元组。
     *
     * @param string $prefix
     * @param string $value
     * @return array
     */
    public function dataLangFormat($prefix, $value);

    /**
     * 构造前台语言切换菜单。
     *
     * @param string $curLanguagePack 当前语言包标识
     * @return array
     */
    public function getLangMenu($curLanguagePack = '');
}

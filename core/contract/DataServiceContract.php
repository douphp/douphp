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
 * 三端共用的碎片化数据访问契约（业务读取 data 表的统一入口）。
 *
 * 真实现 {@see \Dou\Core\Service\Data\DataService} 跟随 features.data 开关 + 类磁盘存在性；
 * 当 features.data 关闭或 DataService 缺席时，由 {@see \Dou\Core\Service\Noop\NullDataService}
 * 兜底，保证 data() 始终非空、业务调用面（`data()->get('hero', 'image')` 等）无需判空。
 *
 * 两个公开动词的语义分工：
 * - `get()`：访问"已加载到内存"的整张主题数据。首次调用触发一次 DB 查询并缓存到实例上，
 *   后续读取（含按 code / 字段精确取值）全部内存命中，模板与 PHP 业务侧均可零成本调用。
 * - `query()`：按 (data_group, data_item, parent_code) 三元组查询模块绑定的子集，按 tuple
 *   实例缓存；典型用于 controller 取某条内容对应的 data 列表。
 */
interface DataServiceContract
{
    /**
     * 读取主题下的 data 数据。
     *
     * 无参时返回整张 dict（按 code 索引）；带 `$code` 返回单行；再带 `$field` 返回字段标量。
     * 缺失情况下统一返回 `$default`（无参缺失返空数组）。
     *
     * - get()                          整张 dict（首次调用打 DB，之后内存命中）
     * - get('abc')                     单行 array|null
     * - get('abc', 'name')             单字段 mixed|null
     * - get('abc', 'image', '/d.png')  带默认值
     *
     * @param string|null $code 行键（data 表 `code` 列）
     * @param string|null $field 行内字段名
     * @param mixed $default 缺失时的默认值
     * @return mixed
     */
    public function get($code = null, $field = null, $default = null);

    /**
     * 按 (group, item, parent) tuple 查询模块绑定数据（如 `query('product', $id)`）。
     *
     * 同一 tuple 在请求生命周期内只打一次 DB；树形结构（is_class=1 父节点）的 child 会递归取齐。
     *
     * @param string $group data_group（如 product / page / index / common / banner）
     * @param string $item data_item（内容主键 / 页面 slug；common / banner / index 可为空）
     * @param string $parent parent_code（递归调用时由上层传入，业务侧通常不需要）
     * @return array
     */
    public function query($group, $item = '', $parent = '');
}

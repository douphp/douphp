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

namespace Dou\Admin\Service\Ai\Import;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 批量导入器契约：把一条 AI 生成的条目落入对应业务模块。
 *
 * 实现类内部复用业务模块既有 Service 的 insert 链路（含审计日志），
 * 不直接绕过到 Model / DB 层。
 */
interface ModuleImporter
{
    /**
     * 承接的模块逻辑名（含 _category 变体）。
     *
     * @return string
     */
    public function module();

    /**
     * 条目必填字段（缺失即判该条失败，不触发 insert）。
     *
     * @return array 字段名列表
     */
    public function requiredFields();

    /**
     * 允许从 AI 条目透传到 insert 的字段白名单。
     *
     * @return array 字段名列表
     */
    public function allowedFields();

    /**
     * 单条入库。
     *
     * @param array $item 已按 allowedFields 过滤的条目数据
     * @param array $context 页面上下文（如 category_id / parent_id）
     * @param int $adminId 操作管理员
     * @return int 新增记录主键
     */
    public function import(array $item, array $context, $adminId);
}

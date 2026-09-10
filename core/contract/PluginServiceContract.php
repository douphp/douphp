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
 * 三端共用插件查询能力契约（业务读取 plugin 表的统一入口）。
 *
 * 真实现 {@see \Dou\Core\Service\Plugin\PluginService} 跟随 plugin 模块装卸；
 * 当 plugin 模块卸载或 features.plugin 关闭时，由
 * {@see \Dou\Core\Service\Noop\NullPluginService} 兜底，保证 plugin() 始终非空、
 * 业务调用面（`plugin()->hasConnect()` 等）无需判空。
 *
 * 注意：本契约面向"业务读取"，与 core/infra/plugin/contract/ 下三类
 * {Payment,Connect,Shipping}PluginProviderInterface 不同（后者是插件实现方契约，
 * 由具体支付/Connect/物流插件 implements，由插件聚合器调度）。
 */
interface PluginServiceContract
{
    /**
     * plugin 模块已开启且 plugin 数据表存在。
     *
     * @return bool
     */
    public function isAvailable();

    /**
     * 指定分组下是否存在插件记录。
     *
     * @param string $group 如 payment、shipping、connect
     * @return bool
     */
    public function hasGroup($group);

    /**
     * 读取指定分组下首条插件记录的字段值。
     *
     * @param string $group
     * @param string $field
     * @return mixed
     */
    public function valueByGroup($group, $field = 'config');

    /**
     * 按 slug 读取插件表字段值。
     *
     * @param string $slug
     * @param string $field
     * @return mixed
     */
    public function valueBySlug($slug, $field = 'name');

    /**
     * 按 slug 读取插件行（不存在返回空数组）。
     *
     * @param string $slug
     * @return array
     */
    public function getBySlug($slug);

    /**
     * 按 slug 判断插件是否存在。
     *
     * @param string $slug
     * @return bool
     */
    public function existsBySlug($slug);

    /**
     * 按 slug 读取插件行，附带反序列化后的 `config`。
     *
     * 仅以 plugin 表存在性兜底，不要求 features.plugin；用于支付/物流/connect
     * 等插件运行时读取自身配置（即使 plugin 模块未在前台菜单开启）。
     *
     * @param string $slug
     * @return array
     */
    public function getWithConfig($slug);

    /**
     * 小程序/API 默认支付方式标识（offlinepay 优先，否则 wxpay）。
     *
     * @return string
     */
    public function defaultPaymentSlug();

    /**
     * 是否启用了第三方登录插件（connect 分组下有记录）。
     *
     * @return bool
     */
    public function hasConnect();
}

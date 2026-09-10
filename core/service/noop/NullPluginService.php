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

use Dou\Core\Contract\PluginServiceContract;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 插件查询能力 Null 实现：plugin 模块卸载或 features.plugin 关闭时由本类兜底。
 *
 * 实现 {@see PluginServiceContract} 全部签名并返回无害默认值，保证 plugin() 始终可解析，
 * plugin 模块缺席时不会触发 PHP 错误，也不会查询不存在的 plugin 表。
 */
class NullPluginService extends BaseService implements PluginServiceContract
{
    /**
     * 插件模块不可用时恒为 false。
     *
     * @return bool
     */
    public function isAvailable()
    {
        return false;
    }

    /**
     * 无 plugin 表时任何分组均不存在。
     *
     * @param string $group
     * @return bool
     */
    public function hasGroup($group)
    {
        return false;
    }

    /**
     * 无 plugin 表时分组字段读取返回 false（与真实现"不可用 / 未命中"分支一致）。
     *
     * @param string $group
     * @param string $field
     * @return mixed
     */
    public function valueByGroup($group, $field = 'config')
    {
        return false;
    }

    /**
     * 无 plugin 表时 slug 字段读取返回 false。
     *
     * @param string $slug
     * @param string $field
     * @return mixed
     */
    public function valueBySlug($slug, $field = 'name')
    {
        return false;
    }

    /**
     * 无 plugin 表时返回空数组。
     *
     * @param string $slug
     * @return array
     */
    public function getBySlug($slug)
    {
        return array();
    }

    /**
     * 无 plugin 表时 slug 命中判定恒为 false。
     *
     * @param string $slug
     * @return bool
     */
    public function existsBySlug($slug)
    {
        return false;
    }

    /**
     * 无 plugin 表时返回空数组，与真实现"plugin 行不存在"分支一致。
     *
     * @param string $slug
     * @return array
     */
    public function getWithConfig($slug)
    {
        return array();
    }

    /**
     * 与真实现兜底分支一致：无插件时默认 wxpay。
     *
     * @return string
     */
    public function defaultPaymentSlug()
    {
        return 'wxpay';
    }

    /**
     * 无 plugin 表时未启用任何第三方登录。
     *
     * @return bool
     */
    public function hasConnect()
    {
        return false;
    }
}

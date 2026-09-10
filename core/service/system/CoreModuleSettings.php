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

namespace Dou\Core\Service\System;

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 核心模块设定（Domain 读模型）。
 *
 * 输入：{@see ModuleSettingReader::read()} 返回的原始数组。
 * 输出：栏目模块 / 单页模块 / 用户中心模块 / 工作台模块 / 订单关联模块 /
 *      隐藏菜单 / 隐藏导航等数组字段，外加 `all_module = column_module + single_module`。
 *
 * 不读 DB、不写 Config，纯字段筛选与合并。
 */
class CoreModuleSettings extends BaseService
{
    /**
     * 由 config/module.php 中需要保留的模块键列表。
     *
     * @var array
     */
    private static $moduleKeys = array(
        'column_module',
        'single_module',
        'link_user_center',
        'link_work_center',
        'link_ai',
        'link_order_item',
        'no_show_menu',
        'no_show_nav',
    );

    /**
     */
    public function __construct()
    {
    }

    /**
     * 构造核心模块设定数组。
     *
     * @param array $raw 由 ModuleSettingReader::read() 返回的原始数据
     * @return array 含 column_module / single_module / link_* / no_show_* / all_module
     */
    public function build(array $raw)
    {
        $coreSetting = array();
        foreach (self::$moduleKeys as $key) {
            $values = isset($raw[$key]) ? $raw[$key] : array();
            $coreSetting[$key] = is_array($values) ? array_filter($values) : array();
        }
        $coreSetting['all_module'] = array_merge(
            $coreSetting['column_module'],
            $coreSetting['single_module']
        );

        return $coreSetting;
    }
}

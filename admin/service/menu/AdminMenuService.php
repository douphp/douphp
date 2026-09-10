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

namespace Dou\Admin\Service\Menu;

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台菜单元数据服务。
 *
 * 承载与后台菜单结构相关的静态 / 配置型数据，供权限映射与权限编辑页面的
 * action_list 列表使用。
 */
class AdminMenuService extends BaseService
{
    /**
     * 后台框架基础菜单键列表。
     *
     * 用于权限映射与权限编辑页 action_list 列表的「框架自带菜单」分组。
     *
     * @return array
     */
    public function basicMenu()
    {
        return array(
            'setting',
            'nav',
            'page',
            'site_home_other',
            'backup',
            'miniprogram',
            'theme',
            'manager',
            'module',
            'cloud',
            'language',
        );
    }
}

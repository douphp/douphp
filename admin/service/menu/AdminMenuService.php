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

use Dou\Core\Foundation\Configuration\Config;
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
            'site_home',
            'backup',
            'miniprogram',
            'theme',
            'manager',
            'module',
            'cloud',
            'language',
            'preinstall',
        );
    }

    /**
     * 隐式授权映射：某权限键被勾选时，连带授权其覆盖的模块。
     *
     * 用于「组合权限键」这类特殊情况：键本身是一个引导/入口页，勾选它即代表同时授权其
     * 背后的真实功能模块（这些模块不在 {@see self::grantableNames()} 池中，无法单独勾选）。
     * 例：`site_home`（首页内容）是引导页，实际功能由 `show`（幻灯广告）/ `data`（内容片段）
     * 承载，故勾选 site_home 即连带放行 show / data。
     *
     * 与「子资源继承父模块」（路由声明给出的 parent_module）不同：本映射表达的是**授权组合**
     * 语义，而非路由结构归属，故落在权限侧由 AdminGate 判定，不污染路由清单。
     *
     * @return array<string,string> 模块名 => 覆盖它的权限键
     */
    public function implicitGrants()
    {
        return array(
            'show' => 'site_home',
            'data' => 'site_home',
        );
    }

    /**
     * 全部「可授权」模块标识（action_list 池子的唯一推导源）。
     *
     * 与权限编辑页勾选项集合严格一致：栏目模块各产出 `m_category` 与 `m` 两个独立可授项
     * （分类与内容分别授权），单体模块产出 `m`，框架基础菜单按站点开关补充
     * module / miniprogram / theme。返回纯字符串列表，保持权限编辑页展示顺序。
     *
     * 后台鉴权以本清单判定「当前标识是否为独立可授项」：命中者只认精确匹配、不允许
     * 继承父模块（如 `product_category`），未命中的子资源则回退父模块校验。
     *
     * @return array<int,string>
     */
    public function grantableNames()
    {
        $names = array();

        foreach ((array) Config::get('module.column_module') as $value) {
            if (!in_array($value, (array) Config::get('module.no_show_menu'))) {
                $names[] = $value . '_category';
                $names[] = $value;
            }
        }

        foreach ((array) Config::get('module.single_module') as $value) {
            if (in_array($value, (array) Config::get('module.no_show_menu'))) {
                continue;
            }
            if (in_array($value, (array) Config::get('system.admin_hidden_single', array()), true)) {
                continue;
            }
            $names[] = $value;
        }

        $basicMenu = $this->basicMenu();
        if (!Config::get('site.close_douphp_plus', false)) {
            $basicMenu[] = 'module';
        }
        if (!Config::get('site.close_miniprogram', false)) {
            $basicMenu[] = 'miniprogram';
        }
        if (!Config::get('site.pure_mode', false)) {
            $basicMenu[] = 'theme';
        }
        foreach ($basicMenu as $value) {
            $names[] = (string) $value;
        }

        return array_values(array_unique($names));
    }
}

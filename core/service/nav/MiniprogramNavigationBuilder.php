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

namespace Dou\Core\Service\Nav;

use Dou\Core\Facade\DB;
use Dou\Core\Facade\Url;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序导航构建（Domain 服务，admin / api 共用）。
 *
 * 输出与端无关的字段（id、name、icon、module、guide、url、status、sort），
 * url 由路由层（Url::urlMini）生成；不依赖 Smarty。
 */
class MiniprogramNavigationBuilder extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 构建小程序导航列表。
     *
     * @param string $type 导航类型（miniprogram_top / miniprogram_tabbar 等）
     * @return array
     */
    public function build($type = 'miniprogram_top')
    {
        $navList = array();
        $rows = DB::table('nav')->where('type', $type)->order('sort ASC, id ASC')->select();
        foreach ((array) $rows as $row) {
            $navList[] = array(
                'id' => $row['id'],
                'name' => $row['name'],
                'icon' => attachment()->url($row['icon']),
                'module' => $row['module'],
                'guide' => $row['guide'],
                'url' => Url::urlMini($row['module'], $row['guide']),
                'status' => language()->dataLangFormat('nav_status_', $row['status']),
                'sort' => $row['sort'],
            );
        }

        return $navList;
    }
}

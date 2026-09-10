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

namespace Dou\Admin\Service\Nav;

use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Num;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 按分类表行同步主导航 nav（名称与父子；仅 type=middle）。
 *
 * $module 须与分类表名及 nav.module 一致（如 item_category、product_category、article_category）。
 */
class NavCategorySyncService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * @param string $module
     * @param int $catId
     * @return void
     */
    public function syncForCategory($module, $catId)
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $module)) {
            return;
        }

        $catId = (int) $catId;
        if ($catId <= 0) {
            return;
        }

        $cateInfo = DB::table($module)->where('id', $catId)->find();
        if (!$cateInfo) {
            return;
        }

        if (DB::table('nav')->where('guide', $catId)->where('module', $module)->where('type', 'middle')->exists()) {
            DB::table('nav')
                ->where('module', $module)
                ->where('guide', $catId)
                ->where('type', 'middle')
                ->update(array('name' => $cateInfo['name']));
            return;
        }

        $parentNavRow = DB::table('nav')
            ->where('module', $module)
            ->where('guide', $cateInfo['parent_id'])
            ->where('type', 'middle')
            ->find();

        if (!$parentNavRow) {
            return;
        }

        DB::table('nav')->insert(array(
            'module' => $module,
            'name' => $cateInfo['name'],
            'guide' => $catId,
            'parent_id' => Num::toIntOrZero($parentNavRow['id']),
            'type' => 'middle',
        ));
    }
}

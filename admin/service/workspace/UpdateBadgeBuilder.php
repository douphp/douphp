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

namespace Dou\Admin\Service\Workspace;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台「更新角标」数据构建（顶层 Smarty 变量 $unum）。
 *
 * 数据源：site.update_number（序列化数组），由云中心 refreshUpdateNumber 落库。
 * 当 site.close_update 为真时返回 null，调用方应据此跳过 Smarty assign。
 */
class UpdateBadgeBuilder extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 构建 $unum 数组；关闭检测时返回 null。
     *
     * @return array|null
     */
    public function build()
    {
        if (Config::get('site.close_update', false)) {
            return null;
        }

        $raw = Config::get('site.update_number', '');
        $number = is_string($raw) ? unserialize($raw) : false;
        if (!is_array($number)) {
            $number = array();
        }

        $number = array_merge(
            array(
                'update' => 0,
                'patch' => 0,
                'module' => 0,
                'theme' => 0,
                'plugin' => 0,
                'miniprogram' => 0,
            ),
            $number
        );
        $number['system'] = (int) $number['update'] + (int) $number['patch'] + (int) $number['module'] + (int) $number['theme'];

        return $number;
    }
}

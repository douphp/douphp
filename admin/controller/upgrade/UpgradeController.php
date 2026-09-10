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

namespace Dou\Admin\Controller\Upgrade;

use Dou\Admin\Controller\BaseController;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 系统升级入口（跳转官方说明并重定向回设置）
 */
class UpgradeController extends BaseController
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $version = Config::get('site.douphp_version', '');

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPGRADE, 1, (string) $version);
        return redirect(route('admin.setting'));
    }
}

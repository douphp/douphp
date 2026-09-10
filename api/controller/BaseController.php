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

namespace Dou\Api\Controller;

use Dou\Core\Controller\BaseController as CoreBaseController;
use Dou\Front\Service\User\UserCenterNavBuilder as FrontUserCenterNavBuilder;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 控制器基类
 *
 * 在 {@see \Dou\Core\Controller\BaseController} 之上，承载 API 端专属逻辑。
 * API 端无 Smarty/Setting/Authorized 等模板相关字段；
 * {@see self::buildLinkUserCenter()} 复用 {@see FrontUserCenterNavBuilder}
 * （由 Api\Init 在容器中以 Front 命名空间挂载）。
 *
 * API 控制器（`api/controller/.../*Controller.php`）统一继承本类。
 *
 * 服务调用一律走 helper / 门面：`DB::` / `auth('api')` / `language()` / `route()` …
 * API 端 `auth('api')` 默认返回 `\Dou\Api\Facade\Auth`；features.user 关闭或 user 模块未装时由
 * {@see \Dou\Core\Foundation\Auth\GuestGuard} 兜底，调用 ->id()/->check() 等读方法均返回游客默认值。
 */
abstract class BaseController extends CoreBaseController
{
    /**
     * API 端会员中心子导航 ViewModel；user 模块未装或 Builder 未注册时返回空结构。
     *
     * Api\Init 在容器中以 {@see FrontUserCenterNavBuilder} 类名注册了
     * 复用前台实现的 Builder，因此 API 与前台共享同一份导航。
     *
     * @param string $currentModule 当前路由模块短名（用于 cur 标记）
     * @return array
     */
    protected function buildLinkUserCenter($currentModule = '')
    {
        if (user() === null) {
            return array();
        }

        if (!app()->has(FrontUserCenterNavBuilder::class)) {
            return array();
        }

        return app(FrontUserCenterNavBuilder::class)->build($currentModule);
    }
}

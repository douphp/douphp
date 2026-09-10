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

namespace Dou\Front\Controller;

use Dou\Core\Controller\BaseController as CoreBaseController;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Front\Service\User\UserCenterNavBuilder as FrontUserCenterNavBuilder;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台控制器基类。
 *
 * 在 {@see \Dou\Core\Controller\BaseController} 之上提供前台版 view() 与 buildLinkUserCenter()。
 * 前台视图引擎实例由容器解析 {@see \Dou\Core\Web\Template\DouView}，多语言菜单数组通过
 * `language()->getLangMenu()` 取，结构化数据服务 {@see \Dou\Front\Service\Seo\SchemaService}
 * 由各子类按需构造注入。
 *
 * 前台控制器（`front/controller/.../*Controller.php`）统一继承本类。
 * 服务调用一律走 helper / 门面：`DB::` / `auth('front')` / `language()` / `message()` / `route()` 等。
 */
abstract class BaseController extends CoreBaseController
{
    /**
     * 构造视图响应（前台模板 + layout 公共变量合并）。
     *
     * 合并语义为 PHP 数组 `+` 的"左操作数优先"：
     *   action data > layoutVars()
     *
     * 即：action 自带键覆盖 layout 公共项，layout 公共项作为默认值。
     *
     * @param string $template
     * @param array $data 本页专属变量
     * @param int $statusCode HTTP 状态码（默认 200）
     * @return \Dou\Core\Web\Http\ViewResponse
     */
    protected function view($template, array $data = array(), $statusCode = 200)
    {
        return new \Dou\Core\Web\Http\ViewResponse(
            app(\Dou\Core\Web\Template\TemplateRendererInterface::class),
            $template,
            $data + $this->layoutVars(),
            (int) $statusCode
        );
    }

    /**
     * 业务成功后的末尾分流（单次 POST 协议统一出口）。
     *
     * 期望 JSON 的请求（fetch + Accept: application/json）走标准成功 envelope，
     * 在 data 中带回 redirect_url 供客户端跳转；普通表单提交走 303 重定向，
     * 保证关闭 JS 时的渐进增强可用。
     *
     * 显式接收 Request 形参，内部不调用 request() helper。
     *
     * @param Request $request 当前请求
     * @param string $redirectUrl 成功后跳转地址
     * @param array $data 附带业务数据（与 redirect_url 合并进 success envelope）
     * @param string $message 提示文案
     * @return \Dou\Core\Web\Http\Response
     */
    protected function respond(Request $request, $redirectUrl, array $data = array(), $message = '')
    {
        if ($request->wantsJson()) {
            $payload = array_merge(array('redirect_url' => (string) $redirectUrl), $data);
            ApiResponse::throwSuccess($payload, (string) $message);
        }

        return redirect($redirectUrl);
    }

    /**
     * 前台 layout 公共变量：每个 action 调用 view() 渲染时自动叠加。
     *
     * 默认实现返回空数组。如需注入 `rec` 等 HTTP 元信息，由 action 在自身 $data 里
     * 显式写入（如 `'rec' => $request->routeAction()`），或在子类 `layoutVars()`
     * 重写中处理。业务 Controller 按需重写并叠加自己的键：
     *
     *   protected function layoutVars()
     *   {
     *       return parent::layoutVars() + array(
     *           'nav_top_list' => $this->nav->top(),
     *           'keywords' => $this->seo->keywords(),
     *       );
     *   }
     *
     * 懒求值：仅当 action 真正调用 view() 渲染模板时才执行；返回 JSON / 重定向
     * 的 action 不会付出此处的代价。
     *
     * 本方法及其重写都**不**直接调 `request` helper；如需 HTTP 元信息（如 `rec` / `cur`），
     * 由 action 在 $data 中显式注入。
     *
     * @return array
     */
    protected function layoutVars()
    {
        return array();
    }

    /**
     * 前台会员中心子导航 ViewModel；user 模块未装或 Builder 未注册时返回空结构。
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

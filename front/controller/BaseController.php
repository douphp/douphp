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
use Dou\Front\Service\Nav\NavigationBuilder;
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
            $this->pageFactVars($data + $this->layoutVars()),
            (int) $statusCode
        );
    }

    /**
     * 页面事实与导航派生变量的统一收口（chat.md §3.1 / §3.4 基座）。
     *
     * 在 action data + layoutVars 合并结果之上补齐三类键（全部"缺才补、有不动"，
     * 保证存量手写值的控制器在清理批次前后行为均不受本方法影响）：
     *
     * 1. 三个导航列表键 fail-safe：渲染路径漏声明时用同源单例兜底构建，模板不报未定义；
     * 2. 顶层 `cur`：由最近一次 `NavigationBuilder::middle()` 解析出的归属模块派生
     *    （纯模块页 = 路由模块，归因页 = 归因模块）；本页未调用过 middle() 时不写键，
     *    沿用 FrontResolver 的引擎级默认 assign；
     * 3. `route_module` / `route_action`：当前页命中的稳定路由事实，供共用 inc 片段做
     *    **内容分支**（区分宿主页面）。红线：**禁止用于菜单高亮**——高亮归 NavigationBuilder
     *    计算的布尔，用这两个变量做高亮等于复活字符串比较链（批次 4 扫描 theme/ 导航类片段
     *    消费这两个键即告警）。菜单归属域判断请用 `cur`，两者语义不同勿混用。
     *
     * @param array $merged action data + layoutVars 的合并结果
     * @return array
     */
    private function pageFactVars(array $merged)
    {
        if (app()->has(NavigationBuilder::class)) {
            $nav = app(NavigationBuilder::class);
            if (!array_key_exists('nav_top_list', $merged)) {
                $merged['nav_top_list'] = $nav->top();
            }
            if (!array_key_exists('nav_middle_list', $merged)) {
                $merged['nav_middle_list'] = $nav->middle();
            }
            if (!array_key_exists('nav_bottom_list', $merged)) {
                $merged['nav_bottom_list'] = $nav->bottom();
            }
        }
        if (!array_key_exists('cur', $merged)) {
            $contextModule = NavigationBuilder::contextModule();
            if ($contextModule !== '') {
                $merged['cur'] = $contextModule;
            }
        }
        if (!array_key_exists('route_module', $merged)) {
            $merged['route_module'] = (string) request()->routeModule();
        }
        if (!array_key_exists('route_action', $merged)) {
            $merged['route_action'] = (string) request()->routeAction();
        }

        return $merged;
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
     * 本方法及其重写都**不**直接调 `request` helper；HTTP 元信息（`rec`）由 action 在
     * $data 中显式注入。顶层 `cur` 不在注入范围：属基类派生结果（见 {@see pageFactVars()}），
     * 禁止手写 `'cur' =>`（devtools/front-nav-scan.php 门禁拦截）。
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

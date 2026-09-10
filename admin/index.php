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
define('IN_DOUCO', true);

// 后台 bootstrap 从上一级网站根加载 引入核心文件
require(dirname(dirname(__FILE__)) . '/core/bootstrap.php');

use Dou\Core\Facade\Route;
use Dou\Core\Foundation\Exception\SiteDebugExceptionRenderer;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\HttpResponseException;
use Dou\Core\Web\Http\Response;

Route::setDelegate(new \Dou\Admin\Foundation\Routing\Router());

// HTTP 边界：将 route 查询参数写入 Request 后从超全局移除，后续路由读取走 Request。
request()->setRouteString(isset($_GET['route']) ? (string) $_GET['route'] : '');
unset($_GET['route'], $_REQUEST['route']);

$booted = false;

try {
    (new \Dou\Admin\Init\Init())->boot();
    $booted = true;
    $httpResponse = Route::dispatch();
    if ($httpResponse instanceof Response) {
        $httpResponse->send();
        exit;
    }
} catch (HttpResponseException $e) {
    $e->getResponse()->send();
    exit;
} catch (\Dou\Core\Foundation\Exception\RedirectException $e) {
    redirect($e->getUrl(), $e->getStatusCode())->send();
    exit;
} catch (\Dou\Core\Foundation\Exception\DomainException $e) {
    if ($booted && request()->isAjax() && strpos(request()->routeString(), 'ai/generate/') === 0) {
        ApiResponse::error('INVALID_PARAMS', $e->getMessage(), $e->getErrors())->send();
        exit;
    }
    if ($booted) {
        message()->respond($e->getMessage(), $e->getBackUrl(), $e->getOut(), $e->getTimer(), $e->getConfirmUrl())->send();
    }
    exit;
} catch (\Exception $e) {
    admin_render_uncaught($e, $booted);
} catch (\Throwable $e) {
    // PHP 7+ 的 \Error 不继承 \Exception，后台入口同样按 site.debug 渲染或回退提示页；
    // 5.6 无 \Throwable，本分支永不匹配（仅解析占位）。
    admin_render_uncaught($e, $booted);
}

/**
 * 处理后台入口未捕获的 Exception。
 *
 * @param \Exception $e
 * @param bool $booted Init 是否已完成（决定能否安全调用 message() 走提示页）
 * @return void
 */
function admin_render_uncaught($e, $booted)
{
    error_log('[DouPHP Admin] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (SiteDebugExceptionRenderer::isSiteDebugEnabled()) {
        SiteDebugExceptionRenderer::renderAndExitForHtml($e, 'admin');
    }
    if ($booted) {
        message()->respond('page_wrong', '')->send();
    }
    exit;
}

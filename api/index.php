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

require(dirname(dirname(__FILE__)) . '/core/bootstrap.php');

use Dou\Core\Facade\Route;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Exception\RedirectException;
use Dou\Core\Foundation\Exception\SiteDebugExceptionRenderer;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\HttpResponseException;
use Dou\Core\Web\Http\Response;

Route::setDelegate(new \Dou\Api\Foundation\Routing\Router());

// HTTP 边界：将 route 查询参数写入 Request 后从超全局移除，后续路由读取走 Request。
request()->setRouteString(isset($_GET['route']) ? (string) $_GET['route'] : '');
unset($_GET['route'], $_REQUEST['route']);

try {
    (new \Dou\Api\Init\Init())->boot();
    $httpResponse = Route::dispatch();
    if ($httpResponse instanceof Response) {
        $httpResponse->send();
        exit;
    }
} catch (HttpResponseException $e) {
    $e->getResponse()->send();
    exit;
} catch (RedirectException $e) {
    redirect($e->getUrl(), $e->getStatusCode())->send();
    exit;
} catch (DomainException $e) {
    ApiResponse::error(ApiCodes::BUSINESS_RULE_VIOLATION, $e->getMessage(), $e->hasErrors() ? $e->getErrors() : array(), 422)->send();
    exit;
} catch (\Exception $e) {
    api_render_uncaught($e);
} catch (\Throwable $e) {
    // PHP 7+ 的 \Error 不继承 \Exception，API 入口同样兜底为 JSON 500；
    // 5.6 无 \Throwable，本分支永不匹配（仅解析占位）。
    api_render_uncaught($e);
}

/**
 * 处理 API 入口未捕获的 Exception。
 *
 * @param \Exception $e
 * @return void
 */
function api_render_uncaught($e)
{
    error_log('[DouPHP API] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    if (SiteDebugExceptionRenderer::isSiteDebugEnabled()) {
        SiteDebugExceptionRenderer::sendApi500($e);
        exit;
    }
    ApiResponse::error(ApiCodes::SERVER_ERROR, 'server_error', array(), 500)->send();
    exit;
}

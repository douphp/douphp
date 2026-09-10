<?php

/**
 * DouPHP
 * ------------------------------------------------------------------------------------
 * 版权所有 2013-2026 漳州豆壳网络科技有限公司，并保留所有权利。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在遵守授权协议前提下对程序代码进行修改和使用；
 * 不允许对程序代码以任何形式任何目的的再发布。
 * 授权协议：http://www.douphp.com/license.html
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-05-11
 */
define('IN_DOUCO', true);

require(dirname(__FILE__) . '/core/bootstrap.php');

use Dou\Core\Facade\Route;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Foundation\Exception\RedirectException;
use Dou\Core\Foundation\Exception\SiteDebugExceptionRenderer;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\HttpResponseException;
use Dou\Core\Web\Http\Response;

Route::setDelegate(new \Dou\Front\Foundation\Routing\Router());

// HTTP 边界路由预处理：剥语言前缀写入 Request（Init::boot 之前需要语言信息），
// route 字符串仅经 Request 流转，业务层不直接读 $_GET/$_REQUEST 中的 route。
$frontRequest = request();
$frontRoute = \Dou\Front\Foundation\Routing\LangPrefixParser::parse(isset($_GET['route']) ? (string) $_GET['route'] : '');
$frontRequest->setRouteLangSign($frontRoute['langSign']);
$frontRequest->setRouteString($frontRoute['routeString']);
unset($_GET['route'], $_REQUEST['route']);

$booted = false;

try {
    (new \Dou\Front\Init\Init())->boot(Route::current());
    $booted = true;
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
    $isJsonRequest = front_request_is_json();

    if ($isJsonRequest) {
        ApiResponse::error(
            'BUSINESS_RULE_VIOLATION',
            $e->getMessage(),
            $e->hasErrors() ? $e->getErrors() : array(),
            422
        )->send();
        exit;
    }

    if ($booted) {
        message()->respond($e->getMessage(), $e->getBackUrl(), '', $e->getTimer(), $e->getConfirmUrl())->send();
    }
    exit;
} catch (\Exception $e) {
    front_render_uncaught($e, $booted);
} catch (\Throwable $e) {
    // PHP 7+ 的 \Error（TypeError / 调 null 方法等）不继承 \Exception，
    // 在入口同样按 site.debug + JSON 检测渲染；5.6 无 \Throwable，本分支永不匹配（仅解析占位）。
    front_render_uncaught($e, $booted);
}

/**
 * 当前请求是否需以 JSON 形式响应（内容协商）。
 *
 * 唯一真源在 {@see \Dou\Core\Web\Http\Request::wantsJson()}：Accept 头含 application/json
 * 或 XMLHttpRequest（isAjax）即视为期望 JSON。入口属 HTTP 边界，允许调 request() helper。
 *
 * @return bool
 */
function front_request_is_json()
{
    return request()->wantsJson();
}

/**
 * 处理前台入口未捕获的 Exception：
 * - 始终 error_log；
 * - site.debug 开 → 输出调试页（HTML 或 JSON）；
 * - 否则按请求类型回退到 `page_wrong` 提示或 JSON 500。
 *
 * PHP 7+ 的 `Error`（如 null 上调用方法）不继承 Exception，由
 * `InitTrait::handleGlobalException` 在 site.debug 下兜底输出 HTML。
 *
 * @param \Exception $e
 * @param bool $booted Init 是否已完成（决定能否安全调用 message() 走提示页）
 * @return void
 */
function front_render_uncaught($e, $booted)
{
    error_log('[DouPHP] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    $isJsonRequest = front_request_is_json();

    if (SiteDebugExceptionRenderer::isSiteDebugEnabled()) {
        if ($isJsonRequest) {
            SiteDebugExceptionRenderer::sendApi500($e);
        } else {
            SiteDebugExceptionRenderer::renderAndExitForHtml($e, 'front');
        }
        exit;
    }
    if ($isJsonRequest) {
        ApiResponse::error('SERVER_ERROR', 'server_error', array(), 500)->send();
        exit;
    }
    if ($booted) {
        message()->respond('page_wrong', '')->send();
    }
    exit;
}

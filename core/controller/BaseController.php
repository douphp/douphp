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

namespace Dou\Core\Controller;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 控制器基类（三端共享部分）。
 *
 * 运行时依赖通过 helper / 门面就近解析；当前语言通过 `locale()` 取，
 * 授权状态通过 `Config::get('app.licensed')` 取。
 *
 * **HTTP 输入注入约定：** Controller action 取外部输入一律以方法签名注入
 * `Dou\Core\Web\Http\Request $request` 或 `*FormRequest $formRequest`（由容器
 * `call()` + `__scene` 自动解析）；action 方法体内及所属控制器的 private / protected
 * helper 都**不**直接调 `request` helper / `Request::*` 静态。helper 若需 HTTP 信息，
 * 必须通过参数显式接收 `Request $request`，由 action 把自己的 `$request` 传入。
 *
 * 端侧专属能力下沉到端侧 BaseController（admin / front / api 各自的 view() /
 * layoutVars() / buildLinkUserCenter() 等）。
 *
 * 子类按需注入特定依赖：
 * <pre>
 * class ArticleController extends \Dou\Admin\Controller\BaseController {
 *     public function __construct(ArticleService $svc) {
 *         $this->articleService = $svc;
 *     }
 * }
 * </pre>
 */
abstract class BaseController
{
    /**
     * 构造 JSON 响应（裸 json_encode；API 端业务接口请优先使用 ApiResponse::success / error）。
     *
     * @param mixed $data
     * @param int $statusCode
     * @param int $encodeOptions 透传 json_encode 第二参（默认 0）
     * @return \Dou\Core\Web\Http\JsonResponse
     */
    protected function json($data, $statusCode = 200, $encodeOptions = 0)
    {
        return new \Dou\Core\Web\Http\JsonResponse($data, (int) $statusCode, (int) $encodeOptions);
    }

    /**
     * 构造通用响应（纯文本 / HTML 片段 / XML / 二进制）。
     *
     * @param string $content
     * @param int $statusCode
     * @param array $headers
     * @return \Dou\Core\Web\Http\Response
     */
    protected function response($content = '', $statusCode = 200, array $headers = array())
    {
        return new \Dou\Core\Web\Http\Response((string) $content, (int) $statusCode, $headers);
    }

    /**
     * 构造重定向响应。
     *
     * @param string $url
     * @param int $statusCode 301 或 302
     * @return \Dou\Core\Web\Http\RedirectResponse
     */
    protected function redirect($url, $statusCode = 302)
    {
        return \Dou\Core\Web\Http\RedirectResponse::create((string) $url, (int) $statusCode);
    }
}

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
 * Release Date: 2026-10-12
 */

namespace Dou\Api\Controller\Article;

use Dou\Api\Controller\BaseController;
use Dou\Api\Request\Article\ArticlePublishFormRequest;
use Dou\Api\Service\Article\ArticlePublishService;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Service\Open\OpenService;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 开放平台文章发布控制器（open/v1/article）。
 *
 * 仅在 open 模块已装时由 api/route/article.php 的守卫段注册；准入由路由级
 * open_auth 中间件按 scope（article.publish / article.update / article.delete）
 * 裁决，应用上下文（currentApp）在验签通过后注入。
 *
 * 控制器仅做：取应用身份、调 ArticlePublishService、统一信封响应；
 * 字段校验由 ArticlePublishFormRequest 完成，业务与持久化在服务层。
 */
class ArticleOpenController extends BaseController
{
    /** @var ArticlePublishService */
    private $publishService;

    /**
     * @param ArticlePublishService $publishService
     */
    public function __construct(ArticlePublishService $publishService)
    {
        $this->publishService = $publishService;
    }

    /**
     * 发布新文章（POST open/v1/article，scope article.publish）。
     *
     * @param ArticlePublishFormRequest $formRequest
     * @return Response
     */
    public function store(ArticlePublishFormRequest $formRequest)
    {
        $app = $this->currentApp();

        $id = $this->publishService->publish(
            $formRequest->validated(),
            (int) $app['id'],
            isset($app['admin_id']) ? (int) $app['admin_id'] : 0
        );

        return ApiResponse::success(array('id' => $id), lang('open_publish_success'));
    }

    /**
     * 更新本应用发布的文章（PUT open/v1/article/{id}，scope article.update）。
     *
     * @param ArticlePublishFormRequest $formRequest
     * @return Response
     */
    public function update(ArticlePublishFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        $app = $this->currentApp();

        $ok = $this->publishService->updatePublish(
            $id,
            $data,
            (int) $app['id'],
            isset($app['admin_id']) ? (int) $app['admin_id'] : 0
        );
        if (!$ok) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('open_publish_not_found'), array(), 404);
        }

        return ApiResponse::success(array('id' => $id), lang('open_publish_updated'));
    }

    /**
     * 删除本应用发布的文章（DELETE open/v1/article/{id}，scope article.delete）。
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        $app = $this->currentApp();

        if (!$this->publishService->remove($id, (int) $app['id'])) {
            return ApiResponse::error(ApiCodes::NOT_FOUND, lang('open_publish_not_found'), array(), 404);
        }

        return ApiResponse::success(array('id' => $id), lang('open_publish_deleted'));
    }

    /**
     * 当前已鉴权应用行；理论恒可用（路由声明层已守卫 open 模块 + open_auth 中间件 fail-closed）。
     *
     * @return array
     */
    private function currentApp()
    {
        $app = OpenService::currentApp();
        if (!is_array($app) || empty($app['id'])) {
            ApiResponse::throwError(ApiCodes::UNAUTHORIZED, lang('open_error_unavailable'), array(), 401);
        }

        return $app;
    }
}

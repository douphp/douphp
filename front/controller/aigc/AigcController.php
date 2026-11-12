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
 * Release Date: 2026-11-11
 */

namespace Dou\Front\Controller\Aigc;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Aigc\GenerationRunner;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Controller\BaseController;
use Dou\Front\Service\Aigc\AigcService;
use Dou\Front\Service\Nav\NavigationBuilder;
use Dou\Front\Service\Seo\BreadcrumbBuilder;
use Dou\Front\Service\Seo\SeoResolver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 前台：应用中心 / 应用页 / 生成提交与轮询 / 作品 / 文件输出。
 */
class AigcController extends BaseController
{
    /** @var AigcService */
    private $aigcService;

    /** @var GenerationRunner */
    private $runner;

    /** @var NavigationBuilder */
    private $nav;

    /** @var BreadcrumbBuilder */
    private $breadcrumb;

    /** @var SeoResolver */
    private $seo;

    public function __construct(
        AigcService $aigcService,
        GenerationRunner $runner,
        NavigationBuilder $nav,
        BreadcrumbBuilder $breadcrumb,
        SeoResolver $seo
    ) {
        $this->aigcService = $aigcService;
        $this->runner = $runner;
        $this->nav = $nav;
        $this->breadcrumb = $breadcrumb;
        $this->seo = $seo;
    }

    /**
     * 应用中心（游客可浏览）。
     *
     * @return Response
     */
    public function index()
    {
        $urHere = $this->breadcrumb->build('aigc', 'aigc');

        return $this->view('aigc.dwt', array(
            'page_title' => $this->seo->pageTitle('aigc', 'aigc'),
            'rec' => 'index',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),
            'app_list' => $this->aigcService->listApps(),
            'is_logged_in' => auth('front') ? auth('front')->check() : false,
        ));
    }

    /**
     * 应用列表（扁平）。
     *
     * @return Response
     */
    public function listing()
    {
        $urHere = $this->breadcrumb->build('aigc', 'aigc_list');

        return $this->view('aigc.dwt', array(
            'page_title' => $this->seo->pageTitle('aigc', 'aigc_list'),
            'rec' => 'list',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),
            'app_list' => $this->aigcService->listApps(),
            'is_logged_in' => auth('front') ? auth('front')->check() : false,
        ));
    }

    /**
     * 应用页（chat 走对话模板，其余走任务表单模板）。
     *
     * @param Request $request
     * @return Response
     */
    public function app(Request $request)
    {
        $slug = trim((string) $request->input('slug', ''));
        $app = $this->aigcService->findBySlug($slug);
        if (!$app) {
            throw new DomainException(lang('aigc_app_not_found', '应用不存在'), HOME_URL);
        }

        $userId = (int) auth('front')->id();
        if ($userId <= 0) {
            $returnUrl = route('aigc.app', array(), array('query' => array('slug' => $slug)));

            return redirect(route('user.login', array(), array('query' => array('return_url' => urlencode($returnUrl)))));
        }

        $urHere = $this->breadcrumb->build('aigc', '', $app['name']);
        $data = array(
            'page_title' => $this->seo->pageTitle('aigc', '', $app['name']),
            'rec' => 'app',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),
            'app' => $app,
            'field_list' => $app['type'] === 'chat' ? array() : $this->aigcService->fields((int) $app['id']),
            'models_json' => json_encode($this->aigcService->availableModels($app), JSON_UNESCAPED_UNICODE),
            'quota_status' => $this->aigcService->quotaStatus($userId),
            'works_url' => route('aigc.works'),
            'package_url' => route('aigc.package'),
            'submit_url' => route('aigc.submit'),
            'poll_url' => route('aigc.poll'),
            'file_url' => route('aigc.file'),
        );

        if ($app['type'] === 'chat') {
            return $this->view('aigc_chat.dwt', $data);
        }

        return $this->view('aigc_app.dwt', $data);
    }

    /**
     * 提交生成任务（JSON）。
     *
     * @param Request $request
     * @return void
     */
    public function submit(Request $request)
    {
        $userId = (int) auth('front')->id();
        $app = $this->aigcService->findBySlug((string) $request->input('slug', ''));
        if (!$app) {
            ApiResponse::throwError('AIGC_APP_NOT_FOUND', lang('aigc_app_not_found', '应用不存在'), array(), 404);
        }

        $built = $this->aigcService->buildGenerationParams($app, $request->post());
        if (empty($built['ok'])) {
            ApiResponse::throwError('AIGC_INVALID_FIELD', $built['error'], array(), 422);
        }

        $result = $this->runner->submit($app, $userId, $built['params'], (string) $request->ip(), (string) $request->server('HTTP_USER_AGENT', ''));
        if (empty($result['success'])) {
            ApiResponse::throwError('AIGC_GENERATE_FAILED', $result['error'], array(), 422);
        }

        ApiResponse::throwSuccess($result);
    }

    /**
     * 轮询任务（JSON）。
     *
     * @param Request $request
     * @return void
     */
    public function poll(Request $request)
    {
        $userId = (int) auth('front')->id();
        $result = $this->runner->poll($request->integer('id', 0), $userId, false);
        if (empty($result['success'])) {
            ApiResponse::throwError('AIGC_POLL_FAILED', $result['error'], array(), 422);
        }

        ApiResponse::throwSuccess($result);
    }

    /**
     * 我的作品。
     *
     * @param Request $request
     * @return Response
     */
    public function works(Request $request)
    {
        $userId = (int) auth('front')->id();
        $type = trim((string) $request->input('type', ''));
        $page = $request->integer('page', 1);
        $pageUrl = route('aigc.works') . ($type !== '' ? '&type=' . rawurlencode($type) : '');
        $bundle = $this->aigcService->works($userId, $type, $page, $pageUrl);
        $urHere = $this->breadcrumb->build('aigc', 'aigc_works');

        return $this->view('aigc_works.dwt', array(
            'page_title' => $this->seo->pageTitle('aigc', 'aigc_works'),
            'rec' => 'works',
            'code_head' => Config::get('site.code_head', ''),
            'ur_here' => $urHere,
            'breadcrumb' => $this->breadcrumb->schema($urHere),
            'work_list' => $bundle['list'],
            'pager' => $bundle['pager'],
            'req' => array('type' => $type),
            'file_url' => route('aigc.file'),
        ));
    }

    /**
     * 产物预览 / 下载（归属校验后读私有磁盘）。
     *
     * @param Request $request
     * @return void
     */
    public function file(Request $request)
    {
        $userId = (int) auth('front')->id();
        $taskId = $request->integer('id', 0);
        $index = $request->integer('index', 0);
        $mode = $request->input('mode', 'inline') === 'download' ? 'download' : 'inline';

        $file = $this->aigcService->resolveWorkFile($taskId, $userId, $index);
        if (!$file) {
            throw new DomainException(lang('aigc_file_not_found', '文件不存在'), HOME_URL);
        }

        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . filesize($file['path']));
        header('Content-Disposition: ' . ($mode === 'download' ? 'attachment' : 'inline') . '; filename="' . rawurlencode($file['name']) . '"');
        readfile($file['path']);
        exit;
    }

    /**
     * 删除作品（JSON）。
     *
     * @param Request $request
     * @return void
     */
    public function task_delete(Request $request)
    {
        $userId = (int) auth('front')->id();
        $result = $this->runner->delete($request->integer('id', 0), $userId, false);
        if (empty($result['success'])) {
            ApiResponse::throwError('AIGC_DELETE_FAILED', $result['error'], array(), 422);
        }

        ApiResponse::throwSuccess(array('deleted' => true));
    }

    /**
     * 页面公共变量。
     *
     * @return array
     */
    protected function layoutVars()
    {
        return array(
            'keywords' => $this->seo->keywords(),
            'description' => $this->seo->description(),
            'nav_top_list' => $this->nav->top(),
            'nav_middle_list' => $this->nav->middle(),
            'nav_bottom_list' => $this->nav->bottom(),
            'link_user_center' => $this->buildLinkUserCenter('aigc'),
            'if_connect_plugin' => plugin()->hasConnect(),
        );
    }
}

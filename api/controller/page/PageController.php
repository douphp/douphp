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

namespace Dou\Api\Controller\Page;

use Dou\Api\Controller\BaseController;
use Dou\Core\Facade\RouteId;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Front\Service\Page\PageService as FrontPageService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 单页 API（共用前台 PageService）
 */
class PageController extends BaseController
{
    /** @var FrontPageService */
    private $pageService;

    /**
     * @param FrontPageService $pageService
     */
    public function __construct(FrontPageService $pageService)
    {
        $this->pageService = $pageService;
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $id = RouteId::page($request->input('id'), $request->input('slug'));
        if ($id == -1) {
            throw new DomainException(lang('page_wrong'));
        }

        $data = $this->pageService->buildPageApiData($id);
        if (!$data) {
            throw new DomainException(lang('page_wrong'));
        }

        return ApiResponse::success($data);
    }
}

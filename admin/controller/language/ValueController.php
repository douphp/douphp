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

namespace Dou\Admin\Controller\Language;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Language\LanguageValueFormRequest;
use Dou\Admin\Service\Language\LanguageValueService;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台多语言字段 AJAX 控制器
 */
class ValueController extends BaseController
{
    /** @var LanguageValueService */
    private $languageValueService;

    /**
     * @param LanguageValueService $languageValueService
     */
    public function __construct(LanguageValueService $languageValueService)
    {
        $this->languageValueService = $languageValueService;
    }

    /**
     * @return void
     */
    public function index()
    {
        // AJAX controller, no template assignments needed
    }

    /**
     * @param LanguageValueFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function store(LanguageValueFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $result = $this->languageValueService->saveValue($data);

        return $this->json($result);
    }

    /**
     * @param LanguageValueFormRequest $formRequest
     * @return \Dou\Core\Web\Http\JsonResponse
     */
    public function value(LanguageValueFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        $result = $this->languageValueService->buildValueData($data);

        return $this->json($result);
    }
}

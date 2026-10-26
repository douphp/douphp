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
use Dou\Admin\Request\Language\LanguageDraftRequest;
use Dou\Admin\Service\Language\LanguageDraftService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 原生字段语言抽屉草稿图片 AJAX 控制器。
 */
class DraftController extends BaseController
{
    /** @var LanguageDraftService */
    private $draftService;

    /**
     * @param LanguageDraftService $draftService
     */
    public function __construct(LanguageDraftService $draftService)
    {
        $this->draftService = $draftService;
    }

    /**
     * @param LanguageDraftRequest $formRequest
     * @return Response
     */
    public function upload(LanguageDraftRequest $formRequest)
    {
        try {
            return $this->json($this->draftService->upload($formRequest->validated()));
        } catch (DomainException $e) {
            return $this->json(array('ok' => false, 'message' => $e->getMessage()));
        }
    }

    /**
     * @param LanguageDraftRequest $formRequest
     * @return Response
     */
    public function release(LanguageDraftRequest $formRequest)
    {
        try {
            return $this->json($this->draftService->release($formRequest->validated()));
        } catch (DomainException $e) {
            return $this->json(array('ok' => false, 'message' => $e->getMessage()));
        }
    }
}

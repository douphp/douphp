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

namespace Dou\Admin\Controller\Ai;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Ai\GenerateTranslateFormRequest;
use Dou\Admin\Service\Ai\GenerateService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 创作「多语言翻译」子资源（route=ai/generate/translate，POST → store）
 *
 * 把主表单当前字段原文译成目标语言，返回纯文本译文；不写入 language_value，
 * 由前端填入多语言弹窗后管理员再点提交保存。
 */
class GenerateTranslateController extends BaseController
{
    /** @var GenerateService */
    private $generateService;

    /**
     * @param GenerateService $generateService
     */
    public function __construct(GenerateService $generateService)
    {
        $this->generateService = $generateService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'ai',
            'submenu' => 'ai',
        );
    }

    /**
     * 多语言翻译（route=ai/generate/translate POST）
     *
     * @param GenerateTranslateFormRequest $formRequest
     * @param Request $request
     * @return void
     */
    public function store(GenerateTranslateFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        try {
            $content = $this->generateService->generateTranslate(
                (int) $data['app_id'],
                (string) $data['field'],
                (string) $data['source_text'],
                (string) $data['target_lang'],
                isset($data['target_lang_name']) ? (string) $data['target_lang_name'] : '',
                (int) auth('admin')->id(),
                (string) $request->ip()
            );
        } catch (DomainException $e) {
            ApiResponse::throwError('AI_GENERATE_FAILED', $e->getMessage());
        }

        ApiResponse::throwSuccess(array('content' => $content));
    }
}

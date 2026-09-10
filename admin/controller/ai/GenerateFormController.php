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
use Dou\Admin\Request\Ai\GenerateFormFormRequest;
use Dou\Admin\Service\Ai\GenerateService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 创作「整表单生成」子资源（route=ai/generate/form，POST → store）
 *
 * 按用户指令一次生成整张表单的字段值数组建模为 ai_generate_form 资源的 store 端点：
 * 每次 POST 创建一组字段值映射（不入库，由前端 setFieldValue 写入当前表单）。
 */
class GenerateFormController extends BaseController
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
     * 整表单生成（route=ai/generate/form POST）
     *
     * @param GenerateFormFormRequest $formRequest
     * @param Request $request
     * @return void
     */
    public function store(GenerateFormFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        try {
            $values = $this->generateService->generateForm(
                (int) $data['app_id'],
                isset($data['prompt']) ? (string) $data['prompt'] : '',
                (int) auth('admin')->id(),
                (string) $request->ip()
            );
        } catch (DomainException $e) {
            ApiResponse::throwError('AI_GENERATE_FAILED', $e->getMessage());
        }

        ApiResponse::throwSuccess(array('values' => $values));
    }
}

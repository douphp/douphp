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
use Dou\Admin\Request\Ai\GenerateBatchFormRequest;
use Dou\Admin\Service\Ai\GenerateService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 创作「批量生成」子资源（route=ai/generate/batch，POST → store）
 *
 * 把若干条按用户指令一次生成 + 入库的批处理结果建模为 ai_generate_batch 资源的
 * store 端点：每次 POST 创建一组生成结果（导入计数 / 失败计数 / 失败明细）。
 */
class GenerateBatchController extends BaseController
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
     * 批量生成 + 入库（route=ai/generate/batch POST）
     *
     * @param GenerateBatchFormRequest $formRequest
     * @param Request $request
     * @return void
     */
    public function store(GenerateBatchFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        $context = array(
            'category_id' => isset($data['category_id']) ? (int) $data['category_id'] : 0,
            'parent_id' => isset($data['parent_id']) ? (int) $data['parent_id'] : 0,
        );

        try {
            $result = $this->generateService->generateBatch(
                (int) $data['app_id'],
                isset($data['prompt']) ? (string) $data['prompt'] : '',
                (int) $data['count'],
                $context,
                (int) auth('admin')->id(),
                (string) $request->ip()
            );
        } catch (DomainException $e) {
            ApiResponse::throwError('AI_GENERATE_FAILED', $e->getMessage());
        }

        ApiResponse::throwSuccess($result);
    }
}

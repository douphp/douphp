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
use Dou\Admin\Request\Ai\GenerateFieldFormRequest;
use Dou\Admin\Service\Ai\GenerateService;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 创作「单字段生成」子资源（route=ai/generate/field，POST → store）
 *
 * 按当前字段名 + 当前上下文 + 用户指令生成单个字段值建模为 ai_generate_field 资源的
 * store 端点：每次 POST 创建一条字段生成结果（不入库，由前端 setFieldValue 写入指定字段）。
 */
class GenerateFieldController extends BaseController
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
     * 单字段生成（route=ai/generate/field POST）
     *
     * 文本应用返回 {content}；图像应用返回任务结果（task_id 等，前端按任务链路轮询/展示）。
     * banner 弹窗追加参数（banner_title / banner_subtitle / banner_style / image_refs /
     * banner_material_mode）仅图像应用消费，文本应用忽略。
     *
     * @param GenerateFieldFormRequest $formRequest
     * @param Request $request
     * @return void
     */
    public function store(GenerateFieldFormRequest $formRequest, Request $request)
    {
        $data = $formRequest->validated();
        try {
            $content = $this->generateService->generateField(
                (int) $data['app_id'],
                (string) $data['field'],
                isset($data['prompt']) ? (string) $data['prompt'] : '',
                isset($data['current_value']) ? (string) $data['current_value'] : '',
                isset($data['form_snapshot']) ? $data['form_snapshot'] : array(),
                isset($data['module']) ? (string) $data['module'] : '',
                (int) auth('admin')->id(),
                (string) $request->ip(),
                isset($data['size']) ? (string) $data['size'] : '',
                isset($data['content_align']) ? (string) $data['content_align'] : '',
                array(
                    'title' => isset($data['banner_title']) ? (string) $data['banner_title'] : '',
                    'subtitle' => isset($data['banner_subtitle']) ? (string) $data['banner_subtitle'] : '',
                    'style' => isset($data['banner_style']) ? (string) $data['banner_style'] : '',
                    'image_refs' => isset($data['image_refs']) ? $data['image_refs'] : array(),
                    'material_mode' => isset($data['banner_material_mode']) ? (string) $data['banner_material_mode'] : '',
                )
            );
        } catch (DomainException $e) {
            ApiResponse::throwError('AI_GENERATE_FAILED', $e->getMessage());
        }

        if (is_array($content)) {
            ApiResponse::throwSuccess($content);
        }

        ApiResponse::throwSuccess(array('content' => $content));
    }

    /**
     * 提示词预览（route=ai/generate/field/preview POST）
     *
     * 只组装不下发模型：文本应用返回 system/user 消息全文（[role] 分段），
     * 图像应用返回最终单段提示词。入参与 store 一致；
     * image_refs 不参与预览（不读盘解析），素材用法指令不注入。
     *
     * @param GenerateFieldFormRequest $formRequest
     * @return void
     */
    public function preview(GenerateFieldFormRequest $formRequest)
    {
        $data = $formRequest->validated();
        try {
            $result = $this->generateService->previewField(
                (int) $data['app_id'],
                (string) $data['field'],
                isset($data['prompt']) ? (string) $data['prompt'] : '',
                isset($data['current_value']) ? (string) $data['current_value'] : '',
                isset($data['form_snapshot']) ? $data['form_snapshot'] : array(),
                isset($data['module']) ? (string) $data['module'] : '',
                isset($data['size']) ? (string) $data['size'] : '',
                isset($data['content_align']) ? (string) $data['content_align'] : '',
                array(
                    'title' => isset($data['banner_title']) ? (string) $data['banner_title'] : '',
                    'subtitle' => isset($data['banner_subtitle']) ? (string) $data['banner_subtitle'] : '',
                    'style' => isset($data['banner_style']) ? (string) $data['banner_style'] : '',
                    'image_refs' => array(),
                    'material_mode' => '',
                )
            );
        } catch (DomainException $e) {
            ApiResponse::throwError('AI_PREVIEW_FAILED', $e->getMessage());
        }

        ApiResponse::throwSuccess($result);
    }

    /**
     * 图片素材选择器的产品主图列表（route=ai/generate/field/products GET）
     * 只列有主图的产品：{id, title, url}，url 为缩略图地址供弹窗预览，
     * 提交时以 .file 号作为 image_refs 传回（服务端据此读盘转 base64）。
     *
     * @param Request $request
     * @return void
     */
    public function products(Request $request)
    {
        $keyword = trim((string) $request->get('keyword', ''));
        $limit = max(1, min(60, $request->integer('limit', 30)));

        $query = DB::table('product')
            ->where('image', '<>', '')
            ->order('id DESC');
        if ($keyword !== '') {
            $query->where('title', 'LIKE', '%' . $keyword . '%');
        }

        $rows = $query->field('id, title, image')->limit($limit)->select();

        $list = array();
        foreach ($rows as $row) {
            $url = attachment()->url($row['image'], true);
            if ($url === '') {
                continue;
            }
            $list[] = array(
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'file' => (string) $row['image'],
                'url' => $url,
            );
        }

        ApiResponse::throwSuccess($list);
    }
}

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

namespace Dou\Admin\Controller\Concerns;

use Dou\Admin\Service\Common\QuickEditService;
use Dou\Core\Foundation\Api\ApiCodes;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 列表单元格快速编辑控制器 concern。
 *
 * 模块控制器 use 本 trait 并实现 quickEditConfig() 声明本模块的模型与可编辑字段；
 * trait 只负责通用 AJAX 动作与 JSON 返回，不感知任何具体模块。
 */
trait QuickEditable
{
    /**
     * 本模块快速编辑配置。
     *
     * @return array array('model' => 模型类, 'fields' => array(字段 => array('type' => ...)))
     */
    abstract protected function quickEditConfig();

    /**
     * 快速编辑单个字段。
     *
     * @param Request $request
     * @return \Dou\Core\Web\Http\JsonResponse
     */
    public function quickEdit(Request $request)
    {
        try {
            $data = app(QuickEditService::class)->save(
                $this->quickEditConfig(),
                (int) $request->post('id'),
                (string) $request->post('field'),
                $request->post('value'),
                (string) $request->routeModule()
            );
        } catch (DomainException $e) {
            return ApiResponse::error(ApiCodes::INVALID_PARAMS, $e->getMessage());
        }

        return ApiResponse::success($data, lang('quick_edit_success'));
    }
}

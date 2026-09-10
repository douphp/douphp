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
use Dou\Admin\Service\Ai\KeyService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\ApiResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 密钥：仅保留 reset（AJAX 即时重置失败计数）。
 *
 * 密钥的增删改已收归模型编辑表单统一保存（ModelController::store/update），
 * 独立列表/编辑页已废弃。
 */
class KeyController extends BaseController
{
    /** @var KeyService */
    private $keyService;

    /**
     * @param KeyService $keyService
     */
    public function __construct(KeyService $keyService)
    {
        $this->keyService = $keyService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'ai',
            'submenu' => 'ai_model',
        );
    }

    /**
     * 重置密钥失败计数（AJAX，模型编辑页密钥组操作列）。
     *
     * @param Request $request
     * @return Response
     */
    public function reset(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai.model'));
        }

        $this->keyService->resetKey($id);

        if ($request->wantsJson()) {
            $row = $this->keyService->getFormattedKey($id);
            return ApiResponse::success(array('id' => $id, 'row' => $row), lang('ai_key_reset_succes'));
        }

        return redirect(route('admin.ai.model'))
            ->with('success', lang('ai_key_reset_succes'));
    }
}

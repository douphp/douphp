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
use Dou\Admin\Service\Ai\AiLogService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 使用日志（route=ai_log/...）
 */
class AiLogController extends BaseController
{
    /** @var AiLogService */
    private $aiLogService;

    /**
     * @param AiLogService $aiLogService
     */
    public function __construct(AiLogService $aiLogService)
    {
        $this->aiLogService = $aiLogService;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'ai',
            'submenu' => 'ai_log',
        );
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $req = $request->all();
        $bundle = $this->aiLogService->getAdminIndexPageData($req);

        return $this->view('ai_log.htm', [
            'ur_here' => lang('ai_log'),
            'rec' => 'default',
            'req' => $req,
            'provider_list' => $bundle['provider_list'],
            'model_list' => $bundle['model_list'],
            'log_list' => $bundle['log_list'],
            'pager' => $bundle['pager'],
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $bundle = $this->aiLogService->getViewBundle($request->integer('id', 0));
        if (!$bundle) {
            throw new DomainException(lang('illegal'), route('admin.ai.log'));
        }

        return $this->view('ai_log.htm', [
            'ur_here' => lang('ai_log_show'),
            'page_actions' => array(
                array('href' => route('admin.ai.log'), 'text' => lang('ai_log_list'), 'style' => ''),
            ),
            'rec' => 'show',
            'log' => $bundle['log'],
        ]);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.ai.log'));
        }

        $result = $this->aiLogService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $result = $this->aiLogService->action($request->post());

        return redirect($result['back_url'])->with('success', $result['message']);
    }
}

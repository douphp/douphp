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

namespace Dou\Admin\Controller\Aigc;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Service\Aigc\UsageLogService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员 AI 使用日志（route=aigc/usage_log/...）
 */
class UsageLogController extends BaseController
{
    /** @var UsageLogService */
    private $usageLogService;

    /**
     * @param UsageLogService $usageLogService
     */
    public function __construct(UsageLogService $usageLogService)
    {
        $this->usageLogService = $usageLogService;
    }

    /**
     * 日志列表（route=aigc/usage_log）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $req = $request->all();
        $bundle = $this->usageLogService->getAdminIndexPageData($req);

        return $this->view('aigc_usage_log.htm', [
            'ur_here' => lang('aigc_usage_log'),
            'rec' => 'default',
            'req' => $req,
            'log_list' => $bundle['log_list'],
            'pager' => $bundle['pager'],
            'provider_list' => $bundle['provider_list'],
            'model_list' => $bundle['model_list'],
            'app_options' => $bundle['app_options'],
            'user_not_found' => $bundle['user_not_found'],
        ]);
    }

    /**
     * 详情页（route=aigc/usage_log/show）
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $bundle = $this->usageLogService->getViewBundle($request->integer('id', 0));
        if (!$bundle) {
            throw new DomainException(lang('illegal'), route('admin.aigc.usage_log'));
        }

        return $this->view('aigc_usage_log.htm', [
            'ur_here' => lang('aigc_usage_log_show'),
            'page_actions' => array(
                array('href' => route('admin.aigc.usage_log'), 'text' => lang('aigc_usage_log_list'), 'style' => ''),
            ),
            'rec' => 'show',
            'log' => $bundle['log'],
        ]);
    }

    /**
     * 删除（route=aigc/usage_log/delete）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.usage_log'));
        }

        $result = $this->usageLogService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * 批量操作（route=aigc/usage_log/action）
     *
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $result = $this->usageLogService->action($request->post());

        return redirect($result['back_url'])->with('success', $result['message']);
    }
}

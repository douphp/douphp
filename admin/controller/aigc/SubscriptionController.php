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
use Dou\Admin\Service\Aigc\SubscriptionService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会员订阅（route=aigc/subscription/...）
 */
class SubscriptionController extends BaseController
{
    /** @var SubscriptionService */
    private $subscriptionService;

    /**
     * @param SubscriptionService $subscriptionService
     */
    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
    }

    /**
     * 订阅列表（route=chat/subscription）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $req = $request->all();
        $bundle = $this->subscriptionService->getAdminIndexPageData($req);

        return $this->view('aigc_subscription.htm', [
            'ur_here' => lang('aigc_subscription'),
            'rec' => 'default',
            'req' => $req,
            'package_list' => $bundle['package_list'],
            'subscription_list' => $bundle['subscription_list'],
            'pager' => $bundle['pager'],
        ]);
    }

    /**
     * 详情页（route=chat/subscription/show）
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $bundle = $this->subscriptionService->getViewBundle($request->integer('id', 0));
        if (!$bundle) {
            throw new DomainException(lang('illegal'), route('admin.aigc.subscription'));
        }

        return $this->view('aigc_subscription.htm', [
            'ur_here' => lang('aigc_subscription_show'),
            'page_actions' => array(
                array('href' => route('admin.aigc.subscription'), 'text' => lang('aigc_subscription_list'), 'style' => ''),
            ),
            'rec' => 'show',
            'subscription' => $bundle['subscription'],
        ]);
    }

    /**
     * 删除（route=chat/subscription/delete）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.subscription'));
        }

        $result = $this->subscriptionService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * 批量操作（route=chat/subscription/action）
     *
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $result = $this->subscriptionService->action($request->post());

        return redirect($result['back_url'])->with('success', $result['message']);
    }
}

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
use Dou\Admin\Service\Aigc\SessionService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台会话管理（route=aigc/session/...，只读运营视图 + 关闭 / 删除）
 */
class SessionController extends BaseController
{
    /** @var SessionService */
    private $sessionService;

    /**
     * @param SessionService $sessionService
     */
    public function __construct(SessionService $sessionService)
    {
        $this->sessionService = $sessionService;
    }

    /**
     * 会话列表（route=aigc/session）
     *
     * @param Request $request
     * @return Response
     */
    public function index(Request $request)
    {
        $req = $request->all();
        $bundle = $this->sessionService->getAdminIndexPageData($req);

        return $this->view('aigc_session.htm', [
            'ur_here' => lang('aigc_session'),
            'rec' => 'default',
            'req' => $req,
            'session_list' => $bundle['session_list'],
            'pager' => $bundle['pager'],
            'app_options' => $bundle['app_options'],
            'user_not_found' => $bundle['user_not_found'],
        ]);
    }

    /**
     * 会话详情（route=aigc/session/show）
     *
     * @param Request $request
     * @return Response
     */
    public function show(Request $request)
    {
        $bundle = $this->sessionService->getViewBundle(
            $request->integer('id', 0),
            $request->integer('before_id', 0)
        );
        if (!$bundle) {
            throw new DomainException(lang('illegal'), route('admin.aigc.session'));
        }

        return $this->view('aigc_session.htm', [
            'ur_here' => lang('aigc_session_show'),
            'page_actions' => array(
                array('href' => route('admin.aigc.session'), 'text' => lang('aigc_session_list'), 'style' => ''),
            ),
            'rec' => 'show',
            'session' => $bundle['session'],
            'message_list' => $bundle['message_list'],
            'has_more' => $bundle['has_more'],
            'oldest_id' => $bundle['oldest_id'],
            'before_id' => $bundle['before_id'],
        ]);
    }

    /**
     * 关闭会话（route=aigc/session/close）
     *
     * @param Request $request
     * @return Response
     */
    public function close(Request $request)
    {
        $id = $request->integer('id', 0);
        $this->sessionService->close($id, (int) auth('admin')->id());

        return redirect(route('admin.aigc.session.show', array('id' => $id)))
            ->with('success', lang('aigc_session_close_succes'));
    }

    /**
     * 删除（route=aigc/session/delete）
     *
     * @param Request $request
     * @return Response
     */
    public function destroy(Request $request)
    {
        $id = $request->integer('id', 0);
        if ($id <= 0) {
            throw new DomainException(lang('illegal'), route('admin.aigc.session'));
        }

        $result = $this->sessionService->delete($id, $request->post());
        return $this->respondDeleteResult($result);
    }

    /**
     * 批量操作（route=aigc/session/action）
     *
     * @param Request $request
     * @return Response
     */
    public function action(Request $request)
    {
        $result = $this->sessionService->action($request->post());

        return redirect($result['back_url'])->with('success', $result['message']);
    }
}

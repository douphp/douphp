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

namespace Dou\Admin\Controller\Login;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Request\Login\LoginFormRequest;
use Dou\Admin\Request\Login\PasswordResetFormRequest;
use Dou\Admin\Service\Login\AdminLoginFlow;
use Dou\Admin\Service\Login\PasswordResetService;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台登录
 */
class LoginController extends BaseController
{
    /** @var AdminLoginFlow */
    private $loginFlow;

    /** @var PasswordResetService */
    private $passwordReset;

    /**
     * @param AdminLoginFlow $loginFlow
     * @param PasswordResetService $passwordReset
     */
    public function __construct(AdminLoginFlow $loginFlow, PasswordResetService $passwordReset)
    {
        $this->loginFlow = $loginFlow;
        $this->passwordReset = $passwordReset;
    }

    /**
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'login',
        );
    }

    /**
     * 登录页
     *
     * @return Response
     */
    public function index()
    {
        return $this->view('login.htm', [
            'root_url' => ROOT_URL,
            'rec' => 'default',
            'page_title' => lang('login'),
        ]);
    }

    /**
     * 登录提交
     *
     * @param LoginFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function post(LoginFormRequest $formRequest, Request $request)
    {
        try {
            $data = $formRequest->validated();
        } catch (DomainException $e) {
            return message()->respond(lang('login_input_wrong'), route('admin.login'), 'out');
        }

        $this->loginFlow->handle($data, (string) $request->ip());
    }

    /**
     * @return void
     */
    public function logout()
    {
        $this->loginFlow->logout();
    }

    /**
     * 找回密码表单 / 重置链接落地
     *
     * @param Request $request
     * @return Response
     */
    public function passwordReset(Request $request)
    {
        $adminId = $request->integer('uid', 0);
        $codeInput = (string) $request->input('code', '');
        $code = preg_match('/^[a-zA-Z0-9]+$/', $codeInput) ? $codeInput : '';
        $data = $this->passwordReset->buildPasswordResetData($adminId, $code);

        if ($data === null) {
            return message()->respond(lang('login_password_reset_fail'), route('admin.login.password_reset'), 'out');
        }

        return $this->view('login.htm', [
            'token' => csrf()->generate('password_reset'),
            'action' => $data['action'],
            'root_url' => ROOT_URL,
            'rec' => 'password_reset',
            'page_title' => lang('login_password_reset'),
            'admin_id' => ($data['action'] === 'reset') ? $data['admin_id'] : '',
            'code' => ($data['action'] === 'reset') ? $data['code'] : '',
        ]);
    }

    /**
     * 找回密码提交
     *
     * @param PasswordResetFormRequest $formRequest
     * @return Response
     */
    public function passwordResetPost(PasswordResetFormRequest $formRequest)
    {
        try {
            $data = $formRequest->validated();
        } catch (DomainException $e) {
            return $this->handlePasswordResetValidationFailure();
        }

        $result = $this->passwordReset->passwordResetPost($data);

        return message()->respond(
            $result['message'],
            $result['back_url'],
            isset($result['out']) ? $result['out'] : '',
            isset($result['timeout']) ? $result['timeout'] : ''
        );
    }

    /**
     * 将 Request 基础校验失败映射回登录页原有提示体验。
     *
     * @return Response|null
     */
    private function handlePasswordResetValidationFailure()
    {
        return message()->respond(lang('login_password_reset_fail'), route('admin.login.password_reset'), 'out');
    }
}

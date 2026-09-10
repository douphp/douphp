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

namespace Dou\Admin\Request\Login;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台找回密码 / 重置密码表单请求。
 *
 * action 为空时按 default 处理，与 login.htm 中找回密码表单的现有字段保持一致。
 */
class PasswordResetFormRequest extends FormRequest
{
    /**
     * 补齐默认 action，保证 validated() 返回稳定分支字段。
     *
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();
        $data['action'] = $this->currentAction($data);

        return $data;
    }

    /**
     * 按 action 分支定义校验与白名单。
     *
     * @return array
     */
    public function rules()
    {
        $action = $this->currentAction(request() ? (array) request()->post() : array());

        if ($action === 'reset') {
            return array(
                'action' => 'required|in:default,reset',
                'admin_id' => 'required|integer|min_value:1',
                'code' => 'required|regex:/^[a-zA-Z0-9]+$/',
                'password' => 'required|password',
                'password_confirm' => 'required|same:password',
                'token' => 'required',
            );
        }

        return array(
            'action' => 'required|in:default,reset',
            'username' => 'required|admin_account',
            'email' => 'required|email',
            'token' => 'required',
        );
    }

    /**
     * 当前提交动作。
     *
     * @param array $data
     * @return string
     */
    private function currentAction(array $data)
    {
        $action = isset($data['action']) ? trim((string) $data['action']) : '';
        if ($action === 'reset') {
            return 'reset';
        }

        return 'default';
    }
}

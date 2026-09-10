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
 * 后台登录表单请求。
 *
 * rules() 同时定义基础格式校验与 AdminLoginFlow::handle() 可接收字段白名单。
 */
class LoginFormRequest extends FormRequest
{
    /**
     * 登录提交规则。
     *
     * @return array
     */
    public function rules()
    {
        return array(
            'username' => 'required|admin_account',
            'password' => 'required',
            'captcha' => '',
            'remember' => 'boolean',
        );
    }
}

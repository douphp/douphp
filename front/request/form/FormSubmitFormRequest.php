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

namespace Dou\Front\Request\Form;

use Dou\Front\Request\BaseFormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表单提交（route=form/submit）。
 *
 * 只声明与表单定义无关的基础规则；动态字段的必填 / 类型 / 选项 / 上传校验由
 * FormService::processSubmit 依 dou_form_field 驱动，再以 errors map 回传。
 */
class FormSubmitFormRequest extends BaseFormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'form_id' => 'required|integer',
            'token' => 'required',
            'captcha' => 'max:10',
        );
    }
}

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

namespace Dou\Front\Request;

use Dou\Core\Facade\DB;
use Dou\Core\Web\Http\FormRequest;
use Dou\Core\Web\Validation\Validator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台 FormRequest 基类。
 *
 * 与 admin/api 的「按字段顺序首错即抛」语义不同，前台单次 POST 协议要求一次返回所有
 * 字段错误，由 dou.form.js 写回各字段的错误位；故所有前台 FormRequest 子类继承本类，
 * `validated()` 统一以 `collectAll=true` 调 Validator，把 errors 数组带回 DomainException。
 */
abstract class BaseFormRequest extends FormRequest
{
    /**
     * 收集全部字段校验错误，便于单次 POST 一次返回 errors。
     *
     * @return array
     */
    public function validated()
    {
        $rules = (array) $this->rules();
        $data = $this->validationData();

        $validator = new Validator(
            DB::getFacadeRoot(),
            lang_all(),
            request()->routeModule()
        );
        $validator->validate($data, $rules, array(), true);

        if (empty($rules)) {
            return array();
        }

        return array_intersect_key($data, $rules);
    }
}

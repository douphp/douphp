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

namespace Dou\Admin\Request\Tool;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\FormRequest;
use Dou\Core\Web\Validation\Validator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台工具模块表单请求（scene=store：编辑器网址批量替换）。
 *
 * rules() 定义校验与 validated() 白名单；
 * 校验失败抛出 DomainException，store 场景附带返回编辑器网址替换页。
 */
class ToolFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        if ($this->scene !== 'store') {
            return array();
        }

        return array(
            'old_url' => 'required',
            'new_url' => 'required',
        );
    }

    /**
     * @return array
     */
    public function validated()
    {
        $rules = (array) $this->rules();
        $data = $this->validationData();

        $messages = array();
        if ($this->scene === 'store') {
            $messages['old_url.required'] = lang('tool_replace_url_old_cue');
            $messages['new_url.required'] = lang('tool_replace_url_new_cue');
        }

        try {
            $validator = new Validator(
                DB::getFacadeRoot(),
                lang_all(),
                request()->routeModule()
            );
            $validator->validate($data, $rules, $messages);
        } catch (DomainException $e) {
            if ($this->scene === 'store' && $e->getBackUrl() === '') {
                throw new DomainException(
                    $e->getMessage(),
                    route('admin.tool.replace_url'),
                    $e->getTimer(),
                    $e->getConfirmUrl(),
                    $e->hasErrors() ? $e->getErrors() : array()
                );
            }
            throw $e;
        }

        if (empty($rules)) {
            return array();
        }

        return array_intersect_key($data, $rules);
    }
}

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
 * Release Date: 2026-09-09
 */

namespace Dou\Admin\Request\Ai;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 翻译请求校验。
 */
class GenerateTranslateFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'app_id' => 'required|integer|min_value:1',
            'field' => 'required|max:100',
            'source_text' => 'required|max:20000',
            'target_lang' => 'required|max:50',
            'target_lang_name' => 'max:100',
        );
    }
}

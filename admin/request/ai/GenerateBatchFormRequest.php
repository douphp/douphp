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
 * AI 批量生成请求校验。
 */
class GenerateBatchFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'app_id' => 'required|integer|min_value:1',
            'prompt' => 'max:4000',
            'count' => 'required|integer|between_value:1,50',
            'category_id' => 'integer|min_value:0',
            'parent_id' => 'integer|min_value:0',
        );
    }
}

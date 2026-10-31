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

namespace Dou\Admin\Request\Language;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 原生字段抽屉图片草稿上传/释放请求。
 */
class LanguageDraftRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'module' => 'required|alpha_dash',
            'slug' => 'alpha_dash',
            'number' => '',
            'token' => '',
            // 抽屉所属语言包：非空时图片落入 images/{pack}/ 目录
            'language_pack' => 'alpha_dash',
        );
    }
}

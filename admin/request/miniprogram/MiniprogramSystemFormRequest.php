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

namespace Dou\Admin\Request\Miniprogram;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 小程序系统参数（仅白名单字段进入 validated）
 *
 * 与 MiniprogramService::ensureMiniprogramParameters 插入的 name 集合保持一致。
 */
class MiniprogramSystemFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'miniprogram_appid' => 'max:500',
            'miniprogram_appsecret' => 'max:500',
            'miniprogram_pay_mch_id' => 'max:500',
            'miniprogram_pay_key' => 'max:500',
            'miniprogram_domain' => 'max:500',
        );
    }
}

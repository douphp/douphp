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

namespace Dou\Admin\Request\Cloud;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 云端账号保存表单（accountPost 场景）。
 *
 * 字段白名单：cloud_user / cloud_password；
 * 邮箱或手机号格式校验（OR 逻辑）保留在 {@see \Dou\Admin\Service\Cloud\CloudService::saveCloudAccount()}
 * 中进行，本类只负责字段过滤与必填校验。
 */
class AccountFormRequest extends FormRequest
{
    /**
     * @return array
     */
    public function rules()
    {
        return array(
            'cloud_user' => 'required|max:100',
            'cloud_password' => 'required|max:60',
        );
    }
}

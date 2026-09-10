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

namespace Dou\Admin\Request\Manager;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 管理员账号表单请求（store / update 场景）。
 *
 * rules() 同时定义校验与 validated() 白名单；
 * DEFINED 时 action_list 非空等业务规则在 ManagerService。
 */
class ManagerFormRequest extends FormRequest
{
    /**
     * 合并校验数据（update 时 id 可能在查询串，此处防御性合并）。
     *
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();

        if ($this->scene === 'update') {
            if (!isset($data['id']) && request()) {
                $data['id'] = request()->integer('id', 0);
            }
            if (isset($data['id'])) {
                $data['admin_id'] = $data['id'];
            }
        }

        return $data;
    }

    /**
     * 输入规则（请求层）；与 Manager::$fillable 分工见 ArticleFormRequest 注释。
     *
     * @return array
     */
    public function rules()
    {
        if ($this->scene === 'update') {
            return array(
                'id' => 'required|integer|min_value:1',
                'username' => 'required|admin_account|unique:admin,username,admin_id',
                'email' => 'email|unique:admin,email,admin_id',
                'old_password' => '',
                'password' => 'password|confirmed',
                'password_confirm' => '',
                'action' => 'in:ADMIN,ALL,DEFINED',
                'action_list' => 'array',
            );
        }

        return array(
            'username' => 'required|admin_account|unique:admin,username',
            'email' => 'email|unique:admin,email',
            'password' => 'required|password|confirmed',
            'password_confirm' => '',
            'action' => 'required|in:ADMIN,ALL,DEFINED',
            'action_list' => 'array',
        );
    }
}

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

namespace Dou\Admin\Request\Module;

use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 模块卸载 POST 校验（scene=destroy，与 ModuleController::destroy 对应）。
 *
 * 确认表单仅 POST confirm，extend_id/token 在 action URL 查询串上，故合并 GET。
 */
class ModuleUninstallFormRequest extends FormRequest
{
    /**
     * 合并 POST 与查询串上的 extend_id（及可选 token 不参与白名单）。
     *
     * @return array
     */
    protected function validationData()
    {
        $data = parent::validationData();

        if (request() !== null) {
            $extendId = trim((string) request()->input('extend_id', ''));
            if ($extendId !== '') {
                $data['extend_id'] = $extendId;
            }
        }

        return $data;
    }

    /**
     * @return array
     */
    public function rules()
    {
        if ($this->scene === 'destroy') {
            return array(
                'extend_id' => 'required|regex:/^[A-Za-z0-9-_.]+$/',
            );
        }

        return array();
    }

    /**
     * 校验失败时补齐返回链接，便于入口 `$context->message->respond()` 跳转回卸载列表。
     *
     * @return array
     */
    public function validated()
    {
        try {
            return parent::validated();
        } catch (DomainException $e) {
            if ($e->getBackUrl() !== '') {
                throw $e;
            }

            throw new DomainException($e->getMessage(), route('admin.module.uninstall'));
        }
    }
}

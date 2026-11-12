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

namespace Dou\Admin\Service\Common;

use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 列表单元格快速编辑通用引擎。
 *
 * 本类不维护任何模块清单：模型类与可编辑字段由各模块控制器通过 quickEditConfig() 声明，
 * 这里只负责类型校验、写入、日志与展示值格式化。
 */
class QuickEditService extends BaseService
{
    /**
     * 保存单个快速编辑字段。
     *
     * @param array $config 控制器声明：array('model' => 模型类, 'fields' => array(字段 => 定义))
     * @param int $id
     * @param string $field
     * @param mixed $value
     * @param string $module 当前路由模块名，仅用于后台操作日志
     * @return array
     * @throws DomainException
     */
    public function save(array $config, $id, $field, $value, $module = '')
    {
        $modelClass = isset($config['model']) ? (string) $config['model'] : '';
        $fields = isset($config['fields']) && is_array($config['fields']) ? $config['fields'] : array();
        $field = is_string($field) ? trim($field) : '';

        if ($modelClass === '' || !class_exists($modelClass) || $field === '' || !isset($fields[$field])) {
            throw new DomainException(lang('illegal'));
        }

        $id = (int) $id;
        if ($id <= 0) {
            throw new DomainException(lang('item_not_exist'));
        }

        if ($value !== null && !is_scalar($value)) {
            throw new DomainException(lang('illegal'));
        }
        $value = (string) $value;

        $definition = is_array($fields[$field]) ? $fields[$field] : array();
        $type = isset($definition['type']) ? (string) $definition['type'] : 'text';

        $model = $modelClass::find($id);
        if (!$model) {
            throw new DomainException(lang('item_not_exist'));
        }

        if ($type === 'price') {
            $value = trim($value);
            if (!preg_match('/^\d+(\.\d{1,2})?$/', $value) || (float) $value > 99999999.99) {
                throw new DomainException(lang('quick_edit_price_invalid'));
            }
            $stored = number_format((float) $value, 2, '.', '');
            $display = !empty($definition['blank_when_zero']) && (float) $stored <= 0
                ? ''
                : Util::formatPrice($stored);
        } elseif ($type === 'int') {
            $value = trim($value);
            $max = isset($definition['max']) ? (int) $definition['max'] : 65535;
            if (!preg_match('/^\d+$/', $value) || (int) $value > $max) {
                throw new DomainException(lang('quick_edit_number_invalid'));
            }
            $stored = (int) $value;
            $display = (string) $stored;
        } else {
            $value = trim($value);
            $max = isset($definition['max']) ? (int) $definition['max'] : 150;
            if ($value === '') {
                throw new DomainException(lang('quick_edit_title_required'));
            }
            if (mb_strlen($value, 'UTF-8') > $max) {
                throw new DomainException(lang('quick_edit_title_too_long'));
            }
            $stored = $value;
            $display = $value;
        }

        $model->fill(array($field => $stored), 'update');
        if (!$model->save()) {
            throw new DomainException(lang('quick_edit_save_failed'));
        }

        audit()->writeAdminLog(
            (int) auth('admin')->id(),
            AdminLogAction::UPDATE,
            1,
            $field . '#' . $id . '=' . mb_substr((string) $stored, 0, 50, 'UTF-8'),
            (string) $module
        );

        return array(
            'id' => $id,
            'field' => $field,
            'value' => $stored,
            'display' => $display,
        );
    }
}

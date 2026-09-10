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

namespace Dou\Admin\Service\Parameter;

use Dou\Admin\Model\Parameter\Parameter;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;
use Dou\Core\Support\Num;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 自定义参数（parameter 表）
 *
 * 持久化经 Parameter；校验失败抛出 DomainException。
 */
class ParameterService extends BaseService
{
    public function __construct()
    {
    }

    /**
     * 列表页数据。
     *
     * @param string $group 已在控制器侧规范（空串表示全部）
     * @return array
     */
    public function buildParameterListData($group)
    {
        return array(
            'parameter_list' => Parameter::fetchListForIndex($group),
        );
    }

    /**
     * 新增表单默认值。
     *
     * @param string $group
     * @return array
     */
    public function buildParameterDefaultData($group)
    {
        return array(
            'parameter' => array(
                'name' => '',
                'lang' => '',
                'cue' => '',
                'sort' => '',
                'id' => '',
                'value' => '',
            ),
            'group' => $group,
        );
    }

    /**
     * 编辑表单数据。
     *
     * @param int $id
     * @return array|null
     */
    public function buildParameterEditData($id)
    {
        $id = Num::toIntOrZero($id);
        if ($id < 1) {
            return null;
        }

        $parameterModel = Parameter::find($id);
        $parameter = $parameterModel ? $parameterModel->getAttributes() : null;
        if (!$parameter || !is_array($parameter)) {
            return null;
        }

        $parameterHelp = lang('parameter_help');
        $help = preg_replace('/参数别名/Ums', $parameter['name'], $parameterHelp);

        return array(
            'parameter' => $parameter,
            'parameter_help' => $help,
        );
    }

    /**
     * 参数值设置页列表。
     *
     * @param string $group 已在控制器侧规范（空串表示全部）
     * @return array
     */
    public function buildParameterSetData($group)
    {
        return array(
            'parameter_list' => Parameter::fetchListForSet($group),
        );
    }

    /**
     * 新增参数定义。
     *
     * @param array $data validated
     * @return array{back_url:string,new_id:int} 新增记录信息：成功跳转地址与主键
     */
    public function insert(array $data)
    {
        $group = $this->resolveStoredGroup(isset($data['group']) ? $data['group'] : '');

        if (Parameter::existsByName($data['name'])) {
            throw new DomainException(lang('parameter_name') . lang('is_exist'), route('admin.parameter'));
        }

        $model = Parameter::create(array(
            'name' => $data['name'],
            'lang' => $data['lang'],
            'cue' => isset($data['cue']) ? $data['cue'] : '',
            'group' => $group,
            'sort' => Num::toIntOrZero(isset($data['sort']) ? $data['sort'] : 0),
        ));
        $newId = (int) $model->getKey();

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CREATE, 1, (string) $data['name']);

        $backUrl = $group === 'customer' ? route('admin.setting') : route('admin.parameter');
        return array('back_url' => $backUrl, 'new_id' => $newId);
    }

    /**
     * 更新参数定义。
     *
     * @param array $data validated（含 id）
     * @return void
     */
    public function update(array $data)
    {
        $id = Num::toIntOrZero(isset($data['id']) ? $data['id'] : 0);
        if ($id < 1) {
            throw new DomainException(lang('illegal'), route('admin.parameter'));
        }

        $model = Parameter::find($id);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.parameter'));
        }

        if (Parameter::existsByNameExcept($data['name'], $id)) {
            throw new DomainException(lang('parameter_name') . lang('is_exist'), route('admin.parameter'));
        }

        $model->fill(array(
            'name' => $data['name'],
            'lang' => $data['lang'],
            'cue' => isset($data['cue']) ? $data['cue'] : '',
            'sort' => Num::toIntOrZero(isset($data['sort']) ? $data['sort'] : 0),
        ), 'update')->save();

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, (string) $data['name']);
    }

    /**
     * 批量保存参数值（仅处理已通过白名单校验的字段）。
     *
     * @param array $data validated
     * @return string 跳转地址
     */
    public function saveValues(array $data)
    {
        $groupRaw = isset($data['group']) ? (string) $data['group'] : '';
        $groupKey = ($groupRaw !== '' && Check::letter($groupRaw)) ? $groupRaw : '';

        foreach ($data as $name => $value) {
            if ($name === 'token' || $name === 'group') {
                continue;
            }
            Parameter::updateValueByName($name, $value);
        }

        $backUrl = $this->resolveSetPostBackUrl($groupKey);

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'parameter_set');

        return $backUrl;
    }

    /**
     * 删除（首次确认或二次提交）。
     *
     * @param int $id
     * @param array $data validated
     * @return array
     */
    public function deleteByIdOrConfirm($id, array $data)
    {
        $parameter = Parameter::findMetaForDelete($id);
        if (!$parameter) {
            throw new DomainException(lang('illegal'), route('admin.parameter'));
        }

        if (!empty($parameter['lock'])) {
            throw new DomainException(lang('parameter_lock'), route('admin.parameter'), '3');
        }

        if (isset($data['confirm'])) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $parameter['name']);
            Parameter::deleteUnlocked($id);

            return array(
                'redirect' => true,
                'back_url' => route('admin.parameter'),
                'message' => '',
            );
        }

        $delCheck = preg_replace('/d%/Ums', $parameter['name'], lang('del_check'));

        return array(
            'redirect' => false,
            'message' => $delCheck,
            'back_url' => route('admin.parameter'),
            'timeout' => '30',
            'confirm_url' => route('admin.parameter.destroy', array('id' => (int) $id)),
        );
    }

    /**
     * @param mixed $groupRaw
     * @return string
     */
    private function resolveStoredGroup($groupRaw)
    {
        if ($groupRaw !== '' && $groupRaw !== null && Check::letter((string) $groupRaw)) {
            return (string) $groupRaw;
        }

        return 'system';
    }

    /**
     * @param string $group 已规范的分组键（空串表示未指向具体后台脚本）
     * @return string
     */
    private function resolveSetPostBackUrl($group)
    {
        if ($group !== '' && file_exists(ADMIN_PATH . $group . '.php')) {
            return ADMIN_PATH . $group . '.php';
        }

        return route('admin.index');
    }
}

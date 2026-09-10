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

namespace Dou\Admin\Service\Module;

use Dou\Admin\Facade\Cloud;
use Dou\Admin\Model\Module\Module;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台「模块扩展」业务服务。
 */
class ModuleService extends BaseService
{
    /** @var Cloud */
    private $cloud;

    /**
     * @param Cloud $cloud
     */
    public function __construct(Cloud $cloud)
    {
        $this->cloud = $cloud;
    }

    /**
     * 在线安装页数据：会话 system_sign 与云端 localsite 载荷。
     *
     * @param string $systemSign 查询参数 system_sign（空则清除会话）。
     * @return array localsite
     */
    public function buildModuleIndexData($systemSign)
    {
        $sign = trim((string) $systemSign);
        if (Check::rec($sign)) {
            Session::set('_system_sign', $sign);
        } else {
            Session::del('_system_sign');
        }

        return array(
            'localsite' => $this->cloud->localSitePayload('module'),
        );
    }

    /**
     * 本地安装页：storage/work/install 下待装 zip 列表。
     *
     * @return array token、install_list
     */
    public function buildModuleInstallLocalData()
    {
        $cacheDir = STORAGE_PATH . 'work/install/';
        $installList = array();
        $zipfileList = glob($cacheDir . '*.zip');
        if (is_array($zipfileList)) {
            foreach ($zipfileList as $zipfile) {
                $installList[] = preg_replace('/.zip/i', '', basename($zipfile));
            }
        }

        return array(
            'install_list' => $installList,
        );
    }

    /**
     * 卸载列表页数据。
     *
     * @return array token、uninstall_list
     */
    public function buildModuleUninstallData()
    {
        return array(
            'uninstall_list' => $this->buildUninstallIdList(),
        );
    }

    /**
     * 卸载二次确认：返回展示确认表单所需的消息载荷，由 Controller 通过 `respondDeleteResult($result)` 走 dou_msg.htm 渲染。
     *
     * @param string $extendId 扩展标识
     * @param string $token CSRF Token（写入确认表单 action URL）
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 扩展标识非法时抛出
     */
    public function buildUninstallConfirm($extendId, $token)
    {
        if (!Check::extendId($extendId)) {
            throw new DomainException(lang('illegal'), route('admin.module.uninstall'));
        }

        $msg = lang('module_uninstall_check');
        $msg = preg_replace('/d%/Ums', $extendId, $msg);

        $confirmUrl = route('admin.module.destroy', array('token' => rawurlencode($token), 'extend_id' => rawurlencode($extendId)));

        return array(
            'message' => $msg,
            'back_url' => route('admin.module.uninstall'),
            'timeout' => '30',
            'confirm_url' => $confirmUrl,
        );
    }

    /**
     * 执行卸载（POST 确认后）。
     *
     * @param string $extendId 已校验的扩展标识
     * @return void
     * @throws DomainException
     */
    public function performUninstall($extendId)
    {
        if (!Check::extendId($extendId)) {
            throw new DomainException(lang('module_uninstall_wrong'), route('admin.module.uninstall'));
        }

        $uninstallList = $this->buildUninstallIdList();
        if (!in_array($extendId, $uninstallList)) {
            throw new DomainException(lang('module_uninstall_wrong'), route('admin.module.uninstall'));
        }

        $numRows = Module::countRowsIfTableExists($extendId);
        if ($numRows > 0) {
            throw new DomainException(lang('module_uninstall_exist_data'), route('admin.module.uninstall'));
        }

        $moduleInstalledFile = STORAGE_PATH . 'installed/' . $extendId . '.installed.php';
        if (!file_exists($moduleInstalledFile)) {
            throw new DomainException(lang('module_uninstall_install_file_wrong'), route('admin.module.uninstall'));
        }

        $this->cloud->clearModule($extendId);
        $this->cloud->changeUpdateDate('module', $extendId, true);
        @unlink($moduleInstalledFile);

        if (audit()) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UNINSTALL, 1, (string) $extendId);
        }
    }

    /**
     * 合并配置中的已装模块与云端记录，得到可卸载标识列表。
     *
     * @return array
     */
    private function buildUninstallIdList()
    {
        $moduleList = (array) Config::get('module.all_module');

        $raw = Config::get('site.update_date', '');
        $updateDate = @unserialize($raw);
        if (!is_array($updateDate) || !isset($updateDate['module']) || !is_array($updateDate['module'])) {
            return $moduleList;
        }

        $installModule = array_keys($updateDate['module']);
        $diff = array_diff($installModule, $moduleList);

        return array_merge($moduleList, $diff);
    }
}

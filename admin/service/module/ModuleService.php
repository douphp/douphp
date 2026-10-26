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
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;
use Dou\Core\Support\FileHelper;

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
     * 按模块子分类为在线安装列表追加分组数据（仅模块页使用）。
     *
     * 分组名称与顺序优先取自云端 meta.categories（接口新增字段），其次退回
     * items[] 内的 category_name / category_slug；组内保持接口原始顺序（下载量倒序）。
     * 未分类模块（无分类归属，或直接挂在根分类如 module 上）不参与展示，items 同步
     * 收敛为可见集合，避免平铺分支又把未分类条目带出来。
     * 每条 item 追加 state（installed|update|install），供卡片做安装状态差异化。
     *
     * @param array<string, mixed>|null $cloudExtend fetchExtendList() 返回值
     * @return array<string, mixed>|null 追加 groups 后的同一结构
     */
    public function attachExtendGroups($cloudExtend)
    {
        if (!is_array($cloudExtend)) {
            return $cloudExtend;
        }

        $items = isset($cloudExtend['items']) && is_array($cloudExtend['items']) ? $cloudExtend['items'] : array();
        if (empty($items)) {
            $cloudExtend['groups'] = array();
            return $cloudExtend;
        }

        // 分类字典：slug => 名称；顺序来自 meta.categories，未列出的分类按首次出现顺序追加
        $names = array();
        $order = array();
        if (isset($cloudExtend['meta']['categories']) && is_array($cloudExtend['meta']['categories'])) {
            foreach ($cloudExtend['meta']['categories'] as $category) {
                if (!is_array($category) || !isset($category['slug'])) {
                    continue;
                }
                $slug = trim((string) $category['slug']);
                if ($slug === '') {
                    continue;
                }
                $order[] = $slug;
                $names[$slug] = isset($category['name']) ? trim((string) $category['name']) : '';
            }
        }

        $rootSlug = isset($cloudExtend['meta']['root']) ? trim((string) $cloudExtend['meta']['root']) : '';

        $buckets = array();
        $visible = array();
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $slug = isset($item['category_slug']) ? trim((string) $item['category_slug']) : '';
            $categoryId = isset($item['category_id']) ? (int) $item['category_id'] : 0;

            // 未分类：无分类归属、分类标识缺失，或直接挂在根分类（父级，如 module）上
            if ($categoryId <= 0 || $slug === '' || ($rootSlug !== '' && $slug === $rootSlug)) {
                continue;
            }

            $item['state'] = $this->resolveExtendState($item);
            $visible[] = $item;
            if (!isset($buckets[$slug])) {
                $buckets[$slug] = array();
                if (!in_array($slug, $order, true)) {
                    $order[] = $slug;
                }
                if (!isset($names[$slug]) || $names[$slug] === '') {
                    $names[$slug] = isset($item['category_name']) ? trim((string) $item['category_name']) : '';
                }
            }
            $buckets[$slug][] = $item;
        }

        $groups = array();
        foreach ($order as $slug) {
            if (empty($buckets[$slug])) {
                continue;
            }
            $name = isset($names[$slug]) && $names[$slug] !== '' ? $names[$slug] : $slug;
            $groups[] = array(
                'slug' => $slug,
                'name' => $name,
                'count' => count($buckets[$slug]),
                'items' => $buckets[$slug],
            );
        }

        $cloudExtend['groups'] = $groups;
        $cloudExtend['items'] = $visible;

        return $cloudExtend;
    }

    /**
     * 推导单条模块在本站的安装状态（仅模块页展示用）。
     *
     * 依据云端 action.kind：installed 为已装且最新，update / buy_then_update 为已装待更新；
     * vip_renew / vip_buy 同一 kind 兼用于「续费后更新」与「续费后安装」，据文案是否含「更新」区分。
     *
     * @param array<string, mixed> $item
     * @return string installed|update|install
     */
    private function resolveExtendState($item)
    {
        $kind = isset($item['action']['kind']) ? (string) $item['action']['kind'] : '';
        $label = isset($item['action']['label']) ? (string) $item['action']['label'] : '';

        if ($kind === 'installed') {
            return 'installed';
        }
        if ($kind === 'update' || $kind === 'buy_then_update') {
            return 'update';
        }
        if ($kind === 'vip_renew' || $kind === 'vip_buy') {
            return (strpos($label, '更新') !== false) ? 'update' : 'install';
        }

        return 'install';
    }

    /**
     * 本地安装页：storage/install/package 下待装 zip 列表。
     *
     * @return array token、install_list
     */
    public function buildModuleInstallLocalData()
    {
        $cacheDir = STORAGE_PATH . 'install/package/';
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
     * 列表项按「模块标识 + 后台语言包名称」输出，模板只消费这两个字段：名称取
     * `$_LANG[<module>]`（如 solution => 解决方案），缺失时回退模块标识（第三方模块
     * 未带模块名键时不至于空白）；与后台菜单 / 审计日志对模块名的取值口径一致。
     *
     * @return array token、uninstall_list
     */
    public function buildModuleUninstallData()
    {
        $uninstallList = array();
        foreach ($this->buildUninstallIdList() as $moduleId) {
            $uninstallList[] = array(
                'id' => $moduleId,
                'name' => $this->moduleLabel($moduleId),
            );
        }

        return array(
            'uninstall_list' => $uninstallList,
        );
    }

    /**
     * 模块标识的中文名称（后台语言包 `$_LANG[<module>]`），缺失时回退标识本身。
     *
     * @param string $moduleId 模块标识
     * @return string
     */
    private function moduleLabel($moduleId)
    {
        $moduleId = (string) $moduleId;
        if ($moduleId === '') {
            return '';
        }

        return lang($moduleId, $moduleId);
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
        $msg = preg_replace('/d%/Ums', $this->moduleLabel($extendId), $msg);

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

        $moduleInstalledFile = STORAGE_PATH . 'install/records/' . $extendId . '.installed.php';
        if (!file_exists($moduleInstalledFile)) {
            throw new DomainException(lang('module_uninstall_install_file_wrong'), route('admin.module.uninstall'));
        }

        $this->cloud->clearModule($extendId);
        $this->purgeModuleData($extendId);
        $this->cloud->changeUpdateDate('module', $extendId, true);
        @unlink($moduleInstalledFile);

        if (audit()) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UNINSTALL, 1, (string) $extendId);
        }
    }

    /**
     * 清理模块在参数表与默认运行期状态目录中的残留。
     *
     * 仅在真正卸载时调用；安装/更新失败回滚走 clearModule()，不会触发本方法。
     *
     * @param string $extendId
     * @return void
     */
    private function purgeModuleData($extendId)
    {
        try {
            DB::table('parameter')->where('group', $extendId)->delete();
        } catch (\Exception $e) {
            error_log('module parameter cleanup failed: ' . $extendId . ' ' . $e->getMessage());
        }

        $stateDir = STORAGE_PATH . 'state/' . $extendId;
        if (is_dir($stateDir)) {
            FileHelper::delDir($stateDir);
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

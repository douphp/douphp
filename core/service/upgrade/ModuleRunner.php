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
 * Release Date: 2026-10-25
 */

namespace Dou\Core\Service\Upgrade;

use Dou\Core\Facade\DB;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 模块升级执行器（核心单一来源）。
 *
 * 模块包的 _action/ 目录内：
 *   - manifest.php ：module / type / install / flags 安装清单（由 InstallService 读取，本类不处理）
 *   - upgrade.php  ：模块特有升级语句（仅升级时执行）
 *   - install.php  ：模块安装初始化（由 InstallService 在安装时执行，本类不处理）
 *   - resources/   ：可选资源，脚本用 $materialsRoot . 'resources/...' 引用
 *
 * 本类只负责通用编排：版本门卫 -> include upgrade.php -> 写版本账本 -> 清模板缓存；
 * 模块版本由云端下发的链接/install-resolve 提供，不再读取包内 version.php。
 * 执行完成后 _action/ 由 InstallService 整目录删除。
 */
class ModuleRunner
{
    /** @var string 站点根目录（结尾带 /） */
    private $rootPath;

    /** @var string 模块物料根 _action/（结尾带 /） */
    private $materialsRoot;

    /**
     * @param string $rootPath 站点根目录
     * @param string $materialsRoot 物料根目录；空串时取 $rootPath . '_action/'
     */
    public function __construct($rootPath, $materialsRoot = '')
    {
        $this->rootPath = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';
        $materialsRoot = (string) $materialsRoot;
        if ($materialsRoot === '') {
            $materialsRoot = $this->rootPath . '_action/';
        }
        $this->materialsRoot = rtrim(str_replace('\\', '/', $materialsRoot), '/') . '/';
    }

    /**
     * 执行模块升级。
     *
     * 版本由云端下发（链接参数或 install-resolve 响应），不再读取包内 version.php。
     * 失败约定：upgrade.php 抛异常向上传播；本类不吞错、不自动回滚（由调用方处理）。
     *
     * @param string $moduleId 模块标识（manifest module）
     * @param string $moduleVersion 模块版本（8 位 YYYYMMDD）
     * @return array array('status' => 'skipped'|'upgraded', 'module', 'from', 'to')
     */
    public function runUpgrade($moduleId = '', $moduleVersion = '')
    {
        @set_time_limit(0);
        @ignore_user_abort(true);

        $moduleId = trim((string) $moduleId);
        $moduleVersion = trim((string) $moduleVersion);

        if ($moduleId === '' || !preg_match('/^[a-z][a-z0-9_]*$/', $moduleId)) {
            throw new \RuntimeException('模块升级失败：缺少合法的模块标识，请通过后台在线更新。');
        }
        if ($moduleVersion === '') {
            throw new \RuntimeException('模块升级失败：缺少云端下发的模块版本，请通过后台在线更新。');
        }
        if (!preg_match('/^\d{8}$/', $moduleVersion)) {
            throw new \RuntimeException('模块升级失败：模块版本格式非法（' . $moduleVersion . '），请通过后台在线更新。');
        }

        // 旧模块脚本可能读取 $moduleMeta['min_core']，保留空占位以兼容外部模块。
        $moduleMeta = array(
            'module' => $moduleId,
            'version' => $moduleVersion,
            'min_core' => '',
        );

        $store = new ModuleVersionStore();
        $installedVersion = $store->get($moduleId);

        // 版本门卫：相等短路 / 降级拒绝（后者消息被 InstallService 白名单豁免，不触发破坏式回滚）
        if ($installedVersion !== '' && $installedVersion === $moduleVersion) {
            return array(
                'status' => 'skipped',
                'module' => $moduleId,
                'from' => $installedVersion,
                'to' => $moduleVersion,
            );
        }
        if ($installedVersion !== '' && strcmp($installedVersion, $moduleVersion) > 0) {
            throw new \RuntimeException($moduleId . ' 模块升级失败：安装包版本（' . $moduleVersion
                . '）低于已安装版本（' . $installedVersion . '），已拒绝执行降级，请使用更新的模块包。');
        }

        $payload = $this->materialsRoot . 'upgrade.php';
        if (is_file($payload)) {
            self::includePayload($payload, array(
                'prefix' => DB::getPrefix(),
                'moduleMeta' => $moduleMeta,
                'moduleId' => $moduleId,
                'moduleVersion' => $moduleVersion,
                'materialsRoot' => $this->materialsRoot,
            ));
        }

        // 版本落库放在载荷成功之后：载荷抛错时保持旧版本，便于重试与快照还原
        $store->record($moduleId, $moduleVersion);

        $cacheClear = new CacheClearService();
        $cacheClear->clearSiteCache($this->rootPath);

        return array(
            'status' => 'upgraded',
            'module' => $moduleId,
            'from' => $installedVersion,
            'to' => $moduleVersion,
        );
    }

    /**
     * 在隔离作用域内 include 升级脚本（静态方法不暴露 $this）。
     *
     * 注入变量：$prefix、$moduleMeta、$moduleId、$moduleVersion、$materialsRoot。
     *
     * @param string $file
     * @param array $vars
     * @return void
     */
    private static function includePayload($file, array $vars)
    {
        extract($vars, EXTR_SKIP);
        include $file;
    }
}

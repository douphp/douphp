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
use Dou\Core\Infra\Database\SchemaSync;

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
 *   - resources/sql/upgrade.sql ：升级期增量演示数据（只允许补齐/新增，由 SeedRunner 执行）
 *   - resources/images/         ：升级期补充素材，镜像到站点根 images/（目标已存在则跳过）
 *   - resources/                ：其它物料，脚本用 $materialsRoot . 'resources/...' 引用
 *
 * 本类只负责通用编排：版本门卫 -> SeedRunner（素材 / SQL / upgrade.php）-> 写版本账本 -> 清模板缓存；
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
     * @param string|null $standardFile 本次解包的结构标准，null 表示无需校验扩展字段
     * @return array 执行状态、模块、起止版本及是否补齐扩展字段
     */
    public function runUpgrade($moduleId = '', $moduleVersion = '', $standardFile = null)
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

        if ($installedVersion !== '' && strcmp($installedVersion, $moduleVersion) > 0) {
            throw new \RuntimeException($moduleId . ' 模块升级失败：安装包版本（' . $moduleVersion
                . '）低于已安装版本（' . $installedVersion . '），已拒绝执行降级，请使用更新的模块包。');
        }

        $schema = null;
        if ($standardFile !== null) {
            $schema = new SchemaSync(array('standards' => array($standardFile)));
            $table = $schema->getTableModel($moduleId);
            if ($schema->hasErrors() || !$table || !in_array('metadata', array_column($table['columns'], 'name'), true)) {
                throw new \RuntimeException($moduleId . ' 模块升级失败：包内标准 SQL 缺少可用的 ' . $moduleId . '.metadata 列定义。');
            }
        }

        // 同版本只核对表结构，避免重复执行升级种子和历史迁移。
        if ($installedVersion !== '' && $installedVersion === $moduleVersion) {
            $repaired = $schema !== null && $this->ensureMetadataColumn($schema, $moduleId);
            return array(
                'status' => 'skipped',
                'module' => $moduleId,
                'from' => $installedVersion,
                'to' => $moduleVersion,
                'metadata_repaired' => $repaired,
            );
        }

        // 升级载荷由核心 SeedRunner 统一编排：resources/images -> resources/sql/upgrade.sql -> upgrade.php
        // （升级期的数据库改动不可回滚，故 upgrade.sql 只允许补齐/新增）
        if (!class_exists('Dou\\Core\\Service\\Extension\\SeedRunner')) {
            throw new \RuntimeException($moduleId . ' 模块升级失败：缺少主系统扩展初始化引擎 core/service/extension/SeedRunner.php，请先升级主系统。');
        }

        $wrong = array();
        $runner = new \Dou\Core\Service\Extension\SeedRunner($this->rootPath, $this->materialsRoot, 'module', $moduleId);
        $runner->run('update', 'update', $wrong, array(
            'cloudId' => $moduleId,
            'moduleType' => '',
            'moduleMeta' => $moduleMeta,
            'moduleId' => $moduleId,
            'moduleVersion' => $moduleVersion,
        ));
        if ($wrong) {
            throw new \RuntimeException(implode('；', $wrong));
        }

        $repaired = $schema !== null && $this->ensureMetadataColumn($schema, $moduleId);

        // 版本落库放在载荷成功之后：载荷抛错时保持旧版本，便于重试与快照还原
        $store->record($moduleId, $moduleVersion);
        if ($schema !== null && $store->get($moduleId) !== $moduleVersion) {
            throw new \RuntimeException($moduleId . ' 模块升级失败：无法写入模块版本账本。');
        }

        $cacheClear = new CacheClearService();
        $cacheClear->clearSiteCache($this->rootPath);

        return array(
            'status' => 'upgraded',
            'module' => $moduleId,
            'from' => $installedVersion,
            'to' => $moduleVersion,
            'metadata_repaired' => $repaired,
        );
    }

    /**
     * 按模块标准补齐扩展字段，校验实际结构后才允许记账。
     *
     * @param SchemaSync $schema 模块结构标准
     * @param string $moduleId 模块主表名
     * @return bool 是否补列
     */
    private function ensureMetadataColumn(SchemaSync $schema, $moduleId)
    {
        if (!DB::tableExist($moduleId)) {
            throw new \RuntimeException($moduleId . ' 模块升级失败：找不到模块主表。');
        }
        if (DB::fieldExist($moduleId, 'metadata')) {
            return false;
        }

        $schema->addColumn($moduleId, 'metadata');
        if ($schema->hasErrors() || !DB::fieldExist($moduleId, 'metadata')) {
            throw new \RuntimeException($moduleId . ' 模块升级失败：无法补充 metadata 列。'
                . implode('；', $schema->getErrors()));
        }

        return true;
    }
}

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
 * Release Date: 2026-10-16
 */

namespace Dou\Core\Service\Upgrade;

use Dou\Core\Facade\DB;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 系统升级流水线执行器（双端单一来源）。
 *
 * 2026-10 由「upgrade/service/UpgradeRunnerService」与「_update/action.php 内联流水线」合并而来：
 * 云渠道（云更新载荷）与向导渠道共用本类，壳只负责兼容自举与上下文装配。
 *
 * 执行顺序（严格）：
 *   S0 环境守卫（set_time_limit / ignore_user_abort）
 *   S1 配置合并：Dou\Upgrade\Lib\ConfigMerge::run（升级包基准 x 站点现行文件）
 *   S2 版本链：VersionRegistry 发现待执行版本（ver >= current，含当前版本，重跑幂等）
 *   S3 结构同步引擎就绪（core/infra/database/SchemaSync，单一权威源；缺失即抛错终止）
 *   S4 dou_config 表重建：读旧值快照 -> 按标准结构完全重建 -> 导入默认行 -> 回灌 +
 *      保留旧站独有配置行（详见 SchemaSync::rebuildTableFromStandard）
 *   S5 配置早回灌（不含版本号与 update_date）——消除"纯默认配置"窗口；失败即抛错
 *   S6 update_date 早写安全网（库真值 ∪ boot 快照）——避免进程中断后"重试污染"
 *   S7 组装 upSql（回灌 + 版本号 + 一次性修正），版本号刻意留到流程末尾写
 *   S8 版本补丁链：按升序 include resources/patches/upgrade_*.php（字段/表改名必须早于结构同步）
 *   S9 标准结构同步 SchemaSync::syncStandards + fail-loud（核心表；模块表归各模块包）
 *   S10 upSql 收尾执行（fnExecute 失败即抛错）+ update_date 末尾复写（写后读回校验）
 *   S11 渠道收尾：版权 API（仅向导）-> 清缓存 -> 旧文件清理 -> 站点升级记录上报（仅向导）
 *
 * 注入到补丁 include 作用域的符号（runPhpPatches 的局部变量，include 时被继承）：
 *   - $up_sql   字符串变量，补丁可追加 SQL，Runner 在 include 结束后写回
 *   - $schema   SchemaSync 实例（规范化迁移 API：renameTable/renameColumn/addColumn/
 *               unixToDatetime/swapValue/replaceValue/nullifyZeroDates/ensureTable 等）
 *   - $_CFG     siteConfig（对标旧 $_CFG）
 *   - $prefix / $dbhost / $dbname / $dbuser / $dbpass 来自 config（或 1.x 兼容层）
 *
 * 数据库访问统一走 DB:: 门面（外层装配已把 Connection 绑定到容器），不向补丁注入 $db 变量。
 */
class Runner
{
    /** @var UpgradeContext */
    private $ctx;

    /** @var VersionRegistry */
    private $registry;

    /** @var ConfigUpdateService */
    private $configUpdate;

    /**
     * @param UpgradeContext $ctx
     * @param array          $options 扩展选项（合并进 ctx->options）
     */
    public function __construct(UpgradeContext $ctx, array $options = array())
    {
        $this->ctx = $ctx;
        if (!empty($options)) {
            $ctx->options = array_merge($ctx->options, $options);
        }
        $this->registry = new VersionRegistry($ctx->materialsRoot);
        $this->configUpdate = new ConfigUpdateService();
    }

    /**
     * 执行完整升级流水线。
     *
     * @return array array('executed_versions' => string[], 'new_version' => string)
     */
    public function run()
    {
        $ctx = $this->ctx;

        // S0 环境守卫：升级包含表重建与全量回写，禁止请求超时 / 客户端断开把进程杀死在
        // "重建完成 → 回灌"之间（该窗口被杀死会留下纯默认配置，后续重试将从被污染的
        // 默认值出发；早回灌 + 权威写点已消除该窗口，环境守卫作为第一道防线保留）。
        @set_time_limit(0);
        @ignore_user_abort(true);

        // S0.5 向导渠道：升级动作开始前先建「仅数据库」还原点（文件已由用户手工整包上传，
        //      无需也不能再备份文件）。尽力而为：失败只记警告不阻断——向导是最后的修复
        //      通道，不应被快照问题卡死；警告在完成页展示，还原点仍可在后台查看。
        if ($ctx->channel === 'wizard') {
            $this->createWizardSnapshot();
        }

        // S1 配置合并：升级包基准 x 站点现行文件——保留用户值、补齐新增项、移除废弃项
        //   （引擎为单文件、除 PHP 内置异常类外无外部依赖，见 lib/ConfigMerge.php 顶部说明）。
        //   ADMIN_REWRITE / DOU_APP_KEY 取值链已在 ContextFactory 中解析完成。
        require_once $ctx->materialsRoot . 'lib/ConfigMerge.php';
        if (!class_exists('Dou\\Upgrade\\Lib\\ConfigMerge')) {
            throw new \Exception('升级引擎缺失：' . $ctx->materialsRoot . 'lib/ConfigMerge.php 未随包更新，请确认升级包完整后再执行。');
        }
        \Dou\Upgrade\Lib\ConfigMerge::run($ctx->rootPath, $ctx->materialsRoot . 'resources/config/', array(
            'overrides' => array(
                'ADMIN_REWRITE' => $ctx->adminRewrite,
                'DOU_APP_KEY'   => $ctx->appKey,
            ),
        ));

        // S2 计算待执行版本（ver >= current，含当前版本本身：重复升级重跑幂等）
        $versions = $this->registry->discover();
        $cur = $this->registry->parseCurrentVersion($ctx->fromVersion);
        $pending = $this->registry->pending($versions, $cur);

        // S3 结构同步引擎就绪（core 单一权威源：升级包已先覆盖系统文件，再执行本程序）
        $schema = $this->bootstrapSchemaEngine();

        // S4 dou_config 表重建：读旧值快照 -> 按标准结构完全重建 -> 导入默认行 -> 回灌 + 保留旧站独有行
        $schema->rebuildTableFromStandard('config', $this->schemaFile('config.sql'), $this->schemaFile('config.sql'));

        // S5 配置早回灌（不含版本号与 update_date）：重建刚返回就立即把站点真实配置写回，
        //     消除"纯默认配置"窗口；失败必须上报，不允许站点静默停留在默认配置上。
        $restoreSql = $this->configUpdate->buildDbConfigRestoreSql(
            $ctx->siteConfig,
            $ctx->prefix,
            $ctx->rootUrl,
            $ctx->dbhost,
            $ctx->dbname,
            $ctx->dbuser,
            $ctx->dbpass
        );
        if (trim((string) $restoreSql) !== '' && !DB::fnExecute($restoreSql)) {
            throw new \Exception('dou_config 配置回灌存在失败语句，站点配置可能停留在默认值，'
                . '升级已中止，请查看 storage/log/ 后重试。');
        }

        // S6 update_date 早写安全网：把模块账本立即修正为真值（库真值 ∪ boot 快照）。
        //     若此后进程中断，下次升级读到的 boot 快照仍是真实账本，不会发生"重试污染"。
        $this->configUpdate->finalizeUpdateDate($ctx->siteConfig, $ctx->prefix, $ctx->newVersion);

        // S7 组装 upSql（回灌 + 版本号；update_date 已由 finalizeUpdateDate 接管）。
        //     版本号只在流程末尾写：半途失败时站点仍显示升级前的旧版本，避免"看起来已升级"（可检测、可重试）。
        $upSql = $restoreSql . $this->configUpdate->buildDbConfigVersionSql($ctx->prefix, $ctx->newVersion);

        // 一次性修正（双端统一；此前仅云侧执行，属已证漂移点）：1.x 老站（release < 20250912）
        // 的「升级时覆盖模板」开关归零。
        if ($ctx->releaseDate < 20250912) {
            $upSql .= "UPDATE `" . $ctx->prefix . "config` SET value = '0' WHERE name = 'update_overwritten_theme';\r\n";
        }

        // S8 版本补丁链（补丁可向 $up_sql 追加 SQL、经 $schema 执行改名/值迁移；array 传递以支持引用语义）
        $upSqlRef = array($upSql);
        $this->runPhpPatches($pending, $upSqlRef, $schema);
        $upSql = $upSqlRef[0];

        // S9 标准结构同步：核心表（table.sql，缺失补建）。
        //    必须晚于 PHP 补丁：字段/表改名先行保数据，本步最后对齐属性，标准外多余列在此删除。
        //    article / product 等模块表归各自模块包的升级程序，系统升级不触碰。
        $schema->syncStandards();
        // 失败必须上报：静默通过会让站点在「表结构与模块代码不匹配」的状态下继续运行；
        // 重跑时补丁虽已跳过（幂等），本步仍会再次对齐，故按提示重试即可修复。
        if ($schema->hasErrors()) {
            $syncErrors = $schema->getErrors();
            throw new \Exception('结构同步存在 ' . count($syncErrors) . ' 条失败语句，表结构可能与代码不匹配，'
                . '请查看 storage/log/schema_sync.log 后重试：' . implode(' | ', array_slice($syncErrors, 0, 3)));
        }

        // S10 执行 upSql（失败必须上报）+ update_date 末尾复写（吸收最终真值，幂等）
        if (trim((string) $upSql) !== '' && !DB::fnExecute($upSql)) {
            throw new \Exception('dou_config 回写存在失败语句，升级已中止，请查看 storage/log/ 后重试。');
        }
        $this->configUpdate->finalizeUpdateDate($ctx->siteConfig, $ctx->prefix, $ctx->newVersion);

        // S11 渠道收尾
        if ($ctx->channel === 'wizard') {
            // 去版权（网络失败不阻塞）
            $this->callCopyrightApi();
        }
        // 清缓存（保留模板编译根目录本身，仅删其下内容）
        $cacheClear = new CacheClearService();
        $cacheClear->clearSiteCache($ctx->rootPath);
        // 清理旧系统残留文件（旧式根/admin 入口、旧后台模板、与新系统不重叠的旧目录）；
        // 放在数据迁移 / config 回写 / 缓存清理都完成之后，确保 data/ 等旧件已被取代；幂等。
        $adminPath = defined('ADMIN_PATH') ? ADMIN_PATH : $ctx->rootPath . 'admin/';
        $oldCleanup = new OldFileCleanupService($ctx->rootPath, $adminPath);
        $oldCleanup->cleanup();
        if ($ctx->channel === 'wizard') {
            // 埋点：手动升级向导完成后上报系统升级记录（匿名基础遥测；一切异常均不阻断升级收尾）
            $this->reportSystem();
        }

        $ctx->executedVersions = $pending;

        return array('executed_versions' => $pending, 'new_version' => $ctx->newVersion);
    }

    /**
     * 向导渠道的「仅数据库」还原点（尽力而为，写 ctx->options['snapshot_dir'|'snapshot_warning']）。
     *
     * @return void
     */
    private function createWizardSnapshot()
    {
        try {
            if (!class_exists('Dou\\Core\\Service\\Upgrade\\SnapshotService')) {
                return;
            }
            $snapshot = new SnapshotService($this->ctx->rootPath);
            $begin = $snapshot->begin(SnapshotService::SCOPE_SYSTEM, 'wizard', array(
                'file_mode' => 'none',
                'db' => true,
                'channel' => 'wizard',
                'title' => '手动升级向导（仅数据库）',
            ));
            if (empty($begin['ok'])) {
                $this->persistWizardSnapshot('', (string) $begin['error']);
                return;
            }
            $deadline = time() + 600;
            while (true) {
                $step = $snapshot->step(array('max_seconds' => 25, 'db_bytes' => 8388608));
                if (empty($step['ok'])) {
                    $this->persistWizardSnapshot('', (string) $step['error']);
                    return;
                }
                if (!empty($step['done'])) {
                    $this->persistWizardSnapshot((string) $step['dir'], '');
                    return;
                }
                if (time() > $deadline) {
                    $this->persistWizardSnapshot('', '数据库还原点创建超时，可在后台「升级还原点」页查看状态');
                    return;
                }
            }
        } catch (\Exception $e) {
            $this->persistWizardSnapshot('', $e->getMessage());
        } catch (\Throwable $e) {
            $this->persistWizardSnapshot('', $e->getMessage());
        }
    }

    /**
     * 持久化本次 wizard 还原点结果（完成页跨请求展示：storage/state/last_snapshot.json）。
     *
     * @param string $dir
     * @param string $warning
     * @return void
     */
    private function persistWizardSnapshot($dir, $warning)
    {
        $this->ctx->options['snapshot_dir'] = $dir;
        $this->ctx->options['snapshot_warning'] = $warning;
        $stateDir = $this->ctx->rootPath . 'storage/state/';
        if (!is_dir($stateDir)) {
            @mkdir($stateDir, 0777, true);
        }
        @file_put_contents($stateDir . 'last_snapshot.json', json_encode(array(
            'dir' => (string) $dir,
            'warning' => (string) $warning,
            'time' => time(),
        )), LOCK_EX);
    }

    /**
     * 结构同步引擎就绪（core/infra/database/SchemaSync，单一权威源）。
     *
     * 升级时序保证可靠性：升级包已先整包覆盖（core 已是最新）再执行本程序；
     * 显式 require + class_exists 双保险，缺失即抛错终止——半旧结构下继续升级会造成表结构错位。
     *
     * @return \Dou\Core\Infra\Database\SchemaSync
     */
    private function bootstrapSchemaEngine()
    {
        $corePath = defined('CORE_PATH') ? CORE_PATH : $this->ctx->rootPath . 'core/';
        require_once $corePath . 'infra/database/SchemaSync.php';
        if (!class_exists('Dou\\Core\\Infra\\Database\\SchemaSync')) {
            throw new \Exception('升级引擎缺失：core/infra/database/SchemaSync.php 未随包更新，请确认升级包完整后再执行。');
        }

        $logDir = $this->ctx->rootPath . 'storage/log/';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }

        return new \Dou\Core\Infra\Database\SchemaSync(array(
            'standards' => array(
                // config 独立定义；article / product 等模块表仍由各自模块包升级。
                array('file' => $this->schemaFile('table.sql'), 'create' => true),
                array('file' => $this->schemaFile('config.sql'), 'create' => true),
            ),
            'log_file' => $logDir . 'schema_sync.log',
        ));
    }

    /**
     * 升级包标准结构资源路径。
     *
     * @param string $name
     * @return string
     */
    private function schemaFile($name)
    {
        return $this->ctx->materialsRoot . 'resources/schema/' . $name;
    }

    /**
     * 按版本顺序 include upgrade_*.php。
     *
     * 补丁顶部 `use Dou\Core\Facade\DB;` 后通过 DB::fieldExist / DB::tableName / DB::query
     * 等静态门面访问数据库。include 进来的补丁脚本继承本方法的局部作用域，可直接读取
     * $up_sql、$schema、$_CFG、$prefix 等局部变量；补丁内若需向 $up_sql 追加 SQL，
     * include 结束后由本方法写回。字段/表改名与值迁移一律经 $schema 的规范化迁移 API 执行。
     *
     * @param string[] $versions
     * @param array    $upSqlRef array(0 => string)；补丁结束后写回元素 0
     * @param \Dou\Core\Infra\Database\SchemaSync $schema 结构同步引擎（注入补丁作用域）
     * @return void
     */
    private function runPhpPatches(array $versions, array &$upSqlRef, $schema)
    {
        $up_sql = $upSqlRef[0];
        $_CFG   = $this->ctx->siteConfig;
        $prefix = $this->ctx->prefix;
        $dbhost = $this->ctx->dbhost;
        $dbname = $this->ctx->dbname;
        $dbuser = $this->ctx->dbuser;
        $dbpass = $this->ctx->dbpass;

        foreach ($versions as $ver) {
            $file = $this->registry->phpFile($ver);
            if (!file_exists($file)) {
                continue;
            }
            include($file);
        }

        $upSqlRef[0] = $up_sql;
    }

    /**
     * 调用官方版权 API，将结果写入 storage/state/cdkey.php。网络失败时静默返回（仅向导渠道）。
     *
     * @return void
     */
    private function callCopyrightApi()
    {
        $cfg = $this->ctx->siteConfig;
        $cloud_account = array();
        if (!empty($cfg['cloud_account'])) {
            $arr = @unserialize($cfg['cloud_account']);
            if (is_array($arr)) {
                $cloud_account = $arr;
            }
        }
        // 没绑过账号就跳过
        if (!$cloud_account) {
            return;
        }

        $hash = isset($cfg['hash_code']) ? (string) $cfg['hash_code'] : '';
        if ($hash === '') {
            return;
        }
        $systemSign = defined('SYSTEM_SIGN') ? SYSTEM_SIGN : 'company';
        $data = array(
            'user'        => isset($cloud_account['user']) ? $cloud_account['user'] : '',
            'password'    => isset($cloud_account['password']) ? $cloud_account['password'] : '',
            'domain'      => $this->ctx->rootUrl,
            'shell'       => substr(md5($hash), 16),
            'version'     => isset($cfg['douphp_version']) ? $cfg['douphp_version'] : '',
            'system_sign' => $systemSign,
        );

        $response = $this->httpPost('https://api.douphp.com/copyright', $data);
        if ($response !== '' && $response !== 'wrong' && $response !== 'unauthorized') {
            $stateDir = $this->ctx->rootPath . 'storage/state/';
            if (!is_dir($stateDir)) {
                @mkdir($stateDir, 0777, true);
            }
            @file_put_contents($stateDir . 'cdkey.php', $response);
        }
    }

    /**
     * 极简 HTTP POST，仅供版权 API 调用；网络失败时返回空串。
     *
     * @param string $url
     * @param array  $data
     * @return string
     */
    private function httpPost($url, array $data)
    {
        if (!function_exists('curl_init')) {
            return '';
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        if ($response === false) {
            $response = '';
        }
        curl_close($ch);
        return (string) $response;
    }

    /**
     * 埋点：向导渠道完成后上报系统升级记录（匿名基础遥测，落库 site / site_event）。
     *
     * 向导自举上下文未绑定后台 app 容器，故直接 new（对齐 install/FinishService）；
     * SiteReportService::send() 内部对 report_site 开关、api_base 缺失与一切异常全兜底，
     * 此处再包一层，绝不阻断升级收尾。
     *
     * @return void
     */
    private function reportSystem()
    {
        try {
            $reporter = new \Dou\Core\Service\Cloud\SiteReportService();
            $reporter->reportSystem('update', $this->ctx->newVersion, $this->ctx->fromVersion);
        } catch (\Exception $e) {
            // 上报失败静默忽略。
        } catch (\Throwable $e) {
            // PHP 7+ 的 \Error（如类加载失败）同样静默忽略。
        }
    }
}

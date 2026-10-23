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

namespace Dou\Admin\Service\Cloud;

use Dou\Admin\Service\Cache\CacheClearService;
use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Facade\Url;
use Dou\Core\Facade\Zip;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\SiteDebugExceptionRenderer;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Metadata\MetadataService;
use Dou\Core\Service\Metadata\MetadataTranslationStore;
use Dou\Core\Service\Metadata\MetadataValues;
use Dou\Core\Service\System\ModuleLanguageManifest;
use Dou\Core\Service\System\ModuleSettingReader;
use Dou\Core\Support\Arr;
use Dou\Core\Support\FileHelper;
use Dou\Core\Support\Naming;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\Client;
use Dou\Core\Web\I18n\JsLangExporter;
use Dou\Core\Web\Routing\RouteTableExporter;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 云端扩展安装流程服务。
 *
 * 主要职责：
 * - 为 cloud/install 多步 API 提供：runPreflight / runDownload / runUnzip / runApply / runFinalize；
 * - 处理模块 / 主题 / 插件 / 系统 / 小程序等不同 type 的目录拷贝与 SQL 注入。
 * - 维护 config/module.php、nav 表、display/defined 配置以及小程序 app.json 同步。
 */
class InstallService extends BaseService
{
    /** @var string 模块包所在目录（绝对路径，斜杠结尾） */
    private $cacheDir;

    /** @var string 站点根目录 */
    private $rootDir;

    /** @var UpdateStateService */
    private $updateState;

    /** @var CacheClearService */
    private $cacheClear;

    /**
     * 最近一次 {@see downloadFile} 失败原因码（供 {@see runDownload} 映射为语言包文案）。
     *
     * @var string
     */
    private $lastDownloadFailureDetail = '';

    /**
     * @param CacheClearService $cacheClear
     * @param string $cacheDir 模块包下载/解压临时工作区（绝对路径或相对站点根）。
     *                         缺省走 STORAGE_PATH . 'install/package/'；
     *                         传相对路径则解析为 ROOT_PATH . $cacheDir . '/'，便于测试。
     */
    public function __construct(CacheClearService $cacheClear, $cacheDir = '')
    {
        $this->cacheClear = $cacheClear;
        $cacheDir = (string) $cacheDir;
        if ($cacheDir === '') {
            $this->cacheDir = STORAGE_PATH . 'install/package/';
        } else {
            $this->cacheDir = ROOT_PATH . rtrim($cacheDir, '/') . '/';
        }
        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0777, true);
        }
        $this->rootDir = ROOT_PATH;
        $this->updateState = new UpdateStateService();
    }

    /**
     * 安装步骤名集合（CloudInstallController / 前端共用，定义在此保持单一来源）。
     */
    const STEP_PREFLIGHT = 'preflight';
    const STEP_DOWNLOAD = 'download';
    const STEP_UNZIP = 'unzip';
    const STEP_SNAPSHOT = 'snapshot';
    const STEP_APPLY = 'apply';
    const STEP_FINALIZE = 'finalize';

    /**
     * 最近一次 install() 过程的附加说明（如模块更新失败后的快照回滚结果），
     * 由 runApply 合并进步骤日志展示。
     *
     * @var array
     */
    private $installNotes = array();

    /**
     * 步骤 1：版本校验 + 重复安装校验 + system 授权前置校验。
     *
     * @param array $params 至少包含 type / cloud_id / mode / version
     * @return array { ok: bool, error: string, logs: string[] }
     */
    public function runPreflight(array $params)
    {
        $type = isset($params['type']) ? (string) $params['type'] : '';
        $cloudId = isset($params['cloud_id']) ? (string) $params['cloud_id'] : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'install';
        $version = isset($params['version']) ? (string) $params['version'] : '';
        $authStatus = isset($params['auth_status']) ? (string) $params['auth_status'] : 'ok';

        $logs = array();

        // 前置检查（core/service/upgrade/PreflightService：全量关键目录写测 + PHP/MySQL 兼容判定 +
        // 扩展检查 + 磁盘余量参考提示；blocked 拒绝、limited/提示写入日志。
        // 类缺失（旧版本代码窗口）时回退旧版基础目录可写检查，行为不劣化）
        if (class_exists('Dou\\Core\\Service\\Upgrade\\PreflightService')) {
            $preflight = new \Dou\Core\Service\Upgrade\PreflightService();
            $report = $preflight->check($this->rootDir, $this->preflightAliasDirs());
            foreach (array_merge($report['warnings'], $report['notices']) as $preflightLine) {
                $logs[] = (string) $preflightLine;
            }
            if (empty($report['ok'])) {
                @unlink($this->cacheDir . $cloudId . '.zip');
                return array(
                    'ok' => false,
                    'error' => implode('；', $report['errors']),
                    'logs' => $logs,
                );
            }
        } else {
            $unwriteable = $this->findUnwriteableDirs();
            if ($unwriteable !== array()) {
                @unlink($this->cacheDir . $cloudId . '.zip');
                return array(
                    'ok' => false,
                    'error' => sprintf((string) lang('cloud_writeable_denied'), implode('、', $unwriteable)),
                    'logs' => $logs,
                );
            }
        }

        if ($mode !== 'local' && $authStatus !== '' && $authStatus !== 'ok') {
            $denial = $this->buildAuthDenial($authStatus);
            if ($denial !== null) {
                @unlink($this->cacheDir . $cloudId . '.zip');
                return array(
                    'ok' => false,
                    'error' => $denial['error'],
                    'logs' => array(),
                    'redirect_url' => $denial['redirect_url'],
                    'redirect_label' => $denial['redirect_label'],
                    'redirect_target' => $denial['redirect_target'],
                );
            }
        }

        if ($version !== '') {
            $wrong = $this->checkMiniVersionSupport($version);
            if ($wrong) {
                @unlink($this->cacheDir . $cloudId . '.zip');
                return array('ok' => false, 'error' => $this->joinMessages($wrong), 'logs' => $logs);
            }
        }

        if ($mode === 'install' || $mode === 'local') {
            $wrong = $this->installCheck($type, $cloudId);
            if ($wrong) {
                @unlink($this->cacheDir . $cloudId . '.zip');
                return array(
                    'ok' => false,
                    'error' => $this->joinMessages($wrong),
                    'logs' => $logs,
                    'repeat_only' => $wrong !== array(),
                );
            }
        }

        return array('ok' => true, 'error' => '', 'logs' => $logs);
    }

    /**
     * 站点写入权限预检：模块包会摊开写入全站关键目录（模块/升级覆盖均如此），
     * 任一已存在的关键目录不可写都应在下载前中止。
     *
     * 不以 {@see is_writable()} 为准：Windows 上站点根目录常被判为不可写，但 PHP 实际可以创建文件。
     *
     * @return array 不可写目录清单（空数组表示全部可写）
     */
    private function findUnwriteableDirs()
    {
        $targets = array(
            '',
            ADMIN_DIR,
            API_DIR,
            MINIPROGRAM_DIR,
            'theme',
            'languages',
            'images',
            'storage',
            'config',
        );

        $failed = array();
        foreach ($targets as $dir) {
            $full = $dir === '' ? ROOT_PATH : ROOT_PATH . rtrim($dir, '/') . '/';
            if (!file_exists($full)) {
                continue;
            }
            if (!$this->directoryIsWritable($full)) {
                $failed[] = $dir === '' ? '站点根目录' : $dir;
            }
        }

        return array_values(array_unique($failed));
    }

    /**
     * 前置检查的目录别名（自定义后台/接口/小程序目录名；不存在自动跳过）。
     *
     * @return array
     */
    private function preflightAliasDirs()
    {
        return array(
            defined('ADMIN_DIR') ? ADMIN_DIR : 'admin',
            defined('API_DIR') ? API_DIR : 'api',
            defined('MINIPROGRAM_DIR') ? MINIPROGRAM_DIR : 'miniprogram',
        );
    }

    /**
     * 记录模块包版本（T6）：从包解压目录 _update/version.php 读元信息，写入 dou_config.module_version。
     *
     * 仅用于「安装模式」成功后记录；「更新模式」由包内升级载荷自行完成（载荷先于本流程收尾执行）。
     *
     * @param string $cloudId
     * @param string $itemDir 包解压目录（绝对路径，不带结尾斜杠）
     * @return void
     */
    private function recordModuleVersionFromPackage($cloudId, $itemDir)
    {
        $metaFile = rtrim(str_replace('\\', '/', (string) $itemDir), '/') . '/_update/version.php';
        if (!is_file($metaFile)) {
            return;
        }
        $meta = include $metaFile;
        if (!is_array($meta) || empty($meta['version'])) {
            return;
        }
        $module = isset($meta['module']) && (string) $meta['module'] !== '' ? (string) $meta['module'] : (string) $cloudId;
        $this->updateState->recordModuleVersion($module, (string) $meta['version']);
    }

    /**
     * 以创建临时文件探测目录是否可写（与 {@see FileHelper::permission()} 一致）。
     *
     * @param string $dir 绝对路径
     * @return bool
     */
    private function directoryIsWritable($dir)
    {
        return FileHelper::permission($dir) === 'write';
    }

    /**
     * 步骤 2：下载安装包到 cache 目录；local 模式仅校验文件存在。
     *
     * @param array $params 至少包含 type / cloud_id / mode（install_id 用于下载进度文件命名）；须含云端 install-resolve 下发的 download_url（经 {@see filterTrustedInstallDownloadUrl} 白名单校验）
     * @return array { ok: bool, error: string, logs: string[] }
     */
    public function runDownload(array $params)
    {
        $type = isset($params['type']) ? (string) $params['type'] : '';
        $cloudId = isset($params['cloud_id']) ? (string) $params['cloud_id'] : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'install';
        $installId = isset($params['install_id']) ? (string) $params['install_id'] : '';

        $logs = array();
        $itemZip = $this->cacheDir . $cloudId . '.zip';

        // 下载进度文件：供前端轮询显示百分比（install_id 形态非法时不启用；开始前清掉可能残留的旧文件）
        $progressFile = '';
        if (preg_match('/^[a-f0-9]{1,64}$/', $installId)) {
            $progressFile = STORAGE_PATH . 'install/' . $installId . '.dl.json';
            @unlink($progressFile);
        }

        if ($mode === 'local') {
            if (!is_file($itemZip)) {
                $msg = lang_has('cloud_local_zip_missing')
                    ? (string) lang('cloud_local_zip_missing')
                    : (string) lang('cloud_down_wrong');
                $logs[] = $msg;

                return array('ok' => false, 'error' => $msg, 'logs' => $logs);
            }
            return array('ok' => true, 'error' => '', 'logs' => $logs);
        }

        $downUrl = '';
        if (isset($params['download_url']) && (string) $params['download_url'] !== '') {
            $downUrl = $this->filterTrustedInstallDownloadUrl((string) $params['download_url']);
        }
        if ($downUrl === '') {
            $msg = lang('cloud_down_wrong');
            $logs[] = $msg;

            return array('ok' => false, 'error' => $msg, 'logs' => $logs);
        }

        $logs[] = lang('cloud_down_ing_0') . $downUrl . lang('cloud_down_ing_1');

        $downloaded = $this->downloadFile($downUrl, $this->cacheDir, $progressFile);
        if ($progressFile !== '') {
            @unlink($progressFile);
        }
        if ($downloaded !== false) {
            // 包完整性校验（最小档）：云端 install-resolve 下发 sha256 时必须一致，否则删除并拒绝；
            // 未下发 sha256（旧云端/镜像缺 sidecar）→ 静默继续（平滑过渡）
            $expected = isset($params['sha256']) ? strtolower(trim((string) $params['sha256'])) : '';
            if ($expected !== '') {
                $actual = @hash_file('sha256', $downloaded);
                if ($actual === false || strtolower((string) $actual) !== $expected) {
                    @unlink($downloaded);
                    $msg = lang_has('cloud_package_hash_mismatch')
                        ? (string) lang('cloud_package_hash_mismatch')
                        : (string) lang('cloud_down_wrong');
                    $logs[] = $msg;
                    return array('ok' => false, 'error' => $msg, 'logs' => $logs);
                }
                $logs[] = lang_has('cloud_package_hash_ok') ? (string) lang('cloud_package_hash_ok') : '包完整性校验通过。';
            }

            return array('ok' => true, 'error' => '', 'logs' => $logs);
        }

        return array('ok' => false, 'error' => $this->resolveDownloadFailureMessage(), 'logs' => $logs);
    }

    /**
     * 步骤 3：解压并按 type 同步目录名（theme / miniprogram / m / admin 别名）。
     *
     * @param array $params 至少包含 type / cloud_id / mode
     * @return array { ok: bool, error: string, logs: string[] }
     */
    public function runUnzip(array $params)
    {
        $type = isset($params['type']) ? (string) $params['type'] : '';
        $cloudId = isset($params['cloud_id']) ? (string) $params['cloud_id'] : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'install';

        $logs = array();
        $logs[] = lang('cloud_unzip_ing');

        $itemZip = $this->cacheDir . $cloudId . '.zip';
        $itemDir = $this->cacheDir . $cloudId;

        if (!is_file($itemZip)) {
            $msg = lang_has('cloud_unzip_missing') ? (string) lang('cloud_unzip_missing') : (string) lang('cloud_down_wrong');
            $logs[] = $msg;

            return array('ok' => false, 'error' => $msg, 'logs' => $logs);
        }

        if (Zip::extract($itemZip, $itemDir)) {
            $this->synchronizeDirname($type, $itemDir, $mode);

            // 解压后二次环境校验（system 包）：按包内 _update/version.php 的 php_min / mysql_min
            // fail-loud（前置检查基于站点现行代码；此处以「包自述要求」为准，防解压完成后才发现环境不满足）
            $metaFile = $itemDir . '/_update/version.php';
            if ($type === 'system' && is_file($metaFile) && class_exists('Dou\\Core\\Service\\Upgrade\\EnvCompatService')) {
                $meta = include $metaFile;
                if (is_array($meta)) {
                    $envCompat = new \Dou\Core\Service\Upgrade\EnvCompatService();
                    $require = $envCompat->checkPackageRequirements($meta);
                    if (empty($require['ok'])) {
                        @unlink($itemZip);
                        FileHelper::delDir($itemDir);
                        $msg = implode('；', $require['errors']);
                        $logs[] = $msg;
                        return array('ok' => false, 'error' => $msg, 'logs' => $logs);
                    }
                }
            }

            return array('ok' => true, 'error' => '', 'logs' => $logs);
        }

        @unlink($itemZip);
        FileHelper::delDir($itemDir);
        $logs[] = lang('cloud_unzip_wrong');

        return array('ok' => false, 'error' => lang('cloud_unzip_wrong'), 'logs' => $logs);
    }

    /**
     * 步骤 3.5：创建升级还原点（unzip 与 apply 之间）。
     *
     * 系统更新（system + update/patch）建 system 域全量还原点；模块 / 主题 / 插件更新
     * （module|theme|plugin + update）按解压目录反推将被覆盖 / 新增的文件，分别建
     * module / theme / plugin 域还原点（theme / plugin 以 item_prefix 映射目标目录）。
     * 模块还原点仅导出该模块自己的表（安装记录 / 包内 SQL 推导），并把升级前的
     * module_version / update_date 账本写入 manifest；模块无表时不含数据库备份。
     * 主题 / 插件更新不执行 SQL 脚本，其还原点不含数据库（db=false）。其余类型
     * （全新安装、小程序）不建还原点，直接放行到 apply。
     *
     * 断点续传：一次调用按时间预算推进一个单元；未完成时返回 next_step=snapshot 与
     * progress 百分比（取自 progress.json 实时游标），由前端循环再次调用本步骤
     * （active.json 记录创建进度），直至完成再进入 apply。完成态固定回传 progress=100：
     * 最后一次 step() 往往连续推进 db / finalize 后直接返回 done，不再携带中间进度，
     * 若末尾不补 100，前端进行中行会定格在 files 阶段上限 80%，看起来像「卡住未完成」。
     *
     * 日志收敛：过程中仅保留前端预置的进行中提示（含旋转圈与进度），完成时不再输出
     * 还原点目录名（改由底部安全提示说明）；快照引擎的内部进度日志不再回传
     * （其它入口不受影响）。
     *
     * 命名：系统还原点目录名 = system-<升级前版本净化>-<YmdHis>（快照内容即升级前状态，
     * 供列表页 / 恢复确认直观识别被备份的版本）；模块 / 主题 / 插件目录名为
     * module|theme|plugin-<cloud_id>-<YmdHis>，向导命名策略不变。
     *
     * @param array $params 至少包含 type / cloud_id / mode / install_id
     * @return array { ok: bool, error: string, logs: string[], next_step?: string, progress?: int|null }
     */
    public function runSnapshot(array $params)
    {
        $type = isset($params['type']) ? (string) $params['type'] : '';
        $cloudId = isset($params['cloud_id']) ? (string) $params['cloud_id'] : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'install';
        $installId = isset($params['install_id']) ? (string) $params['install_id'] : '';

        $isSystem = ($type === 'system' && ($mode === 'update' || $mode === 'patch'));
        $isModule = ($type === 'module' && $mode === 'update');
        $isTheme = ($type === 'theme' && $mode === 'update');
        $isPlugin = ($type === 'plugin' && $mode === 'update');
        if (!$isSystem && !$isModule && !$isTheme && !$isPlugin) {
            return array('ok' => true, 'error' => '', 'logs' => array(), 'next_step' => self::STEP_APPLY);
        }

        if (!class_exists('Dou\\Core\\Service\\Snapshot\\SnapshotService')) {
            return array('ok' => false, 'error' => (string) lang('cloud_snapshot_engine_missing'), 'logs' => array());
        }

        $snapshot = new \Dou\Core\Service\Snapshot\SnapshotService(ROOT_PATH);
        $active = $snapshot->active();
        $logs = array();
        // 同 install_id 的活跃快照由 begin() 续建；其它会话遗留的活跃快照（上次创建中断、
        // 4 小时内）必须交给 begin() 中止重建——否则 step() 会继续推进旧扩展的旧快照，
        // 本次更新实际没有还原点，失败回滚还会命中过期的旧快照。
        $resumeOther = $active !== null
            && $installId !== ''
            && (!isset($active['install_id']) || (string) $active['install_id'] !== $installId);
        if ($active === null || $resumeOther) {
            // 系统还原点记录「被备份的升级前版本」：snapshot 步骤先于 apply 执行，
            // 此处读到的 douphp_version 即升级前版本；取不到时回退目标包标识（原行为）。
            $scopeId = $cloudId;
            $title = $cloudId;
            if ($isSystem) {
                $backedVersion = (string) Config::get('site.douphp_version', '');
                if ($backedVersion !== '') {
                    $scopeId = $backedVersion;
                    $title = $backedVersion;
                }
            }
            // 模块还原点只导出模块自身表；升级前版本账本随 manifest 固化，供还原收尾回写
            $moduleId = $cloudId;
            $moduleTables = array();
            $moduleState = array();
            if ($isModule) {
                $moduleId = $this->snapshotModuleId($cloudId);
                $moduleTables = $this->snapshotModuleTables($cloudId, $moduleId);
                $moduleState = $this->updateState->moduleSnapshotState($moduleId, $cloudId);
            }
            $options = array(
                'db' => ($isSystem || ($isModule && !empty($moduleTables))),
                'channel' => 'cloud',
                'install_id' => $installId,
                'title' => $title,
            );
            if ($isModule) {
                $options['tables'] = $moduleTables;
                $options['module_state'] = $moduleState;
            }
            $scope = 'module';
            if ($isSystem) {
                $options['file_mode'] = 'full';
                $scope = 'system';
            } else {
                $options['file_mode'] = 'list';
                $options['item_dir'] = $this->cacheDir . $cloudId;
                if ($isTheme) {
                    $options['item_prefix'] = 'theme/' . $cloudId . '/';
                    $scope = 'theme';
                } elseif ($isPlugin) {
                    $options['item_prefix'] = 'plugin/' . $cloudId . '/';
                    $scope = 'plugin';
                }
            }
            $begin = $snapshot->begin($scope, $scopeId, $options);
            if (empty($begin['ok'])) {
                return array('ok' => false, 'error' => (string) $begin['error'], 'logs' => $logs);
            }
        }

        $result = $snapshot->step(array('max_seconds' => 12, 'file_batch' => 700, 'db_bytes' => 4194304));
        if (empty($result['ok'])) {
            return array('ok' => false, 'error' => (string) $result['error'], 'logs' => $logs);
        }

        if (empty($result['done'])) {
            return array(
                'ok' => true,
                'error' => '',
                'logs' => $logs,
                'next_step' => self::STEP_SNAPSHOT,
                'progress' => $this->snapshotPercent($snapshot),
            );
        }

        // 还原点目录名不再作为日志输出：完成态由底部安全提示（finish_note_html）说明，
        // 避免升级日志里出现一串对用户无意义的目录名
        // 完成态显式回传 100%：最后一次 step() 在 files→db→finalize 连续推进后直接 done，
        // 不再返回中间进度，缺失该值会让前端进度定格在 80%（files 上限）疑似未完成
        return array(
            'ok' => true,
            'error' => '',
            'logs' => $logs,
            'next_step' => self::STEP_APPLY,
            'progress' => 100,
        );
    }

    /**
     * 还原点创建百分比（阶段加权估算，用于前端进行中提示）。
     *
     * 口径：files 阶段 5–80%（file_index/file_total）、db 阶段 80–98%（table_index/表数）、
     * finalize 99%；collect（收集清单，无细粒度游标）与取不到进度时返回 null（前端回落秒表）。
     * 完成态的 100% 不在本方法内产生，由 {@see runSnapshot()} 收尾时显式回传。
     *
     * @param \Dou\Core\Service\Snapshot\SnapshotService $snapshot
     * @return int|null
     */
    private function snapshotPercent($snapshot)
    {
        $active = $snapshot->active();
        if (!is_array($active) || !isset($active['progress']) || !is_array($active['progress'])) {
            return null;
        }
        $p = $active['progress'];
        $phase = isset($p['phase']) ? (string) $p['phase'] : '';

        if ($phase === 'files') {
            $total = isset($p['file_total']) ? (int) $p['file_total'] : 0;
            if ($total > 0) {
                $index = isset($p['file_index']) ? (int) $p['file_index'] : 0;

                return 5 + (int) floor(75 * $index / $total);
            }

            return null;
        }
        if ($phase === 'db') {
            $total = isset($p['tables']) ? count((array) $p['tables']) : 0;
            if ($total > 0) {
                $index = isset($p['table_index']) ? (int) $p['table_index'] : 0;

                return 80 + (int) floor(18 * $index / $total);
            }

            return 85;
        }
        if ($phase === 'finalize') {
            return 99;
        }

        return null;
    }

    /**
     * 步骤 4：执行 install() 主体（文件拷贝 / SQL / module.php / display 等）。
     *
     * @param array $params 至少包含 type / cloud_id / mode
     * @return array { ok: bool, error: string, logs: string[], redirect_url?: string }
     */
    public function runApply(array $params)
    {
        $type = isset($params['type']) ? (string) $params['type'] : '';
        $cloudId = isset($params['cloud_id']) ? (string) $params['cloud_id'] : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'install';

        $logs = array();
        $typeLabel = lang('cloud_' . $type, $type);
        if ($type === 'system') {
            $typeLabel = '';
        }
        $logs[] = lang('cloud_install_ing') . $typeLabel . '…';

        // 系统升级前捕获旧核心版本，供 runFinalize 上报 from_version。
        // install() 内的 copyExtractedFiles / _update/action.php 会覆盖 site.douphp_version，
        // 且 runFinalize 是后续独立请求（Config 已重载为新版本），故此处先行暂存到 Session。
        if ($type === 'system' && $mode === 'update') {
            Session::set('cloud_report_from_version', (string) Config::get('site.douphp_version', ''));
        }

        $wrong = $this->install($type, $cloudId, $mode);
        // 附加说明无论成败都回传：失败时含快照回滚结果
        foreach ($this->installNotes as $note) {
            $logs[] = (string) $note;
        }
        if (is_array($wrong) && $wrong) {
            return array('ok' => false, 'error' => $this->joinMessages($wrong), 'logs' => $logs);
        }

        $post = $this->runSystemPostHooks($type);
        if ($post['ok'] === false) {
            return array(
                'ok' => false,
                'error' => $post['error'],
                'logs' => $logs,
                'redirect_url' => $post['redirect_url'],
            );
        }

        return array('ok' => true, 'error' => '', 'logs' => $logs);
    }

    /**
     * 步骤 5：收尾——审计日志、按钮 HTML、批量后续项 URL。
     *
     * @param array $params 至少包含 type / cloud_id / mode / theme_id
     * @param array $session 当前会话快照（用于读取 batch、theme_id 决策后续）
     * @return array { ok: bool, logs: string[], result: array<string, string|array|null> }
     */
    public function runFinalize(array $params, array $session)
    {
        $type = isset($params['type']) ? (string) $params['type'] : '';
        $cloudId = isset($params['cloud_id']) ? (string) $params['cloud_id'] : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'install';
        $themeId = isset($params['theme_id']) ? (string) $params['theme_id'] : '';
        $batch = isset($session['batch']) && is_array($session['batch']) ? $session['batch'] : array();
        $version = isset($params['version']) ? (string) $params['version'] : '';
        $backUrl = isset($params['back_url']) ? (string) $params['back_url'] : '';

        $logs = array();
        $text = $mode === 'update' ? lang('cloud_update_0') : lang('cloud_install_0');
        $logs[] = $text . $cloudId . lang('cloud_install_1');

        $typeLabel = lang('cloud_' . $type, $type);
        if ($type === 'system') {
            $typeLabel = lang_has('cloud_system') ? (string) lang('cloud_system') : 'system';
        }
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::INSTALL, 1, $typeLabel . ':' . $cloudId);

        // 埋点：云端安装/升级成功后上报记录（匿名基础遥测，落库 site / site_event）。
        // 覆盖三条 URL：模块/主题/插件/小程序安装升级、系统升级。from_version 取自 runApply
        // 阶段暂存的旧核心版本（仅系统升级）。SiteReportService 内部对开关与异常全兜底，
        // 此处再包一层 try/catch，绝不阻断收尾流程。
        try {
            $fromVersion = '';
            if ($type === 'system') {
                $fromVersion = (string) Session::get('cloud_report_from_version', '');
                Session::del('cloud_report_from_version');
            }
            $reporter = app(\Dou\Core\Service\Cloud\SiteReportService::class);
            $eventType = $mode === 'install' ? 'install' : 'update';
            if ($type === 'system') {
                $reporter->reportSystem($eventType, $version, $fromVersion);
            } elseif (in_array($type, array('module', 'theme', 'plugin', 'miniprogram'), true)) {
                $reporter->reportExtend($type, $cloudId, $eventType, $version);
            }
        } catch (\Exception $e) {
            // 上报失败静默忽略。
        } catch (\Throwable $e) {
            // PHP 7+ 的 \Error（如容器解析失败）同样静默忽略。
        }

        // 系统升级成功后自动刷新授权凭据（去版权）：版权凭据的校验串绑定 douphp_version，
        // 升级落库新版本后旧凭据必然失效（前台 app.licensed 归 false，版权重新出现）。
        // runFinalize 是 apply 之后的独立请求、Config 已重载为新版本，只有在这里领取到的
        // 凭据才与新版本匹配；未配置云账户 / 本地访问 / 云端不可用均静默跳过，不产生任何提示。
        if ($type === 'system' && ($mode === 'update' || $mode === 'patch')) {
            $this->refreshCopyrightCredential();
        }

        $buttons = $this->buildSuccessButtons($mode, $type, $cloudId, $backUrl);
        $btnAction = isset($buttons['action']) ? (string) $buttons['action'] : '';
        $btnBack = isset($buttons['back']) ? (string) $buttons['back'] : '';
        $btnNote = isset($buttons['note']) ? (string) $buttons['note'] : '';

        $nextInstall = $this->resolveNextInstall($batch, $themeId, $mode, $version);

        return array(
            'ok' => true,
            'logs' => $logs,
            'result' => array(
                'btn_action_html' => $btnAction,
                'btn_back_html' => $btnBack,
                'finish_note_html' => $btnNote,
                'next_install' => $nextInstall,
            ),
        );
    }

    /**
     * system 类型安装成功后的钩子：clearCache。
     *
     * @param string $type
     * @return array { ok: bool, error?: string, redirect_url?: string }
     */
    private function runSystemPostHooks($type)
    {
        if ($type !== 'system') {
            return array('ok' => true);
        }

        $this->cacheClear->clearCache(STORAGE_PATH . 'cache/template');

        return array('ok' => true);
    }

    /**
     * 系统升级成功后自动刷新授权凭据（去版权）。
     *
     * 版权凭据（storage/state/cdkey.php）的校验串绑定 site.douphp_version，
     * 升级完成、版本号落库后旧凭据立即失效，需按新版本重新向云端领取一次，
     * 否则前台版权信息会重新出现，用户必须手动访问 cloud/copyright 修复。
     *
     * 仅当站点已配置云账户且当前不是本地访问时发起；成功与失败均静默处理，
     * 不写入步骤日志，也不阻断收尾。
     *
     * @return void
     */
    private function refreshCopyrightCredential()
    {
        $account = CloudService::loadCloudAccount();
        if ($account['user'] === '' || $account['password'] === '') {
            return;
        }
        if ($this->isLocalAccess()) {
            return;
        }

        try {
            (new CloudService())->refreshCopyright();
        } catch (\Exception $e) {
            // 云端不可用 / 未开通 VIP 等失败静默忽略。
        } catch (\Throwable $e) {
            // PHP 7+ 的 \Error（如容器解析失败）同样静默忽略。
        }
    }

    /**
     * 当前请求是否来自本地访问（localhost / 回环地址 / 私有网段 IP）。
     *
     * 本地开发环境无法匹配云端绑定的授权域名，自动去版权必然失败，
     * 因此这类访问直接跳过，避免发出无意义的云端请求。
     *
     * @return bool
     */
    private function isLocalAccess()
    {
        $values = array();
        if (isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== '') {
            $values[] = (string) $_SERVER['HTTP_HOST'];
        }
        if (defined('ROOT_URL')) {
            $values[] = (string) ROOT_URL;
        }

        $resolved = false;
        foreach ($values as $value) {
            $url = strpos($value, '://') === false ? 'http://' . $value : $value;
            $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));
            if ($host === '') {
                continue;
            }
            $resolved = true;
            if ($host === 'localhost' || substr($host, -10) === '.localhost') {
                return true;
            }
            if (filter_var($host, FILTER_VALIDATE_IP)
                && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                // 回环 / 私有 / 保留网段均视为本地访问。
                return true;
            }
        }

        // 无法识别主机名时按本地处理，避免在开发环境误发云端请求。
        return $resolved === false;
    }

    /**
     * 根据 batch / theme_id 推断下一个待安装的 cloud_id（前端用于自动创建下一个会话）。
     *
     * @param array $batch
     * @param string $themeId
     * @param string $mode
     * @param string $version
     * @return array|null { type, cloud_id, mode, version, theme_id }
     */
    private function resolveNextInstall(array $batch, $themeId, $mode, $version)
    {
        if (!empty($batch)) {
            $next = array_shift($batch);
            return array(
                'type' => 'module',
                'cloud_id' => (string) $next,
                'mode' => (string) $mode,
                'version' => (string) $version,
                'theme_id' => (string) $themeId,
                'batch' => array_values($batch),
            );
        }
        if ($themeId !== '') {
            return array(
                'type' => 'theme',
                'cloud_id' => (string) $themeId,
                'mode' => 'install',
                'version' => (string) $version,
                'theme_id' => '',
                'batch' => array(),
            );
        }
        return null;
    }

    /**
     * 把错误信息数组拼成可读字符串。
     *
     * @param array $messages
     * @return string
     */
    private function joinMessages(array $messages)
    {
        $clean = array();
        foreach ($messages as $msg) {
            $msg = trim((string) $msg);
            if ($msg !== '') {
                $clean[] = $msg;
            }
        }
        return implode('；', $clean);
    }

    /**
     * include 升级 / 模板初始化脚本：隔离多余输出，并把可恢复异常写入 $wrong。
     *
     * 脚本与本方法共享 $wrong（引用），以便脚本内 `$wrong[] = …` 仍能回传到 {@see install()}。
     *
     * @param string $file
     * @param array $wrong
     * @return void
     */
    private function includeOptionalScript($file, array &$wrong)
    {
        if (!is_file($file)) {
            return;
        }

        $bufferLevel = ob_get_level();
        ob_start();
        $caught = null;
        try {
            include_once $file;
        } catch (\Exception $e) {
            $caught = $e;
        } catch (\Throwable $e) {
            // PHP 7+ Error（如 class not found）不继承 Exception；5.6 无 Throwable，本分支不匹配。
            $caught = $e;
        }
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }

        if ($caught === null) {
            return;
        }
        if ($this->isOptionalUpdateHookError($caught)) {
            return;
        }
        $wrong[] = $caught->getMessage();
    }

    /**
     * 升级脚本末尾对已移除类的调用视为可跳过，避免拷贝/SQL 已成功后被当成整单失败。
     *
     * @param \Exception|\Throwable $e
     * @return bool
     */
    private function isOptionalUpdateHookError($e)
    {
        $message = $e->getMessage();
        if (strpos($message, 'CredentialCipher') !== false) {
            return true;
        }

        // 模块版本门卫（T6）：降级拒绝属「跳过执行」语义，不得触发 clearModule 破坏式回滚
        return strpos($message, '已拒绝执行降级') !== false;
    }

    /**
     * 安装实际处理：根据 type / mode 完成文件拷贝、SQL 注入、配置文件改写。
     *
     * @param string $type
     * @param string $cloudId
     * @param string $mode
     * @return array|null 失败时返回错误信息数组，成功时返回 null
     */
    public function install($type, $cloudId, $mode)
    {
        $prefix = DB::getPrefix();
        $sql = '';
        $moduleType = '';
        $operate = array();
        $wrong = array();
        $this->installNotes = array();

        $itemZip = $this->cacheDir . $cloudId . '.zip';
        $itemDir = $this->cacheDir . $cloudId;
        $sqlInstallFile = $this->rootDir . 'storage/backup/' . $cloudId . '.sql';
        $updateDir = $this->rootDir . '_update/';
        $updateFile = $updateDir . 'action.php';
        $themeInitDir = $this->rootDir . 'theme/' . $cloudId . '/_init/';
        $themeInitFile = $themeInitDir . 'action.php';
        $themeInitImage = $themeInitDir . 'images/';
        $installedDir = STORAGE_PATH . 'install/records/';

        if (file_exists($updateDir)) {
            FileHelper::delDir($updateDir);
        }

        $copyFailed = $this->copyExtractedFiles($type, $cloudId, $mode, $itemDir, $installedDir);
        if ($copyFailed) {
            // 拷贝失败必须进入 $wrong：否则主题 / 插件更新会「半更新」被记为成功，
            // 失败快照回滚分支永远不会触发。仅逐条展示前 10 个，避免大目录刷屏。
            $shown = 0;
            foreach ($copyFailed as $rel) {
                if ($shown >= 10) {
                    break;
                }
                $wrong[] = str_replace('d%', (string) $rel, (string) lang('cloud_files_wrong'));
                $shown++;
            }
            if (count($copyFailed) > $shown) {
                $wrong[] = str_replace('d%', (string) count($copyFailed), (string) lang('cloud_files_wrong_count'));
            }
        }

        if ($type === 'module') {
            $moduleFlags = array(
                'link_user_center' => false,
                'link_work_center' => false,
                'link_order_item' => false,
                'link_ai' => false,
                'no_show_menu' => false,
                'no_show_nav' => false,
            );
            if ($mode !== 'update') {
                if (file_exists($sqlInstallFile)) {
                    $sql = file_get_contents($sqlInstallFile);
                    $sql = preg_replace('/dou_/Ums', $prefix, $sql);
                    if (DB::fnExecute($sql)) {
                        $moduleType = strpos($sql, $cloudId . '_category') === false ? 'single_module' : 'column_module';
                        $operate = $this->detectConfigOperates($sql);
                        if ($operate) {
                            $this->changeSystemConfig($cloudId, $operate);
                        }
                        $moduleFlags = $this->detectModuleFlags($sql);
                    } else {
                        $wrong[] = lang('cloud_sql_wrong');
                    }
                }
                if (strpos($sql, 'CREATE-NAV') !== false) {
                    $this->changeNav($cloudId, $moduleType);
                }
            } else {
                $this->includeOptionalScript($updateFile, $wrong);
            }

            if ($moduleType !== '') {
                if (!$this->changeModulePhp($cloudId, $moduleType, false, $moduleFlags['link_user_center'], $moduleFlags['link_work_center'], $moduleFlags['no_show_menu'], $moduleFlags['no_show_nav'], $moduleFlags['link_order_item'], $moduleFlags['link_ai'])) {
                    $wrong[] = lang('cloud_systemfile_wrong');
                }
                $this->changeMiniprogramConfigFile();
                // T6：模块「安装」成功后记录包版本（「更新」模式由包内升级载荷自行记录）
                if ($mode !== 'update') {
                    $this->recordModuleVersionFromPackage($cloudId, $itemDir);
                }
            }
        } elseif ($type === 'system' || $type === 'miniprogram' || $type === 'dou') {
            $this->includeOptionalScript($updateFile, $wrong);
        } elseif ($type === 'theme' && $mode !== 'update') {
            if (file_exists($themeInitDir)) {
                $this->includeOptionalScript($themeInitFile, $wrong);
                FileHelper::copyDir($themeInitImage, $this->rootDir . 'images/');
            }
        }

        if ($wrong) {
            // 模块 / 主题 / 插件「更新」失败优先快照回滚；模块无快照时回退 clearModule
            // 卸载语义（含安装失败）；主题 / 插件与其它类型保持现场不动。
            $restoreState = $this->tryRestoreUpdateSnapshot($type, $mode, $cloudId);
            if ($restoreState === 'none' && $type === 'module' && empty($this->installNotes)) {
                $this->clearModule($cloudId);
            }
        }
        @unlink($itemZip);
        FileHelper::delDir($itemDir);
        @unlink($sqlInstallFile);
        FileHelper::delDir($updateDir);
        FileHelper::delDir($themeInitDir);

        if ($wrong) {
            return $wrong;
        }

        if ($type !== 'system' && $type !== 'onedou' && $type !== 'dou') {
            $this->updateState->changeUpdateDate($type, $cloudId, false, $mode);
        }

        return null;
    }

    /**
     * 模块 / 主题 / 插件更新失败时从最近还原点回滚（替代 clearModule 破坏式卸载）。
     *
     * 语义约定（T7）：
     *   - 更新失败：优先回滚到更新前快照（模块含文件 + 数据库 + 模块版本账本；
     *     主题 / 插件为文件级快照，恢复 theme/<slug>/、plugin/<slug>/ 目录）；
     *     模块无可用快照时回退旧语义 clearModule（等价卸载）；
     *     快照存在但回滚未完成（失败 / 超时）时不执行 clearModule——半还原现场由
     *     后台「升级还原点」页继续/手动处理，避免把仍在用的扩展连文件一起删掉。
     *   - 模块「安装」失败与真卸载：保持 clearModule 卸载语义（由调用方处理）；
     *     主题 / 插件无快照时保持现场，不执行任何卸载动作。
     *
     * @param string $type
     * @param string $mode
     * @param string $cloudId
     * @return string restored（已回滚）| failed（有快照但未完成）| none（无快照，走旧语义）
     */
    private function tryRestoreUpdateSnapshot($type, $mode, $cloudId)
    {
        if (!in_array($type, array('module', 'theme', 'plugin'), true) || $mode !== 'update') {
            return 'none';
        }
        if (!class_exists('Dou\\Admin\\Service\\Backup\\RestoreService')
            || !class_exists('Dou\\Core\\Service\\Snapshot\\SnapshotRepository')) {
            return 'none';
        }

        $repository = new \Dou\Core\Service\Snapshot\SnapshotRepository(ROOT_PATH);
        $row = $repository->latest((string) $type, (string) $cloudId);
        if ($row === null) {
            return 'none';
        }

        $restore = new \Dou\Admin\Service\Backup\RestoreService(ROOT_PATH);
        $begin = $restore->begin($row['name']);
        if (empty($begin['ok'])) {
            $this->installNotes[] = str_replace('d%', (string) $begin['error'], (string) lang('cloud_snapshot_restore_failed'));
            return 'failed';
        }

        $deadline = time() + 180;
        while (true) {
            $step = $restore->step(array('max_seconds' => 20, 'file_batch' => 800, 'delete_batch' => 800));
            if (empty($step['ok'])) {
                $this->installNotes[] = str_replace('d%', (string) $step['error'], (string) lang('cloud_snapshot_restore_failed'));
                return 'failed';
            }
            if (!empty($step['done'])) {
                $this->installNotes[] = (string) lang('cloud_snapshot_restored');
                return 'restored';
            }
            if (time() > $deadline) {
                $this->installNotes[] = (string) lang('cloud_snapshot_restore_pending');
                return 'failed';
            }
        }
    }

    /**
     * 把解压后的目录文件按 type 拷贝到站点对应目录（同时记录 module 类型的 installed 清单）。
     *
     * 主题 / 插件返回失败文件的相对站点根路径列表（theme/<slug>/...、plugin/<slug>/...），
     * 由调用方并入 $wrong 以便触发快照回滚；其它类型保持既有拷贝语义，返回空数组。
     *
     * @param string $type
     * @param string $cloudId
     * @param string $mode
     * @param string $itemDir
     * @param string $installedDir
     * @return array 拷贝失败的站点相对路径列表
     */
    private function copyExtractedFiles($type, $cloudId, $mode, $itemDir, $installedDir)
    {
        if ($type === 'theme') {
            $themeDir = $this->rootDir . 'theme/' . $cloudId;
            if ($mode === 'update') {
                // 升级不覆盖站点已自定义的 Logo：先摘除包内与目标同名的 logo 图片，再整体覆盖拷贝
                $this->preserveThemeLogos($itemDir, $themeDir);
            }
            $failed = $this->copyDirCollectFailures($itemDir, $themeDir, 'theme/' . $cloudId . '/');
            FileHelper::copyDir($this->rootDir . 'images/data', $this->rootDir . 'images/data_old');
            return $failed;
        }

        if ($type === 'plugin') {
            $pluginDir = defined('PLUGIN_PATH') ? rtrim((string) PLUGIN_PATH, '/') . '/' : $this->rootDir . 'plugin/';
            return $this->copyDirCollectFailures($itemDir, $pluginDir . $cloudId, 'plugin/' . $cloudId . '/');
        }

        // 配置基准保护：升级包携带 _update/resources/config/ 基准文件时，包根 config/ 中与基准同名的
        // 文件不参与覆盖拷贝——站点现行配置文件保持原样，交由 _update/action.php 的
        // 配置合并引擎（Dou\Upgrade\Lib\ConfigMerge）做「基准 x 现行」合并（保留用户值）。摘除仅作用于本次临时解压
        // 目录（升级结束整体删除），包内 _update/resources/config/ 基准文件本身不受影响。
        $baselineDir = rtrim($itemDir, '/\\') . '/_update/resources/config/';
        if (is_dir($baselineDir)) {
            foreach ((array) @glob($baselineDir . '*.php') as $baselineFile) {
                $packageSameName = rtrim($itemDir, '/\\') . '/config/' . basename($baselineFile);
                if (is_file($packageSameName)) {
                    @unlink($packageSameName);
                }
            }
        }

        if ($type === 'system' && $mode === 'update') {
            if (Config::get('site.site_theme', '') === 'default') {
                FileHelper::copyDir($this->rootDir . 'theme/default', $this->rootDir . 'theme/default_old');
                FileHelper::copyDir(
                    $this->rootDir . 'theme/' . Config::get('site.site_theme', ''),
                    $this->rootDir . 'theme/' . Config::get('site.site_theme', '') . '_old'
                );
            }
        }

        if ($type === 'module') {
            $readDirFile = FileHelper::readDirFile($itemDir, $this->cacheDir . $cloudId . '/');
            $dropTableSqlList = $this->dropTableSql($cloudId);
            $installedKey = '<?php' . "\r\n"
                . '$installed_file_list = ' . "'" . serialize($readDirFile) . "';" . "\r\n"
                . '$installed_sql_list = ' . "'" . serialize($dropTableSqlList) . "'" . "\r\n"
                . '?>';

            if (!is_dir($installedDir)) {
                mkdir($installedDir, 0777, true);
            }
            file_put_contents($installedDir . $cloudId . '.installed.php', $installedKey);
        }

        if (SYSTEM_SIGN === 'api') {
            FileHelper::delDir($itemDir, false, '', false);
            FileHelper::delDir($itemDir . '/m/');
            FileHelper::delDir($itemDir . '/theme/');
        }

        if ($type === 'module') {
            if ($mode === 'update') {
                $siteTheme = (string) Config::get('site.site_theme', '');
                if (Config::get('site.update_overwritten_theme', false) || $siteTheme === '' || $siteTheme === 'default') {
                    // 覆盖全部文件：「升级时覆盖模板」开启，或站点使用默认模板（默认模板不受开关保护，始终覆盖）
                    FileHelper::copyDir($itemDir, $this->rootDir);
                } else {
                    // 关闭「升级时覆盖模板」仅保护非默认模板：跳过当前模板目录中已存在的文件，
                    // 其余文件（含默认模板 theme/default）仍无条件覆盖
                    FileHelper::copyDir($itemDir, $this->rootDir, false, true, false, '/theme/' . $siteTheme . '/');
                }
            } else {
                FileHelper::copyDir($itemDir, $this->rootDir, false, true);
            }
        } else {
            FileHelper::copyDir($itemDir, $this->rootDir);
        }

        return array();
    }

    /**
     * 递归拷贝目录并收集失败项（不静默吞掉 mkdir / copy 的失败）。
     *
     * 拷贝语义与 FileHelper::copyDir 的默认调用一致（递归、覆盖同名文件、不删源）；
     * 源目录不存在时视为无文件可拷（与原行为一致，不算失败）。供主题 / 插件更新把
     * 「半拷贝」升级为可判定的失败，从而触发快照回滚。
     *
     * @param string $sourceDir 源目录（绝对路径）
     * @param string $destinationDir 目标目录（绝对路径）
     * @param string $relative 相对站点根的路径前缀（含结尾 /，用于错误提示）
     * @return array 失败项的站点相对路径列表（目录创建失败时记目录本身）
     */
    private function copyDirCollectFailures($sourceDir, $destinationDir, $relative)
    {
        $failures = array();
        if (!@is_dir($sourceDir)) {
            return $failures;
        }
        if (!is_dir($destinationDir) && !@mkdir($destinationDir, 0777, true) && !is_dir($destinationDir)) {
            return array(rtrim($relative, '/'));
        }
        $handle = @opendir($sourceDir);
        if ($handle === false) {
            return array(rtrim($relative, '/'));
        }
        while (($file = @readdir($handle)) !== false) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $source = rtrim($sourceDir, '/\\') . '/' . $file;
            $target = rtrim($destinationDir, '/\\') . '/' . $file;
            $rel = $relative . $file;
            if (@is_dir($source) && !is_link($source)) {
                $failures = array_merge($failures, $this->copyDirCollectFailures($source, $target, $rel . '/'));
            } elseif (!@copy($source, $target) || !@is_file($target)) {
                $failures[] = $rel;
            }
        }
        @closedir($handle);

        return $failures;
    }

    /**
     * 主题升级：保留站点已自定义的 Logo 图片（theme/<id>/images/ 第一层，文件名以 logo 开头）。
     *
     * 主题包内与站点同名的 logo 文件在拷贝前从临时解压目录摘除，使 {@see FileHelper::copyDir}
     * 不再覆盖；目标不存在的 logo 文件（主题新增语言 / 新增 Logo）保留在包内照常安装。
     * 摘除仅作用于本次临时解压目录（升级结束整体删除），不动站点文件。
     * 保留动作本身不输出前台日志，避免升级日志里出现对用户无意义的文件名清单。
     *
     * @param string $itemDir 包解压目录（绝对路径）
     * @param string $themeDir 目标主题目录（绝对路径）
     * @return void
     */
    private function preserveThemeLogos($itemDir, $themeDir)
    {
        $sourceImageDir = rtrim(str_replace('\\', '/', (string) $itemDir), '/') . '/images/';
        $targetImageDir = rtrim(str_replace('\\', '/', (string) $themeDir), '/') . '/images/';
        if (!is_dir($sourceImageDir)) {
            return;
        }

        foreach ((array) @scandir($sourceImageDir) as $file) {
            if (!is_string($file) || $file === '.' || $file === '..') {
                continue;
            }
            // 仅处理第一层、以 logo 开头的图片文件名（不区分大小写）
            if (!preg_match('/^logo/i', $file) || !is_file($sourceImageDir . $file)) {
                continue;
            }
            if (is_file($targetImageDir . $file)) {
                @unlink($sourceImageDir . $file);
            }
        }
    }

    /**
     * 根据 SQL 文本检测 CREATE-CONFIG-* 操作集合。
     *
     * @param string $sql
     * @return array
     */
    private function detectConfigOperates($sql)
    {
        $operate = array();
        if (strpos($sql, 'CREATE-CONFIG-DISPLAY') !== false) {
            $operate[] = 'CREATE-CONFIG-DISPLAY';
        }
        if (strpos($sql, 'CREATE-CONFIG-HOME-DISPLAY') !== false) {
            $operate[] = 'CREATE-CONFIG-HOME-DISPLAY';
        }
        if (strpos($sql, 'CREATE-CONFIG-DEFINED') !== false) {
            $operate[] = 'CREATE-CONFIG-DEFINED';
        }

        return $operate;
    }

    /**
     * 根据 SQL 文本解析模块开关标志（LINK-*、NO-SHOW-*）。
     *
     * @param string $sql
     * @return array
     */
    private function detectModuleFlags($sql)
    {
        return array(
            'link_user_center' => strpos($sql, 'LINK-USER-CENTER') !== false,
            'link_work_center' => strpos($sql, 'LINK-WORK-CENTER') !== false,
            'link_order_item' => strpos($sql, 'LINK-ORDER-ITEM') !== false,
            'link_ai' => strpos($sql, 'LINK-AI') !== false,
            'no_show_menu' => strpos($sql, 'NO-SHOW-MENU') !== false,
            'no_show_nav' => strpos($sql, 'NO-SHOW-NAV') !== false,
        );
    }

    /**
     * 按 install-resolve 给出的 `auth_status` 生成 preflight 拒绝结果。
     *
     * 返回结构：{ error, redirect_url, redirect_label, redirect_target }；
     * 未识别的状态（含 `unavailable`）返回 null，由调用方按通用错误处理。
     *
     * @param string $authStatus
     * @return array|null
     */
    private function buildAuthDenial($authStatus)
    {
        switch ($authStatus) {
            case 'unauthorized':
                return array(
                    'error' => lang('cloud_copyright_no_vip'),
                    'redirect_url' => 'https://www.douphp.com/buy',
                    'redirect_label' => lang('cloud_buy_vip'),
                    'redirect_target' => '_blank',
                );
            case 'login_required':
                return array(
                    'error' => lang('cloud_install_login_required'),
                    'redirect_url' => route('admin.cloud.account'),
                    'redirect_label' => lang('cloud_install_login_cloud_account'),
                    'redirect_target' => '_self',
                );
            case 'not_purchased':
                return array(
                    'error' => lang('cloud_install_not_purchased'),
                    'redirect_url' => 'https://www.douphp.com/extend',
                    'redirect_label' => lang('cloud_install_buy_extend'),
                    'redirect_target' => '_blank',
                );
            case 'vip_required':
                return array(
                    'error' => lang('cloud_install_vip_required'),
                    'redirect_url' => 'https://www.douphp.com/buy',
                    'redirect_label' => lang('cloud_install_buy_vip'),
                    'redirect_target' => '_blank',
                );
            case 'vip_expired':
                return array(
                    'error' => lang('cloud_install_vip_expired'),
                    'redirect_url' => 'https://www.douphp.com/vip/renew',
                    'redirect_label' => lang('cloud_install_renew_vip'),
                    'redirect_target' => '_blank',
                );
            case 'unavailable':
                return array(
                    'error' => lang('cloud_install_resolve_unavailable'),
                    'redirect_url' => '',
                    'redirect_label' => '',
                    'redirect_target' => '',
                );
        }

        return null;
    }

    /**
     * 校验安装包下载 URL（防 SSRF）。
     *
     * 仅允许与 `cloud.download_base` 同 scheme/host/port，禁止 userinfo。
     * `cloud.api_base` 仅用于 API 通信，不参与下载地址校验。
     *
     * @param string $url
     * @return string 通过返回原串，否则空串
     */
    public function filterTrustedInstallDownloadUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2048) {
            return '';
        }

        $parts = @parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return '';
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        $downloadBase = rtrim((string) Config::get('cloud.download_base', ''), '/');
        if ($downloadBase === '') {
            return '';
        }

        // 主机必须与 download_base 完全一致；协议在过渡期允许 http/https 并存
        // （download_base 已切 https；云端尚未切换前仍可能下发 http，同一主机视为可信——
        //  包完整性由 install-resolve 下发的 sha256 强校验兜底）。
        $baseParts = @parse_url($downloadBase);
        if (!$baseParts || empty($baseParts['host'])) {
            return '';
        }
        $gotHost = strtolower($parts['host']);
        $wantHost = strtolower($baseParts['host']);
        if ($gotHost !== $wantHost) {
            return '';
        }

        // 仅允许默认端口（http 80 / https 443），防非常规端口外联
        $defPort = ($scheme === 'https') ? 443 : 80;
        $gotPort = isset($parts['port']) ? (int) $parts['port'] : $defPort;
        if (!in_array($gotPort, array(80, 443), true)) {
            return '';
        }

        return $url;
    }

    /**
     * 同步目录名（主题、小程序、admin 别名等）。
     *
     * @param string $type
     * @param string $itemDir
     * @param string $mode
     * @return void
     */
    public function synchronizeDirname($type, $itemDir, $mode)
    {
        if ($type !== 'system') {
            if (file_exists($itemDir . '/theme/default') && Config::get('site.site_theme', '') !== 'default') {
                FileHelper::copyDir($itemDir . '/theme/default', $itemDir . '/theme/' . Config::get('site.site_theme', ''));
            }
            if (file_exists($itemDir . '/miniprogram/default') && Config::get('site.miniprogram_code', '') !== 'default') {
                FileHelper::copyDir(
                    $itemDir . '/miniprogram/default',
                    $itemDir . '/miniprogram/' . Config::get('site.miniprogram_code', '')
                );
            }
        }

        if (file_exists($itemDir . '/miniprogram')) {
            if (MINIPROGRAM_DIR !== 'miniprogram') {
                @rename($itemDir . '/miniprogram', $itemDir . '/' . MINIPROGRAM_DIR);
            }
        }
        if (ADMIN_DIR !== 'admin') {
            @rename($itemDir . '/admin', $itemDir . '/' . ADMIN_DIR);
        }
    }

    /**
     * 校验当前内核版本是否满足扩展最低版本要求。
     *
     * @param string $version
     * @return array
     */
    public function checkMiniVersionSupport($version)
    {
        $wrong = array();
        $clientVersionNumber = substr(Config::get('site.douphp_version', ''), -8);
        if ($clientVersionNumber < $version) {
            $wrong[] = lang('cloud_below_mini_version_support');
        }

        return $wrong;
    }

    /**
     * 重复安装检查（module/plugin/miniprogram 已存在时阻断）。
     *
     * @param string $type
     * @param string $cloudId
     * @return array
     */
    public function installCheck($type, $cloudId)
    {
        $wrong = array();
        if ($type === 'module') {
            if (in_array($cloudId, (array) Config::get('module.all_module'))) {
                $wrong[] = lang('cloud_' . $type) . lang('cloud_install_repeat');
            }
        } elseif ($type === 'plugin') {
            $pluginDir = defined('PLUGIN_PATH') ? rtrim((string) PLUGIN_PATH, '/') . '/' : $this->rootDir . 'plugin/';
            if (file_exists($pluginDir . $cloudId)) {
                $wrong[] = lang('cloud_' . $type) . lang('cloud_install_repeat');
            }
        } elseif ($type === 'miniprogram') {
            if (file_exists($this->rootDir . MINIPROGRAM_DIR . '/' . $cloudId)) {
                $wrong[] = lang('cloud_' . $type) . lang('cloud_install_repeat');
            }
        }

        return $wrong;
    }

    /**
     * 改写 config/module.php：插入或删除模块标识与开关项。
     *
     * @param string $cloudId
     * @param string $type column_module|single_module（删除时可为空）
     * @param bool $del true 表示从配置文件移除模块
     * @param bool $linkUserCenter
     * @param bool $linkWorkCenter
     * @param bool $noShowMenu
     * @param bool $noShowNav
     * @param bool $linkOrderItem
     * @param bool $linkAi
     * @return bool|null 写入成功返回 true
     */
    public function changeModulePhp($cloudId, $type = '', $del = false, $linkUserCenter = false, $linkWorkCenter = false, $noShowMenu = false, $noShowNav = false, $linkOrderItem = false, $linkAi = false)
    {
        // 读磁盘账本原始数据（仅 8 桶键），不读 Config('module') —— 后者经 CoreModuleSettings::build
        // 附带了运行时派生的 all_module，写回会污染 config/module.php。
        $ledger = (array) app(ModuleSettingReader::class)->read();

        $bucketKeys = array(
            'column_module',
            'single_module',
            'link_user_center',
            'link_work_center',
            'link_ai',
            'no_show_menu',
            'no_show_nav',
            'link_order_item',
        );

        $buckets = array();
        foreach ($bucketKeys as $bucket) {
            $buckets[$bucket] = (isset($ledger[$bucket]) && is_array($ledger[$bucket])) ? array_values($ledger[$bucket]) : array();
        }

        if ($del) {
            foreach ($bucketKeys as $bucket) {
                $buckets[$bucket] = array_values(array_filter(
                    $buckets[$bucket],
                    function ($value) use ($cloudId) {
                        return $value !== $cloudId;
                    }
                ));
            }
        } else {
            if ($type === 'column_module') {
                $buckets['column_module'][] = $cloudId;
            } else {
                $buckets['single_module'][] = $cloudId;
            }
            if ($linkUserCenter) {
                $buckets['link_user_center'][] = $cloudId;
            }
            if ($linkWorkCenter) {
                $buckets['link_work_center'][] = $cloudId;
            }
            if ($noShowMenu) {
                $buckets['no_show_menu'][] = $cloudId;
            }
            if ($noShowNav) {
                $buckets['no_show_nav'][] = $cloudId;
            }
            if ($linkOrderItem) {
                $buckets['link_order_item'][] = $cloudId;
            }
            if ($linkAi) {
                $buckets['link_ai'][] = $cloudId;
            }
        }

        foreach ($bucketKeys as $bucket) {
            $buckets[$bucket] = array_values(array_unique($buckets[$bucket]));
        }

        // 按原文件键序回写：已知桶用处理后的结果，账本中的其它键原样透传；文件缺失的桶补到末尾。
        $output = array();
        foreach ($ledger as $key => $value) {
            $output[$key] = in_array($key, $bucketKeys, true) ? $buckets[$key] : $value;
        }
        foreach ($bucketKeys as $bucket) {
            if (!array_key_exists($bucket, $output)) {
                $output[$bucket] = $buckets[$bucket];
            }
        }

        $moduleFile = $this->rootDir . 'config/module.php';
        // 头部版权注释与安装包内 config/module.php 保持一致：写盘时一并拼接，避免安装/卸载重写后头部被冲掉。
        $payload = "<?php\n\n/**\n * DouPHP®\n * ------------------------------------------------------------------------------------\n"
            . " * Copyright (c) 2013-2026 漳州豆壳网络科技有限公司 (DouCo® Co.,Ltd.)\n"
            . " *\n * 本软件基于 MIT 协议开源发布，完整协议文本见项目根目录 LICENSE 文件。\n"
            . " * 网站地址：http://www.douphp.com\n * ------------------------------------------------------------------------------------\n"
            . " * Author: DouCo Co.,Ltd.\n * Release Date: 2026-09-08\n */\n\n"
            . "return " . Arr::export($output) . ";\n";
        // LOCK_EX：多 worker（FastCGI）下避免并发读到写了一半的文件。
        if (file_put_contents($moduleFile, $payload, LOCK_EX)) {
            // config/module.php 由 bootstrap 每次请求 include 读取并派生 all_module；开启 OPcache 时
            // 旧字节码会被缓存，导致安装/卸载写盘后立即跳转的列表页仍读到改写前的模块账本（需再次
            // 刷新才消失）。写盘成功后主动失效该文件的 OPcache 条目，确保下一请求重新编译。
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($moduleFile, true);
            }
            return true;
        }

        return null;
    }

    /**
     * 改写 display / defined 配置（按 CREATE-CONFIG-* 信号增删 cloud_id 的展示项）。
     *
     * @param string $cloudId
     * @param array|string $operate
     * @return void
     */
    public function changeSystemConfig($cloudId, $operate)
    {
        $display = unserialize(Config::get('site.display', ''));
        $defined = unserialize(Config::get('site.defined', ''));
        if ($operate === 'DELL') {
            unset($display[$cloudId], $display['home_' . $cloudId], $defined[$cloudId]);
        } else {
            if (in_array('CREATE-CONFIG-DISPLAY', $operate)) {
                $display[$cloudId] = 10;
            }
            if (in_array('CREATE-CONFIG-HOME-DISPLAY', $operate)) {
                $display['home_' . $cloudId] = 5;
            }
            if (in_array('CREATE-CONFIG-DEFINED', $operate)) {
                $defined[$cloudId] = '';
            }
        }

        DB::table('config')->where('name', 'defined')->update(array('value' => serialize($defined)));
        DB::table('config')->where('name', 'display')->update(array('value' => serialize($display)));
    }

    /**
     * 维护 nav 表的中部导航：新建模块时插入，卸载时删除。
     *
     * @param string $cloudId
     * @param string $moduleType column_module|single_module
     * @param bool $del
     * @return void
     */
    public function changeNav($cloudId, $moduleType = '', $del = false)
    {
        if ($del) {
            DB::table('nav')->where('module', $cloudId)->delete();
            DB::table('nav')->where('module', $cloudId . '_category')->delete();
            return;
        }

        $langFile = ROOT_PATH . 'languages/' . Config::get('site.language', '') . '/admin/' . $cloudId . '.lang.php';
        if (!file_exists($langFile)) {
            $langFile = ROOT_PATH . 'languages/zh_cn/admin/' . $cloudId . '.lang.php';
        }
        include($langFile);
        $navName = isset($_LANG['nav_' . $cloudId]) ? $_LANG['nav_' . $cloudId] : $cloudId;
        $module = $moduleType === 'column_module' ? $cloudId . '_category' : $cloudId;
        DB::table('nav')->insert(array(
            'module' => $module,
            'name' => $navName,
            'type' => 'middle',
        ));
    }

    /**
     * 卸载模块清单：根据 installed 文件回滚拷贝过的文件、删除表、重置配置。
     *
     * @param string $cloudId
     * @return void
     */
    public function clearModule($cloudId)
    {
        $prefix = DB::getPrefix();
        $moduleInstalledFile = STORAGE_PATH . 'install/records/' . $cloudId . '.installed.php';

        if (file_exists($moduleInstalledFile)) {
            $installed_file_list = '';
            $installed_sql_list = '';
            include($moduleInstalledFile);

            $installedFileList = (array) unserialize((string) $installed_file_list);

            $resolvedPaths = array();
            foreach ($installedFileList as $line) {
                foreach ($this->resolveInstalledRelativePaths((string) $line) as $relPath) {
                    $resolvedPaths[] = $relPath;
                }
            }
            $resolvedPaths = array_values(array_unique($resolvedPaths));

            $dirAbsPaths = array();
            foreach ($resolvedPaths as $relPath) {
                $absPath = ROOT_PATH . $relPath;
                if (is_file($absPath)) {
                    @unlink($absPath);
                } elseif (is_dir($absPath)) {
                    $dirAbsPaths[] = $absPath;
                }
            }

            FileHelper::removeEmptyDirs($dirAbsPaths);

            $installedSqlList = unserialize((string) $installed_sql_list);
            foreach ((array) $installedSqlList as $line) {
                $line = preg_replace('/dou_/Ums', $prefix, $line);
                DB::query($line);
            }
        }

        MetadataService::instance()->deleteByModule($cloudId);
        $metadataTranslations = new MetadataTranslationStore();
        $metadataTranslations->deleteByModule($cloudId);
        MetadataValues::purgeByModule($cloudId);

        $this->changeSystemConfig($cloudId, 'DELL');
        $this->changeNav($cloudId, null, true);
        $this->changeModulePhp($cloudId, null, true);
        $this->changeMiniprogramConfigFile();
        // T6：模块被清理（卸载 / 失败回滚）时同步移除其包版本记录，避免残留脏账本
        $this->updateState->removeModuleVersion($cloudId);
        // T7：模块卸载（含安装失败回滚）时清理其专属还原点，避免残留不可再用的快照
        if (class_exists('Dou\\Core\\Service\\Snapshot\\SnapshotRepository')) {
            $repository = new \Dou\Core\Service\Snapshot\SnapshotRepository(ROOT_PATH);
            $repository->deleteByScope('module', $cloudId);
        }
    }

    /**
     * 解析 installed 清单中的单行路径，输出相对 ROOT_PATH 的实际路径数组。
     *
     * 复用安装时的 `#admin/` / `#miniprogram/` 前缀替换；当原路径含
     * `theme/default` 或 `miniprogram/default` 且当前站点改用了其它主题 / 小程序代号时，
     * 追加变体路径，保证两份目录都能被清理。
     *
     * @param string $line installed 清单中的原始路径（以 `#` 起头）
     * @return array 相对 ROOT_PATH 的路径数组（统一 `/` 分隔，1~3 条）
     */
    private function resolveInstalledRelativePaths($line)
    {
        $line = str_replace('#admin/', '#' . ADMIN_DIR . '/', $line);
        $line = str_replace('#miniprogram/', '#' . MINIPROGRAM_DIR . '/', $line);
        $line = str_replace('#', '', $line);

        // 旧式 .installed.php 清单可能仍写 `data/backup/` / `data/installed/` / `data/slide/`；
        // 解析阶段映射为 `storage/backup/` / `storage/install/records/` / `images/slide/`；
        // 2.0 中间态清单写的 `storage/installed/` 同样映射到 `storage/install/records/`。
        if ($line === 'data') {
            // 顶层 `data` 目录名映射为 `storage`（removeEmptyDirs 只清空目录，
            // storage/ 内仍有其他模块的运行时数据时不会被删除）。
            $line = 'storage';
        } else {
            $line = preg_replace('#^data/backup(/|$)#', 'storage/backup$1', $line);
            $line = preg_replace('#^data/installed(/|$)#', 'storage/install/records$1', $line);
            $line = preg_replace('#^storage/installed(/|$)#', 'storage/install/records$1', $line);
            $line = preg_replace('#^data/slide(/|$)#', 'images/slide$1', $line);
        }

        $paths = array($line);

        $siteTheme = Config::get('site.site_theme', '');
        if (strpos($line, 'theme/default') !== false && $siteTheme !== '' && $siteTheme !== 'default') {
            $paths[] = str_replace('default', $siteTheme, $line);
        }

        $miniprogramCode = Config::get('site.miniprogram_code', '');
        if (strpos($line, 'miniprogram/default') !== false && $miniprogramCode !== '' && $miniprogramCode !== 'default') {
            $paths[] = str_replace('default', $miniprogramCode, $line);
        }

        return array_values(array_unique($paths));
    }

    /**
     * 直接 DROP 当前 sql 清单中的表（仅模块用）。
     *
     * @param string $cloudId
     * @return bool|null 成功 true，没有可执行 SQL 时 null
     */
    public function delModuleTable($cloudId)
    {
        $dropTableSqlList = $this->dropTableSql($cloudId);
        if ($dropTableSqlList) {
            foreach ((array) $dropTableSqlList as $line) {
                if (!DB::query($line)) {
                    return false;
                }
            }
            return true;
        }

        return null;
    }

    /**
     * 提取扩展包 sql 中的 DROP TABLE 语句列表（替换为当前表前缀）。
     *
     * @param string $cloudId
     * @return array
     */
    public function dropTableSql($cloudId)
    {
        $prefix = DB::getPrefix();
        $dropTableSqlList = array();
        $sqlFile = $this->cacheDir . $cloudId . '/storage/backup/' . $cloudId . '.sql';
        if (file_exists($sqlFile)) {
            $content = file($sqlFile);
            foreach ((array) $content as $line) {
                if (strpos($line, 'DROP TABLE IF EXISTS') !== false) {
                    $line = preg_replace('/dou_/Ums', $prefix, trim($line));
                    $dropTableSqlList[] = $line;
                }
            }
        }

        return $dropTableSqlList;
    }

    /**
     * 模块还原点的模块标识：优先包内 _update/version.php 的 module 字段。
     *
     * @param string $cloudId
     * @return string
     */
    private function snapshotModuleId($cloudId)
    {
        $metaFile = rtrim($this->cacheDir . $cloudId, '/\\') . '/_update/version.php';
        if (is_file($metaFile)) {
            $meta = include $metaFile;
            if (is_array($meta) && !empty($meta['module'])) {
                return (string) $meta['module'];
            }
        }

        return (string) $cloudId;
    }

    /**
     * 模块还原点候选表清单：安装记录为主，包内标准 SQL 兜底并集。
     *
     * 只返回归一后的完整表名；是否真实存在由 SnapshotService 与数据库表清单精确交集。
     *
     * @param string $cloudId
     * @param string $moduleId
     * @return array
     */
    private function snapshotModuleTables($cloudId, $moduleId)
    {
        if (!preg_match('/^[A-Za-z0-9_\-]+$/', (string) $cloudId)) {
            return array();
        }

        $prefix = (string) DB::getPrefix();
        $candidates = array_merge(
            $this->moduleTablesFromRecords($cloudId),
            $this->moduleTablesFromPackageSql($cloudId, $moduleId)
        );

        $tables = array();
        foreach ($candidates as $name) {
            $full = $this->normalizeModuleTableName($name, $prefix);
            if ($full === '') {
                continue;
            }
            $bare = substr($full, strlen($prefix));
            if (!$this->isModuleOwnedTable($bare, $moduleId, $cloudId)) {
                continue;
            }
            $tables[strtolower($full)] = $full;
        }

        return array_values($tables);
    }

    /**
     * 从安装记录 storage/install/records/{id}.installed.php 提取模块表。
     *
     * @param string $cloudId
     * @return array
     */
    private function moduleTablesFromRecords($cloudId)
    {
        $file = STORAGE_PATH . 'install/records/' . $cloudId . '.installed.php';
        if (!is_file($file)) {
            return array();
        }

        // 闭包隔离记录文件里的变量，避免与当前方法作用域互相覆盖
        $loader = function ($recordFile) {
            $installed_sql_list = '';
            include $recordFile;
            return (string) $installed_sql_list;
        };
        $list = @unserialize($loader($file));
        $tables = array();
        foreach ((array) $list as $sql) {
            if (preg_match('/DROP\s+TABLE\s+IF\s+EXISTS\s+`?([A-Za-z0-9_]+)`?/i', (string) $sql, $m)) {
                $tables[] = $m[1];
            }
        }

        return $tables;
    }

    /**
     * 从模块包标准 SQL 提取模块表（兼容 storage/backup 与旧 data/backup 布局）。
     *
     * @param string $cloudId
     * @param string $moduleId
     * @return array
     */
    private function moduleTablesFromPackageSql($cloudId, $moduleId)
    {
        $names = array_unique(array((string) $cloudId, (string) $moduleId));
        $paths = array();
        foreach ($names as $name) {
            if ($name === '' || !preg_match('/^[A-Za-z0-9_\-]+$/', $name)) {
                continue;
            }
            $paths[] = $this->cacheDir . $cloudId . '/storage/backup/' . $name . '.sql';
            $paths[] = $this->cacheDir . $cloudId . '/data/backup/' . $name . '.sql';
        }

        $tables = array();
        foreach (array_unique($paths) as $path) {
            if (!is_file($path)) {
                continue;
            }
            $content = (string) file_get_contents($path);
            if (preg_match_all('/^\s*(?:DROP\s+TABLE\s+IF\s+EXISTS|CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?)\s+`?([A-Za-z0-9_]+)`?/im', $content, $m)) {
                foreach ($m[1] as $name) {
                    $tables[] = $name;
                }
            }
        }

        return $tables;
    }

    /**
     * 表名归一：去反引号 / 当前前缀 / 导出前缀 dou_，再补当前前缀。
     *
     * @param string $name
     * @param string $prefix
     * @return string 非法或空串返回 ''
     */
    private function normalizeModuleTableName($name, $prefix)
    {
        $name = trim((string) $name);
        $name = trim($name, '`');
        if ($name === '') {
            return '';
        }

        if ($prefix !== '' && strpos($name, $prefix) === 0) {
            $bare = substr($name, strlen($prefix));
        } elseif (stripos($name, 'dou_') === 0) {
            $bare = substr($name, 4);
        } else {
            $bare = $name;
        }

        if (!preg_match('/^[A-Za-z0-9_]+$/', $bare)) {
            return '';
        }

        return $prefix . $bare;
    }

    /**
     * 表归属闸门：裸表名必须等于模块标识或以「模块标识_」开头。
     *
     * @param string $bare
     * @param string $moduleId
     * @param string $cloudId
     * @return bool
     */
    private function isModuleOwnedTable($bare, $moduleId, $cloudId)
    {
        $bare = (string) $bare;
        $ids = array_unique(array((string) $moduleId, (string) $cloudId));
        foreach ($ids as $id) {
            $id = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $id);
            if ($id === '') {
                continue;
            }
            if ($bare === $id || strpos($bare, $id . '_') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * 下载扩展包到本地缓存目录。
     *
     * 对下载地址统一使用 POST，body 携带云账号、站点信息及与云端一致的 `localsystem`（便于下载域解析版本等）。
     *
     * 大体积系统升级包（~10M）不再整包读入内存字符串（旧实现在此对 10M 二进制跑 trim + 全量正则，
     * 既慢又易因内存拷贝受影响），而是通过 {@see Client} 的 `stream_to` 直接流式落盘；
     * 随后仅读文件头做 ZIP 归档签名判定，并用 ZipArchive 完整性校验 + Content-Length 比对拦截
     * “下载中途截断但开头仍为 PK”导致的后续「压缩包解压失败」；遇瞬时/截断失败自动重试。
     *
     * @param string $fileUrl
     * @param string $savePath
     * @param string $progressFile 可选：下载进度文件路径（非空时启用 curl 进度回调，节流写入 total/now/t）
     * @return string|false 成功返回保存绝对路径，失败返回 false
     */
    public function downloadFile($fileUrl, $savePath, $progressFile = '')
    {
        $this->lastDownloadFailureDetail = '';
        $basename = basename($fileUrl);
        $fileName = strpos($basename, '.html') ? str_replace('.html', '.zip', $basename) : $basename;
        $saveFile = $savePath . $fileName;
        $fileUrl = str_replace(' ', '%20', $fileUrl);
        $cloudAccount = CloudService::loadCloudAccount();
        $data = array(
            'user' => isset($cloudAccount['user']) ? $cloudAccount['user'] : '',
            'password' => isset($cloudAccount['password']) ? $cloudAccount['password'] : '',
            'url' => ROOT_URL,
            'system_sign' => SYSTEM_SIGN,
            'localsystem' => $this->updateState->localSystemPayload(),
        );

        // 下载进度回调（进度文件非空时启用）：0.5 秒节流写盘，供前端轮询接口读取显示百分比
        $progressCb = null;
        if ($progressFile !== '') {
            $lastWritten = 0.0;
            $progressCb = function ($dlTotal, $dlNow) use (&$lastWritten, $progressFile) {
                $dlTotal = (int) $dlTotal;
                $dlNow = (int) $dlNow;
                if ($dlTotal <= 0) {
                    return;
                }
                $now = microtime(true);
                if ($dlNow < $dlTotal && ($now - $lastWritten) < 0.5) {
                    return;
                }
                $lastWritten = $now;
                @file_put_contents(
                    $progressFile,
                    json_encode(array('total' => $dlTotal, 'now' => $dlNow, 't' => time())),
                    LOCK_EX
                );
            };
        }

        $mirrorErrors = array('upstream_not_found', 'upstream_unavailable', 'upstream_misconfigured');

        // 先清理可能残留的旧包，避免上一次失败留下半截文件被误判为完整。
        @unlink($saveFile);

        $attempts = 3;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = $this->attemptStreamDownload($fileUrl, $data, $saveFile, $mirrorErrors, $progressCb);
            if ($result === true) {
                return $saveFile;
            }

            $this->lastDownloadFailureDetail = $result;
            @unlink($saveFile);

            // 仅对可瞬时恢复的失败（网络错误/截断/空响应/一般性 5xx）重试；权限/不存在类错误直接返回。
            if (!$this->isRetryableDownloadFailure($result) || $attempt >= $attempts) {
                return false;
            }
            usleep(400000); // 0.4s 退避
        }

        return false;
    }

    /**
     * 单次流式下载尝试。
     *
     * @param callable|null $progressCb 可选进度回调（入参 $dlTotal / $dlNow），转交 {@see Client} 的 progress_cb
     * @return true|string 成功返回 true；失败返回失败原因码（供 {@see $lastDownloadFailureDetail}）
     */
    private function attemptStreamDownload($fileUrl, array $data, $saveFile, array $mirrorErrors, $progressCb = null)
    {
        $options = array(
            'return_meta' => true,
            'timeout' => 7200,
            'connect_timeout' => 20,
            'stream_to' => $saveFile,
        );
        if (is_callable($progressCb)) {
            $options['progress_cb'] = $progressCb;
        }
        $meta = Client::request('POST', $fileUrl, $data, array(), $options);

        if (!is_array($meta)) {
            return 'curl_error';
        }

        $errno = isset($meta['errno']) ? (int) $meta['errno'] : 0;
        if ($errno !== 0) {
            return 'curl_error';
        }

        $httpCode = isset($meta['http_code']) ? (int) $meta['http_code'] : 0;
        $size = isset($meta['size']) ? (int) $meta['size'] : 0;
        $contentLength = isset($meta['content_length']) ? (int) $meta['content_length'] : -1;

        // 空响应：可能是登录/授权哨兵或镜像错误的小文本，读回落盘头部判定。
        if ($size <= 0 || !is_file($saveFile)) {
            return $this->classifyErrorBody($saveFile, $mirrorErrors, 'empty_body');
        }

        // 非 2xx：读小 body 区分具体上游错误。
        if ($httpCode < 200 || $httpCode >= 300) {
            if ($httpCode === 404) {
                return 'upstream_not_found';
            }
            if ($httpCode === 502) {
                return 'upstream_unavailable';
            }
            if ($httpCode === 500) {
                return $this->readHead($saveFile, 256) === 'upstream_misconfigured' ? 'upstream_misconfigured' : 'http_bad_status';
            }
            return 'http_bad_status';
        }

        // 2xx：先按 ZIP 归档签名判定（仅读文件头，不再整包 trim / 正则）。
        if (!$this->fileLooksLikeZip($saveFile)) {
            return $this->classifyErrorBody($saveFile, $mirrorErrors, 'invalid_package');
        }

        // 完整性校验：Content-Length 已知时比对已下载字节数，识别中途截断。
        if ($contentLength > 0 && $size !== $contentLength) {
            return 'truncated_package';
        }

        // 结构校验：能被归档库成功打开说明尾部中央目录完整（截断包在此失败，避免拖到解压步骤）。
        if (!$this->zipArchiveIsReadable($saveFile)) {
            return 'truncated_package';
        }

        return true;
    }

    /**
     * 失败码是否值得重试（瞬时网络/截断/一般 5xx），权限与不存在类错误不重试。
     *
     * @param string $code
     * @return bool
     */
    private function isRetryableDownloadFailure($code)
    {
        return in_array($code, array('curl_error', 'truncated_package', 'empty_body', 'http_bad_status'), true);
    }

    /**
     * 读取文件头部字节（用于哨兵/签名判定，不将整个大文件载入内存）。
     *
     * @param string $file
     * @param int $len
     * @return string 已 trim 的头部内容
     */
    private function readHead($file, $len)
    {
        if (!is_file($file)) {
            return '';
        }
        $fp = @fopen($file, 'rb');
        if ($fp === false) {
            return '';
        }
        $head = @fread($fp, max(1, (int) $len));
        @fclose($fp);

        return $head === false ? '' : trim($head);
    }

    /**
     * 从落盘的（小）错误正文识别常见哨兵，返回对应失败码。
     *
     * @param string $saveFile
     * @param array $mirrorErrors
     * @param string $defaultCode 无法归类时的默认码
     * @return string
     */
    private function classifyErrorBody($saveFile, array $mirrorErrors, $defaultCode)
    {
        $head = $this->readHead($saveFile, 512);
        if ($head === '') {
            return $defaultCode;
        }
        foreach ($mirrorErrors as $code) {
            if ($head === $code) {
                return $code;
            }
        }
        if ($head === 'login_required') {
            return 'login_required';
        }
        if (strpos($head, '404 Not Found') !== false) {
            return 'upstream_not_found';
        }
        if (preg_match('/^(invalid_mode|invalid_id|invalid_params)$/', $head)) {
            return 'http_bad_status';
        }

        return $defaultCode;
    }

    /**
     * 判断落盘文件是否为合法 ZIP 归档（仅校验本地文件头 4 字节签名）。
     *
     * @param string $file
     * @return bool
     */
    private function fileLooksLikeZip($file)
    {
        if (!is_file($file)) {
            return false;
        }
        $fp = @fopen($file, 'rb');
        if ($fp === false) {
            return false;
        }
        $signature = @fread($fp, 4);
        @fclose($fp);
        if ($signature === false || strlen($signature) < 4) {
            return false;
        }

        return $signature === "PK\x03\x04" || $signature === "PK\x05\x06" || $signature === "PK\x07\x08";
    }

    /**
     * 用 ZipArchive 打开归档校验完整性（中央目录缺失/截断包会打开失败）。
     *
     * ZipArchive 扩展不可用时仅能依赖 Content-Length 比对，此处放行交由解压步骤处理。
     *
     * @param string $file
     * @return bool
     */
    private function zipArchiveIsReadable($file)
    {
        if (!class_exists('ZipArchive')) {
            return true;
        }
        $archive = new \ZipArchive();
        $opened = @$archive->open($file);
        if ($opened === true) {
            $archive->close();

            return true;
        }

        return false;
    }

    /**
     * 将 {@see $lastDownloadFailureDetail} 映射为安装步骤展示用错误句。
     *
     * @return string
     */
    private function resolveDownloadFailureMessage()
    {
        $detail = $this->lastDownloadFailureDetail;
        $map = array(
            'upstream_not_found' => 'cloud_down_upstream_not_found',
            'upstream_unavailable' => 'cloud_down_upstream_unavailable',
            'upstream_misconfigured' => 'cloud_down_upstream_misconfigured',
            'login_required' => 'cloud_down_login_required',
            'invalid_package' => 'cloud_down_invalid_package',
            'truncated_package' => 'cloud_down_truncated',
        );
        if (isset($map[$detail]) && lang_has($map[$detail])) {
            return lang($map[$detail]);
        }

        return lang('cloud_down_wrong');
    }

    /**
     * 遍历小程序代码包内某模块目录，发现其全部子页面（剔除主页面 {module}/{module}）。
     *
     * 以页面入口文件 `*.wxml` 为页面标志，递归扫描 `{pagesRoot}/{module}` 下所有页面，
     * 返回相对模块目录、去扩展名、确定性升序排序的子页路径（如 `list`、`user`、`user/show`）。
     *
     * @param string $pagesRoot 代码包 pages 目录绝对路径
     * @param string $module 模块短名
     * @return array
     */
    private function discoverModulePageSubPaths($pagesRoot, $module)
    {
        $moduleDir = $pagesRoot . '/' . $module;
        if (!is_dir($moduleDir)) {
            return array();
        }

        $subPaths = array();
        foreach ($this->globRecursiveWxml($moduleDir) as $file) {
            $rel = str_replace('\\', '/', substr($file, strlen($moduleDir) + 1));
            $rel = preg_replace('/\.wxml$/', '', $rel);
            if ($rel === $module) {
                continue;
            }
            $subPaths[] = $rel;
        }
        sort($subPaths, SORT_STRING);

        return $subPaths;
    }

    /**
     * 递归收集目录下所有 `*.wxml` 页面入口文件的绝对路径。
     *
     * @param string $dir
     * @return array
     */
    private function globRecursiveWxml($dir)
    {
        $result = array();
        $items = glob($dir . '/*');
        if (!is_array($items)) {
            return $result;
        }
        foreach ($items as $item) {
            if (is_dir($item)) {
                $result = array_merge($result, $this->globRecursiveWxml($item));
            } elseif (substr($item, -5) === '.wxml') {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * 去重保序并强制启动页 `pages/index/index` 排在首位。
     *
     * @param array $pages
     * @return array
     */
    private function normalizeMiniprogramPages(array $pages)
    {
        $pages = array_values(array_unique($pages));
        $home = 'pages/index/index';
        $idx = array_search($home, $pages, true);
        if ($idx !== false && $idx !== 0) {
            unset($pages[$idx]);
            array_unshift($pages, $home);
        }

        return array_values($pages);
    }

    /**
     * 判断模块的 API 主控制器是否存在；小程序 app.json 仅收录已实装模块的页面。
     *
     * @param string $module
     * @return bool
     */
    private function hasApiModuleController($module)
    {
        return is_readable($this->apiModuleControllerFile($module));
    }

    /**
     * 拼出模块 API 主控制器的绝对路径。
     *
     * @param string $module
     * @return string
     */
    private function apiModuleControllerFile($module)
    {
        return ROOT_PATH . API_DIR . '/controller/' . $module . '/' . Naming::studly($module) . 'Controller.php';
    }

    /**
     * 同步小程序代码包 app.json / 路由表 / 冷启动语言种子 / 运行时配置，仅写入 default 与当前启用包（site.miniprogram_code）。
     *
     * 导航标题改由小程序端运行时经 commonStore.lang（route=lang 全量译串包）+ utils/page_title.ts 取值；
     * 冷启动首帧文案由 config/seed.generated.ts（本方法写入）提供，与 GET lang 刷新同源。
     *
     * @param string $miniprogramDomain
     * @return bool|null 无任何代码包含 app.json 时返回 false
     */
    public function changeMiniprogramConfigFile($miniprogramDomain = '')
    {
        $miniprogramRoot = ROOT_PATH . MINIPROGRAM_DIR;
        if (!is_dir($miniprogramRoot)) {
            return false;
        }

        $slugList = $this->resolveMiniprogramSyncSlugs($miniprogramRoot);
        if (!$slugList) {
            return false;
        }

        $noHandle = (array) Config::get('system.miniprogram_no_handle', array());
        $discoverySlug = in_array('default', $slugList, true) ? 'default' : $slugList[0];
        $pagesRoot = $miniprogramRoot . '/' . $discoverySlug . '/pages';

        $pages = array();

        // 内建模块（始终注册进 app.json，需有 API 控制器 + 磁盘页面；不生成导航标题项）
        foreach ((array) Config::get('system.miniprogram_builtin_module', array()) as $module) {
            if (!$this->hasApiModuleController($module)) {
                continue;
            }
            $pages[] = 'pages/' . $module . '/' . $module;
            foreach ($this->discoverModulePageSubPaths($pagesRoot, $module) as $sub) {
                $pages[] = 'pages/' . $module . '/' . $sub;
            }
        }

        foreach ((array) Config::get('module.column_module') as $module) {
            if (!$this->hasApiModuleController($module)) {
                continue;
            }
            $pages[] = 'pages/' . $module . '_category/' . $module . '_category';
            $pages[] = 'pages/' . $module . '/' . $module;
            foreach ($this->discoverModulePageSubPaths($pagesRoot, $module) as $sub) {
                $pages[] = 'pages/' . $module . '/' . $sub;
            }
        }

        foreach ((array) Config::get('module.single_module') as $module) {
            if (in_array($module, $noHandle, true)) {
                continue;
            }
            if (!$this->hasApiModuleController($module)) {
                continue;
            }
            $pages[] = 'pages/' . $module . '/' . $module;
            foreach ($this->discoverModulePageSubPaths($pagesRoot, $module) as $sub) {
                $pages[] = 'pages/' . $module . '/' . $sub;
            }
        }

        // 小程序专属页（无对应业务模块，如 debug）
        foreach ((array) Config::get('system.miniprogram_extra_page', array()) as $extra) {
            $pages[] = $extra;
        }

        $pages = $this->normalizeMiniprogramPages($pages);

        $tabbarList = app(\Dou\Core\Service\Nav\MiniprogramNavigationBuilder::class)->build('miniprogram_tabbar');
        $tabBarList = array();
        foreach ($tabbarList as $row) {
            $tabBarList[] = array(
                'selectedIconPath' => 'images/tabbar_' . $row['module'] . '_on.png',
                'iconPath' => 'images/tabbar_' . $row['module'] . '.png',
                'pagePath' => Url::urlMini($row['module'], $row['guide'], true),
                'text' => $row['name'],
            );
        }

        $routeTable = RouteTableExporter::exportForEnd('Api');

        $frontPack = (string) Config::get('site.language', 'zh_cn');
        $allModules = (array) Config::get('module.all_module', array());
        if (!$allModules) {
            $allModules = array_merge(
                (array) Config::get('module.column_module', array()),
                (array) Config::get('module.single_module', array())
            );
        }
        $langFiles = app(ModuleLanguageManifest::class)->build($allModules, $frontPack);
        $langSeedJson = JsLangExporter::langJsonForFiles($langFiles);
        $siteName = (string) Config::get('site.site_name', '');

        $syncedAny = false;
        foreach ($slugList as $slug) {
            if ($this->writeMiniprogramAppJsonForSlug($miniprogramRoot, $slug, $pages, $tabBarList)) {
                $this->writeMiniprogramRouteTableForSlug($miniprogramRoot, $slug, $routeTable);
                $this->writeMiniprogramLangSeedForSlug($miniprogramRoot, $slug, $langSeedJson, $siteName);
                $syncedAny = true;
            }
        }

        if (!$syncedAny) {
            return false;
        }

        if ($miniprogramDomain !== null && $miniprogramDomain !== '') {
            $domain = $miniprogramDomain;
        } else {
            $paramDomain = Config::get('param.miniprogram_domain', '');
            $domain = $paramDomain !== '' ? $paramDomain : ROOT_URL;
        }
        $this->syncMiniprogramRuntimeConfig($domain);

        return null;
    }

    /**
     * 将 pages、tabBar.list 写入指定代码包 app.json（保留该包其它配置项）。
     *
     * @param string $miniprogramRoot
     * @param string $slug
     * @param array $pages
     * @param array $tabBarList
     * @return bool
     */
    protected function writeMiniprogramAppJsonForSlug($miniprogramRoot, $slug, $pages, $tabBarList)
    {
        $appJsonFile = $miniprogramRoot . '/' . $slug . '/app.json';
        if (!is_file($appJsonFile)) {
            return false;
        }

        $appJson = json_decode(file_get_contents($appJsonFile), true);
        if (!is_array($appJson)) {
            return false;
        }

        $appJson['pages'] = $pages;
        if (!isset($appJson['tabBar']) || !is_array($appJson['tabBar'])) {
            $appJson['tabBar'] = array();
        }
        $appJson['tabBar']['list'] = $tabBarList;

        $appJsonContent = json_encode($appJson, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        file_put_contents($appJsonFile, $appJsonContent);

        return true;
    }

    /**
     * 将 api 命名路由表写入指定代码包 utils/routes.generated.ts（整文件覆盖）。
     *
     * 产物供 utils/route.ts 的 route(name, params) 按名取址，与 config/site.ts 一样为生成文件、纳入版本库。
     *
     * @param string $miniprogramRoot
     * @param string $slug
     * @param array $routeTable name => pattern（来自 {@see RouteTableExporter::exportForEnd}）
     * @return bool
     */
    protected function writeMiniprogramRouteTableForSlug($miniprogramRoot, $slug, array $routeTable)
    {
        $routeTableFile = $miniprogramRoot . '/' . $slug . '/utils/routes.generated.ts';
        if (!is_dir(dirname($routeTableFile))) {
            return false;
        }

        $lines = '';
        foreach ($routeTable as $name => $pattern) {
            $nameLiteral = str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $name);
            $patternLiteral = str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $pattern);
            $lines .= "  '" . $nameLiteral . "': '" . $patternLiteral . "',\n";
        }

        $content = "/**\n"
            . " * api 命名路由表 —— 由后台 InstallService::writeMiniprogramRouteTableForSlug 整文件覆盖，请勿手改。\n"
            . " *\n"
            . " * key：路由名（已去 api. 前缀）；value：?route= 路径模板，{x} 为占位符，由 utils/route.ts 的 route() 填充。\n"
            . " */\n"
            . "\n"
            . "export const routes: Record<string, string> = {\n"
            . $lines
            . "}\n";
        file_put_contents($routeTableFile, $content);

        return true;
    }

    /**
     * 将前台语言包冷启动种子写入指定代码包 config/seed.generated.ts（整文件覆盖）。
     *
     * 内容与 route=lang / JsLangExporter 同源；供 commonStore 第 0 帧初始化 lang 与 siteName，
     * 网络 refresh 后再覆盖。
     *
     * @param string $miniprogramRoot
     * @param string $slug
     * @param string $langJson JsLangExporter::langJsonForFiles 产物
     * @param string $siteName Config::get('site.site_name')
     * @return bool
     */
    protected function writeMiniprogramLangSeedForSlug($miniprogramRoot, $slug, $langJson, $siteName)
    {
        $seedFile = $miniprogramRoot . '/' . $slug . '/config/seed.generated.ts';
        if (!is_dir(dirname($seedFile))) {
            return false;
        }

        $siteLiteral = str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $siteName);
        $content = "/**\n"
            . " * 冷启动种子 —— 由后台 InstallService::writeMiniprogramLangSeedForSlug 整文件覆盖，请勿手改。\n"
            . " *\n"
            . " * lang：前台语言包全表快照（与 route=lang 同源）；siteName：站点名称。\n"
            . " * commonStore 首帧 import 本文件，refresh() 成功后由网络数据覆盖。\n"
            . " */\n"
            . "\n"
            . "export const siteName = '" . $siteLiteral . "'\n"
            . "export const lang: Record<string, string> = " . $langJson . "\n";
        file_put_contents($seedFile, $content);

        return true;
    }

    /**
     * 将业务域名 / API 目录 / 调试开关写入小程序代码包运行时配置，仅写入 default 与当前启用包。
     *
     * 按文件存在性分支：
     *   - 命中 config/site.ts：TS 整文件覆盖（default 包形态，mp_url 由 root_url + API_DIR 拼成）。
     *   - 否则回落到 utils/dou.ts / utils/dou.js 的字面量正则替换（hanjing 等遗留 JS 包形态）。
     *
     * @param string $domain
     * @return void
     */
    protected function syncMiniprogramRuntimeConfig($domain)
    {
        $miniprogramRoot = ROOT_PATH . MINIPROGRAM_DIR;
        if (!is_dir($miniprogramRoot)) {
            return;
        }

        $slugList = $this->resolveMiniprogramSyncSlugs($miniprogramRoot);
        if (!$slugList) {
            return;
        }

        $apiDir = defined('API_DIR') ? API_DIR : 'api';
        $rootUrl = rtrim($domain, '/') . '/';
        $debugEnabled = SiteDebugExceptionRenderer::isSiteDebugEnabled();
        $debugFlag = $debugEnabled ? 'true' : 'false';
        $rewriteEnabled = (bool) Config::get('site.rewrite', false);
        $rewriteFlag = $rewriteEnabled ? 'true' : 'false';

        foreach ($slugList as $slug) {
            $tsFile = $miniprogramRoot . '/' . $slug . '/config/site.ts';
            $legacyTs = $miniprogramRoot . '/' . $slug . '/utils/dou.ts';
            $legacyJs = $miniprogramRoot . '/' . $slug . '/utils/dou.js';

            if (is_file($tsFile)) {
                $rootLiteral = str_replace(array('\\', "'"), array('\\\\', "\\'"), $rootUrl);
                $mpLiteral = str_replace(array('\\', "'"), array('\\\\', "\\'"), $rootUrl . $apiDir . '/');
                $tsContent = "/**\n"
                    . " * 站点级运行数据 —— 由后台 InstallService::syncMiniprogramRuntimeConfig 整文件覆盖。\n"
                    . " *\n"
                    . " * - root_url / mp_url：站点部署域名派生\n"
                    . " * - rewrite_enable：镜像 site.rewrite（与 front / API 伪静态同一开关）\n"
                    . " * - debug_enable：镜像 admin 站点调试开关\n"
                    . " * - douLoading：全局 loading UI 开关\n"
                    . " *\n"
                    . " * 业务侧请直接 `import { mp_url } from '../config/site.js'`，无须额外薄壳。\n"
                    . " */\n"
                    . "\n"
                    . "export const root_url = '" . $rootLiteral . "'\n"
                    . "export const mp_url = '" . $mpLiteral . "'\n"
                    . "export const douLoading = true\n"
                    . "export const debug_enable = " . $debugFlag . "\n"
                    . "export const rewrite_enable = " . $rewriteFlag . "\n";
                file_put_contents($tsFile, $tsContent);
                continue;
            }

            $legacyFile = is_file($legacyTs) ? $legacyTs : (is_file($legacyJs) ? $legacyJs : null);
            if ($legacyFile === null) {
                continue;
            }
            $content = file_get_contents($legacyFile);
            $content = preg_replace(
                '/(root_url(?:: string)?\s*=\s*)\'[^\']*\'/U',
                "$1'" . $rootUrl . "'",
                $content
            );
            $content = preg_replace(
                '/(debug_enable(?:: boolean)?\s*=\s*)(true|false)/',
                '$1' . $debugFlag,
                $content
            );
            file_put_contents($legacyFile, $content);
        }
    }

    /**
     * 解析需要同步的小程序代码包 slug：default 模板包 + 当前启用包（site.miniprogram_code），
     * 与 $miniprogramRoot 下实际存在的子目录求交集，去重保序。
     *
     * @param string $miniprogramRoot
     * @return array
     */
    protected function resolveMiniprogramSyncSlugs($miniprogramRoot)
    {
        $existing = FileHelper::getSubdirs($miniprogramRoot);
        if (!$existing) {
            return array();
        }

        $candidates = array('default');
        $current = Config::get('site.miniprogram_code', '');
        if ($current !== '' && $current !== 'default') {
            $candidates[] = $current;
        }

        $slugs = array();
        foreach ($candidates as $slug) {
            if (in_array($slug, $existing, true)) {
                $slugs[] = $slug;
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * 构造安装成功后的按钮 HTML 片段（唯一来源；前端 {@see admin/view/js/cloud.js} 成功态直接渲染）。
     *
     * 仅在 {@see runFinalize} 收尾成功时调用；仅打开 `cloud/install` 页面不会执行本方法。
     * `update` 与 `patch` 均视为「已装机上的更新类操作」，返回按钮统一回 {@see CloudController::update} 入口。
     *
     * 还原点入口（note）：仅在流程真正创建了还原点时输出「查看还原点」按钮
     * （system + update/patch、module|theme|plugin + update）；其余场景（全新安装等）为空串。
     *
     * 自定义返回地址（$backUrl）只作为 module 全新安装时的「进入模块」附加按钮，
     * 且必须通过 {@see isAdminBackUrl} 校验；「返回××管理中心」按钮始终回对应中心，
     * 避免按钮文案与跳转目标不一致（历史缺陷：模块安装后被自定义地址覆盖成前台形态 URL）。
     *
     * @param string $mode
     * @param string $type
     * @param string $cloudId
     * @param string $backUrl 安装成功后「进入模块」按钮的目标地址（须为本站后台 URL）
     * @return array { action: string, back: string, note: string }
     */
    public function buildSuccessButtons($mode, $type, $cloudId, $backUrl = '')
    {
        $btnAction = '';
        $btnBack = '';
        $btnNote = '';
        switch ($type) {
            case 'system':
                $btnBack = '<a href="' . Util::absolutizeEntryUrl('index.php') . '" class="btn-secondary">' . lang('cloud_admin_home') . '</a>';
                break;
            case 'miniprogram':
                $btnBack = '<a href="' . Util::absolutizeEntryUrl(route('admin.miniprogram')) . '" class="btn-secondary">' . lang('cloud_miniprogram_home') . '</a>';
                break;
            case 'plugin':
                $btnAction = '<a href="' . Util::absolutizeEntryUrl(route('admin.plugin.create', array('slug' => $cloudId))) . '" class="btn-secondary">' . lang('cloud_plugin_enable') . '</a>';
                $btnBack = '<a href="' . Util::absolutizeEntryUrl(route('admin.plugin')) . '" class="btn-secondary">' . lang('cloud_plugin_home') . '</a>';
                break;
            case 'theme':
                $btnAction = '<a href="' . Util::absolutizeEntryUrl(route('admin.theme.enable', array('slug' => $cloudId))) . '" class="btn-secondary js-post">' . lang('cloud_theme_enable') . '</a>';
                $btnBack = '<a href="' . Util::absolutizeEntryUrl(route('admin.theme')) . '" class="btn-secondary">' . lang('cloud_theme_home') . '</a>';
                break;
            case 'module':
                $systemSign = Session::get('_system_sign', '');
                $moduleQuery = array();
                if ($systemSign) {
                    $moduleQuery['system_sign'] = $systemSign;
                }
                $btnBack = '<a href="' . Util::absolutizeEntryUrl(route('admin.module', array(), array('query' => $moduleQuery))) . '" class="btn-secondary">' . lang('cloud_module_home') . '</a>';
                break;
            default:
                $btnBack = '<a href="' . Util::absolutizeEntryUrl('index.php') . '" class="btn-secondary">' . lang('cloud_admin_home') . '</a>';
        }

        // 还原点入口：仅当流程真正创建了还原点时输出，条件与 {@see runSnapshot}
        // 建快照条件一致（system + update/patch、module|theme|plugin + update）；
        // 全新安装等无快照场景不显示，避免误导。
        $snapshotCreated = ($type === 'system' && ($mode === 'update' || $mode === 'patch'))
            || (in_array($type, array('module', 'theme', 'plugin'), true) && $mode === 'update');
        if ($snapshotCreated) {
            $viewLabel = lang_has('cloud_finish_note_view') ? (string) lang('cloud_finish_note_view') : '查看还原点';
            $btnNote = '<a class="btn-secondary" href="' . Util::absolutizeEntryUrl(route('admin.backup.snapshot')) . '">' . $viewLabel . '</a>';
        }

        if ($mode === 'update' || $mode === 'patch') {
            $btnBack = '<a href="' . Util::absolutizeEntryUrl(route('admin.cloud.update')) . '" class="btn">' . lang('cloud_update_home') . '</a>';
        }

        // 预置模块安装成功后的「进入模块」入口：仅 module + 全新安装 + 合法后台地址时输出。
        // 返回按钮不覆盖（始终回模块管理中心），保证按钮文案与跳转目标一致。
        if ($type === 'module' && $mode !== 'update' && $mode !== 'patch'
            && $backUrl !== '' && self::isAdminBackUrl($backUrl)) {
            $enterLabel = lang_has('cloud_module_enter') ? (string) lang('cloud_module_enter') : '进入模块';
            $btnAction = '<a href="' . htmlspecialchars((string) $backUrl, ENT_QUOTES, DOU_CHARSET) . '" class="btn">' . $enterLabel . '</a>' . $btnAction;
        }

        return array('action' => $btnAction, 'back' => $btnBack, 'note' => $btnNote);
    }

    /**
     * 判定 back_url 是否指向本站后台入口（同源 + 后台入口路径前缀）。
     *
     * 合法形态（以 ADMIN_URL = http://host/sub/admin/ 为例）：
     * - 相对：`index.php?route=...`（后台页面内相对解析到后台目录）
     * - 相对：`/sub/admin/index.php?route=...`
     * - 绝对：`http://host/sub/admin/index.php?route=...`
     *
     * 前台入口（如 `/sub/index.php?route=admin/ai`）、其它目录、协议相对 `//` 与危险协议一律拒绝。
     * 安装入口（{@see CloudController::install} 的参数清洗）与收尾按钮（本类）共用此口径，
     * 防止站内非后台地址经 back_url 混入会话后在完成页渲染。
     *
     * @param string $url
     * @return bool
     */
    public static function isAdminBackUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '' || !defined('ADMIN_URL')) {
            return false;
        }
        if (preg_match('/^(javascript|data|vbscript|file):/i', $url) || strpos($url, '//') === 0) {
            return false;
        }

        $admin = parse_url((string) ADMIN_URL);
        if (!is_array($admin) || empty($admin['host'])) {
            return false;
        }
        $adminHost = (string) $admin['host'];
        $adminPath = isset($admin['path']) ? (string) $admin['path'] : '/';

        $parsed = parse_url($url);
        if ($parsed === false) {
            return false;
        }

        // 无 host：后台页面内的相对入口（index.php 开头的链接在后台目录下解析）
        if (empty($parsed['host'])) {
            if (strpos($url, 'index.php') === 0) {
                return true;
            }
            return $adminPath !== '/' && strpos($url, $adminPath) === 0;
        }

        if (strcasecmp((string) $parsed['host'], $adminHost) !== 0) {
            return false;
        }
        if ($adminPath === '/' || $adminPath === '') {
            return true;
        }
        $path = isset($parsed['path']) ? (string) $parsed['path'] : '';

        return strpos($path, $adminPath) === 0;
    }
}

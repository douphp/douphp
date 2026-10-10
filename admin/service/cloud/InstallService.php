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
    const STEP_APPLY = 'apply';
    const STEP_FINALIZE = 'finalize';

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

        $unwriteable = $this->findUnwriteableDirs();
        if ($unwriteable !== array()) {
            @unlink($this->cacheDir . $cloudId . '.zip');
            return array(
                'ok' => false,
                'error' => sprintf((string) lang('cloud_writeable_denied'), implode('、', $unwriteable)),
                'logs' => $logs,
            );
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
     * @param array $params 至少包含 type / cloud_id / mode；须含云端 install-resolve 下发的 download_url（经 {@see filterTrustedInstallDownloadUrl} 白名单校验）
     * @return array { ok: bool, error: string, logs: string[] }
     */
    public function runDownload(array $params)
    {
        $type = isset($params['type']) ? (string) $params['type'] : '';
        $cloudId = isset($params['cloud_id']) ? (string) $params['cloud_id'] : '';
        $mode = isset($params['mode']) ? (string) $params['mode'] : 'install';

        $logs = array();
        $itemZip = $this->cacheDir . $cloudId . '.zip';

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

        if ($this->downloadFile($downUrl, $this->cacheDir)) {
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
            return array('ok' => true, 'error' => '', 'logs' => $logs);
        }

        @unlink($itemZip);
        FileHelper::delDir($itemDir);
        $logs[] = lang('cloud_unzip_wrong');

        return array('ok' => false, 'error' => lang('cloud_unzip_wrong'), 'logs' => $logs);
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

        $buttons = $this->buildSuccessButtons($mode, $type, $cloudId);
        $btnAction = isset($buttons['action']) ? (string) $buttons['action'] : '';
        $btnBack = isset($buttons['back']) ? (string) $buttons['back'] : '';

        $nextInstall = $this->resolveNextInstall($batch, $themeId, $mode, $version);

        return array(
            'ok' => true,
            'logs' => $logs,
            'result' => array(
                'btn_action_html' => $btnAction,
                'btn_back_html' => $btnBack,
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

        return strpos($message, 'CredentialCipher') !== false;
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

        $this->copyExtractedFiles($type, $cloudId, $mode, $itemDir, $installedDir);

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
            $this->clearModule($cloudId);
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
     * 把解压后的目录文件按 type 拷贝到站点对应目录（同时记录 module 类型的 installed 清单）。
     *
     * @param string $type
     * @param string $cloudId
     * @param string $mode
     * @param string $itemDir
     * @param string $installedDir
     * @return void
     */
    private function copyExtractedFiles($type, $cloudId, $mode, $itemDir, $installedDir)
    {
        if ($type === 'theme') {
            FileHelper::copyDir($itemDir, $this->rootDir . 'theme/' . $cloudId);
            FileHelper::copyDir($this->rootDir . 'images/fragment', $this->rootDir . 'images/fragment_old');
            FileHelper::copyDir($this->rootDir . 'images/box', $this->rootDir . 'images/box_old');
            return;
        }

        if ($type === 'plugin') {
            $pluginDir = defined('PLUGIN_PATH') ? rtrim((string) PLUGIN_PATH, '/') . '/' : $this->rootDir . 'plugin/';
            FileHelper::copyDir($itemDir, $pluginDir . $cloudId);
            return;
        }

        // 配置基准保护：升级包携带 _update/resources/config/ 基准文件时，包根 config/ 中与基准同名的
        // 文件不参与覆盖拷贝——站点现行配置文件保持原样，交由 _update/action.php 的
        // config_merge 引擎做「基准 x 现行」合并（保留用户值）。摘除仅作用于本次临时解压
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

        $gotHost = strtolower($parts['host']);
        $defPort = ($scheme === 'https') ? 443 : 80;
        $gotPort = isset($parts['port']) ? (int) $parts['port'] : $defPort;
        $baseParts = @parse_url($downloadBase);
        if (!$baseParts || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return '';
        }

        $wantScheme = strtolower($baseParts['scheme']);
        if ($scheme !== $wantScheme) {
            return '';
        }

        $wantHost = strtolower($baseParts['host']);
        if ($gotHost !== $wantHost) {
            return '';
        }

        $wantPort = isset($baseParts['port']) ? (int) $baseParts['port'] : $defPort;
        if ($wantPort !== $gotPort) {
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
        $payload = "<?php\nreturn " . Arr::export($output) . ";\n";
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

        $this->changeSystemConfig($cloudId, 'DELL');
        $this->changeNav($cloudId, null, true);
        $this->changeModulePhp($cloudId, null, true);
        $this->changeMiniprogramConfigFile();
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
     * @return string|false 成功返回保存绝对路径，失败返回 false
     */
    public function downloadFile($fileUrl, $savePath)
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

        $mirrorErrors = array('upstream_not_found', 'upstream_unavailable', 'upstream_misconfigured');

        // 先清理可能残留的旧包，避免上一次失败留下半截文件被误判为完整。
        @unlink($saveFile);

        $attempts = 3;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $result = $this->attemptStreamDownload($fileUrl, $data, $saveFile, $mirrorErrors);
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
     * @return true|string 成功返回 true；失败返回失败原因码（供 {@see $lastDownloadFailureDetail}）
     */
    private function attemptStreamDownload($fileUrl, array $data, $saveFile, array $mirrorErrors)
    {
        $meta = Client::request(
            'POST',
            $fileUrl,
            $data,
            array(),
            array(
                'return_meta' => true,
                'timeout' => 7200,
                'connect_timeout' => 20,
                'stream_to' => $saveFile,
            )
        );

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
     * @param string $mode
     * @param string $type
     * @param string $cloudId
     * @return array { action: string, back: string }
     */
    public function buildSuccessButtons($mode, $type, $cloudId)
    {
        $btnAction = '';
        $btnBack = '';
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

        if ($mode === 'update' || $mode === 'patch') {
            $btnBack = '<a href="' . Util::absolutizeEntryUrl(route('admin.cloud.update')) . '" class="btn-secondary">' . lang('cloud_update_home') . '</a>';
        }

        return array('action' => $btnAction, 'back' => $btnBack);
    }
}

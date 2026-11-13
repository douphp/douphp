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

namespace Dou\Admin\Controller\Cloud;

use Dou\Admin\Controller\BaseController;
use Dou\Admin\Facade\Cloud;
use Dou\Admin\Model\Setting\Setting;
use Dou\Admin\Request\Cloud\AccountFormRequest;
use Dou\Admin\Service\Cloud\CloudService;
use Dou\Admin\Service\Cloud\InstallService;
use Dou\Admin\Service\Cloud\InstallSessionService;
use Dou\Admin\Service\Menu\AdminNavResolver;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Web\Http\HttpResponseException;
use Dou\Core\Web\Http\JsonResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;
use Dou\Core\Web\Routing\RouteEntry;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 云端扩展后台控制器（瘦控制器）。
 *
 * 控制器仅负责取参、token 校验、assign 与渲染 / 跳转；
 * 标题与跳转链接拼装、安装流程、HTML 片段构造均下沉到 {@see CloudService} 与 Cloud 门面对应服务。
 */
class CloudController extends BaseController
{
    /** @var Cloud */
    private $cloud;

    /** @var CloudService */
    private $cloudService;

    /** @var InstallService */
    private $installService;

    /** @var InstallSessionService */
    private $installSession;

    /**
     * @param Cloud $cloud
     * @param CloudService $cloudService
     * @param InstallService $installService
     * @param InstallSessionService $installSession
     */
    public function __construct(
        Cloud $cloud,
        CloudService $cloudService,
        InstallService $installService,
        InstallSessionService $installSession
    ) {
        $this->cloud = $cloud;
        $this->cloudService = $cloudService;
        $this->installService = $installService;
        $this->installSession = $installSession;
    }

    /**
     * @return Response
     */
    public function index()
    {
        return redirect(route('admin.cloud.update'));
    }

    /**
     * 扩展安装 / 更新容器页（POST + JSON 多步 API 的入口）。
     *
     * `source=offline` 为离线升级：取包来源切到本地包目录（storage/install/package/），
     * 不再请求云端 install-resolve；离线只做核心程序升级，type / mode 由服务端固定为
     * system / update（链接上也会显式带 mode=update），因此还原点、版本上报与收尾
     * 按钮与在线升级完全一致。
     *
     * 流程：
     *   1) 从 **QueryString** 读取 type / cloud_id / mode 等（`Request::query`），避免 POST 同名字段覆盖 GET（`Request::input` 先 POST 后 GET）。
     *   2) `type` 可省略：在 `mode=update|patch` 且无 `type` 时视为 **system** 内核包；`cloud_id=font` 且无 `type` 时视为 **system** 字体包。
     *   3) `cloud_id` 形如 `a|b|c` 时拆出首项 a 与批量队列 [b, c]。
     *   4) 调 {@see InstallSessionService::create()} 落盘新会话，得到 install_id。
     *   5) 渲染 `cloud.htm` 的 `rec='install'` 区块，将 install_id / token / 步骤元数据
     *      （preflight→download→unzip→[snapshot]→apply→finalize）暴露给前端 JS，
     *      由其逐步 POST `cloud/install_step` 完成实际工作；snapshot 步仅在
     *      {@see InstallService::needsSnapshot()} 判定为升级时出现（全新安装无还原点），
     *      未完成时返回 next_step 指回自身，前端循环续做直至还原点创建完成。
     *
     * 同步阶段（不在浏览器实时显示「半截 HTML」）已下沉到独立 {@see installStep()} 中执行。
     *
     * @param Request $request
     * @return Response
     */
    public function install(Request $request)
    {
        $rawCloudId = (string) $request->query('cloud_id', '');
        $themeIdInput = $request->query('theme_id', '');
        $themeId = $themeIdInput !== '' && preg_match("/^[A-Za-z0-9-_]+$/", $themeIdInput) ? $themeIdInput : '';

        $mode = $this->resolveMode($request->query('mode', ''));
        $version = $this->eightDigits($request->query('version', ''));
        $miniVersion = $this->eightDigits($request->query('mini_version', ''));
        $backUrl = $this->sanitizeAdminBackUrl($request->query('back_url', ''));

        // 取包来源：cloud（默认，走云端下载）/ offline（离线升级，用本地升级包）。
        // 离线只做核心程序升级：type=system、mode=update 由服务端固定，忽略 URL 上的 type/mode。
        $source = (string) $request->query('source', 'cloud') === 'offline' ? 'offline' : 'cloud';
        $isOffline = ($source === 'offline');
        $offlineDownloadUrl = $isOffline
            ? $this->sanitizeDisplayDownloadUrl($request->query('download_url', ''))
            : '';

        $cloudId = '';
        $batch = array();
        if ($isOffline) {
            // 离线时 cloud_id 就是用户上传的 zip 文件名（去掉 .zip）
            $cloudId = $this->sanitizeOfflineCloudId($rawCloudId);
        } else {
            $strictId = preg_match("/^[A-Za-z0-9-_.|]+$/", $rawCloudId) ? $rawCloudId : '';
            if ($strictId !== '') {
                $parts = explode('|', $strictId);
                $cloudId = (string) array_shift($parts);
                foreach ($parts as $p) {
                    $p = trim((string) $p);
                    if ($p !== '') {
                        $batch[] = $p;
                    }
                }
            }
        }

        $type = $request->alpha('type', '', 'query');
        if ($type === '') {
            if ($mode === 'update' || $mode === 'patch') {
                $type = 'system';
            } elseif ($cloudId === 'font') {
                $type = 'system';
            }
        }

        if ($isOffline) {
            $type = 'system';
            $mode = 'update';
        }

        if ($type === '' || $cloudId === '') {
            throw new DomainException(lang('illegal'), route('admin.setting'));
        }

        // 离线升级不依赖云端接口：允许在未配置 cloud.api_base 的站点上继续（仅手动放置安装包）。
        if (!$isOffline && rtrim((string) Config::get('cloud.api_base', ''), '/') === '') {
            throw new DomainException(lang('cloud_api_base_missing'), route('admin.setting'));
        }

        if ($mode === 'local' || $isOffline) {
            $authStatus = 'ok';
            $downloadUrl = '';
            $downloadSha256 = '';
        } else {
            $resolved = $this->cloudService->resolveInstallDownload($type, $cloudId, $mode);
            $authStatus = isset($resolved['status']) ? (string) $resolved['status'] : 'unavailable';
            $downloadUrl = isset($resolved['download_url']) ? (string) $resolved['download_url'] : '';
            $downloadSha256 = isset($resolved['sha256']) ? (string) $resolved['sha256'] : '';
            // install-resolve 是版本权威来源：仅在响应值合法时覆盖链接参数
            if (isset($resolved['version']) && $this->eightDigits($resolved['version']) !== '') {
                $version = $this->eightDigits($resolved['version']);
            }
            if (isset($resolved['mini_version']) && $this->eightDigits($resolved['mini_version']) !== '') {
                $miniVersion = $this->eightDigits($resolved['mini_version']);
            }
            if ($downloadUrl !== '') {
                $downloadUrl = $this->installService->filterTrustedInstallDownloadUrl($downloadUrl);
            }
            if ($downloadUrl === '') {
                // URL 被白名单拒绝时其哈希一并弃用，避免「有哈希无包」的不一致状态
                $downloadSha256 = '';
            }
        }

        $installId = $this->installSession->create(array(
            'type' => $type,
            'cloud_id' => $cloudId,
            'mode' => $mode,
            'version' => $version,
            'mini_version' => $miniVersion,
            'theme_id' => $themeId,
            'batch' => $batch,
            'download_url' => $downloadUrl,
            'sha256' => $downloadSha256,
            'auth_status' => $authStatus,
            'back_url' => $backUrl,
            'source' => $source,
        ));

        $viewData = $this->cloudService->buildHandleViewData($type, $cloudId, $mode, $version, $themeId);

        $steps = array(
            InstallService::STEP_PREFLIGHT,
            InstallService::STEP_DOWNLOAD,
            InstallService::STEP_UNZIP,
        );
        // 还原点只服务升级：安装（install/local）不建还原点，步骤列表里也不出现 snapshot
        if ($this->installService->needsSnapshot($type, $mode)) {
            $steps[] = InstallService::STEP_SNAPSHOT;
        }
        $steps[] = InstallService::STEP_APPLY;
        $steps[] = InstallService::STEP_FINALIZE;
        $langPack = array(
            'cloud_install_next' => lang('cloud_install_next'),
            'cloud_update_next' => lang('cloud_update_next'),
            'cloud_install_theme' => lang('cloud_install_theme'),
            'cloud_update_theme' => lang('cloud_update_theme'),
            'cloud_update_plugin' => lang('cloud_update_plugin'),
            'cloud_admin_home' => lang('cloud_admin_home'),
            'cloud_update_home' => lang('cloud_update_home'),
            'cloud_account' => lang('cloud_account'),
            'cloud_down_ing_0' => lang('cloud_down_ing_0'),
            'cloud_down_ing_1' => lang('cloud_down_ing_1'),
            'cloud_unzip_ing' => lang('cloud_unzip_ing'),
            'cloud_snapshot_ing' => lang('cloud_snapshot_ing'),
            'cloud_install_ing' => lang('cloud_install_ing'),
            'cloud_install_request_failed' => lang('cloud_install_request_failed'),
            'cloud_install_step_failed_generic' => lang('cloud_install_step_failed_generic'),
            'cloud_step_elapsed' => lang('cloud_step_elapsed'),
            'cloud_offline_step_verify' => lang('cloud_offline_step_verify'),
            'cloud_install_offline_btn' => lang('cloud_install_offline_btn'),
            'cloud_install_offline_hint' => lang('cloud_install_offline_hint'),
        );

        // 失败提示里的「离线升级」入口：把升级包下载地址带过去（离线页只展示不抓取）。
        // 在线运行时取 install-resolve 解到的地址；离线运行时透传 URL 上已有的 download_url。
        $carryDownloadUrl = $isOffline ? $offlineDownloadUrl : $downloadUrl;
        $offlineQuery = array();
        if ($carryDownloadUrl !== '') {
            $offlineQuery['download_url'] = $carryDownloadUrl;
        }

        $meta = array(
            'install_id' => $installId,
            'token' => csrf()->token(),
            'steps' => $steps,
            'lang' => $langPack,
            'mode' => $mode,
            'cloud_id' => $cloudId,
            'download_url' => $downloadUrl,
            'type_label' => $viewData['type_label'],
            'source' => $source,
            'offline_url' => route('admin.cloud.offline', array(), array('query' => $offlineQuery)),
        );

        return $this->view('cloud.htm', $this->installNavVars($type, $mode) + [
            'ur_here' => lang('cloud_handle'),
            'rec' => 'install',
            'install_id' => $installId,
            'install_token' => csrf()->token(),
            'install_meta_json' => $this->encodeJsonForView($meta),
            'type' => $viewData['type_label'],
            'cloud_id' => $cloudId,
            'title' => $viewData['title'],
            'mode' => $mode,
            // 自动版本：脚本一改版本号即变，客户端无需强刷缓存
            'cloud_js_ver' => (string) (@filemtime(ADMIN_PATH . 'view/js/cloud.js') ?: '20261108'),
        ]);
    }

    /**
     * 离线升级页（GET，核心程序包专用）。
     *
     * 零必填参数：直接打开即可用。页面扫描 storage/install/package/ 内的 zip，
     * 以包内 `_update/version.php` 的版本与当前版本比较后逐条给出结论：
     * 可升级 / 与当前版本相同（可重跑）/ 低于当前版本（禁止）/ 无法识别版本 / 非核心包。
     *
     * 可选参数 download_url（仅展示，供用户下载升级包）；升级包由用户自行上传，本页不访问云端。
     * 点【开始升级】拼出完整升级链接 `route=cloud/install&mode=update&source=offline&cloud_id=<包名>`，
     * 由 {@see install()} 继续跑多步向导（还原点、版本上报与在线升级一致）。
     *
     * @param Request $request
     * @return Response
     */
    public function offline(Request $request)
    {
        $downloadUrl = $this->sanitizeDisplayDownloadUrl($request->query('download_url', ''));

        // 上次在线下载（超时 / 网关 503）会在包目录留下半截包，进入本页时先清掉，
        // 避免它出现在下方包列表里被当成可用升级包。
        $this->installService->purgeIncompletePackages();

        $list = $this->installService->listLocalPackages();

        $packages = array();
        foreach ($list['items'] as $item) {
            $item['start_url'] = '';
            if (!empty($item['allowed'])) {
                $query = array(
                    'mode' => 'update',
                    'source' => 'offline',
                    'cloud_id' => (string) $item['cloud_id'],
                );
                if ($downloadUrl !== '') {
                    $query['download_url'] = $downloadUrl;
                }
                $item['start_url'] = route('admin.cloud.install', array(), array('query' => $query));
            }
            $packages[] = $item;
        }

        $refreshQuery = array('t' => time());
        if ($downloadUrl !== '') {
            $refreshQuery['download_url'] = $downloadUrl;
        }

        $currentVersion = (string) $list['current_version'];

        return $this->view('cloud_offline.htm', $this->installNavVars('system', 'update') + array(
            'ur_here' => lang('cloud_offline'),
            'page_cue' => $this->upgradeRiskCue(),
            // 当前版本单列区块展示（置于步骤一上方），不再挤在页头标题行
            'current_version' => htmlspecialchars($currentVersion, ENT_QUOTES, 'UTF-8'),
            'page_actions' => array(
                array(
                    'href' => route('admin.cloud.update'),
                    'text' => lang('cloud_update_home'),
                    'style' => '',
                ),
            ),
            'download_url' => $downloadUrl,
            // 只暴露站点内相对路径：该页多数运行在线上，避免把服务器绝对路径输出到页面
            'package_dir_rel' => 'storage/install/package/',
            'packages' => $packages,
            'refresh_url' => route('admin.cloud.offline', array(), array('query' => $refreshQuery)),
        ));
    }

    /**
     * 展示用下载地址校验：仅接受 http/https，其余一律丢弃。
     *
     * @param mixed $url
     * @return string
     */
    private function sanitizeDisplayDownloadUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '' || strlen($url) > 2048) {
            return '';
        }
        $parts = @parse_url($url);
        if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return '';
        }

        return $url;
    }

    /**
     * 离线升级的 cloud_id：即用户上传的 zip 文件名（去掉 .zip），允许中文与空格。
     *
     * @param mixed $raw
     * @return string 合法返回包名，否则返回空串
     */
    private function sanitizeOfflineCloudId($raw)
    {
        $raw = str_replace('\\', '/', (string) $raw);
        $name = basename($raw);
        $name = (string) preg_replace('/\.zip$/i', '', $name);
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 180) {
            return '';
        }
        if (strpos($name, '/') !== false || strpos($name, '|') !== false || strpos($name, "\0") !== false) {
            return '';
        }

        return $name;
    }

    /**
     * 安装容器页的侧栏归属（页面级 nav 覆盖）。
     *
     * cloud/install 同时承载系统更新与模块 / 模板 / 插件 / 小程序安装：
     * 系统更新流程归属「设置 > 系统更新」，字体等系统包安装归属「设置 > 设置」；
     * 模块 / 模板 / 插件 / 小程序按 type 归属各自侧栏节点；dou / onedou 等整站包
     * 不覆盖，保持自然解析（无高亮）。
     *
     * @param string $type 扩展类型
     * @param string $mode 安装模式（update / patch / install / local）
     * @return array
     */
    private function installNavVars($type, $mode)
    {
        $entry = request()->routeEntry();
        if (!$entry instanceof RouteEntry) {
            return array();
        }

        if ($type === 'system') {
            $overrides = in_array($mode, array('update', 'patch'), true)
                ? array('sidebar' => 'setting', 'sidebar_child' => 'update')
                : array('sidebar' => 'setting', 'sidebar_child' => 'setting');
        } elseif (in_array($type, array('module', 'theme', 'plugin', 'miniprogram'), true)) {
            $overrides = array('sidebar' => $type);
        } else {
            return array();
        }

        return array(
            'nav' => AdminNavResolver::resolve((string) $entry->name, $overrides),
        );
    }

    /**
     * 安装页脚本注入用：转为 JS 内安全的 JSON 字面量。
     *
     * @param mixed $value
     * @return string
     */
    private function encodeJsonForView($value)
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $json = json_encode($value, $flags);
        return $json === false ? 'null' : $json;
    }

    /**
     * 安装单步执行入口（JSON API，POST）。
     *
     * 入参：install_id / step / token（form-encoded 或 query）
     * 出参：{ ok, step, next, finished, success, error, redirect_url, logs[], result, mode }
     *
     * `mode` 为会话内当前安装模式（install|update|patch|local），供前端与失败页脚与 {@see InstallService::buildSuccessButtons} 对齐。
     *
     * - 在 {@see InstallSessionService::withLock()} 中以排他锁保护单会话并发；
     * - finalize 成功后：会话标记 finished=true，前端仅读取 result.btn_*_html（由
     *   {@see InstallService::buildSuccessButtons} 生成）渲染收尾按钮，
     *   或读取 result.next_install 在前端 redirect 到下一个 install 入口。
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function installStep(Request $request)
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        if (!$request->isMethod('POST')) {
            return $this->installStepJson(array('ok' => false, 'error' => lang('illegal')), 405);
        }

        $token = trim((string) $request->input('token', ''));
        if (!csrf()->verify($token, 'static_admin')) {
            return $this->installStepJson(array('ok' => false, 'error' => lang('illegal')), 419);
        }

        $installId = (string) $request->input('install_id', '');
        $step = (string) $request->input('step', '');

        if (!$this->installSession->isValidId($installId)) {
            return $this->installStepJson(array('ok' => false, 'error' => lang('illegal')), 400);
        }

        $allowed = array(
            InstallService::STEP_PREFLIGHT => true,
            InstallService::STEP_DOWNLOAD => true,
            InstallService::STEP_UNZIP => true,
            InstallService::STEP_SNAPSHOT => true,
            InstallService::STEP_APPLY => true,
            InstallService::STEP_FINALIZE => true,
        );
        if (!isset($allowed[$step])) {
            return $this->installStepJson(array('ok' => false, 'error' => lang('illegal')), 400);
        }

        $service = $this->installService;
        $session = $this->installSession;

        // 全局互斥锁（跨会话/跨入口）：preflight 获取、逐步骤刷新、失败/收尾释放；
        // 防「两个窗口」或「云更新 + 手动向导」并发升级同一站点。类缺失时优雅跳过（旧版本代码窗口）。
        $runLock = class_exists('Dou\\Core\\Service\\Upgrade\\RunLockService')
            ? new \Dou\Core\Service\Upgrade\RunLockService(ROOT_PATH)
            : null;

        $response = array(
            'ok' => false,
            'step' => $step,
            'next' => null,
            'finished' => false,
            'success' => false,
            'error' => '',
            'logs' => array(),
            'result' => null,
            'redirect_url' => null,
            'install_id' => $installId,
            'mode' => '',
        );

        // 下载可能持续数分钟：提前释放 PHP 会话文件锁，避免前端进度轮询请求被
        // session_start 阻塞（CSRF 与入参均已读取完毕；安装会话由 InstallSessionService
        // 独立文件锁保护，本请求后续不再写 PHP Session）。
        if ($step === InstallService::STEP_DOWNLOAD && session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        // 本请求是否持有升级锁（preflight 获取 / 后续步骤续锁），会话落盘后统一收口
        $lockHeld = false;

        $updatedState = $session->withLock($installId, function ($state) use ($service, $step, &$response, $session, $runLock, $installId, &$lockHeld) {
            if (!empty($state['finished'])) {
                $response['ok'] = true;
                $response['finished'] = true;
                $response['success'] = !empty($state['success']);
                $response['result'] = isset($state['result']) ? $state['result'] : null;
                return $state;
            }

            if ($runLock !== null) {
                // preflight 首次获取；后续步骤续锁（未持有时内部转为 acquire）。
                // 失败说明另一请求正持锁，必须中止本步骤，否则两个流程会并发改写站点文件。
                $locked = $step === InstallService::STEP_PREFLIGHT
                    ? $runLock->acquire($installId)
                    : $runLock->touch($installId);
                if (empty($locked['ok'])) {
                    $response['ok'] = false;
                    $response['error'] = (string) $locked['error'];
                    return $state;
                }
                $lockHeld = true;
            }

            $params = $this->buildStepParams($state, $installId);

            $outcome = $this->runStep($service, $step, $params, $state);

            if ($step === InstallService::STEP_PREFLIGHT && empty($outcome['ok']) && !empty($outcome['repeat_only'])) {
                $skippedId = isset($params['cloud_id']) ? (string) $params['cloud_id'] : '';
                $safety = 0;
                while ($safety < 64
                    && empty($outcome['ok'])
                    && !empty($outcome['repeat_only'])
                    && $this->installChainCanAdvance($state)
                ) {
                    $safety++;
                    $advanced = $this->advanceInstallChainTarget($state);
                    if ($advanced === null) {
                        break;
                    }
                    $state = $advanced;
                    $state = $session->pushLog(
                        $state,
                        $step,
                        'info',
                        $skippedId . lang('cloud_install_repeat')
                    );
                    $params = $this->buildStepParams($state, $installId);
                    $skippedId = $params['cloud_id'];
                    $outcome = $service->runPreflight($params);
                }
            }

            $logs = isset($outcome['logs']) && is_array($outcome['logs']) ? $outcome['logs'] : array();
            foreach ($logs as $line) {
                $state = $session->pushLog($state, $step, !empty($outcome['ok']) ? 'info' : 'error', (string) $line);
            }

            if (empty($outcome['ok'])) {
                $state['failed_step'] = $step;
                $errorMsg = isset($outcome['error']) ? (string) $outcome['error'] : '';
                if ($errorMsg === '') {
                    $errorMsg = lang_has('cloud_install_step_failed_generic')
                        ? (string) lang('cloud_install_step_failed_generic')
                        : (string) lang('cloud_down_wrong');
                }
                if ($errorMsg !== '') {
                    $state = $session->pushLog($state, $step, 'error', $errorMsg);
                }
                $response['ok'] = false;
                $response['error'] = $errorMsg;
                $response['logs'] = $logs;
                if (!empty($outcome['redirect_url'])) {
                    $response['redirect_url'] = (string) $outcome['redirect_url'];
                }
                if (!empty($outcome['redirect_label'])) {
                    $response['redirect_label'] = (string) $outcome['redirect_label'];
                }
                if (!empty($outcome['redirect_target'])) {
                    $response['redirect_target'] = (string) $outcome['redirect_target'];
                }
                return $state;
            }

            $state['failed_step'] = '';
            if (!isset($state['completed_steps']) || !is_array($state['completed_steps'])) {
                $state['completed_steps'] = array();
            }
            if (!in_array($step, $state['completed_steps'], true)) {
                $state['completed_steps'][] = $step;
            }
            // 步骤可携带 next_step 覆写（快照创建未完成时指回自身，前端循环续做）
            $next = !empty($outcome['next_step']) ? (string) $outcome['next_step'] : $this->nextStep($step, $state);
            $state['current_step'] = $next ? $next : InstallService::STEP_FINALIZE;

            $response['ok'] = true;
            $response['logs'] = $logs;
            $response['next'] = $next;
            // 快照步骤的真实进度（0-100 整数或 null）透传，前端用于更新进行中行
            $response['progress'] = isset($outcome['progress']) ? $outcome['progress'] : null;

            if ($step === InstallService::STEP_FINALIZE) {
                $state['success'] = true;
                $state['finished'] = true;
                $state['result'] = isset($outcome['result']) ? $outcome['result'] : array();
                $response['success'] = true;
                $response['finished'] = true;
                $response['result'] = $state['result'];
            }

            return $state;
        });

        if ($updatedState === null) {
            // 会话丢失（可能已被清理）：顺手清掉本会话可能遗留的锁记录，非持有者调用为无操作
            if ($runLock !== null) {
                $runLock->release($installId);
            }

            return $this->installStepJson(array(
                'ok' => false,
                'error' => lang('cloud_install_session_missing'),
                'step' => $step,
                'install_id' => $installId,
            ), 410);
        }

        // 锁生命周期统一在会话状态落盘之后收口：
        // 失败/收尾立即释放；成功但未收尾写 30 秒交接宽限，供下一个步骤请求续锁。
        if ($runLock !== null && $lockHeld) {
            if (empty($response['ok']) || !empty($response['finished'])) {
                $runLock->release($installId);
            } else {
                $runLock->handoff($installId);
            }
        }

        if (isset($updatedState['mode'])) {
            $response['mode'] = (string) $updatedState['mode'];
        }

        return $this->installStepJson($response, 200);
    }

    /**
     * 下载进度轮询（JSON API，GET）。
     *
     * 供前端在 download 步骤并行轮询（每 1.2 秒）：读取 {@see InstallService::runDownload()}
     * 经 curl 进度回调节流落盘的进度文件，换算为 0-99 百分比；文件缺失或超过 5 秒未刷新
     * （下载已结束 / 请求已消亡）时返回 percent=null，前端回落秒表显示。
     *
     * 本接口不触碰安装会话锁、不写 PHP Session，可安全与下载长请求并发
     * （installStep() 在 download 步骤已提前 session_write_close()）。
     *
     * 入参：install_id / token（query）
     * 出参：{ ok, percent: int|null, now: int, total: int }
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function installProgress(Request $request)
    {
        $token = trim((string) $request->input('token', ''));
        if (!csrf()->verify($token, 'static_admin')) {
            return $this->installStepJson(array('ok' => false, 'error' => lang('illegal')), 419);
        }

        $installId = (string) $request->input('install_id', '');
        if (!$this->installSession->isValidId($installId)) {
            return $this->installStepJson(array('ok' => false, 'error' => lang('illegal')), 400);
        }

        $percent = null;
        $now = 0;
        $total = 0;

        $progressFile = STORAGE_PATH . 'install/' . $installId . '.dl.json';
        if (is_file($progressFile)) {
            $raw = @file_get_contents($progressFile);
            $data = $raw !== false ? json_decode($raw, true) : null;
            if (is_array($data) && isset($data['total'], $data['now'], $data['t'])) {
                $total = (int) $data['total'];
                $now = (int) $data['now'];
                // 超过 5 秒未刷新视为无效（下载已结束或请求已消亡）
                if ($total > 0 && (time() - (int) $data['t']) <= 5) {
                    $pct = (int) floor($now * 100 / $total);
                    $percent = $pct > 99 ? 99 : ($pct < 0 ? 0 : $pct);
                } else {
                    $total = 0;
                    $now = 0;
                }
            }
        }

        return $this->installStepJson(array(
            'ok' => true,
            'percent' => $percent,
            'now' => $now,
            'total' => $total,
        ), 200);
    }

    /**
     * 路由实际步骤到 InstallService 对应方法。
     *
     * @param InstallService $service
     * @param string $step
     * @param array $params
     * @param array $state 当前会话快照（finalize 时用于读 batch）
     * @return array
     */
    private function runStep(InstallService $service, $step, array $params, array $state)
    {
        switch ($step) {
            case InstallService::STEP_PREFLIGHT:
                return $service->runPreflight($params);
            case InstallService::STEP_DOWNLOAD:
                return $service->runDownload($params);
            case InstallService::STEP_UNZIP:
                return $service->runUnzip($params);
            case InstallService::STEP_SNAPSHOT:
                return $service->runSnapshot($params);
            case InstallService::STEP_APPLY:
                return $service->runApply($params);
            case InstallService::STEP_FINALIZE:
                return $service->runFinalize($params, $state);
        }
        return array('ok' => false, 'error' => 'unknown_step', 'logs' => array());
    }

    /**
     * 计算下一步名称；finalize 返回 null。
     *
     * 当前目标不需要还原点时跳过 snapshot（unzip 直连 apply），
     * 避免前端出现「正在创建升级还原点」的空步骤。
     *
     * @param string $step
     * @param array $state 当前会话快照（链式安装时 type / mode 已切到当前目标）
     * @return string|null
     */
    private function nextStep($step, array $state)
    {
        $order = array(
            InstallService::STEP_PREFLIGHT => InstallService::STEP_DOWNLOAD,
            InstallService::STEP_DOWNLOAD => InstallService::STEP_UNZIP,
            InstallService::STEP_UNZIP => InstallService::STEP_SNAPSHOT,
            InstallService::STEP_SNAPSHOT => InstallService::STEP_APPLY,
            InstallService::STEP_APPLY => InstallService::STEP_FINALIZE,
            InstallService::STEP_FINALIZE => null,
        );
        $next = isset($order[$step]) ? $order[$step] : null;

        if ($next === InstallService::STEP_SNAPSHOT
            && !$this->installService->needsSnapshot(
                isset($state['type']) ? (string) $state['type'] : '',
                isset($state['mode']) ? (string) $state['mode'] : 'install'
            )) {
            $next = InstallService::STEP_APPLY;
        }

        return $next;
    }

    /**
     * 从安装会话构建步骤参数（两处调用共用，避免字段漂移）。
     *
     * @param array $state
     * @param string $installId
     * @return array
     */
    private function buildStepParams(array $state, $installId)
    {
        return array(
            'type' => isset($state['type']) ? (string) $state['type'] : '',
            'cloud_id' => isset($state['cloud_id']) ? (string) $state['cloud_id'] : '',
            'mode' => isset($state['mode']) ? (string) $state['mode'] : 'install',
            'version' => isset($state['version']) ? (string) $state['version'] : '',
            'mini_version' => isset($state['mini_version']) ? (string) $state['mini_version'] : '',
            'theme_id' => isset($state['theme_id']) ? (string) $state['theme_id'] : '',
            'download_url' => isset($state['download_url']) ? (string) $state['download_url'] : '',
            'sha256' => isset($state['sha256']) ? (string) $state['sha256'] : '',
            'auth_status' => isset($state['auth_status']) ? (string) $state['auth_status'] : 'ok',
            'back_url' => isset($state['back_url']) ? (string) $state['back_url'] : '',
            'source' => isset($state['source']) ? (string) $state['source'] : 'cloud',
            'install_id' => (string) $installId,
        );
    }

    /**
     * 当前会话是否还能在「仅重复安装」失败时前进到链上下一项。
     *
     * @param array $state
     * @return bool
     */
    private function installChainCanAdvance(array $state)
    {
        $batch = isset($state['batch']) && is_array($state['batch']) ? $state['batch'] : array();
        if (!empty($batch)) {
            return true;
        }
        $type = isset($state['type']) ? (string) $state['type'] : '';
        $themeId = isset($state['theme_id']) ? (string) $state['theme_id'] : '';

        return $type === 'module' && $themeId !== '';
    }

    /**
     * 在链式安装中前进到下一目标：先消费 batch，再 module → theme。
     *
     * batch 队列内各项目沿用会话当前 type（模块 / 模板 / 插件批量更新共用同一队列），
     * 只有「模块装完再装其配套模板」这一历史链路才会在 batch 耗尽后切换 type。
     *
     * @param array $state
     * @return array|null 新 state；无法前进返回 null
     */
    private function advanceInstallChainTarget(array $state)
    {
        // 已安装扩展直接跳过，避免为“最终仍会跳过”的目标调用 install-resolve 并累加下载计数
        $safety = 0;
        while ($safety < 64) {
            $safety++;
            $batch = isset($state['batch']) && is_array($state['batch']) ? $state['batch'] : array();
            if (!empty($batch)) {
                $next = array_shift($batch);
                $state['cloud_id'] = (string) $next;
                $state['batch'] = array_values($batch);
            } else {
                $type = isset($state['type']) ? (string) $state['type'] : '';
                $themeId = isset($state['theme_id']) ? (string) $state['theme_id'] : '';
                if ($type !== 'module' || $themeId === '') {
                    return null;
                }
                $state['type'] = 'theme';
                $state['cloud_id'] = $themeId;
                $state['theme_id'] = '';
                $state['batch'] = array();
                $state['mode'] = 'install';
            }

            // 「重复安装」判定只存在于全新安装 / 本地安装（见 InstallService::runPreflight），
            // 更新模式必须继续；按当前 type 判定，模板 / 插件与模块同一口径。
            $type = isset($state['type']) ? (string) $state['type'] : '';
            $mode = isset($state['mode']) ? (string) $state['mode'] : 'install';
            if (($mode === 'install' || $mode === 'local')
                && $type !== ''
                && $this->installService->installCheck($type, $state['cloud_id'])) {
                $state = $this->installSession->pushLog(
                    $state,
                    InstallService::STEP_PREFLIGHT,
                    'info',
                    (string) $state['cloud_id'] . lang('cloud_install_repeat')
                );
                continue;
            }

            return $this->refreshChainTargetResolution($state);
        }

        return null;
    }

    /**
     * 链式目标切换后重新解析下载地址与版本，避免沿用上一项的 URL/version。
     *
     * @param array $state
     * @return array
     */
    private function refreshChainTargetResolution(array $state)
    {
        $state['download_url'] = '';
        $state['sha256'] = '';
        $state['version'] = '';
        $state['mini_version'] = '';
        $state['auth_status'] = 'ok';

        $mode = isset($state['mode']) ? (string) $state['mode'] : 'install';
        if ($mode === 'local') {
            return $state;
        }

        $type = isset($state['type']) ? (string) $state['type'] : '';
        $cloudId = isset($state['cloud_id']) ? (string) $state['cloud_id'] : '';
        if ($type === '' || $cloudId === '') {
            $state['auth_status'] = 'unavailable';
            return $state;
        }

        $resolved = $this->cloudService->resolveInstallDownload($type, $cloudId, $mode);
        $state['auth_status'] = isset($resolved['status']) ? (string) $resolved['status'] : 'unavailable';
        $downloadUrl = isset($resolved['download_url']) ? (string) $resolved['download_url'] : '';
        $downloadSha256 = isset($resolved['sha256']) ? (string) $resolved['sha256'] : '';
        if ($downloadUrl !== '') {
            $downloadUrl = $this->installService->filterTrustedInstallDownloadUrl($downloadUrl);
        }
        if ($downloadUrl === '') {
            $downloadSha256 = '';
        }
        $state['download_url'] = $downloadUrl;
        $state['sha256'] = $downloadSha256;
        $state['version'] = isset($resolved['version']) ? $this->eightDigits($resolved['version']) : '';
        $state['mini_version'] = isset($resolved['mini_version']) ? $this->eightDigits($resolved['mini_version']) : '';

        return $state;
    }

    /**
     * 标准化 mode：允许 install / update / patch / local，其它一律视为 install。
     * `patch` 与系统包上游路径一致，须原样进会话，供 {@see InstallService::buildSuccessButtons} 等与 update 同等回到 cloud/update。
     *
     * @param string $mode
     * @return string
     */
    private function resolveMode($mode)
    {
        $mode = (string) $mode;
        if ($mode === 'update' || $mode === 'patch' || $mode === 'local') {
            return $mode;
        }
        return 'install';
    }

    /**
     * 归一 8 位日期版本号（YYYYMMDD）；非法值返回空串。
     *
     * @param mixed $value
     * @return string
     */
    private function eightDigits($value)
    {
        $value = trim((string) $value);

        return preg_match('/^\d{8}$/', $value) ? $value : '';
    }

    /**
     * 校验自定义返回地址：必须是本站后台入口 URL，防止开放重定向与错入口。
     *
     * 判定口径统一由 {@see InstallService::isAdminBackUrl} 提供（同源 + ADMIN_URL 路径前缀）：
     * - 相对路径（不含 host）且以 index.php 或后台入口路径开头 → 接受；
     * - 绝对 URL 且 host 与 ADMIN_URL 一致、path 落在后台入口下 → 接受；
     * - 前台入口（如 `/douphp.dou/index.php?route=admin/ai`）、其它目录、危险协议一律拒绝。
     *
     * @param string $url
     * @return string 合法时返回原值（已 trim），否则返回空串
     */
    private function sanitizeAdminBackUrl($url)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return '';
        }

        return InstallService::isAdminBackUrl($url) ? $url : '';
    }

    /**
     * 用 HttpResponseException 抛出 JsonResponse，让框架统一收尾。
     *
     * @param array $data
     * @param int $status
     * @return JsonResponse
     */
    private function installStepJson(array $data, $status = 200)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        throw new HttpResponseException(new JsonResponse($data, (int) $status, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 扩展详情弹窗（POST: name、frame）。
     *
     * @param Request $request
     * @return Response
     */
    public function details(Request $request)
    {
        $name = (string) $request->post('name', '');
        $frame = (string) $request->post('frame', '');

        return $this->response($this->cloudService->buildDetailsFrame($name, $frame));
    }

    /**
     * 订购扩展：拉取云端订购 HTML 并渲染。
     *
     * @param Request $request
     * @return Response
     */
    public function order(Request $request)
    {
        $cloudId = $request->extendId('cloud_id');
        $action = $request->rec('action', 'default');
        $type = $request->alpha('type');

        // POST 提交分支：CSRF 由 admin CsrfMiddleware 统一校验（static_admin），到此处保证已通过。
        if ($action === 'checkout' && $request->isMethod('POST')) {
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::CONFIRM, 1, $type . ':' . $cloudId);
        }

        $cloudHtml = $this->cloudService->fetchOrderHtml($type, $action, $cloudId);

        return $this->view('cloud.htm', [
            'ur_here' => lang('cloud_' . $type) . lang('cloud_order'),
            'rec' => 'order',
            'module_link' => $this->cloudService->moduleLinkForOrderType($type),
            'cloud_id' => $cloudId,
            'action' => $action,
            'type' => $type,
            'cloud_html' => $cloudHtml,
        ]);
    }

    /**
     * 验证云账号授权（去除版权信息入口）。
     *
     * @return Response
     */
    public function copyright()
    {
        $this->ensureAllAuthority();

        $msg = $this->cloudService->copyright(true);
        if ($msg) {
            return redirect(route('admin.cloud.account'))->with('error', $msg);
        }
    }

    /**
     * 云账号管理页。
     *
     * @param Request $request
     * @return Response
     */
    public function account(Request $request)
    {
        $this->ensureAllAuthority();

        $cloudAccount = unserialize(Config::get('site.cloud_account', ''));

        $accountAction = ($request->input('action', '') === 'set' || !$cloudAccount) ? 'set' : 'view';

        return $this->view('cloud.htm', [
            'ur_here' => lang('cloud_account'),
            'page_cue' => '<p>' . lang('cloud_account_intro') . '</p>',
            'action' => $accountAction,
            'rec' => 'account',
            'cloud_account' => $cloudAccount,
        ]);
    }

    /**
     * 云账号保存（POST）。
     *
     * 字段白名单与校验规则定义在 {@see AccountFormRequest::rules()}；
     * 邮箱或手机格式与远程接口校验在 Service 内完成。
     *
     * @param AccountFormRequest $formRequest
     * @param Request $request
     * @return Response
     */
    public function accountPost(AccountFormRequest $formRequest, Request $request)
    {
        $this->ensureAllAuthority();

        $this->cloudService->saveCloudAccount($formRequest->validated());

        return redirect(route('admin.cloud.account'))->with('success', lang('cloud_account_success'));
    }

    /**
     * 清空云账号。
     *
     * @return Response
     */
    public function accountClean()
    {
        $this->ensureAllAuthority();

        $this->cloudService->clearCloudAccount();

        return redirect(route('admin.cloud.account'))->with('success', lang('cloud_account_clean_success'));
    }

    /**
     * 更新主页：服务端一次拉取云端整块更新片段（样式表链接 + 版本信息条 + 四类升级列表）。
     *
     * 唯一数据源为 `fetchUpdateHtml()`（POST /update），本地不做片段拼接；请求失败或返回空时
     * 输出 update_cue 提示，不留白。
     * 可更新数量仍由 `/connect` 独立维护：`refreshUpdateNumber` 落库的 `update_number`
     * 在下一次后台请求由 UpdateBadgeBuilder 输出到 `$unum`。
     * 关闭升级（`site.close_update`）时跳过云端调用，也不输出提示（页头 page_cue 已说明）。
     *
     * @return Response
     */
    public function update()
    {
        $closeUpdate = (bool) Config::get('site.close_update', false);
        $pageActions = array();
        $pageCue = '';
        $canManage = $this->canManageUpdateNotice();

        if ($canManage) {
            if ($closeUpdate) {
                $pageActions[] = array(
                    'href' => route('admin.cloud.open_update'),
                    'text' => lang('cloud_open_update'),
                    'post' => true,
                    'style' => '',
                );
            } else {
                $pageActions[] = array(
                    'href' => route('admin.cloud.close_update'),
                    'text' => lang('cloud_close_update'),
                    'post' => true,
                    'confirm' => lang('cloud_close_update_confirm'),
                    'style' => '',
                );
            }
        }

        if ($closeUpdate) {
            $pageCue = '<p>' . lang('cloud_update_closed_cue');
            if ($canManage) {
                $pageCue .= lang('cloud_update_closed_manage_cue');
            }
            $pageCue .= '</p>';
        }

        $localsystem = $this->cloud->localSystemPayload();
        $localsite = $this->cloud->localSitePayload();

        $updateHtml = '';
        $updateCue = '';
        if (!$closeUpdate) {
            // 角标：/connect 独立，与更新片段互不影响
            $this->cloudService->refreshUpdateNumber($localsite, $localsystem, true);

            // 云端一次下发整块片段（link + 版本信息条 + 四段列表）
            $updateHtml = (string) $this->cloudService->fetchUpdateHtml($localsite, $localsystem, time());

            if ($updateHtml === '') {
                // 请求失败或返回空：正常情况不会出现，必须明确告知而不是留白
                $updateCue = lang('cloud_connect_failed');
            }
        }

        // 升级风险提示行固定输出（与离线升级页同源文案），不随是否存在可更新项变化，
        // 保证每次进入更新主页的页头提示始终一致
        $pageCue .= $this->upgradeRiskCue();

        return $this->view('cloud.htm', [
            'ur_here' => lang('cloud_update'),
            'rec' => 'update',
            'page_actions' => $pageActions,
            'page_cue' => $pageCue,
            'update_closed' => $closeUpdate,
            'update_html' => $updateHtml,
            'update_cue' => $updateCue,
        ]);
    }

    /**
     * 关闭在线升级提示。
     *
     * @return Response
     */
    public function closeUpdate()
    {
        $this->ensureUpdateNoticeAuthority();
        Setting::updateConfigValue('close_update', '1');
        // 清空缓存的更新数量，重新开启后从干净状态重新拉取
        Setting::updateConfigValue('update_number', serialize(array()));

        return redirect(route('admin.cloud.update'))->with('success', lang('cloud_close_update_success'));
    }

    /**
     * 开启在线升级提示。
     *
     * @return Response
     */
    public function openUpdate()
    {
        $this->ensureUpdateNoticeAuthority();
        Setting::updateConfigValue('close_update', '0');

        return redirect(route('admin.cloud.update'))->with('success', lang('cloud_open_update_success'));
    }

    /**
     * 升级风险提示行（更新主页与离线升级页共用）：手动备份入口 + 自动还原点告知。
     *
     * 作为页头 page_cue 输出，沿用后台默认的灰底提示行样式，不附加自定义类与样式。
     *
     * @return string
     */
    private function upgradeRiskCue()
    {
        return '<p>' . lang('cloud_upgrade_backup_tips')
            . '<a href="' . route('admin.backup') . '">' . lang('cloud_upgrade_backup_btn') . '</a>'
            . lang('cloud_upgrade_snapshot_tips') . '</p>';
    }

    /**
     * 当前管理员是否可以管理升级提示开关（与设置权限一致）。
     *
     * @return bool
     */
    private function canManageUpdateNotice()
    {
        $admin = auth('admin')->user();
        if (!is_array($admin) || !isset($admin['type']) || $admin['type'] !== 'defined') {
            return true;
        }

        $actionList = isset($admin['action_list']) ? explode(',', $admin['action_list']) : array();

        return in_array('setting', $actionList, true);
    }

    /**
     * 升级提示开关权限断言。
     *
     * @return void
     * @throws DomainException
     */
    private function ensureUpdateNoticeAuthority()
    {
        if (!$this->canManageUpdateNotice()) {
            throw new DomainException(lang('without'), route('admin.cloud.update'));
        }
    }

    /**
     * 后台超级管理员权限断言。
     *
     * @return void
     * @throws DomainException 非全权限管理员时抛出
     */
    private function ensureAllAuthority()
    {
        $admin = auth('admin')->user();
        if (!isset($admin['action_list']) || $admin['action_list'] !== 'ALL') {
            throw new DomainException(lang('without'), route('admin.setting'));
        }
    }
}

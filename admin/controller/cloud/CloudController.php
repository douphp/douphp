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
use Dou\Admin\Request\Cloud\AccountFormRequest;
use Dou\Admin\Service\Cloud\CloudService;
use Dou\Admin\Service\Cloud\InstallService;
use Dou\Admin\Service\Cloud\InstallSessionService;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Web\Http\HttpResponseException;
use Dou\Core\Web\Http\JsonResponse;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Http\Response;

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
     * {@inheritDoc}
     */
    protected function layoutVars()
    {
        return parent::layoutVars() + array(
            'cur' => 'cloud',
        );
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
     * 流程：
     *   1) 从 **QueryString** 读取 type / cloud_id / mode 等（`Request::query`），避免 POST 同名字段覆盖 GET（`Request::input` 先 POST 后 GET）。
     *   2) `type` 可省略：在 `mode=update|patch` 且无 `type` 时视为 **system** 内核包；`cloud_id=font` 且无 `type` 时视为 **system** 字体包。
     *   3) `cloud_id` 形如 `a|b|c` 时拆出首项 a 与批量队列 [b, c]。
     *   4) 调 {@see InstallSessionService::create()} 落盘新会话，得到 install_id。
     *   5) 渲染 `cloud.htm` 的 `rec='install'` 区块，将 install_id / token / 5 步元数据
     *      暴露给前端 JS，由其逐步 POST `cloud/install_step` 完成实际工作。
     *
     * 同步阶段（不在浏览器实时显示「半截 HTML」）已下沉到独立 {@see installStep()} 中执行。
     *
     * @param Request $request
     * @return Response
     */
    public function install(Request $request)
    {
        $rawCloudId = preg_match("/^[A-Za-z0-9-_.|]+$/", $request->query('cloud_id', '')) ? $request->query('cloud_id', '') : '';
        $themeIdInput = $request->query('theme_id', '');
        $themeId = $themeIdInput !== '' && preg_match("/^[A-Za-z0-9-_]+$/", $themeIdInput) ? $themeIdInput : '';

        $mode = $this->resolveMode($request->query('mode', ''));
        $version = $request->digits('version', '', 'query');

        $cloudId = '';
        $batch = array();
        if ($rawCloudId !== '') {
            $parts = explode('|', $rawCloudId);
            $cloudId = (string) array_shift($parts);
            foreach ($parts as $p) {
                $p = trim((string) $p);
                if ($p !== '') {
                    $batch[] = $p;
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

        if ($type === '' || $cloudId === '') {
            throw new DomainException(lang('illegal'), route('admin.setting'));
        }

        if (rtrim((string) Config::get('cloud.api_base', ''), '/') === '') {
            throw new DomainException(lang('cloud_api_base_missing'), route('admin.setting'));
        }

        if ($mode === 'local') {
            $authStatus = 'ok';
            $downloadUrl = '';
        } else {
            $resolved = $this->cloudService->resolveInstallDownload($type, $cloudId, $mode);
            $authStatus = isset($resolved['status']) ? (string) $resolved['status'] : 'unavailable';
            $downloadUrl = isset($resolved['download_url']) ? (string) $resolved['download_url'] : '';
            if ($downloadUrl !== '') {
                $downloadUrl = $this->installService->filterTrustedInstallDownloadUrl($downloadUrl);
            }
        }

        $installId = $this->installSession->create(array(
            'type' => $type,
            'cloud_id' => $cloudId,
            'mode' => $mode,
            'version' => $version,
            'theme_id' => $themeId,
            'batch' => $batch,
            'download_url' => $downloadUrl,
            'auth_status' => $authStatus,
        ));

        $viewData = $this->cloudService->buildHandleViewData($type, $cloudId, $mode, $version, $themeId);

        $steps = array(
            InstallService::STEP_PREFLIGHT,
            InstallService::STEP_DOWNLOAD,
            InstallService::STEP_UNZIP,
            InstallService::STEP_APPLY,
            InstallService::STEP_FINALIZE,
        );
        $langPack = array(
            'cloud_install_next' => lang('cloud_install_next'),
            'cloud_update_next' => lang('cloud_update_next'),
            'cloud_install_theme' => lang('cloud_install_theme'),
            'cloud_admin_home' => lang('cloud_admin_home'),
            'cloud_update_home' => lang('cloud_update_home'),
            'cloud_account' => lang('cloud_account'),
            'cloud_down_ing_0' => lang('cloud_down_ing_0'),
            'cloud_down_ing_1' => lang('cloud_down_ing_1'),
            'cloud_unzip_ing' => lang('cloud_unzip_ing'),
            'cloud_install_ing' => lang('cloud_install_ing'),
            'cloud_install_request_failed' => lang('cloud_install_request_failed'),
            'cloud_install_step_failed_generic' => lang('cloud_install_step_failed_generic'),
        );

        $meta = array(
            'install_id' => $installId,
            'token' => csrf()->token(),
            'steps' => $steps,
            'lang' => $langPack,
            'mode' => $mode,
            'cloud_id' => $cloudId,
            'download_url' => $downloadUrl,
            'type_label' => $viewData['type_label'],
        );

        return $this->view('cloud.htm', [
            'ur_here' => lang('cloud_handle'),
            'rec' => 'install',
            'install_id' => $installId,
            'install_token' => csrf()->token(),
            'install_meta_json' => $this->encodeJsonForView($meta),
            'type' => $viewData['type_label'],
            'cloud_id' => $cloudId,
            'title' => $viewData['title'],
            'mode' => $mode,
        ]);
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
            InstallService::STEP_APPLY => true,
            InstallService::STEP_FINALIZE => true,
        );
        if (!isset($allowed[$step])) {
            return $this->installStepJson(array('ok' => false, 'error' => lang('illegal')), 400);
        }

        $service = $this->installService;
        $session = $this->installSession;

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

        $updatedState = $session->withLock($installId, function ($state) use ($service, $step, &$response, $session) {
            if (!empty($state['finished'])) {
                $response['ok'] = true;
                $response['finished'] = true;
                $response['success'] = !empty($state['success']);
                $response['result'] = isset($state['result']) ? $state['result'] : null;
                return $state;
            }

            $params = array(
                'type' => isset($state['type']) ? (string) $state['type'] : '',
                'cloud_id' => isset($state['cloud_id']) ? (string) $state['cloud_id'] : '',
                'mode' => isset($state['mode']) ? (string) $state['mode'] : 'install',
                'version' => isset($state['version']) ? (string) $state['version'] : '',
                'theme_id' => isset($state['theme_id']) ? (string) $state['theme_id'] : '',
                'download_url' => isset($state['download_url']) ? (string) $state['download_url'] : '',
                'auth_status' => isset($state['auth_status']) ? (string) $state['auth_status'] : 'ok',
            );

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
                    $params = array(
                        'type' => isset($state['type']) ? (string) $state['type'] : '',
                        'cloud_id' => isset($state['cloud_id']) ? (string) $state['cloud_id'] : '',
                        'mode' => isset($state['mode']) ? (string) $state['mode'] : 'install',
                        'version' => isset($state['version']) ? (string) $state['version'] : '',
                        'theme_id' => isset($state['theme_id']) ? (string) $state['theme_id'] : '',
                        'download_url' => isset($state['download_url']) ? (string) $state['download_url'] : '',
                        'auth_status' => isset($state['auth_status']) ? (string) $state['auth_status'] : 'ok',
                    );
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
            $next = $this->nextStep($step);
            $state['current_step'] = $next ? $next : InstallService::STEP_FINALIZE;

            $response['ok'] = true;
            $response['logs'] = $logs;
            $response['next'] = $next;

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
            return $this->installStepJson(array(
                'ok' => false,
                'error' => lang('cloud_install_session_missing'),
                'step' => $step,
                'install_id' => $installId,
            ), 410);
        }

        if (isset($updatedState['mode'])) {
            $response['mode'] = (string) $updatedState['mode'];
        }

        return $this->installStepJson($response, 200);
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
     * @param string $step
     * @return string|null
     */
    private function nextStep($step)
    {
        $order = array(
            InstallService::STEP_PREFLIGHT => InstallService::STEP_DOWNLOAD,
            InstallService::STEP_DOWNLOAD => InstallService::STEP_UNZIP,
            InstallService::STEP_UNZIP => InstallService::STEP_APPLY,
            InstallService::STEP_APPLY => InstallService::STEP_FINALIZE,
            InstallService::STEP_FINALIZE => null,
        );
        return isset($order[$step]) ? $order[$step] : null;
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
     * @param array $state
     * @return array|null 新 state；无法前进返回 null
     */
    private function advanceInstallChainTarget(array $state)
    {
        $batch = isset($state['batch']) && is_array($state['batch']) ? $state['batch'] : array();
        if (!empty($batch)) {
            $next = array_shift($batch);
            $state['cloud_id'] = (string) $next;
            $state['batch'] = array_values($batch);

            return $state;
        }
        $type = isset($state['type']) ? (string) $state['type'] : '';
        $themeId = isset($state['theme_id']) ? (string) $state['theme_id'] : '';
        if ($type === 'module' && $themeId !== '') {
            $state['type'] = 'theme';
            $state['cloud_id'] = $themeId;
            $state['theme_id'] = '';
            $state['batch'] = array();
            $state['mode'] = 'install';

            return $state;
        }

        return null;
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
     * 更新主页：服务端直接拉取云端三类升级提示 HTML 与可更新数量。
     *
     * 关闭升级（`site.close_update`）时跳过云端调用；
     * `refreshUpdateNumber` 落库的 `update_number` 在下一次后台请求由 UpdateBadgeBuilder 输出到 `$unum`。
     *
     * @return Response
     */
    public function update()
    {
        $localsystem = $this->cloud->localSystemPayload();
        $localsite = $this->cloud->localSitePayload();

        $systemUpdateHtml = '';
        $moduleUpdateHtml = '';
        $themeUpdateHtml = '';
        if (!Config::get('site.close_update', false)) {
            $this->cloudService->refreshUpdateNumber($localsite, $localsystem);
            $systemUpdateHtml = $this->cloudService->fetchSystemUpdateHtml($localsystem);
            $moduleUpdateHtml = $this->cloudService->fetchModuleUpdateHtml($localsite);
            $themeUpdateHtml = $this->cloudService->fetchThemeUpdateHtml($localsite);
        }

        return $this->view('cloud.htm', [
            'ur_here' => lang('cloud_update'),
            'rec' => 'update',
            'system_update_html' => $systemUpdateHtml,
            'module_update_html' => $moduleUpdateHtml,
            'theme_update_html' => $themeUpdateHtml,
        ]);
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

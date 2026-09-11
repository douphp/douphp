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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\CloudApi;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 豆壳云端账号与订购扩展对接（校验账号、拉取订购页 HTML、记录更新序号）。
 *
 * 职责：
 * - 请求 api.douphp.com 校验/保存云端账号至 config.cloud_account。
 * - 订购扩展客户端 HTML（插件/模板/模块入口链接）。
 * - 组装 cloud/install 容器页与 details 弹窗的视图数据，控制器只负责 assign / display。
 * - 调用 `/extend/list` 取 JSON 后追加本后台筛选 / 分页 URL，供 Smarty 模板渲染（预览/缩略图 URL 由 API 返回）。
 */
class CloudService extends BaseService
{
    /**
     * 扩展列表：slug → 后台声明式路由名。
     *
     * @var array<string, string>
     */
    private static $extendListRoutes = array(
        'module' => 'admin.module',
        'theme' => 'admin.theme.install',
        'plugin' => 'admin.plugin.install',
        'miniprogram' => 'admin.miniprogram.install',
    );

    /** @var UpdateStateService */
    private $updateState;

    /**
     */
    public function __construct()
    {
        $this->updateState = new UpdateStateService();
    }

    /**
     * 组装 cloud/install 页视图数据（标题、类型标签）。
     *
     * 仅服务本后台 `route=cloud/install` 容器页。`action_url` 键保留为空串，兼容仍读取该键的模板。
     *
     * @param string $type 扩展类型（system|module|plugin|theme|miniprogram|dou|onedou）
     * @param string $cloudId 云端资源 id（可能为 '|' 拼接的批量）
     * @param string $mode 模式：install|update|patch|local
     * @param string $version 最低核心版本号
     * @param string $themeId 对应主题 id（模块附带）
     * @return array
     */
    public function buildHandleViewData($type, $cloudId, $mode, $version, $themeId)
    {
        $typeLabel = lang('cloud_' . $type, $type);
        if ($type === 'system') {
            $typeLabel = '';
        }

        if ($mode === 'update' || $mode === 'patch') {
            $title = lang('cloud_title_update');
        } else {
            if ($themeId !== '') {
                $title = preg_replace('/d%/Ums', $themeId, lang('cloud_title_install_for_theme'));
            } else {
                $title = lang('cloud_title_install');
            }
        }
        if ($typeLabel !== '') {
            $title .= $typeLabel . ' ' . $cloudId;
        } else {
            $title .= ' ' . $cloudId;
        }

        return array(
            'title' => $title,
            'type_label' => $typeLabel,
            'action_url' => '',
        );
    }

    /**
     * 组装 cloud/details 弹窗 HTML 片段（POST 的 name/frame 由控制器透传校验后传入）。
     *
     * @param string $name 弹窗标题
     * @param string $frame 内嵌 iframe 地址
     * @return string
     */
    public function buildDetailsFrame($name, $frame)
    {
        $safeName = htmlspecialchars((string) $name, ENT_QUOTES, DOU_CHARSET);
        $safeFrame = htmlspecialchars((string) $frame, ENT_QUOTES, DOU_CHARSET);

        return '<div class="cloud-frame">'
            . '<div class="bg" onclick="$(this).closest(\'.cloud-frame\').remove();"></div>'
            . '<div class="frame details">'
            . '<h2><a href="javascript:void(0)" class="close" onclick="$(this).closest(\'.cloud-frame\').remove();">X</a>'
            . $safeName . '</h2>'
            . '<div class="content">'
            . '<iframe frameborder="0" hspace="0" src="' . $safeFrame . '" width="100%" height="600">'
            . lang('cloud_frame_cue')
            . '</iframe></div></div></div>';
    }

    /**
     * 拉取云端订购扩展页 HTML（POST 带上本地 cloud_account）；主站将返回正文嵌入订购弹窗。
     *
     * @param string $type 订购类型（如 plugin/theme）
     * @param string $action 动作标识
     * @param string $cloudId 云端资源 id
     * @return string HTML 片段或接口返回正文
     */
    public function fetchOrderHtml($type, $action, $cloudId)
    {
        $cloudAccount = unserialize(Config::get('site.cloud_account', ''));
        $data = array(
            'type' => $type,
            'action' => $action,
            'cloud_id' => $cloudId,
            'user' => isset($cloudAccount['user']) ? $cloudAccount['user'] : '',
            'password' => isset($cloudAccount['password']) ? $cloudAccount['password'] : '',
        );

        return CloudApi::postRaw(CloudApi::PATH_ORDER_EXTEND_CLIENT_HTML, $data);
    }

    /**
     * 按订购类型返回后台安装/模块入口 URL。
     *
     * @param string $type plugin|theme|其它
     * @return string 相对 index.php 的路由
     */
    public function moduleLinkForOrderType($type)
    {
        switch ($type) {
            case 'plugin':
                return route('admin.plugin.install');
            case 'theme':
                return route('admin.theme.install');
            default:
                return route('admin.module');
        }
    }

    /**
     * 保存云端账号：校验邮箱或手机格式，远程校验通过后序列化写入 config。
     *
     * @param array $validated AccountFormRequest 通过的字段（cloud_user、cloud_password）
     * @return void
     */
    public function saveCloudAccount(array $validated)
    {
        $cloudUser = isset($validated['cloud_user']) ? $validated['cloud_user'] : '';
        $cloudPassword = isset($validated['cloud_password']) ? $validated['cloud_password'] : '';

        if (!Check::email($cloudUser) && !Check::telphone($cloudUser)) {
            throw new DomainException(lang('cloud_account_user_wrong'), route('admin.cloud.account', array(), array('query' => array('action' => 'set'))));
        }

        $cloudAccount = array(
            'user' => $cloudUser,
            'password' => md5($cloudPassword),
        );

        $envelope = CloudApi::postJson(CloudApi::PATH_USER_CLIENT_CHECK, array(
            'user' => $cloudAccount['user'],
            'password' => $cloudAccount['password'],
        ));

        if (!is_array($envelope) || empty($envelope['valid'])) {
            throw new DomainException(lang('cloud_account_wrong'), route('admin.cloud.account', array(), array('query' => array('action' => 'set'))));
        }

        DB::table('config')
            ->where('name', 'cloud_account')
            ->update(array('value' => serialize($cloudAccount)));

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'cloud_account:' . $cloudUser);
    }

    /**
     * 清空 config 中的 cloud_account 并记日志。
     *
     * @return void
     */
    public function clearCloudAccount()
    {
        DB::table('config')
            ->where('name', 'cloud_account')
            ->update(array('value' => ''));

        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'cloud_account');
    }

    /**
     * 探测云端版权授权状态（无副作用，不写入文件）。
     *
     * 用于安装流程在 preflight 阶段前置拦截：仅当云端明确返回 401/403 时阻断。
     * 云端不可用或返回非预期结构时，返回 code=-1，由调用方决定是否放行。
     *
     * @return array { code:int, status:string, cdkey:string, message:string }
     */
    public function probeCopyright()
    {
        $cloudAccount = unserialize(Config::get('site.cloud_account', ''));
        $data = array(
            'user' => isset($cloudAccount['user']) ? $cloudAccount['user'] : '',
            'password' => isset($cloudAccount['password']) ? $cloudAccount['password'] : '',
            'domain' => ROOT_URL,
            'shell' => substr(md5(DOU_SHELL), 16),
            'version' => Config::get('site.douphp_version', ''),
            'system_sign' => SYSTEM_SIGN,
        );
        $raw = CloudApi::postRaw(CloudApi::PATH_REPORT_COPYRIGHT, $data);
        $envelope = json_decode($raw, true);

        $out = array(
            'code' => is_array($envelope) && isset($envelope['code']) ? (int) $envelope['code'] : -1,
            'status' => is_array($envelope) && isset($envelope['data']['status']) ? (string) $envelope['data']['status'] : '',
            'cdkey' => is_array($envelope) && isset($envelope['data']['cdkey']) ? (string) $envelope['data']['cdkey'] : '',
            'message' => is_array($envelope) && isset($envelope['message']) ? (string) $envelope['message'] : '',
        );

        return $out;
    }

    /**
     * 验证云账号授权并写入 data/..cdkey.php（成功时）。
     *
     * 接口返回 JSON 信封：
     *   - code = 0 + data.cdkey 为 ..cdkey.php 文件内容；
     *   - code = 401 表示 wrong（账号/密码不正确）；
     *   - code = 403 表示 unauthorized（账号未购买当前域名授权）。
     *
     * @param bool $showMsg 是否返回提示文本（true 返回消息字符串）
     * @return string|null
     */
    public function copyright($showMsg = false)
    {
        $cloudAccount = unserialize(Config::get('site.cloud_account', ''));
        $data = array(
            'user' => isset($cloudAccount['user']) ? $cloudAccount['user'] : '',
            'password' => isset($cloudAccount['password']) ? $cloudAccount['password'] : '',
            'domain' => ROOT_URL,
            'shell' => substr(md5(DOU_SHELL), 16),
            'version' => Config::get('site.douphp_version', ''),
            'system_sign' => SYSTEM_SIGN,
        );
        $probe = $this->probeCopyright();
        $status = (string) $probe['status'];
        $code = (int) $probe['code'];
        $cdkey = (string) $probe['cdkey'];

        if ($code === 0 && $status === 'ok' && $cdkey !== '') {
            $stateDir = STORAGE_PATH . 'state/';
            if (!is_dir($stateDir)) {
                @mkdir($stateDir, 0777, true);
            }
            file_put_contents($stateDir . 'cdkey.php', $cdkey);
            $msg = lang('cloud_copyright_success');
        } elseif ($code === 403 || (isset($probe['message']) && $probe['message'] === 'unauthorized')) {
            $msg = lang('cloud_copyright_no_vip');
        } else {
            $msg = lang('cloud_account_cue');
        }

        if ($showMsg) {
            return $msg;
        }

        return null;
    }

    /**
     * 拉取云端 `/update/system` 升级提示 HTML 片段（JSON `data.html`）。
     *
     * @param string $localsystem 已 urlencode(serialize(...)) 的系统环境载荷
     * @return string 成功时为 HTML 片段，云端不可用 / 客户端已是最新版时为空串
     */
    public function fetchSystemUpdateHtml($localsystem)
    {
        $data = CloudApi::getJson(CloudApi::PATH_UPDATE_SYSTEM, array(
            'localsystem' => (string) $localsystem,
        ));
        if (!is_array($data) || !isset($data['html'])) {
            return '';
        }
        $html = (string) $data['html'];

        return $this->stripInstallTypeSystemQueryFromHtml($html);
    }

    /**
     * 从 HTML 中的 cloud/install 链接去掉 `type=system`，由后台入口按 mode 默认 system，避免地址栏与文案出现英文 system。
     *
     * @param string $html
     * @return string
     */
    private function stripInstallTypeSystemQueryFromHtml($html)
    {
        if ($html === '' || strpos($html, 'type=system') === false) {
            return $html;
        }
        $html = str_replace('?type=system&', '?', $html);
        $html = str_replace('&type=system&', '&', $html);
        $html = str_replace('&type=system', '', $html);
        $html = str_replace('?type=system', '?', $html);

        return $html;
    }

    /**
     * 拉取云端 `/update/module` 升级提示 HTML 片段（JSON `data.html`）。
     *
     * @param string $localsite 已 urlencode(serialize(...)) 的站点扩展载荷
     * @return string
     */
    public function fetchModuleUpdateHtml($localsite)
    {
        $data = CloudApi::getJson(CloudApi::PATH_UPDATE_MODULE, array(
            'localsite' => (string) $localsite,
        ));
        if (!is_array($data) || !isset($data['html'])) {
            return '';
        }
        return (string) $data['html'];
    }

    /**
     * 拉取云端 `/update/theme` 升级提示 HTML 片段（JSON `data.html`）。
     *
     * @param string $localsite 已 urlencode(serialize(...)) 的站点扩展载荷
     * @return string
     */
    public function fetchThemeUpdateHtml($localsite)
    {
        $data = CloudApi::getJson(CloudApi::PATH_UPDATE_THEME, array(
            'localsite' => (string) $localsite,
        ));
        if (!is_array($data) || !isset($data['html'])) {
            return '';
        }
        return (string) $data['html'];
    }

    /**
     * 拉取云端 `/connect` 各通道可更新数量并落库（JSON 信封解包后的计数字段写入 `update_number`）。
     *
     * 云端连接失败时不写库，保持上次成功值。
     *
     * @param string $localsite 已 urlencode(serialize(...)) 的站点扩展载荷
     * @param string $localsystem 已 urlencode(serialize(...)) 的系统环境载荷
     * @return void
     */
    public function refreshUpdateNumber($localsite, $localsystem)
    {
        $data = CloudApi::getJson(CloudApi::PATH_CONNECT, array(
            'localsite' => (string) $localsite,
            'localsystem' => (string) $localsystem,
        ));
        if (!is_array($data)) {
            return;
        }

        $updateNumber = array(
            'update' => isset($data['update']) ? (int) $data['update'] : 0,
            'patch' => isset($data['patch']) ? (int) $data['patch'] : 0,
            'module' => isset($data['module']) ? (int) $data['module'] : 0,
            'plugin' => isset($data['plugin']) ? (int) $data['plugin'] : 0,
            'theme' => isset($data['theme']) ? (int) $data['theme'] : 0,
        );

        DB::table('config')
            ->where('name', 'update_number')
            ->update(array('value' => serialize($updateNumber)));
    }

    /**
     * 拉取云服务 `/extend/list` 并在主站侧追加筛选 / 分页 URL，供 Smarty 渲染。
     *
     * 返回值结构（在 API 原始 data 上追加几处 `url` 字段，未修改 items 字段语义）：
     *   items[]                         API 原样字段（含 preview_frame_url、thumb_url）；其中 action 内指向本后台的
     *                                     安装/更新 URL 经 {@see normalizeExtendListActionRoutes()} 统一为
     *                                     `route=cloud/install`（若接口偶发返回其它 route 片段则纠正）
     *   - pagination                      page / page_size / total / total_pages + pages[]
     *   - pagination.pages[i]             page / url / active
     *   - meta.theme_filters              active / all_url / free_url
     *   - meta.system_sign_tabs           active / all_url / items[i].{value,name,url}
     *
     * @param string $slug 'module' | 'theme' | 'plugin' | 'miniprogram'
     * @param array<string, mixed> $currentGet 当前页 $_GET（用于序列化为 `get` 参数，并合并出筛选/分页 URL）
     * @param string $localsite 已 urlencode(serialize(...)) 的 localsite 载荷
     * @return array<string, mixed>|null 接口不可用 / 解包失败时返回 null
     */
    public function fetchExtendList($slug, array $currentGet, $localsite)
    {
        $slug = (string) $slug;
        if ($slug === '') {
            return null;
        }

        $data = CloudApi::getJson(CloudApi::PATH_EXTEND_LIST, array(
            'slug' => $slug,
            'get' => urlencode(serialize($currentGet)),
            'localsite' => (string) $localsite,
        ));

        if (!is_array($data)) {
            return null;
        }

        $data = $this->normalizeExtendListActionRoutes($data);

        $baseRoute = '';
        if (isset(self::$extendListRoutes[$slug])) {
            $baseRoute = route(self::$extendListRoutes[$slug]);
        }

        if (!isset($data['items']) || !is_array($data['items'])) {
            $data['items'] = array();
        }

        if ($baseRoute !== '') {
            if (!empty($data['meta']['theme_filters'])) {
                $data['meta']['theme_filters']['all_url'] = $this->extendListUrl(
                    $baseRoute,
                    $currentGet,
                    array('type' => '', 'page' => '')
                );
                $data['meta']['theme_filters']['free_url'] = $this->extendListUrl(
                    $baseRoute,
                    $currentGet,
                    array('type' => 'free', 'page' => '')
                );
            }

            if (!empty($data['meta']['system_sign_tabs'])) {
                $data['meta']['system_sign_tabs']['all_url'] = $this->extendListUrl(
                    $baseRoute,
                    $currentGet,
                    array('system_sign' => '', 'page' => '')
                );
                if (isset($data['meta']['system_sign_tabs']['items']) && is_array($data['meta']['system_sign_tabs']['items'])) {
                    foreach ($data['meta']['system_sign_tabs']['items'] as &$tab) {
                        $tab['url'] = $this->extendListUrl(
                            $baseRoute,
                            $currentGet,
                            array('system_sign' => isset($tab['value']) ? (string) $tab['value'] : '', 'page' => '')
                        );
                    }
                    unset($tab);
                }
            }

            $totalPages = isset($data['pagination']['total_pages']) ? (int) $data['pagination']['total_pages'] : 0;
            if ($totalPages > 1) {
                $currentPage = isset($data['pagination']['page']) ? (int) $data['pagination']['page'] : 1;
                $pages = array();
                for ($p = 1; $p <= $totalPages; $p++) {
                    $pages[] = array(
                        'page' => $p,
                        'url' => $this->extendListUrl($baseRoute, $currentGet, array('page' => $p)),
                        'active' => $p === $currentPage,
                    );
                }
                $data['pagination']['pages'] = $pages;
            }
        }

        if (!isset($data['pagination']) || !is_array($data['pagination'])) {
            $data['pagination'] = array();
        }
        if (!isset($data['pagination']['pages']) || !is_array($data['pagination']['pages'])) {
            $data['pagination']['pages'] = array();
        }

        return $data;
    }

    /**
     * 将扩展列表 items[].action 中的安装/更新 URL 规范为当前后台使用的 `route=cloud/install`。
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeExtendListActionRoutes(array $data)
    {
        if (!isset($data['items']) || !is_array($data['items'])) {
            return $data;
        }
        foreach ($data['items'] as $idx => $row) {
            if (!isset($row['action']) || !is_array($row['action'])) {
                continue;
            }
            $data['items'][$idx]['action'] = $this->normalizeSingleActionRoute($row['action']);
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $action
     * @return array<string, mixed>
     */
    private function normalizeSingleActionRoute(array $action)
    {
        if (isset($action['url']) && is_string($action['url'])) {
            $action['url'] = $this->enforceCloudInstallRouteInUrl($action['url']);
        }
        $popupOk = isset($action['popup']) && is_array($action['popup']) && count($action['popup']) > 0;
        if ($popupOk) {
            if (isset($action['popup']['btn_link']) && is_string($action['popup']['btn_link'])) {
                $action['popup']['btn_link'] = $this->enforceCloudInstallRouteInUrl($action['popup']['btn_link']);
            }
        } else {
            $action['popup'] = false;
        }

        return $action;
    }

    /**
     * @param string $url
     * @return string
     */
    private function enforceCloudInstallRouteInUrl($url)
    {
        $url = (string) $url;
        if ($url === '' || strpos($url, 'route=cloud/handle') === false) {
            return $url;
        }
        return str_replace('route=cloud/handle', 'route=cloud/install', $url);
    }

    /**
     * 基于当前 GET 合并覆盖参数，拼出指向云列表入口的完整 URL（用于筛选条 / 分页）。
     *
     * - `route` 已包含在 $baseRoute 中，会从合并参数中去除避免重复。
     * - 覆盖值为空串或 null 时表示「清除该参数」。
     *
     * @param string $baseRoute 形如 `index.php?route=theme/install` 的入口
     * @param array<string, mixed> $currentGet 当前 $_GET（含 route）
     * @param array<string, mixed> $overrides 待覆盖键
     * @return string
     */
    public function extendListUrl($baseRoute, array $currentGet, array $overrides)
    {
        $merged = $currentGet;
        unset($merged['route']);

        foreach ($overrides as $key => $value) {
            if ($value === '' || $value === null) {
                unset($merged[$key]);
            } else {
                $merged[$key] = $value;
            }
        }

        $query = http_build_query($merged);
        if ($query === '') {
            return $baseRoute;
        }
        $sep = strpos($baseRoute, '?') === false ? '?' : '&';
        return $baseRoute . $sep . $query;
    }

    /**
     * 拉取安装包下载地址并区分授权状态。
     *
     * 约束：下载地址只认云端 `/download/install-resolve` 返回值，不做本地 URL 拼接兜底。
     *
     * 请求体携带：
     *   - `localsystem`（与 `/connect`、`/update/system` 同格式），便于云端识别客户端环境；
     *   - `user`、`password`：本地 `cloud_account` 中的云账号邮箱/手机 + MD5 密码，
     *     供云端对四类付费扩展（module/theme/plugin/miniprogram）做「已购 OR VIP 在期」判定；
     *   - `site_url`：当前站点根 URL，云端用作访问审计。
     *
     * 与云端 envelope 的对应：
     *   - `code === 0` + 非空 `data.download_url` → `'ok'`；
     *   - `code === 401` 或 `message === 'login_required'` → `'login_required'`；
     *   - `code === 403` + `message === 'unauthorized'`（版权失败）→ `'unauthorized'`；
     *   - `code === 403` + `message === 'not_purchased'` → `'not_purchased'`；
     *   - `code === 403` + `message === 'vip_required'`  → `'vip_required'`；
     *   - `code === 403` + `message === 'vip_expired'`   → `'vip_expired'`；
     *   - 其它（连不上 / code 非预期 / 缺字段）→ `'unavailable'`。
     *
     * @param string $type
     * @param string $cloudId
     * @param string $mode
     * @return array{status:string, download_url:string}
     */
    public function resolveInstallDownload($type, $cloudId, $mode)
    {
        $cloudAccount = unserialize((string) Config::get('site.cloud_account', ''));

        $envelope = CloudApi::postJsonEnvelope(CloudApi::PATH_DOWNLOAD_INSTALL_RESOLVE, array(
            'type' => (string) $type,
            'cloud_id' => (string) $cloudId,
            'mode' => (string) $mode,
            'localsystem' => $this->updateState->localSystemPayload(),
            'user' => is_array($cloudAccount) && isset($cloudAccount['user']) ? (string) $cloudAccount['user'] : '',
            'password' => is_array($cloudAccount) && isset($cloudAccount['password']) ? (string) $cloudAccount['password'] : '',
            'site_url' => defined('ROOT_URL') ? (string) ROOT_URL : '',
        ));

        if (is_array($envelope)) {
            $code = isset($envelope['code']) ? (int) $envelope['code'] : -1;
            $message = isset($envelope['message']) ? (string) $envelope['message'] : '';
            $data = isset($envelope['data']) && is_array($envelope['data']) ? $envelope['data'] : array();

            if ($code === 0 && isset($data['download_url']) && (string) $data['download_url'] !== '') {
                return array('status' => 'ok', 'download_url' => (string) $data['download_url']);
            }

            if ($code === 401 || $message === 'login_required') {
                return array('status' => 'login_required', 'download_url' => '');
            }

            if ($code === 403) {
                if ($message === 'not_purchased') {
                    return array('status' => 'not_purchased', 'download_url' => '');
                }
                if ($message === 'vip_required') {
                    return array('status' => 'vip_required', 'download_url' => '');
                }
                if ($message === 'vip_expired') {
                    return array('status' => 'vip_expired', 'download_url' => '');
                }
                return array('status' => 'unauthorized', 'download_url' => '');
            }
        }

        return array('status' => 'unavailable', 'download_url' => '');
    }
}

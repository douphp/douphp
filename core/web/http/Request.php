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

namespace Dou\Core\Web\Http;

// 业务依赖通过 helpers 在 validate() 内即用即取，不依赖 Container 注入
use Dou\Core\Facade\DB;
use Dou\Core\Support\Check;
use Dou\Core\Web\Validation\Validator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * HTTP 请求对象
 *
 * 目标：
 * 1) 统一访问 GET/POST/COOKIE/SERVER/HEADER
 * 2) 提供常用类型读取与判定方法
 * 3) 持有由 Init / Router 写入的请求级派生属性：
 *    - 路由信息（routeModule / routeAction / routeSub）：3 端 Router::dispatch 解析路径段后写入
 *    - baseUrl：Init 早期由 HTTP_HOST + 入口脚本目录推导得出，仅 SiteConfigAssembler 作为 root_url fallback 消费一次
 * 4) 保持 PHP 5.6+ 兼容，不依赖外部组件
 */
class Request
{
    /** @var array */
    protected $get;

    /** @var array */
    protected $post;

    /** @var array */
    protected $cookie;

    /** @var array */
    protected $server;

    /** @var string 当前路由模块短名（由 Router 写入） */
    protected $routeModule = '';

    /** @var string 当前路由动作（由 Router 写入；缺省 'default'） */
    protected $routeAction = 'default';

    /** @var string 当前路由子段（由 Router 写入） */
    protected $routeSub = '';

    /** @var string 已剥语言前缀的路由字符串（入口预处理写入 Request） */
    protected $routeString = '';

    /** @var string 路由中识别到的语言前缀（如 zh-cn；无则空串） */
    protected $routeLangSign = '';

    /** @var array 路由路径参数独立袋（id/category_id/slug 等，由端 Resolver 写入，仅 route() 可读） */
    protected $routeParams = array();

    /** @var string 由 HTTP_HOST + 入口脚本目录推导出的请求级 base URL */
    protected $baseUrl = '';

    /**
     * 可信反向代理名单（精确 IP 或 CIDR）。默认空 = 不信任任何代理：
     * ip() 仅取 REMOTE_ADDR，X-Forwarded-For / X-Forwarded-Proto 一律忽略，
     * 杜绝客户端伪造 XFF 顶替真实 IP。仅当 REMOTE_ADDR 命中本名单（即请求确实
     * 经由我方部署的可信代理转发）才采信转发头。
     *
     * 由 Init 早期 {@see \Dou\Core\Init\InitTrait::loadSecurityConfig()} 经
     * config/security.php 灌入；中间件层 TrustProxyMiddleware 幂等再断言一次。
     *
     * @var array
     */
    private static $trustedProxies = array();

    /**
     * @param array|null $get
     * @param array|null $post
     * @param array|null $cookie
     * @param array|null $server
     */
    public function __construct($get = null, $post = null, $cookie = null, $server = null)
    {
        $this->get = is_array($get) ? $get : $_GET;
        $this->post = is_array($post) ? $post : $_POST;
        $this->cookie = is_array($cookie) ? $cookie : $_COOKIE;
        $this->server = is_array($server) ? $server : $_SERVER;
        $this->mergeJsonBody();
    }

    /**
     * 仅当 Content-Type 为 application/json 时，解析请求体并合并进 POST 袋。
     *
     * 表单流程（multipart / x-www-form-urlencoded）不读 php://input，不受影响；
     * 纯 JSON 请求（API 客户端）此时 $_POST 为空，合并无冲突，使后续 input()/post()/all()
     * 与 validate() 能透明读取 JSON 字段。已存在的 POST 键优先保留。
     *
     * @return void
     */
    private function mergeJsonBody()
    {
        $contentType = isset($this->server['CONTENT_TYPE']) ? (string) $this->server['CONTENT_TYPE'] : '';
        if ($contentType === '' || stripos($contentType, 'application/json') === false) {
            return;
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return;
        }
        foreach ($decoded as $key => $value) {
            if (!array_key_exists($key, $this->post)) {
                $this->post[$key] = $value;
            }
        }
    }

    /**
     * 从超全局捕获请求。
     *
     * @return static
     */
    public static function capture()
    {
        return new static($_GET, $_POST, $_COOKIE, $_SERVER);
    }

    /**
     * 获取所有输入（默认 POST 覆盖同名 GET）。
     *
     * @return array
     */
    public function all()
    {
        return array_merge($this->get, $this->post);
    }

    /**
     * 获取输入参数（先 POST 后 GET）。
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function input($key, $default = null)
    {
        if (isset($this->post[$key])) {
            return $this->post[$key];
        }
        if (isset($this->get[$key])) {
            return $this->get[$key];
        }
        return $default;
    }

    /**
     * 获取 QueryString 参数。
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function query($key, $default = null)
    {
        return isset($this->get[$key]) ? $this->get[$key] : $default;
    }

    /**
     * 获取 GET 参数
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function get($key = null, $default = null)
    {
        if ($key === null) {
            return $this->get;
        }
        return isset($this->get[$key]) ? $this->get[$key] : $default;
    }

    /**
     * 获取 POST 参数。
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function post($key = null, $default = null)
    {
        if ($key === null) {
            return $this->post;
        }
        return isset($this->post[$key]) ? $this->post[$key] : $default;
    }

    /**
     * 获取 Cookie 参数。
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function cookie($key, $default = null)
    {
        return isset($this->cookie[$key]) ? $this->cookie[$key] : $default;
    }

    /**
     * 获取 Server 参数。
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function server($key, $default = null)
    {
        return isset($this->server[$key]) ? $this->server[$key] : $default;
    }

    /**
     * 仅取指定键。
     *
     * @param array $keys
     * @return array
     */
    public function only(array $keys)
    {
        $data = $this->all();
        $picked = array();
        foreach ($keys as $key) {
            if (isset($data[$key])) {
                $picked[$key] = $data[$key];
            }
        }
        return $picked;
    }

    /**
     * 排除指定键。
     *
     * @param array $keys
     * @return array
     */
    public function except(array $keys)
    {
        $data = $this->all();
        foreach ($keys as $key) {
            unset($data[$key]);
        }
        return $data;
    }

    /**
     * 参数是否存在（null 视为不存在）。
     *
     * @param string $key
     * @return bool
     */
    public function has($key)
    {
        return $this->input($key, null) !== null;
    }

    /**
     * 参数是否有值（空串/仅空白/null 视为空）。
     *
     * @param string $key
     * @return bool
     */
    public function filled($key)
    {
        $value = $this->input($key, null);
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return !empty($value);
        }
        return true;
    }

    /**
     * 读取布尔值。
     *
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public function boolean($key, $default = false)
    {
        if (!$this->has($key)) {
            return (bool) $default;
        }
        $value = $this->input($key);
        if (is_bool($value)) {
            return $value;
        }
        $value = strtolower(trim((string) $value));
        return in_array($value, array('1', 'true', 'on', 'yes'), true);
    }

    /**
     * 读取整数值。
     *
     * @param string $key
     * @param int $default
     * @return int
     */
    public function integer($key, $default = 0)
    {
        $value = $this->input($key, null);
        if ($value === null || !is_numeric($value)) {
            return (int) $default;
        }
        return intval($value);
    }

    // -------------------------------------------------------------------------
    // Typed string accessors
    //
    // 用途：把全站「取值→校验→失败回退默认值」三段式收口成单行。
    // 设计：
    //   - 通用类型层（integer/boolean/enum）自包含，方法名 `integer()` /
    //     `boolean()` / `enum()` 与值类型语义一一对应
    //   - 业务规则层（digits/alpha/slug/...）委托 {@see Check}，
    //     与 Validator/FormRequest 共享同一份正则定义，保证 DRY
    // 行为约定：
    //   - 读出值为 null 或非字符串 → 返回 $default
    //   - 字符串 trim 后为空 → 返回 $default
    //   - 校验不通过 → 返回 $default；通过 → 返回 trim 后的字符串
    //   - 与现网 `Check::xxx(request()->input(...)) ? request()->input(...) : $d`
    //     等价，并消除散落的 trim()
    // 数据源：$from 取 'input' | 'post' | 'query' | 'get'，默认 'input'
    // -------------------------------------------------------------------------

    /**
     * 按 Check 方法过滤字符串输入，失败回退默认值（业务规则层私有核心）。
     *
     * @param string $key 字段名
     * @param string $checkMethod Check 静态方法名（如 number/slug/rec）
     * @param mixed $default 失败时返回的默认值
     * @param string $from 数据源：input|post|query|get
     * @param bool $negate true 时把 Check 结果取反（用于 illegalChar 这类否定语义）
     * @return mixed 通过校验则返回 trim 后的字符串，否则返回 $default
     */
    private function checked($key, $checkMethod, $default = '', $from = 'input', $negate = false)
    {
        $value = $this->readFrom($key, $from);
        if (!is_string($value)) {
            return $default;
        }
        $value = trim($value);
        if ($value === '') {
            return $default;
        }
        $ok = (bool) Check::{$checkMethod}($value);
        if ($negate) {
            $ok = !$ok;
        }
        return $ok ? $value : $default;
    }

    /**
     * 按 $from 选择数据源读取原始值。
     *
     * @param string $key
     * @param string $from input|post|query|get|route
     * @return mixed
     */
    private function readFrom($key, $from)
    {
        switch ($from) {
            case 'post':
                return $this->post($key, null);
            case 'query':
                return $this->query($key, null);
            case 'get':
                return $this->get($key, null);
            case 'route':
                return $this->route($key, null);
            case 'input':
            default:
                return $this->input($key, null);
        }
    }

    /**
     * 纯数字串（^[0-9]+$，委托 Check::number）。
     *
     * 与 {@see integer()} 区分：integer 返回 int 类型且接受任意 numeric（含小数、负数、科学计数法）；
     * digits 返回原字符串，仅接受纯数字串，用于 order_sn / user_sn 等"看似数字但本质是 ID 字符串"的字段。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function digits($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'number', $default, $from);
    }

    /**
     * 小写字母串（^[a-z]+$，委托 Check::letter）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function alpha($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'letter', $default, $from);
    }

    /**
     * URL Slug 格式（小写字母数字+连字符/下划线，委托 Check::slug）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function slug($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'slug', $default, $from);
    }

    /**
     * 路由动作段（^[a-z_]+$，委托 Check::rec）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function rec($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'rec', $default, $from);
    }

    /**
     * 基础字符串（^[A-Za-z0-9._-]+$，委托 Check::basicString）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function basicString($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'basicString', $default, $from);
    }

    /**
     * 扩展 ID（^[A-Za-z0-9-_.]+$，委托 Check::extendId）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function extendId($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'extendId', $default, $from);
    }

    /**
     * 纯文本（不含非法字符，委托 !Check::illegalChar）。
     *
     * 用于 area parent/current、team class 等"允许中文等普通字符、但禁 shell 元字符"的字段。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function plainText($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'illegalChar', $default, $from, true);
    }

    /**
     * 日期字符串（YYYY-MM-DD，委托 Check::date）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function date($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'date', $default, $from);
    }

    /**
     * 邮政编码（委托 Check::postcode）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function postcode($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'postcode', $default, $from);
    }

    /**
     * 金额字符串（数字与小数点，委托 Check::money）。
     *
     * 仅做格式过滤；如需 {@see \Dou\Core\Support\Num} 或 Util::formatPrice 等业务变换，请在调用方完成。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function money($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'money', $default, $from);
    }

    /**
     * 价格字符串（委托 Check::price）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function price($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'price', $default, $from);
    }

    /**
     * 手机号（委托 Check::telphone）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function telphone($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'telphone', $default, $from);
    }

    /**
     * 图形验证码（4 位字母数字，委托 Check::captcha），通过后自动 strtoupper。
     *
     * 默认从 POST 读取（验证码字段几乎总是表单提交而非 query）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function captcha($key = 'captcha', $default = '', $from = 'post')
    {
        $value = $this->checked($key, 'captcha', $default, $from);
        if ($value === $default) {
            return $value;
        }
        return strtoupper($value);
    }

    /**
     * 分类/Class 段（字母数字+中文，委托 Check::className）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function className($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'className', $default, $from);
    }

    /**
     * 语言包目录名（^[a-z_]+$，委托 Check::languagePack）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function languagePack($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'languagePack', $default, $from);
    }

    /**
     * 用户名（手机/邮箱/无非法字符，委托 Check::username）。
     *
     * @param string $key
     * @param mixed $default
     * @param string $from
     * @return mixed
     */
    public function username($key, $default = '', $from = 'input')
    {
        return $this->checked($key, 'username', $default, $from);
    }

    /**
     * 白名单枚举。
     *
     * 与 Check 委托型不同：此方法不经过 Check，直接用严格 in_array 做白名单兜底。
     * 适用于业务上已知有限取值的字段（如地区接口 type ∈ {province, city, district}）。
     *
     * @param string $key
     * @param array $allowed 允许的字符串集合
     * @param mixed $default
     * @param string $from
     * @return mixed 命中白名单返回 trim 后的字符串，否则返回 $default
     */
    public function enum($key, array $allowed, $default = '', $from = 'input')
    {
        $value = $this->readFrom($key, $from);
        if (!is_string($value)) {
            return $default;
        }
        $value = trim($value);
        if ($value === '') {
            return $default;
        }
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * HTTP Method（GET/POST/PUT/PATCH/DELETE...），含方法伪装（method spoofing）。
     *
     * 浏览器 form 只能发 GET / POST；RESTful 的 PUT/PATCH/DELETE 经「方法伪装」承载：
     * 仅当真实 REQUEST_METHOD 为 POST 时采信伪装值，优先级 `_method`(body) >
     * `X-HTTP-Method-Override`(header)，白名单 PUT/PATCH/DELETE，其它一律忽略。
     * JSON / 原生 PUT/DELETE 客户端不经此分支，直接返回真实方法。
     *
     * @return string
     */
    public function method()
    {
        $real = isset($this->server['REQUEST_METHOD']) ? strtoupper((string) $this->server['REQUEST_METHOD']) : 'GET';
        if ($real !== 'POST') {
            return $real;
        }

        $spoof = '';
        $bodyOverride = $this->post('_method', '');
        if (is_string($bodyOverride) && $bodyOverride !== '') {
            $spoof = $bodyOverride;
        } else {
            $headerOverride = $this->header('X-HTTP-Method-Override', '');
            if (is_string($headerOverride) && $headerOverride !== '') {
                $spoof = $headerOverride;
            }
        }

        $spoof = strtoupper(trim($spoof));
        if (in_array($spoof, array('PUT', 'PATCH', 'DELETE'), true)) {
            return $spoof;
        }
        return 'POST';
    }

    /**
     * 当前请求是否为指定 Method。
     *
     * @param string $method
     * @return bool
     */
    public function isMethod($method)
    {
        return $this->method() === strtoupper((string) $method);
    }

    /**
     * 是否 Ajax 请求。
     *
     * @return bool
     */
    public function isAjax()
    {
        $requestedWith = isset($this->server['HTTP_X_REQUESTED_WITH'])
            ? $this->server['HTTP_X_REQUESTED_WITH'] : '';
        return strtolower($requestedWith) === 'xmlhttprequest';
    }

    /**
     * 当前请求是否期望 JSON 响应（内容协商）。
     *
     * 满足任一即视为期望 JSON：
     *   - Accept 头包含 application/json；
     *   - XMLHttpRequest（isAjax）。
     *
     * 作为服务端「单次 POST + 内容协商」的唯一判定入口，入口层异常渲染与
     * Controller 末尾分流统一复用本方法，避免多处各自判定造成漂移。
     *
     * @return bool
     */
    public function wantsJson()
    {
        $accept = (string) $this->header('Accept', '');
        if ($accept !== '' && stripos($accept, 'application/json') !== false) {
            return true;
        }
        return $this->isAjax();
    }

    /**
     * 获取 Header。
     *
     * @param string $name
     * @param mixed $default
     * @return mixed
     */
    public function header($name, $default = null)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return isset($this->server[$key]) ? $this->server[$key] : $default;
    }

    /**
     * 从 `Authorization` 请求头抽取 Bearer token（RFC 6750）。
     *
     * strip `Bearer ` 前缀（大小写不敏感，前缀后允许 1+ 空格），未匹配返回空串。
     * API 端 token 鉴权统一走此入口；未命中由调用方按未鉴权处理。
     *
     * 多源回退（Apache + PHP-CGI/FPM 默认会 strip Authorization 头）：
     *   1) `HTTP_AUTHORIZATION` —— 正常路径（nginx + fastcgi 默认透传）
     *   2) `REDIRECT_HTTP_AUTHORIZATION` —— mod_rewrite 透传规则落点（.htaccess 内 `[E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`）
     *   3) `apache_request_headers()` —— mod_php / 部分 fastcgi 部署可用
     *
     * @return string
     */
    public function bearerToken()
    {
        $header = (string) $this->header('Authorization', '');
        if ($header === '' && isset($this->server['REDIRECT_HTTP_AUTHORIZATION'])) {
            $header = (string) $this->server['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if ($header === '' && function_exists('apache_request_headers')) {
            $apacheHeaders = apache_request_headers();
            if (is_array($apacheHeaders)) {
                if (isset($apacheHeaders['Authorization'])) {
                    $header = (string) $apacheHeaders['Authorization'];
                } elseif (isset($apacheHeaders['authorization'])) {
                    $header = (string) $apacheHeaders['authorization'];
                }
            }
        }
        if ($header === '') {
            return '';
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $header, $m) === 1) {
            return trim($m[1]);
        }
        return '';
    }

    /**
     * 抽取当前请求携带的 CSRF token，多源读取，服务端不强制客户端塞 token 的位置。
     *
     * 优先级：
     *   1. body / query 的 token 字段（原生 form / hidden input / 一次性 token dual-POST）
     *   2. HTTP Header X-CSRF-Token / X-XSRF-Token（AJAX 全局拦截器注入的 static_xxx token）
     *
     * @return string 命中的 token；未命中返回空串，由调用方按业务判定
     */
    public function csrfToken()
    {
        $body = (string) $this->input('token');
        if ($body !== '') {
            return $body;
        }
        foreach (array('X-CSRF-Token', 'X-XSRF-Token') as $name) {
            $value = (string) $this->header($name, '');
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * 返回所有 HTTP 头，以 "Authorization"、"Content-Type" 形式为键。
     *
     * @return array
     */
    public function headers()
    {
        $headers = array();
        foreach ($this->server as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $key = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))));
                $headers[$key] = $value;
            }
        }
        return $headers;
    }

    /**
     * 客户端 IP。
     *
     * 候选来源（按业务优先级收集）：
     *   1) X-Forwarded-For —— 拆分逗号后逐段（左到右，原始客户端在最左）
     *   2) HTTP_CLIENT_IP
     *   3) REMOTE_ADDR
     *
     * 匹配顺序（按用户场景的可读性诉求"优先 IPv4，再退 IPv6"）：
     *   - 第一遍：在所有候选中找第一个 IPv4（含两种"与 IPv4 等价"的 IPv6 表达，
     *     经 normalizeIpv4 归一化后视为 IPv4）。
     *   - 第二遍：在所有候选中找第一个纯 IPv6 公网地址。
     *   - 兜底：返回首段原始字符串，确保不丢失信息。
     *
     * 归一化（normalizeIpv4 内部）：
     *   - `::1`            → `127.0.0.1`（IPv6 loopback ↔ IPv4 loopback）
     *   - `::ffff:1.2.3.4` → `1.2.3.4`（IPv4-mapped IPv6，本质就是同一个 IPv4）
     *   其它纯 IPv6 地址保留原样，不做有损转换。
     *
     * @return string
     */
    public function ip()
    {
        $candidates = $this->collectClientIpCandidates();

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeIpv4($candidate);
            if (filter_var($normalized, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $normalized;
            }
        }

        foreach ($candidates as $candidate) {
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return $candidate;
            }
        }

        return isset($candidates[0]) ? $candidates[0] : '';
    }

    /**
     * 设置可信反向代理名单（精确 IP 或 CIDR，如 '10.0.0.0/8'、'192.168.1.5'）。
     *
     * 默认不信任任何代理；只有把我方部署的负载均衡 / 反代的出口 IP 配进来后，
     * ip() / isSecure() 才会采信对应请求的 X-Forwarded-For / X-Forwarded-Proto。
     *
     * @param array $proxies
     * @return void
     */
    public static function setTrustedProxies(array $proxies)
    {
        $clean = array();
        foreach ($proxies as $p) {
            $p = trim((string) $p);
            if ($p !== '') {
                $clean[] = $p;
            }
        }
        self::$trustedProxies = $clean;
    }

    /**
     * 当前请求的 REMOTE_ADDR 是否来自可信代理。
     *
     * @return bool
     */
    private function isFromTrustedProxy()
    {
        if (empty(self::$trustedProxies)) {
            return false;
        }
        $remote = isset($this->server['REMOTE_ADDR']) ? (string) $this->server['REMOTE_ADDR'] : '';
        if ($remote === '') {
            return false;
        }
        foreach (self::$trustedProxies as $proxy) {
            if ($this->ipMatches($remote, $proxy)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 判断 IP 是否匹配某条规则（精确 IP 或 CIDR）。
     *
     * @param string $ip
     * @param string $rule
     * @return bool
     */
    private function ipMatches($ip, $rule)
    {
        if (strpos($rule, '/') === false) {
            return $ip === $rule;
        }

        list($subnet, $bits) = explode('/', $rule, 2);
        $bits = (int) $bits;
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bytes = intval($bits / 8);
        $remainder = $bits % 8;
        if ($bytes > 0 && substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        if ($remainder === 0) {
            return true;
        }
        $mask = chr(0xff << (8 - $remainder) & 0xff);
        return (substr($ipBin, $bytes, 1) & $mask) === (substr($subnetBin, $bytes, 1) & $mask);
    }

    /**
     * 收集所有候选 IP（按业务优先级原顺序展开，已去除空白段）。
     *
     * 默认不信任任何代理：仅返回 REMOTE_ADDR，X-Forwarded-For / HTTP_CLIENT_IP 一律忽略，
     * 避免客户端伪造转发头顶替真实 IP。仅当 REMOTE_ADDR 命中可信代理名单时才展开转发头。
     *
     * @return array
     */
    private function collectClientIpCandidates()
    {
        $remote = (isset($this->server['REMOTE_ADDR']) && $this->server['REMOTE_ADDR'] !== '')
            ? $this->server['REMOTE_ADDR']
            : '';

        if (!$this->isFromTrustedProxy()) {
            return $remote !== '' ? array($remote) : array();
        }

        $candidates = array();

        if (!empty($this->server['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $this->server['HTTP_X_FORWARDED_FOR']);
            foreach ($parts as $part) {
                $trimmed = trim($part);
                if ($trimmed !== '') {
                    $candidates[] = $trimmed;
                }
            }
        }

        if (!empty($this->server['HTTP_CLIENT_IP'])) {
            $candidates[] = trim($this->server['HTTP_CLIENT_IP']);
        }

        if ($remote !== '') {
            $candidates[] = $remote;
        }

        return $candidates;
    }

    /**
     * 把"与 IPv4 等价"的 IPv6 表达归一化为 IPv4 文本；其它原样返回。
     *
     * @param string $ip
     * @return string
     */
    private function normalizeIpv4($ip)
    {
        if ($ip === '::1') {
            return '127.0.0.1';
        }
        if (stripos($ip, '::ffff:') === 0) {
            $candidate = substr($ip, 7);
            if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                return $candidate;
            }
        }
        return $ip;
    }

    /**
     * User-Agent。
     *
     * @return string
     */
    public function userAgent()
    {
        return isset($this->server['HTTP_USER_AGENT']) ? $this->server['HTTP_USER_AGENT'] : '';
    }

    /**
     * 请求路径（不含 QueryString）。
     *
     * @return string
     */
    public function path()
    {
        $uri = isset($this->server['REQUEST_URI']) ? $this->server['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        return $path !== null ? $path : '/';
    }

    /**
     * 是否 HTTPS。
     *
     * @return bool
     */
    public function isSecure()
    {
        if (isset($this->server['HTTPS']) && strtolower((string) $this->server['HTTPS']) !== 'off' && $this->server['HTTPS'] !== '') {
            return true;
        }
        if (isset($this->server['SERVER_PORT']) && (string) $this->server['SERVER_PORT'] === '443') {
            return true;
        }
        // 仅当请求来自可信代理时，才采信其 X-Forwarded-Proto 标注的原始协议。
        if ($this->isFromTrustedProxy() && !empty($this->server['HTTP_X_FORWARDED_PROTO'])) {
            $proto = strtolower(trim((string) $this->server['HTTP_X_FORWARDED_PROTO']));
            // 多级代理时可能逗号分隔，取最左（最接近客户端）。
            if (strpos($proto, ',') !== false) {
                $segs = explode(',', $proto);
                $proto = trim($segs[0]);
            }
            if ($proto === 'https') {
                return true;
            }
        }
        return false;
    }

    /**
     * 请求 Scheme。
     *
     * @return string
     */
    public function scheme()
    {
        return $this->isSecure() ? 'https' : 'http';
    }

    /**
     * 主机名（含端口）。
     *
     * @return string
     */
    public function host()
    {
        if (isset($this->server['HTTP_HOST']) && $this->server['HTTP_HOST'] !== '') {
            return $this->server['HTTP_HOST'];
        }
        $host = isset($this->server['SERVER_NAME']) ? $this->server['SERVER_NAME'] : 'localhost';
        $port = isset($this->server['SERVER_PORT']) ? (string) $this->server['SERVER_PORT'] : '';
        if ($port !== '' && $port !== '80' && $port !== '443') {
            return $host . ':' . $port;
        }
        return $host;
    }

    /**
     * 基础 URL（不含 QueryString）。
     *
     * @return string
     */
    public function url()
    {
        return $this->scheme() . '://' . $this->host() . $this->path();
    }

    /**
     * 完整 URL（含 QueryString）。
     *
     * @return string
     */
    public function fullUrl()
    {
        $uri = isset($this->server['REQUEST_URI']) ? $this->server['REQUEST_URI'] : '/';
        return $this->scheme() . '://' . $this->host() . $uri;
    }

    /**
     * 取上传文件对象（强类型 {@see UploadedFile}）。
     *
     * - 单文件字段：返回 {@see UploadedFile}；不存在返回 null。
     * - 多文件字段（name="xxx[]"）：返回 UploadedFile[]；不存在返回空数组。
     * - 不传 $key：返回全部字段的 UploadedFile 视图（按字段名归集）。
     *
     * @param string|null $key
     * @return UploadedFile|UploadedFile[]|array|null
     */
    public function file($key = null)
    {
        if ($key === null) {
            $all = array();
            foreach (array_keys($_FILES) as $field) {
                $all[$field] = $this->file($field);
            }

            return $all;
        }

        if (!isset($_FILES[$key])) {
            return null;
        }
        $entry = $_FILES[$key];
        if (!is_array($entry) || !isset($entry['name'])) {
            return null;
        }
        if (is_array($entry['name'])) {
            return UploadedFile::allFromGlobals($key);
        }

        return UploadedFile::fromGlobals($key);
    }

    /**
     * 声明式输入校验
     *
     * 对当前请求数据（GET + POST 合并）按规则进行校验，失败时抛出 DomainException。
     *
     * 规则示例：
     *   'name' => 'required'
     *   'slug' => 'required|alpha_dash|unique:article_category,slug'
     *   'slug' => 'required|alpha_dash|unique:article_category,slug,category_id'
     *
     * 消息示例（field.rule 格式）：
     *   'name.required' => '分类名称不能为空'
     *   'slug.alpha_dash' => '别名格式不正确'
     *   'slug.unique'     => '别名已存在'
     *
     * @param array $rules ['field' => 'rule1|rule2|...']
     * @param array $messages ['field.rule' => '已翻译的错误消息']
     * @param bool $collectAll true=按字段聚合全部错误；false=首错即抛（默认）
     * @return void
     * @throws \Dou\Core\Foundation\Exception\DomainException
     */
    public function validate(array $rules, array $messages = [], $collectAll = false)
    {
        $validator = new Validator(DB::getFacadeRoot(), lang_all(), $this->routeModule());
        $validator->validate($this->all(), $rules, $messages, $collectAll);
    }

    /**
     * 写入当前路由（由 3 端 Router::dispatch 解析路径段后调用）。
     *
     * @param string $module
     * @param string $action
     * @param string $sub
     * @return void
     */
    public function setRoute($module, $action, $sub = '')
    {
        $this->routeModule = (string) $module;
        $this->routeAction = (string) $action;
        $this->routeSub = (string) $sub;
    }

    /**
     * 当前路由模块短名（未匹配时为空串）。
     *
     * @return string
     */
    public function routeModule()
    {
        return $this->routeModule;
    }

    /**
     * 当前路由动作（未匹配时为 'default'）。
     *
     * @return string
     */
    public function routeAction()
    {
        return $this->routeAction;
    }

    /**
     * 当前路由子段（如 admin 三段路由的中间段；未匹配时为空串）。
     *
     * @return string
     */
    public function routeSub()
    {
        return $this->routeSub;
    }

    /**
     * 写入已剥语言前缀的路由字符串（入口脚本预处理后注入）。
     *
     * @param string $route
     * @return void
     */
    public function setRouteString($route)
    {
        $this->routeString = (string) $route;
    }

    /**
     * 当前路由字符串（已剥语言前缀）。前台空串表示首页。
     *
     * @return string
     */
    public function routeString()
    {
        return $this->routeString;
    }

    /**
     * 写入路由中识别到的语言前缀（前台入口由 LangPrefixParser 解析，admin/api 恒为空）。
     *
     * @param string $sign 如 zh-cn；无前缀时为空串
     * @return void
     */
    public function setRouteLangSign($sign)
    {
        $this->routeLangSign = (string) $sign;
    }

    /**
     * 当前路由语言前缀（无则空串）。
     *
     * @return string
     */
    public function routeLangSign()
    {
        return $this->routeLangSign;
    }

    /**
     * 写入路由路径参数（由端 Resolver 解析路径段后调用）。
     *
     * 独立于 get/post/json 输入袋：input()/integer()/query()/all() 不读取此袋，
     * 路由参数必须经 route() 访问，从语义上与表单 / 查询输入彻底分离。
     *
     * @param array $params
     * @return void
     */
    public function setRouteParams(array $params)
    {
        $this->routeParams = $params;
    }

    /**
     * 把路由路径捕获的标量参数合并进 GET 输入袋（admin / api 资源路径化用）。
     *
     * 资源路径化后 `?route=article/5/edit` 的 id 由匹配器以路径段捕获；为与
     * `?route=article/edit&id=5`（id 落 $_GET）行为一致，把捕获的标量路径参数注入
     * GET 袋，使既有控制器 `integer('id')` / `input('id')` 透明读取，无需逐控制器改取值口。
     * 路径捕获优先：同名键覆盖既有 GET 值（路径段比残留 query 更权威）。
     *
     * @param array $params 匹配器捕获的具名路径参数
     * @return void
     */
    public function mergeRouteInputs(array $params)
    {
        foreach ($params as $key => $value) {
            if (is_scalar($value)) {
                $this->get[$key] = $value;
            }
        }
    }

    /**
     * 读取路由路径参数：先 routeParams 袋，缺失时回退 query（GET）。
     *
     * routeParams 袋永远优先；仅当该键不在路径袋时回退查询串。这样可同时覆盖：
     *   - 路径形态（伪静态开启 `/oN`，或始终内嵌于 route 串的 id/slug/category_slug 等）；
     *   - 查询形态（伪静态关闭时分页器输出 `?page=N`，此时 page 仅在 GET 袋）。
     * 路径参数只可能以「路径段」或「查询串」承载，绝不经 POST，故回退仅查 GET 袋。
     * input()/integer()/query() 仍不读 routeParams 袋，二者职责互不交叉。
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function route($key, $default = null)
    {
        if (array_key_exists($key, $this->routeParams)) {
            return $this->routeParams[$key];
        }
        if (isset($this->get[$key])) {
            return $this->get[$key];
        }
        return $default;
    }

    /**
     * 写入由入口脚本目录推导的请求级 base URL（Init 早期调用，仅 SiteConfigAssembler 消费）。
     *
     * @param string $url
     * @return void
     */
    public function setBaseUrl($url)
    {
        $this->baseUrl = (string) $url;
    }

    /**
     * 当前请求级 base URL：由 HTTP_HOST + 入口脚本目录推导而来。
     *
     * 仅作为 SiteConfigAssembler 在 DB.config.domain 为空时的 fallback。
     * 业务侧请用 {@see \Dou\Core\Foundation\Configuration\Config::get}('site.root_url') 或常量 ROOT_URL
     * 获取站点对外的规范根 URL。
     *
     * @return string
     */
    public function baseUrl()
    {
        return $this->baseUrl;
    }
}

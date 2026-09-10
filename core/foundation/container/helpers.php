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

use Dou\Admin\Contract\AdminLanguageContract;
use Dou\Core\Contract\DataServiceContract;
use Dou\Core\Contract\LanguageContract;
use Dou\Core\Contract\MessageResponderInterface;
use Dou\Core\Contract\PluginServiceContract;
use Dou\Core\Foundation\Auth\AuthManager;
use Dou\Core\Foundation\Auth\GuardContract;
use Dou\Core\Foundation\Auth\StatefulGuardContract;
use Dou\Core\Foundation\Container\Container;
use Dou\Core\Foundation\Csrf\CsrfManager;
use Dou\Core\Foundation\Lang\LangBag;
use Dou\Core\Foundation\Locale\Locale;
use Dou\Core\Infra\Security\Xss;
use Dou\Core\Service\Attachment\AttachmentService;
use Dou\Core\Service\Audit\AuditService;
use Dou\Core\Service\Noop\NullMessageResponder;
use Dou\Core\Service\User\UserService;
use Dou\Core\Utility\Other;
use Dou\Core\Web\Http\Request;
use Dou\Core\Web\Routing\UrlGenerator;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

if (!function_exists('app')) {
    /**
     * 容器语法糖：从全局容器解析实例（亦作其它 helper 的底层）。
     *
     * 业务调用面优先使用本文件提供的专用 helper（auth()/message() 等）或静态门面
     * （Db::、Session::、Cloud:: 等）；没有对应专用 helper / 门面的服务
     * （MiniprogramNavigationBuilder 等）走 `app(ClassName::class)` 兜底。
     *
     * @param string|null $abstract 类名/绑定标识；为 null 时返回容器实例本身
     * @param array $parameters 额外构造函数参数（仅 make 路径生效）
     * @return mixed
     */
    function app($abstract = null, $parameters = array())
    {
        $container = Container::getInstance();
        if ($abstract === null) {
            return $container;
        }

        return $container->make($abstract, $parameters);
    }
}

if (!function_exists('request')) {
    /**
     * 当前请求对象。
     *
     * @return Request
     */
    function request()
    {
        return app(Request::class);
    }
}

if (!function_exists('csrf')) {
    /**
     * CSRF 令牌管理器。
     *
     * @return CsrfManager
     */
    function csrf()
    {
        return app(CsrfManager::class);
    }
}

if (!function_exists('xss')) {
    /**
     * XSS 过滤工具。
     *
     * @return Xss
     */
    function xss()
    {
        return app(Xss::class);
    }
}

if (!function_exists('route')) {
    /**
     * 生成完整站点 URL（点分路由短写法）。
     *
     * 唯一调用形态：
     *   route('article.show', ['id' => 5])
     *   route('user.login')
     *   route($module . '.category', ['category_id' => $id])
     *   route('page.show', ['slug' => 'agreement'], ['query' => ['from' => 'home']])
     *
     * 需要 instance 方法（urlMini / warmupUrlCache / getSlugPath）时改用
     * {@see \Dou\Core\Facade\Url} 门面：
     *   `use Dou\Core\Facade\Url;` 后 `Url::xxx(...)`。
     *
     * @param string $route 点分路由键
     * @param array $params 具名路径变量（id / category_id / class / slug 等）
     * @param array $options page / query / lang
     * @return string
     */
    function route($route, array $params = array(), array $options = array())
    {
        return app(UrlGenerator::class)
            ->url((string) $route, $params, $options);
    }
}

if (!function_exists('attachment')) {
    /**
     * 附件领域服务（dou_file 表 + 落盘）。
     *
     * 业务侧：
     *   $number = attachment()->store('article', $id, $file, 'main');
     *   attachment()->delete($number);
     *   attachment()->url($number, true);
     *   attachment()->gallery('article', $id, 'gallery', true);
     *
     * @return AttachmentService
     */
    function attachment()
    {
        return app(AttachmentService::class);
    }
}

if (!function_exists('message')) {
    /**
     * 三端「提示页 + 跳转 + 终止」响应器。
     *
     * API 端解析到 {@see NullMessageResponder}，
     * 直接调用会抛 RuntimeException 提示用 ApiResponse 替代。
     *
     * @return MessageResponderInterface
     */
    function message()
    {
        return app(MessageResponderInterface::class);
    }
}

if (!function_exists('plugin')) {
    /**
     * 插件查询契约（plugin 模块卸载时由 NullPluginService 兜底）。
     *
     * @return PluginServiceContract
     */
    function plugin()
    {
        return app(PluginServiceContract::class);
    }
}

if (!function_exists('audit')) {
    /**
     * 审计服务。
     *
     * @return AuditService
     */
    function audit()
    {
        return app(AuditService::class);
    }
}

if (!function_exists('auth')) {
    /**
     * 端侧 Guard（**必须显式传 guard 名**）。
     *
     * 业务调用面统一使用完整写法：
     * - `auth('admin')` 取后台管理员 guard，实现 {@see StatefulGuardContract}
     * - `auth('front')` 取前台会员 guard，实现 {@see StatefulGuardContract}
     * - `auth('api')`   取小程序 / API guard，实现 {@see GuardContract}
     *
     * `auth()` 必传 guard 名：null / 空字符串均抛 InvalidArgumentException，
     * 让「当前调的是哪端」在调用点直接可读，避免 admin shell 误读 front 会员态等混淆。
     *
     * @param string $guard guard 名（admin / front / api）
     * @return object guard 实例（具体类型由所在端决定）
     * @throws \InvalidArgumentException 当 $guard 为 null / 空字符串
     */
    function auth($guard)
    {
        /** @var AuthManager $mgr */
        $mgr = app(AuthManager::class);
        return $mgr->guard($guard);
    }
}

if (!function_exists('locale')) {
    /**
     * 当前请求选定的语言（请求作用域单例）。
     *
     * 与 lang() / language() 区分：
     *  - locale()    取当前请求选定的语言（mode/sign/pack；默认语言时 isActive() 为 false）
     *  - language()  取多语言契约服务（langBox / dataLangFormat / 切换菜单等）
     *  - lang($key)  查 LangBag 译串表（与 features.language 无关，永远加载）
     *
     * Init 早期（bootCommon 阶段，instantiateCoreObjects 之前）resolveCurLang 已需要写入；
     * 本 helper 检测到容器未注册时即时建一个空 Locale 单例，确保任何启动阶段调用都安全。
     *
     * @return Locale
     */
    function locale()
    {
        $container = Container::getInstance();
        if (!$container->has(Locale::class)) {
            $container->instance(Locale::class, new Locale());
        }
        return $container->make(Locale::class);
    }
}

if (!function_exists('language')) {
    /**
     * 语言服务实例（含 langBox / dataLangFormat 等通用方法；后台再扩展 deleteLang / buildLangButtons / buildLangList）。
     *
     * 与译串入口 lang('key') 区分：language() 取契约实例，lang($key) 仅查 LangBag 译串表。
     *
     * 运行期由各端 Init 绑定：前台 / API 绑定到 LanguageContract，后台绑定到 AdminLanguageContract
     * （其继承 LanguageContract）。PHPDoc 写成 union 是为让 IDE 在后台调用面识别 admin 端
     * 扩展方法，运行时仍走单一容器解析。
     *
     * @return LanguageContract|AdminLanguageContract
     */
    function language()
    {
        return app(LanguageContract::class);
    }
}

if (!function_exists('lang')) {
    /**
     * 译串查表：从 LangBag 取串；不存在时返回 $default。
     *
     * 与服务实例入口 language() 区分：language() 返回 LanguageContract（多语言扩展能力），
     * lang('key') 仅查「语言包译串表」（永远加载，与 features.language 无关）。
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    function lang($key, $default = '')
    {
        return app(LangBag::class)->get($key, $default);
    }
}

if (!function_exists('lang_set')) {
    /**
     * 向当前请求的语言包写入键值。
     *
     * 实际写入 LangBag 单例——所有读路径（lang('key') / 模板 / Init 注入到 smarty 的拷贝）共享同一份。
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    function lang_set($key, $value)
    {
        app(LangBag::class)->set($key, $value);
    }
}

if (!function_exists('lang_has')) {
    /**
     * 语言键是否存在。
     *
     * 与 lang('key') 不同：本函数返回 bool，不读值。专门用于 if 条件 / 复合判断
     * 这类只关心「键是否存在」的场景。
     *
     * @param string $key
     * @return bool
     */
    function lang_has($key)
    {
        return app(LangBag::class)->has($key);
    }
}

if (!function_exists('lang_all')) {
    /**
     * 整表 by-value 返回当前语言包。
     *
     * 供按值传 $lang 的位置使用：邮件 SiteMail::sendXxx、Smarty assign('lang', ...)、
     * Validator 构造、主题扩展 $_LANG 注入等。
     *
     * @return array
     */
    function lang_all()
    {
        return app(LangBag::class)->all();
    }
}

if (!function_exists('user')) {
    /**
     * 会员服务（user 模块未装时返回 null）。
     *
     * @return UserService|null
     */
    function user()
    {
        $container = Container::getInstance();
        return $container->has(UserService::class)
            ? $container->make(UserService::class)
            : null;
    }
}

if (!function_exists('data')) {
    /**
     * 碎片化数据访问契约（data 模块卸载 / features.data 关闭时由 NullDataService 兜底，永不为 null）。
     *
     * 业务侧两类调用：
     * - `data()->get()` / `data()->get('hero')` / `data()->get('hero', 'image')`
     *   读取主题数据：无参整张 dict、单参单行、双参单字段、三参带默认值；首次调用打 DB，
     *   之后实例缓存内存命中。
     * - `data()->query('product', $id)` / `query('page', $slug)` / `query('index')`
     *   按 (group, item, parent) tuple 查询模块绑定数据，同 tuple 实例缓存。
     *
     * @return DataServiceContract
     */
    function data()
    {
        return app(DataServiceContract::class);
    }
}

if (!function_exists('other')) {
    /**
     * Other 自定义工具类（core/utility/Other.php 不存在时为 null）。
     *
     * 用于补充主系统未覆盖的临时 / 定制能力；首轮调用 file_exists + new，
     * 之后命中 static 缓存。$resolved 哨兵保证「文件不存在」分支下不会
     * 每次调用都重跑 file_exists。
     *
     * @return Other|null
     */
    function other()
    {
        static $instance = null;
        static $resolved = false;
        if ($resolved) {
            return $instance;
        }
        $resolved = true;
        if (file_exists(CORE_PATH . 'utility/Other.php')) {
            $instance = new Other();
        }
        return $instance;
    }
}

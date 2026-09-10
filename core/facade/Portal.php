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

namespace Dou\Core\Facade;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Module\ModuleModelResolver;
use Dou\Core\Support\CategoryIds;
use Dou\Core\Support\Str;
use Dou\Front\Model\Box\Box;
use Dou\Front\Model\Link\Link;
use Dou\Front\Model\Page\Page;
use Dou\Front\Model\Show\Show;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 门户静态门面：前台主题脚本（inc/..from_theme.php）与小程序扩展脚本
 * （inc/..from_miniprogram.php）共用的统一入口，以 Portal::xxx() 调用模板赋值、
 * 路由上下文、多语言、站点装饰位、内容列表、分类树等能力。
 *
 * 由 ThemeExtensionLoader / MiniprogramExtensionLoader 在 include 扩展脚本前
 * 调用 boot() 写入赋值目标与当前路由。
 *
 * assign() 的落点由 boot() 注入的 $assignStore 决定：
 * - 主题侧：DouView 引擎实例（assign 写入模板变量供 HTML 渲染）；
 * - 小程序侧：MiniprogramExtensionLoader 实例（assign 写入其内部 $assigns，最终合并进 JSON 响应）。
 * 两者均暴露 assign($key, $value) 方法，门面按鸭子类型委托，不强类型约束。
 */
class Portal
{
    /** @var object|null 暴露 assign($key, $value) 的赋值目标（DouView / MiniprogramExtensionLoader） */
    private static $assignStore = null;

    /** @var string 当前路由模块名（首页固定 'index'） */
    private static $routeModule = '';

    /** @var string 当前路由动作名 */
    private static $routeAction = '';

    /** @var array 模板标签（{list}/{category}）请求级结果缓存：module + props => rows */
    private static $tagCache = array();

    /**
     * 写入门户上下文（赋值目标 + 当前路由），include 扩展脚本前调用一次。
     *
     * @param string $routeModule
     * @param string $routeAction
     * @param object|null $assignStore 暴露 assign($key, $value) 的对象（DouView / MiniprogramExtensionLoader）
     * @return void
     */
    public static function boot($routeModule = '', $routeAction = '', $assignStore = null)
    {
        self::$routeModule = (string) $routeModule;
        self::$routeAction = (string) $routeAction;
        self::$assignStore = $assignStore;
    }

    /**
     * 赋值到当前上下文（主题写模板变量，小程序写响应数组）。
     *
     * @param string|array $key
     * @param mixed $value
     * @return void
     */
    public static function assign($key, $value = null)
    {
        if (self::$assignStore === null) {
            return;
        }
        self::$assignStore->assign($key, $value);
    }

    /**
     * 当前路由模块名。
     *
     * @return string
     */
    public static function routeModule()
    {
        return self::$routeModule;
    }

    /**
     * 当前路由动作名。
     *
     * @return string
     */
    public static function routeAction()
    {
        return self::$routeAction;
    }

    /**
     * 语言包译串。
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function langKey($key, $default = '')
    {
        return lang($key, $default);
    }

    /**
     * 多语言字段覆写。
     *
     * @param array|mixed $row
     * @param string $module
     * @param string $fields 逗号分隔字段名
     * @return array
     */
    public static function langBox($row, $module, $fields = '')
    {
        return language()->langBox($row, $module, $fields);
    }

    /**
     * 多语言单字段取值。
     *
     * @param string $value 原始值
     * @param string $module
     * @param string|int $itemId
     * @param string $field
     * @return string
     */
    public static function langValue($value, $module, $itemId, $field)
    {
        return language()->langValue($value, $module, $itemId, $field);
    }

    /**
     * 幻灯 / 横幅列表。
     *
     * @param string $type pc / miniprogram 等；空串返回全部
     * @return array
     */
    public static function showList($type = 'pc')
    {
        return Show::showList($type);
    }

    /**
     * 指定 class_slug 下的 box 列表。
     *
     * @param string $classSlug
     * @return array
     */
    public static function boxList($classSlug = '')
    {
        return Box::boxList($classSlug);
    }

    /**
     * 主题 data 字典访问；features.data 关闭时由 NullDataService 返回 $default、不抛错。
     *
     * - Portal::data()                          整张 dict（按 code 索引）
     * - Portal::data('hero')                    单行 array|null
     * - Portal::data('hero', 'image')           单字段 mixed|null
     * - Portal::data('hero', 'image', '/x.png') 带默认值
     *
     * @param string|null $code
     * @param string|null $field
     * @param mixed $default
     * @return mixed
     */
    public static function data($code = null, $field = null, $default = null)
    {
        return data()->get($code, $field, $default);
    }

    /**
     * 友情链接列表；features.link 关闭返回空数组。
     *
     * @return array
     */
    public static function linkList()
    {
        if (!class_exists(Link::class)) {
            return array();
        }

        return Link::linkList();
    }

    /**
     * 栏目（带分类）模块内容列表；features 关闭或未命中模块 Model 返回空数组。
     *
     * 管线：with('category') + scopePublished（按 schema['hasStatus'] 决定）
     * + scopeFilterByCategory（按 $catId 决定）+ order($sort, id DESC) + limit。
     * 适配 module.column_module（product / article / doc / professional / solution /
     * support / course / download / gallery / cases / video / item）。
     * 无分类的单模块请改用 singleList()。
     *
     * 会员视图固定为游客视图（userId = 0），列表不挂 forUser scope。
     *
     * @param string $module
     * @param string|int $catId 'ALL' / '' / null = 不按分类过滤；其它值经 scopeFilterByCategory 展开子孙
     * @param int|string $limit 0 / '' 不限制
     * @param string $sort 自定义排序前缀（追加 id DESC）；空 = 仅 id DESC
     * @param array $extraScopes 追加的无参 scope 名数组（method_exists 守卫未命中时 silently skip）
     * @param int $excerptLength 列表 description 截字长度（<=0 透传不截）
     * @return array
     */
    public static function columnList($module, $catId = 'ALL', $limit = 0, $sort = '', array $extraScopes = array(), $excerptLength = 200)
    {
        if (!Config::get('features.' . $module, false)) {
            return array();
        }

        $cls = app(ModuleModelResolver::class)->modelClassFor($module);
        if ($cls === null) {
            return array();
        }

        $schema = method_exists($cls, 'moduleSchema') ? $cls::moduleSchema() : array();

        $query = $cls::with('category');

        if (!empty($schema['hasStatus']) && method_exists($cls, 'scopePublished')) {
            $query->published();
        }

        if ($catId !== 'ALL' && $catId !== '' && $catId !== null
            && method_exists($cls, 'scopeFilterByCategory')) {
            $query->filterByCategory($catId);
        }

        foreach ($extraScopes as $scopeName) {
            if (method_exists($cls, 'scope' . ucfirst($scopeName))) {
                $query->{$scopeName}();
            }
        }

        $query->order($sort ? $sort . ', id DESC' : 'id DESC');

        if ($limit) {
            $query->limit((int) $limit);
        }

        return self::applyDescriptionExcerpt($query->get()->toArray(), $excerptLength);
    }

    /**
     * 单（无分类）模块内容列表；features 关闭或未命中模块 Model 返回空数组。
     *
     * 不挂 with('category') / filterByCategory，不需要 catId。
     * 适配 certificate / brand / equipment / onepic / partner / service / store / team。
     *
     * @param string $module
     * @param int|string $limit 0 / '' 不限制
     * @param string $sort 自定义排序前缀（追加 id DESC）；空 = 仅 id DESC
     * @param int $excerptLength 列表 description 截字长度（<=0 透传不截）
     * @return array
     */
    public static function singleList($module, $limit = 0, $sort = '', $excerptLength = 200)
    {
        if (!Config::get('features.' . $module, false)) {
            return array();
        }

        $cls = app(ModuleModelResolver::class)->modelClassFor($module);
        if ($cls === null) {
            return array();
        }

        $query = $cls::query();

        if (method_exists($cls, 'scopePublished')) {
            $query->published();
        }

        $query->order($sort ? $sort . ', id DESC' : 'id DESC');

        if ($limit) {
            $query->limit((int) $limit);
        }

        return self::applyDescriptionExcerpt($query->get()->toArray(), $excerptLength);
    }

    /**
     * 首页 / 公共区域 about 装配位。
     *
     * 含 name / content / description / link / page_list；
     * content 在 description 非空时取 description，否则取 Markdown 渲染后 Str::excerpt(300) 摘要。
     *
     * @return array
     */
    public static function about()
    {
        return Page::about();
    }

    /**
     * 单页嵌套树；未命中 Model 返回空数组。
     *
     * @param int $parentId
     * @param string|int $currentId
     * @return array
     */
    public static function pageTree($parentId = 0, $currentId = '')
    {
        $cls = app(ModuleModelResolver::class)->modelClassFor('page');
        if ($cls === null || !method_exists($cls, 'pageTree')) {
            return array();
        }

        return $cls::pageTree($parentId, $currentId);
    }

    /**
     * 分类嵌套树；未命中分类 Model 返回空数组。
     *
     * @param string $module 模块名（article / product 等，内部拼 xxx_category）
     * @param string|int $currentCatId 当前激活分类 id
     * @return array
     */
    public static function categoryTree($module, $currentCatId = 0)
    {
        $cls = app(ModuleModelResolver::class)->categoryModelClassFor($module);
        if ($cls === null || !method_exists($cls, 'tree')) {
            return array();
        }

        return $cls::tree($currentCatId);
    }

    /**
     * 分类树 + 每个分类旗下的内容列表；未命中分类 Model 返回空数组。
     *
     * 会员视图固定 userId = 0。
     *
     * @param string $module
     * @param int $itemNumber 每分类内容条数（0 = 不附内容）
     * @param bool $includeChildren true 时递归挂载 child
     * @param int $excerptLength 每分类 list / child.list 内容行 description 截字长度（<=0 透传不截）
     * @return array
     */
    public static function categoryTreeWithItems($module, $itemNumber = 5, $includeChildren = false, $excerptLength = 200)
    {
        $cls = app(ModuleModelResolver::class)->categoryModelClassFor($module);
        if ($cls === null || !method_exists($cls, 'withItems')) {
            return array();
        }

        $tree = $cls::withItems((int) $itemNumber, 0, (bool) $includeChildren, 0);

        return self::applyDescriptionExcerptToTree($tree, $excerptLength);
    }

    /**
     * 模板 {list} 标签统一入口：按模块白名单自动分流 columnList / singleList。
     *
     * 分流规则：
     * - module ∈ module.column_module → columnList（支持 catId 过滤）；
     * - module ∈ module.single_module 且 Model moduleSchema()['listable'] 为真 → singleList；
     * - 其余（业务单模块如 order / user、未知模块）→ 空数组，不抛错。
     *
     * sort 仅接受「字段名 ASC|DESC」逗号序列（防 ORDER BY 注入），不合法时忽略走默认 id DESC。
     * 同请求内按 module + props 缓存，同页重复块不重复查询。
     *
     * @param string $module
     * @param array $props catId / limit / sort / excerpt
     * @return array
     */
    public static function listFor($module, array $props = array())
    {
        $module = (string) $module;
        if ($module === '' || preg_match('/^[a-zA-Z0-9_]+$/', $module) !== 1) {
            return array();
        }

        $cacheKey = 'list:' . $module . ':' . serialize($props);
        if (array_key_exists($cacheKey, self::$tagCache)) {
            return self::$tagCache[$cacheKey];
        }

        $limit = isset($props['limit']) ? (int) $props['limit'] : 0;
        $sort = isset($props['sort']) ? self::sanitizeSort($props['sort']) : '';
        $excerpt = isset($props['excerpt']) ? (int) $props['excerpt'] : 200;

        $rows = array();
        if (in_array($module, (array) Config::get('module.column_module', array()), true)) {
            $catId = isset($props['catId']) ? $props['catId'] : 'ALL';
            $rows = self::columnList($module, $catId, $limit, $sort, array(), $excerpt);
        } elseif (in_array($module, (array) Config::get('module.single_module', array()), true)
            && self::isListableSingle($module)) {
            $rows = self::singleList($module, $limit, $sort, $excerpt);
        }

        self::$tagCache[$cacheKey] = $rows;

        return $rows;
    }

    /**
     * 模板 {category} 标签统一入口：仅栏目型模块，按 with 分流 categoryTree / categoryTreeWithItems。
     *
     * @param string $module
     * @param array $props with('items') / perCat / children / cur / excerpt
     * @return array
     */
    public static function categoryFor($module, array $props = array())
    {
        $module = (string) $module;
        if ($module === '' || preg_match('/^[a-zA-Z0-9_]+$/', $module) !== 1) {
            return array();
        }
        if (!in_array($module, (array) Config::get('module.column_module', array()), true)) {
            return array();
        }

        $cacheKey = 'category:' . $module . ':' . serialize($props);
        if (array_key_exists($cacheKey, self::$tagCache)) {
            return self::$tagCache[$cacheKey];
        }

        if (isset($props['with']) && $props['with'] === 'items') {
            $perCat = isset($props['perCat']) ? (int) $props['perCat'] : 5;
            $children = !empty($props['children']);
            $excerpt = isset($props['excerpt']) ? (int) $props['excerpt'] : 200;
            $tree = self::categoryTreeWithItems($module, $perCat, $children, $excerpt);
        } else {
            $cur = isset($props['cur']) ? $props['cur'] : 0;
            $tree = self::categoryTree($module, $cur);
        }

        self::$tagCache[$cacheKey] = $tree;

        return $tree;
    }

    /**
     * 单模块是否声明为主题可列表（Model moduleSchema()['listable'] 为真）。
     *
     * @param string $module
     * @return bool
     */
    private static function isListableSingle($module)
    {
        $cls = app(ModuleModelResolver::class)->modelClassFor($module);
        if ($cls === null || !method_exists($cls, 'moduleSchema')) {
            return false;
        }
        $schema = $cls::moduleSchema();

        return !empty($schema['listable']);
    }

    /**
     * ORDER BY 白名单校验：仅接受「字段名 ASC|DESC」逗号序列，不合法返回空串（走默认 id DESC）。
     *
     * @param mixed $sort
     * @return string
     */
    private static function sanitizeSort($sort)
    {
        $sort = trim((string) $sort);
        if ($sort === '') {
            return '';
        }
        if (preg_match('/^[a-zA-Z0-9_]+\s+(ASC|DESC)(\s*,\s*[a-zA-Z0-9_]+\s+(ASC|DESC))*$/i', $sort) !== 1) {
            return '';
        }

        return $sort;
    }

    /**
     * 单页行（按 unique_id）；未命中返回 null。
     *
     * @param string $uniqueId
     * @return array|null
     */
    public static function page($uniqueId)
    {
        $model = Page::where('unique_id', (string) $uniqueId)->first();

        return $model ? $model->getAttributes() : null;
    }

    /**
     * 分类自身 + 全部子孙 category_id 集合（用于扩展脚本手写 category_id IN (...) 拼接）。
     *
     * @param string $module
     * @param int|string $catId
     * @return array<int, int>
     */
    public static function categorySubtreeIds($module, $catId)
    {
        return CategoryIds::subtree($module . '_category', $catId);
    }

    /**
     * 扁平 rows 中每行 description 收口截字（columnList / singleList 兜底）。
     *
     * 与 HasContentListFields::getDescriptionAttribute() 协作：accessor 仅做语义转换
     * （markdown 渲染 + strip_tags 转纯文本），不截字；本 helper 做长度收口，避免套件直接拿到全文。
     *
     * @param array $rows
     * @param int $length 截字长度（<=0 透传不截）
     * @return array
     */
    private static function applyDescriptionExcerpt(array $rows, $length)
    {
        $length = (int) $length;
        if ($length <= 0) {
            return $rows;
        }
        foreach ($rows as $i => $row) {
            if (is_array($row) && isset($row['description'])) {
                $rows[$i]['description'] = Str::excerpt((string) $row['description'], $length, false);
            }
        }

        return $rows;
    }

    /**
     * categoryTreeWithItems() 的嵌套树结构截字收口；递归处理每个节点的 list 与 child。
     *
     * 节点结构：{category_id, name, list: [内容行], child: '' | [子节点]}。
     *
     * @param array $tree
     * @param int $length 内容行 description 截字长度（<=0 透传不截）
     * @return array
     */
    private static function applyDescriptionExcerptToTree(array $tree, $length)
    {
        $length = (int) $length;
        if ($length <= 0) {
            return $tree;
        }
        foreach ($tree as $i => $node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['list']) && is_array($node['list'])) {
                $tree[$i]['list'] = self::applyDescriptionExcerpt($node['list'], $length);
            }
            if (isset($node['child']) && is_array($node['child'])) {
                $tree[$i]['child'] = self::applyDescriptionExcerptToTree($node['child'], $length);
            }
        }

        return $tree;
    }
}

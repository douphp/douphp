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

namespace Dou\Core\Web\Routing;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Naming;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 站点 URL 统一构建器
 *
 * 对外暴露：
 *   buildFullUrl()           生成完整 URL（按 site.rewrite + config/route.php 选中 style），仅由 {@see UrlGenerator::url} 调用
 *   rewriteUrlMiniprogram()  生成小程序 pages 路径
 *   warmupUrlCache()         列表页批量预热 URL 字段缓存
 *   getSlugPath()            读取模块 / 分类 slug 路径片段
 *
 * 调用契约（底线）：
 *   - buildFullUrl 仅接受由 {@see Route} 解析后的结构化 $intent；不接受散参字符串、不做语义猜测。
 *   - 输入不规范应由调用面提前格式化，禁止在此处吞错或回退兼容。
 *   - 不读取 IS_ADMIN 等调用方上下文常量；按入参与站点配置生成 URL，admin / front 调用输出一致。
 *
 * 内部处理阶段（与类内方法顺序大致对应）：
 *   - 路径片段：buildPrettyPath()，按 $intent['kind'] 直接分发；按站点 site.rewrite + config/route.php 选中 style 决定输出形态。
 *   - 完整 URL：composeFullUrl() 拼接 ROOT_URL、语言前缀；$page 非空时由 applyPagination() 追加分页。
 *     开启伪静态时，buildFullUrl() 会先把 /oN 嵌入路径再调用 composeFullUrl()，此时 $page 通常已清空。
 *   - 规则与模板：mergeRouteConfig() 合并 config/route.php 与 config/route_custom.php；按站点配置选中各 type 的 style 规则集。
 *   - 缓存：warmupUrlCache()、cachedField()、cachedCategorySlug() 使用进程级静态缓存，列表预热与单条生成共用。
 *
 * DouPHP 路由命名约定（与 $intent['kind'] 对应）：
 *   list      模块根路径（{module}）
 *   detail    内容详情（page / 栏目 / 单表模块统一 kind=detail，由 id 驱动）
 *   category  栏目分类（{base}.category，category_id 驱动；空为分类根）
 *   class     class 筛选页（{module}/class/{class}）
 *   action2   两段路由 {module}/{seg2}（如 user/login）
 *   action3   三段路由 {module}/{seg2}/{seg3}（如 user/contact/add）
 *
 * 挂载：作为 {@see Route} 的内部实现，由 Route Facade 统一对外暴露短名方法。
 * 进程级静态缓存（字段 / slug）跨实例保留，供列表预热与逐条构建共用。
 */
class UrlBuilder
{
    /** @var \Dou\Core\Infra\Database\Connection */
    private $db;

    /** @var array<string, array> URL 字段缓存：slug / created_at / category_id 等（进程级） */
    private static $urlFieldCache = array();

    /** @var array<string, array> 分类 slug 缓存（进程级） */
    private static $urlSlugCache = array();

    public function __construct()
    {
        $this->db = DB::getFacadeRoot();
    }

    /**
     * 由声明式条目（RouteManifest 具名 entry）的 pattern + 具名值生成完整站点 URL。
     *
     * 与 {@see buildFullUrl} 同样经过 ROOT_URL / 多语前缀 / 分页 /oN / 关闭伪静态时的 index.php?route= 包装；
     * 区别仅在于路径片段不经 buildPrettyPath（kind 分发）而是 PrettyUrlCompiler::fill 直接填入。
     * 小程序场景由调用方（UserCenterNavBuilder 等）走 {@see rewriteUrlMiniprogram}，本方法仅用于浏览器端。
     *
     * @param string $pattern PrettyUrlCompiler 迷你语言的 URL 模板
     * @param array $values 具名值（pattern 占位符 → 字符串）
     * @param string|int $page 分页页码
     * @return string 空字符串表示无法生成有效 URL（如 pattern 为空）
     */
    public function buildFullUrlFromPattern($pattern, array $values = array(), $page = '')
    {
        $pattern = (string) $pattern;
        if ($pattern === '') {
            return '';
        }

        $rewriteMode = Config::get('site.rewrite', false);
        $path = PrettyUrlCompiler::fill($pattern, $values);
        if ($path === '') {
            return '';
        }

        if ($page !== '' && $page !== 0 && $page !== '0') {
            $path .= '/o' . $page;
            $page = '';
        }

        if (!$rewriteMode) {
            $path = 'index.php?route=' . $path;
        }

        return $this->composeFullUrl($path, $rewriteMode, $page);
    }

    /**
     * 生成完整站点 URL（仅由 {@see UrlGenerator::url} 调用）。
     *
     * @param array $intent 由 Route 解析得到的结构化意图，字段含义见类头注释
     * @param string|int $page 分页页码。开启伪静态时，在路径末尾嵌入 /oN 后传入 composeFullUrl() 的 $page 会置空；
     *                         关闭伪静态等场景仍由此参数经 composeFullUrl() → applyPagination() 追加。
     * @return string 空字符串表示无法生成有效 URL
     */
    public function buildFullUrl(array $intent, $page = '')
    {
        if (defined('IS_MINIPROGRAM') && IS_MINIPROGRAM) {
            return $this->buildMiniProgramUrl($intent);
        }

        $rewriteMode = Config::get('site.rewrite', false);

        $path = $this->buildPrettyPath($intent);
        if ($path === '') {
            return '';
        }

        // 分页以 /oN 段嵌入路径（与 config/route.php 模板里 [/o{page:\d*}] 一致），
        // 嵌入后 $page 置空避免 composeFullUrl 二次拼接
        if ($page !== '' && $page !== 0 && $page !== '0') {
            $path .= '/o' . $page;
            $page = '';
        }

        // 关闭伪静态时，仅替换前缀为 index.php?route=，路径模板与开启伪静态完全一致
        if (!$rewriteMode) {
            $path = 'index.php?route=' . $path;
        }

        return $this->composeFullUrl($path, $rewriteMode, $page);
    }

    /**
     * 小程序场景下，把结构化意图映射回 ($module, $value)，转交给 rewriteUrlMiniprogram 处理。
     *
     * 小程序路径与站点伪静态规则脱钩，仍按 module + value 形态生成 pages 路径。
     *
     * @param array $intent
     * @return string
     */
    private function buildMiniProgramUrl(array $intent)
    {
        $module = isset($intent['module']) ? (string) $intent['module'] : '';
        if ($module === '') {
            return '';
        }

        $kind = isset($intent['kind']) ? $intent['kind'] : '';
        switch ($kind) {
            case 'detail':
                if ($module === 'page' && isset($intent['slug']) && (string) $intent['slug'] !== '') {
                    return $this->rewriteUrlMiniprogram('page', $intent['slug']);
                }
                return $this->rewriteUrlMiniprogram($module, isset($intent['id']) ? $intent['id'] : '');
            case 'category':
                return $this->rewriteUrlMiniprogram($module . '_category', isset($intent['category_id']) ? $intent['category_id'] : '');
            case 'action2':
                return $this->rewriteUrlMiniprogram($module, isset($intent['seg2']) ? $intent['seg2'] : '');
            case 'action3':
                // 小程序无三段表达，退回主路径
                return $this->rewriteUrlMiniprogram($module, isset($intent['seg2']) ? $intent['seg2'] : '');
            case 'class':
            case 'list':
            default:
                return $this->rewriteUrlMiniprogram($module);
        }
    }

    /**
     * 生成小程序 pages 路径
     *
     * @param string $module 模块名
     * @param string|int $value ID / 动作段（纯字母 / 下划线）
     * @param bool $tabbar 是否 tabBar 页（true 用相对前缀 'pages/'，false 用绝对前缀 '/pages/'）
     * @return string
     */
    public function rewriteUrlMiniprogram($module, $value = '', $tabbar = false)
    {
        $valueStr = (string) $value;
        $prefix = $tabbar ? 'pages/' : '/pages/';

        // 动作段（纯字母 / 下划线）：pages/{module}/{action}
        if ($valueStr !== '' && preg_match('/^[A-Za-z_]+$/', $valueStr)) {
            return $prefix . $module . '/' . $valueStr;
        }

        // tabBar 无 value → pages/{module}/{module}
        if ($tabbar) {
            return $prefix . $module . '/' . $module;
        }

        $isCategoryModule = (strpos($module, 'category') !== false);

        // 分类模块：带 category_id 或回到分类根
        if ($isCategoryModule) {
            if ($value || $value === '0') {
                return '/pages/' . $module . '/' . $module . '?category_id=' . $value;
            }
            return '/pages/' . $module . '/' . $module;
        }

        // 非分类模块 + 有 value → 详情页
        if ($value) {
            $columns = $this->modulesColumns();
            if (in_array($module, $columns, true) || $module === 'page') {
                return '/pages/' . $module . '/' . $module . '?id=' . $value;
            }
            return '/pages/' . $module . '/show?id=' . $value;
        }

        return '/pages/' . $module . '/' . $module;
    }

    /**
     * 批量预热 URL 字段缓存（列表页调用，减少详情 URL 逐条查库）
     *
     * @param string $module 内容模块名
     * @param array $rows 列表行（含 id，可选 category_id）
     * @return void
     */
    public function warmupUrlCache($module, $rows)
    {
        if (empty($rows)) {
            return;
        }

        $pattern = RouteRules::getColumnDetailPattern();
        if ($pattern === '') {
            return;
        }

        $ids = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));
        if (empty($ids)) {
            return;
        }

        if ($this->hasPlaceholder($pattern, 'slug') && $this->db->fieldExist($module, 'slug')) {
            $this->warmupFieldCache($module . '_slug', $module, 'slug', $ids, '');
        }

        $needTime = $this->hasPlaceholder($pattern, 'year') || $this->hasPlaceholder($pattern, 'month');
        if ($needTime && $this->db->fieldExist($module, 'created_at')) {
            $this->warmupFieldCache($module . '_add_time', $module, 'created_at', $ids, 0);
        }

        if ($this->hasPlaceholder($pattern, 'category_slug') && $this->db->fieldExist($module, 'category_id')) {
            $this->warmupCatIdCache($module, $rows, $ids);
            $this->warmupCategorySlugCache($module);
        }
    }

    /**
     * 获取模块 / 分类的 slug 路径片段（传统路由场景使用）
     *
     * @param string $module 模块名（可带 _category 后缀）
     * @param string|int $id 内容 ID 或分类 ID
     * @param string $mode 'full' 返回含模块前缀的片段；其它值返回 slug 或模块回退值
     * @return string
     */
    public function getSlugPath($module, $id, $mode = 'full')
    {
        $field = 'id';
        $tableModule = $module;
        $isCategoryModule = (strpos($module, '_category') !== false);

        // 非分类且非 page：根据内容 ID 反查 category_id，再查分类表别名
        if (!$isCategoryModule && $module !== 'page') {
            if ($id && $this->db->fieldExist($module, 'category_id')) {
                $id = $this->db->table($module)->where('id', intval($id))->value('category_id');
            }
            $tableModule = $module . '_category';
        }

        $slug = '';
        if ($id && $this->db->tableExist($tableModule) && $this->db->fieldExist($tableModule, 'slug')) {
            $slug = (string) $this->db->table($tableModule)->where($field, intval($id))->value('slug');
        }

        $moduleBase = Naming::baseModule($module);

        if ($mode === 'full') {
            if ($moduleBase === 'page') {
                return $slug;
            }
            if ($moduleBase === 'article') {
                return 'news' . ($slug ? '/' . $slug : '');
            }
            // 短地址模块下，模块名段被省略：列表/详情的路径片段就是 slug（无 slug 时为空段）
            if (ShortUrlPolicy::isShort($moduleBase)) {
                return $slug;
            }
            return $moduleBase . ($slug ? '/' . $slug : '');
        }

        return $slug ? $slug : Naming::urlModule($moduleBase);
    }

    /**
     * 按规则生成伪静态路径（不含 ROOT_URL / 语言前缀）
     *
     * 严格按 $intent['kind'] 分发：
     *   list      模块根（page / 栏目走对应根模板，其它模块即 module）
     *   detail    内容详情（page → buildPagePath；single → module/id；column → buildColumnDetailPath）
     *   category  栏目分类（仅栏目模块；非栏目时降级为 module）
     *   class     module/class/{class}
     *   action2   栏目模块拼上根路径，其它模块直接 module/seg2
     *   action3   同上，再追加 /seg3
     *
     * @param array $intent
     * @return string
     */
    private function buildPrettyPath(array $intent)
    {
        $module = (string) $intent['module'];
        if ($module === '') {
            return '';
        }

        $rules = $this->loadRouteRules();
        $modules = $this->modules();
        $singles = isset($modules['single_module']) ? (array) $modules['single_module'] : array();
        $columns = isset($modules['column_module']) ? (array) $modules['column_module'] : array();

        $kind = $intent['kind'];

        if ($kind === 'list') {
            return $this->buildPrettyListPath($rules, $module, $columns);
        }

        if ($kind === 'detail') {
            return $this->buildPrettyDetailPath($rules, $intent, $singles, $columns);
        }

        if ($kind === 'category') {
            $catId = isset($intent['category_id']) ? $intent['category_id'] : '';
            return $this->buildPrettyCategoryPath($rules, $module, $catId, $columns);
        }

        if ($kind === 'class') {
            $class = isset($intent['class']) ? $intent['class'] : '';
            if ($class === '' || $class === null) {
                return '';
            }
            return $module . '/class/' . $class;
        }

        if ($kind === 'action2') {
            return $this->buildPrettyActionPath($rules, $module, (string) $intent['seg2'], '', $columns);
        }

        if ($kind === 'action3') {
            return $this->buildPrettyActionPath($rules, $module, (string) $intent['seg2'], (string) $intent['seg3'], $columns);
        }

        return '';
    }

    /**
     * list 形态：模块根路径
     *
     * @param array $rules
     * @param string $module
     * @param array $columns
     * @return string
     */
    private function buildPrettyListPath(array $rules, $module, array $columns)
    {
        if ($module === 'page') {
            return $this->buildPagePath($rules, '');
        }
        if (in_array($module, $columns, true)) {
            $urlModule = Naming::urlModule($module);
            return $this->buildColumnCategoryPath($rules, $urlModule, $module, '');
        }
        return $module;
    }

    /**
     * detail 形态：内容详情
     *
     * @param array $rules
     * @param array $intent
     * @param array $singles
     * @param array $columns
     * @return string
     */
    private function buildPrettyDetailPath(array $rules, array $intent, array $singles, array $columns)
    {
        $module = (string) $intent['module'];

        if ($module === 'page') {
            if (isset($intent['slug']) && (string) $intent['slug'] !== '') {
                return $this->buildPagePath($rules, $intent['slug'], true);
            }
            $id = isset($intent['id']) ? $intent['id'] : '';
            return $this->buildPagePath($rules, $id, false);
        }

        $id = isset($intent['id']) ? $intent['id'] : '';
        $hasId = ($id !== '' && $id !== null && $id !== 0 && $id !== '0');

        if ($hasId && in_array($module, $singles, true)) {
            return $module . '/' . $id;
        }

        if (in_array($module, $columns, true)) {
            $urlModule = Naming::urlModule($module);
            if (!$hasId) {
                return $this->buildColumnCategoryPath($rules, $urlModule, $module, '');
            }
            return $this->buildColumnDetailPath($rules, $urlModule, $module, $id);
        }

        return $hasId ? $module . '/' . $id : $module;
    }

    /**
     * category 形态：分类页（仅栏目模块）
     *
     * @param array $rules
     * @param string $module
     * @param string|int $catId
     * @param array $columns
     * @return string
     */
    private function buildPrettyCategoryPath(array $rules, $module, $catId, array $columns)
    {
        if (in_array($module, $columns, true)) {
            $urlModule = Naming::urlModule($module);
            return $this->buildColumnCategoryPath($rules, $urlModule, $module, $catId);
        }
        return $module;
    }

    /**
     * action2 / action3 形态：动作路径
     *
     * 栏目模块下，先取栏目根路径再追加；其它模块直接以模块名为根。
     *
     * @param array $rules
     * @param string $module
     * @param string $seg2
     * @param string $seg3
     * @param array $columns
     * @return string
     */
    private function buildPrettyActionPath(array $rules, $module, $seg2, $seg3, array $columns)
    {
        if (in_array($module, $columns, true)) {
            $urlModule = Naming::urlModule($module);
            $root = $this->buildColumnCategoryPath($rules, $urlModule, $module, '');
            if ($root === '') {
                return '';
            }
            return $this->appendSubRoute($root, $seg2, $seg3);
        }
        return $this->appendSubRoute($module, $seg2, $seg3);
    }

    /**
     * 按 DouPHP 约定拼接第 2 段 / 第 3 段路径
     *
     * 约定：
     *   $controller === '' → 不追加
     *   $action     === '' → 仅追加 /controller（2 段路由：controller 即动作）
     *   $action     !== '' → 追加 /controller/action（3 段路由）
     *
     * @param string $base 已构建的主路径（如 'product' / 'news/ceo'）
     * @param string $controller URL 第 2 段
     * @param string $action URL 第 3 段
     * @return string
     */
    private function appendSubRoute($base, $controller, $action)
    {
        if ($controller === '') {
            return $base;
        }
        if ($action === '') {
            return $base . '/' . $controller;
        }
        return $base . '/' . $controller . '/' . $action;
    }

    /**
     * 生成 page 单页伪静态路径
     *
     * @param array $rules
     * @param string|int $id 数字 id，或当 $slugLiteral 为 true 时为 slug 字面量
     * @param bool $slugLiteral 为 true 时表示 $id 参数已是 slug，不再按主键查表
     * @return string
     */
    private function buildPagePath(array $rules, $id, $slugLiteral = false)
    {
        $pageRules = isset($rules['page']) ? $rules['page'] : array();

        if ($slugLiteral) {
            $slugStr = (string) $id;
            if (empty($pageRules)) {
                return $slugStr . '.html';
            }
            $pattern = $pageRules[0]['pattern'];
            $values = array('id' => $slugStr, 'slug' => $slugStr);
            return PrettyUrlCompiler::fill($pattern, $values);
        }

        if (empty($pageRules)) {
            return $this->getSlugPath('page', $id) . '.html';
        }

        $pattern = $pageRules[0]['pattern'];
        $values = array('id' => $id);

        if ($this->hasPlaceholder($pattern, 'slug')) {
            $slug = '';
            if ($id && $this->db->tableExist('page') && $this->db->fieldExist('page', 'slug')) {
                $slug = (string) $this->cachedField('page_slug', $id, 'page', 'slug');
            }
            $values['slug'] = $slug ? $slug : $id;
        }

        return PrettyUrlCompiler::fill($pattern, $values);
    }

    /**
     * 栏目分类页伪静态路径
     *
     * 规则按占位符特征分组：
     *   - alias 规则：含 {category_slug}   别名型，优先级最高
     *   - id    规则：含 {id} 且 pattern 含 'category'  数字 ID 型
     *   - root  规则：其余（模块根路径）
     *   归档型规则（含 {year}）不在此函数处理。
     *
     * @param array $rules
     * @param string $urlModule URL 展示用模块名（如 article → news）
     * @param string $baseModule 数据库模块名
     * @param string|int $catId
     * @return string
     */
    private function buildColumnCategoryPath(array $rules, $urlModule, $baseModule, $catId)
    {
        $columnRules = isset($rules['column']) ? $rules['column'] : array();

        // 短链模块：模块根路径被省略，分类页直接用别名（或 ID）作为整段路径
        if (ShortUrlPolicy::isShort($baseModule)) {
            if (!$catId) {
                return '';
            }
            $categorySlug = $this->cachedCategorySlug($baseModule . '_category', $catId);
            return $categorySlug ? $categorySlug : strval($catId);
        }

        if (empty($columnRules)) {
            return $this->getSlugPath($catId ? $baseModule . '_category' : $baseModule, $catId);
        }

        list($aliasRule, $idRule, $rootRule) = $this->classifyColumnCategoryRules($columnRules);

        if ($catId && $aliasRule) {
            $categorySlug = $this->cachedCategorySlug($baseModule . '_category', $catId);
            return PrettyUrlCompiler::fill($aliasRule['pattern'], array(
                'module' => $urlModule,
                'id' => $catId,
                'category_slug' => $categorySlug ? $categorySlug : $catId,
            ));
        }

        if ($catId && $idRule) {
            return PrettyUrlCompiler::fill($idRule['pattern'], array(
                'module' => $urlModule,
                'id' => $catId,
            ));
        }

        if ($rootRule) {
            return PrettyUrlCompiler::fill($rootRule['pattern'], array('module' => $urlModule));
        }

        return $urlModule;
    }

    /**
     * 按占位符特征把 column 分类规则分组
     *
     * @param array $columnRules
     * @return array [$aliasRule, $idRule, $rootRule]  三者均可能为 null
     */
    private function classifyColumnCategoryRules(array $columnRules)
    {
        $alias = $id = $root = null;

        foreach ($columnRules as $rule) {
            if (!isset($rule['target'])) {
                continue;
            }
            $p = $rule['pattern'];
            if ($this->hasPlaceholder($p, 'category_slug')) {
                $alias = $rule;
            } elseif ($this->hasPlaceholder($p, 'year')) {
                // 归档型规则不用于分类路径构建
            } elseif ($this->hasPlaceholder($p, 'id') && strpos($p, 'category') !== false) {
                $id = $rule;
            } else {
                $root = $rule;
            }
        }

        return array($alias, $id, $root);
    }

    /**
     * 栏目内容详情页伪静态路径
     *
     * 详情规则即 column 规则里第一条无 target 的规则；按其 pattern 中的占位符填值。
     *
     * @param array $rules
     * @param string $urlModule
     * @param string $baseModule
     * @param string|int $id
     * @return string
     */
    private function buildColumnDetailPath(array $rules, $urlModule, $baseModule, $id)
    {
        $columnRules = isset($rules['column']) ? $rules['column'] : array();

        if (empty($columnRules)) {
            return $this->getSlugPath($baseModule, $id) . '/' . $id . '.html';
        }

        $detailRule = null;
        foreach ($columnRules as $rule) {
            if (!isset($rule['target'])) {
                $detailRule = $rule;
                break;
            }
        }

        if (!$detailRule) {
            return ShortUrlPolicy::stripModulePrefix($urlModule . '/' . $id, $urlModule, $baseModule);
        }

        $pattern = $detailRule['pattern'];
        $values = array('module' => $urlModule, 'id' => $id);

        if ($this->hasPlaceholder($pattern, 'category_slug')) {
            $catId = '';
            if ($id && $this->db->fieldExist($baseModule, 'category_id')) {
                $catId = $this->cachedField($baseModule . '_category_id', $id, $baseModule, 'category_id');
            }
            $categorySlug = $catId ? $this->cachedCategorySlug($baseModule . '_category', $catId) : '';
            $values['category_slug'] = $categorySlug ? $categorySlug : ($catId ? $catId : '');
        }

        if ($this->hasPlaceholder($pattern, 'slug')) {
            $slug = '';
            if ($id && $this->db->fieldExist($baseModule, 'slug')) {
                $slug = trim((string) $this->cachedField($baseModule . '_slug', $id, $baseModule, 'slug'));
            }
            if ($slug === '') {
                return '';
            }
            $values['slug'] = $slug;
        }

        if ($this->hasPlaceholder($pattern, 'year') || $this->hasPlaceholder($pattern, 'month')) {
            $addTime = 0;
            if ($id && $this->db->fieldExist($baseModule, 'created_at')) {
                $addTime = (int) $this->cachedField($baseModule . '_add_time', $id, $baseModule, 'created_at');
            }
            $ts = $addTime ? $addTime : time();
            $values['year'] = date('Y', $ts);
            $values['month'] = date('m', $ts);
        }

        $path = PrettyUrlCompiler::fill($pattern, $values);

        return ShortUrlPolicy::stripModulePrefix($path, $urlModule, $baseModule);
    }

    /**
     * 将路径片段拼装为带 ROOT_URL、语言前缀、分页的完整 URL
     *
     * 若路径中已由 buildFullUrl() 嵌入分页段（开启伪静态的 /oN），$page 为空则不再追加分页。
     *
     * @param string $path
     * @param bool $rewriteMode
     * @param string|int $page 非空时交给 applyPagination()（典型：关闭伪静态、或未在 buildFullUrl() 内嵌分页的场景）
     * @return string
     */
    private function composeFullUrl($path, $rewriteMode, $page)
    {
        $url = $this->applyLanguagePrefix($path);
        if ($page) {
            $url = $this->applyPagination($url, $page, $rewriteMode);
        }
        return $url;
    }

    /**
     * 追加 ROOT_URL 及语言前缀
     *
     * @param string $path
     * @return string
     */
    private function applyLanguagePrefix($path)
    {
        $curLang = $this->currentLangState();
        if (empty($curLang)) {
            return ROOT_URL . $path;
        }
        if (isset($curLang['mode']) && $curLang['mode'] === 'rewrite_open') {
            return ROOT_URL . $curLang['sign'] . '/' . $path;
        }
        return Util::normalizeQueryString(ROOT_URL . $path . '&lang=' . $curLang['pack']);
    }

    /**
     * 追加分页段
     *
     * 开启伪静态时追加 /oN；否则经 Util::normalizeQueryString 追加 &page=N。
     * 列表/详情若在 buildFullUrl() 内已写入 /oN，通常不会再以非空 $page 调用此处。
     *
     * @param string $url
     * @param string|int $page
     * @param bool $rewriteMode
     * @return string
     */
    private function applyPagination($url, $page, $rewriteMode)
    {
        if ($rewriteMode) {
            return $url . '/o' . $page;
        }
        return Util::normalizeQueryString($url . '&page=' . $page);
    }

    /**
     * 加载并缓存当前路由清单的 page / column / simple meta 规则分组。
     *
     * 取自 RouteManifest 单一数据源；入站匹配器（PrettyRouteMatcher）与本出站构建器
     * 读同一份 manifest，保证双向语义一致。
     *
     * @return array
     */
    private function loadRouteRules()
    {
        return RouteManifest::getRuleGroups();
    }

    /**
     * 检测 pattern 是否含指定占位符（兼容 {name} 和 {name:regex} 两种语法）
     *
     * @param string $pattern
     * @param string $name
     * @return bool
     */
    private function hasPlaceholder($pattern, $name)
    {
        return strpos($pattern, '{' . $name) !== false;
    }

    /**
     * 批量查询并回填字段缓存（slug / created_at 等）
     *
     * @param string $cacheKey
     * @param string $table
     * @param string $field
     * @param int[] $ids
     * @param mixed $defaultOnMiss 未命中时的填充值
     * @return void
     */
    private function warmupFieldCache($cacheKey, $table, $field, array $ids, $defaultOnMiss)
    {
        $cached = isset(self::$urlFieldCache[$cacheKey]) ? self::$urlFieldCache[$cacheKey] : array();
        $miss = array_values(array_diff($ids, array_keys($cached)));
        if (empty($miss)) {
            return;
        }

        $rows = $this->db->table($table)
            ->field('id, ' . $field)
            ->where('id', 'IN', $miss)
            ->select();

        foreach ((array) $rows as $r) {
            self::$urlFieldCache[$cacheKey][$r['id']] = $r[$field];
        }
        foreach ($miss as $mid) {
            if (!isset(self::$urlFieldCache[$cacheKey][$mid])) {
                self::$urlFieldCache[$cacheKey][$mid] = $defaultOnMiss;
            }
        }
    }

    /**
     * 预热内容表 → category_id 的缓存
     *
     * 若列表行自带 category_id 字段，优先直接回填；否则才查库。
     *
     * @param string $module
     * @param array $rows
     * @param int[] $ids
     * @return void
     */
    private function warmupCatIdCache($module, array $rows, array $ids)
    {
        $cacheKey = $module . '_category_id';
        $cached = isset(self::$urlFieldCache[$cacheKey]) ? self::$urlFieldCache[$cacheKey] : array();
        $miss = array_values(array_diff($ids, array_keys($cached)));
        if (empty($miss)) {
            return;
        }

        if (isset($rows[0]['category_id'])) {
            foreach ($rows as $r) {
                self::$urlFieldCache[$cacheKey][$r['id']] = $r['category_id'];
            }
            return;
        }

        $catRows = $this->db->table($module)
            ->field('id, category_id')
            ->where('id', 'IN', $miss)
            ->select();

        foreach ((array) $catRows as $r) {
            self::$urlFieldCache[$cacheKey][$r['id']] = $r['category_id'];
        }
        foreach ($miss as $mid) {
            if (!isset(self::$urlFieldCache[$cacheKey][$mid])) {
                self::$urlFieldCache[$cacheKey][$mid] = 0;
            }
        }
    }

    /**
     * 预热分类 category_id → slug 缓存
     *
     * @param string $module
     * @return void
     */
    private function warmupCategorySlugCache($module)
    {
        $catTable = $module . '_category';
        if (!$this->db->tableExist($catTable) || !$this->db->fieldExist($catTable, 'slug')) {
            return;
        }

        $catKey = $module . '_category_id';
        $catIds = array_unique(array_filter(array_values(
            isset(self::$urlFieldCache[$catKey]) ? self::$urlFieldCache[$catKey] : array()
        )));
        if (empty($catIds)) {
            return;
        }

        $cachedCats = isset(self::$urlSlugCache[$catTable]) ? self::$urlSlugCache[$catTable] : array();
        $missCats = array_values(array_diff($catIds, array_keys($cachedCats)));
        if (empty($missCats)) {
            return;
        }

        $uidRows = $this->db->table($catTable)
            ->field('id, slug')
            ->where('id', 'IN', $missCats)
            ->select();

        foreach ((array) $uidRows as $r) {
            self::$urlSlugCache[$catTable][$r['id']] = $r['slug'];
        }
        foreach ($missCats as $cid) {
            if (!isset(self::$urlSlugCache[$catTable][$cid])) {
                self::$urlSlugCache[$catTable][$cid] = '';
            }
        }
    }

    /**
     * 从字段缓存读取单值；miss 时查库并回填（空值也回填，避免重复查询）
     *
     * @param string $cacheKey 一级键（如 'article_slug'）
     * @param int $id 行 ID
     * @param string $table 表名
     * @param string $field 取值字段
     * @param string $where WHERE 字段（默认 id）
     * @return mixed
     */
    private function cachedField($cacheKey, $id, $table, $field, $where = 'id')
    {
        if (!isset(self::$urlFieldCache[$cacheKey][$id])) {
            self::$urlFieldCache[$cacheKey][$id] = $this->db->table($table)
                ->where($where, intval($id))
                ->value($field);
        }
        return self::$urlFieldCache[$cacheKey][$id];
    }

    /**
     * 从 slug 缓存读取分类别名；miss 时查库并回填（含表 / 字段存在判断）
     *
     * @param string $table 分类表（如 article_category）
     * @param int $catId
     * @return string
     */
    private function cachedCategorySlug($table, $catId)
    {
        if (!isset(self::$urlSlugCache[$table][$catId])) {
            if ($this->db->tableExist($table) && $this->db->fieldExist($table, 'slug')) {
                self::$urlSlugCache[$table][$catId] = (string) $this->db->table($table)
                    ->where('id', intval($catId))
                    ->value('slug');
            } else {
                self::$urlSlugCache[$table][$catId] = '';
            }
        }
        return (string) self::$urlSlugCache[$table][$catId];
    }

    /**
     * 全站业务设置（模块列表、单页 / 栏目模块名等）。
     *
     * @return array Config::get('module')
     */
    private function modules()
    {
        return Config::get('module');
    }

    /**
     * 栏目型模块名列表。
     *
     * @return array module.column_module 数组
     */
    private function modulesColumns()
    {
        $m = $this->modules();
        return isset($m['column_module']) ? (array) $m['column_module'] : array();
    }

    /**
     * 当前请求语言上下文（来自 Locale 单例）。
     *
     * @return array 可能含 mode、sign、pack；无多语言或未设置时为空数组
     */
    private function currentLangState()
    {
        return locale()->toArray();
    }
}

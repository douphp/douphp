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

namespace Dou\Admin\Service\Data;

use Dou\Admin\Model\Data\Data;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 模板数据扫描器：扫描当前主题模板文件中所有 `{$data.<code>.<field>}` 调用，
 * 解析其中嵌入的 `|default:""` / `|parent:""` / `|group:""` / `|item:""` 修饰器，
 * 把缺失的 code 自动补建到 `dou_data` 表。
 *
 * 设计要点：
 * - 模板引擎对未注册修饰器（parent/group/item）会原样返回值不报错，故这三个修饰器
 *   仅供本扫描器解析关联关系，不影响前台渲染（见 RenderContext::filter()）。
 * - 已存在的 code 一律跳过（包括 is_locked=1），仅补缺失项，绝不覆盖用户已编辑内容。
 * - 通过同步生成的数据 is_locked 强制为 1：避免后续再次同步时被误覆盖，同时提示
 *   用户这是「自动生成」条目，需手动解锁后才能改 code / 删除。
 * - 同 code 多次声明同字段 default 时，以最后一次为准（模板从上到下、文件按枚举顺序）。
 * - _group 默认值以 code 首次出现的文件名为准（index.dwt → index，其它 → common）；
 *   模板内 |group:"x" 可显式覆盖。
 *
 * 模板语法示例：
 *   {$data.about.name|default:"关于我们"}
 *   {$data.about.text|default:"公司简介..."|group:"index"}
 *   {$data.partner.name|parent:"partner_group"|group:"common"|item:""}
 */
class TemplateDataScanner extends BaseService
{
    /**
     * 扫描时识别的字段名 → 落库列名映射。
     *
     * 模板里 `{$data.<code>.<field>}` 的 field 段会被解析成对应的列。
     * 未在此映射里的 field 会被忽略（如 `id`、`content`、`is_class` 等运行期合成字段）。
     *
     * @var array
     */
    private static $fieldMap = array(
        'name'  => 'name',
        'text'  => 'text',
        'image' => 'image',
        'link'  => 'link',
    );

    /**
     * 扫描当前主题模板，把缺失的 data 行自动补建。
     *
     * 入口由 SiteHomeController::sync() 调用，要求后台鉴权上下文（写 admin log）。
     *
     * 返回数组结构：
     *   - scanned:    扫描到的 code 总数（去重后）
     *   - inserted:   实际新建的条数
     *   - skipped:    已存在被跳过的条数
     *   - items:      新建明细 array(array('code'=>, 'name'=>, 'group'=>), ...)
     *
     * @return array
     */
    public function syncCurrentTheme()
    {
        $theme = (string) Config::get('site.site_theme', '');
        // ROOT_PATH 已用 '/' 归一化（见 core/bootstrap.php），这里统一用 '/' 拼接
        $themeDir = ROOT_PATH . 'theme/' . $theme;
        if ($theme === '' || !is_dir($themeDir)) {
            return array('scanned' => 0, 'inserted' => 0, 'skipped' => 0, 'items' => array());
        }

        $declarations = $this->scanTheme($themeDir);
        $existingCodes = Data::findAllCodesByTheme($theme);

        // 解析 parent 继承 group：声明了 parent 的 code，group 强制继承父级 group，
        // 无论自身是否显式声明 group。父级可能在本次扫描结果里，也可能是已存在 DB 记录。
        $this->inheritGroupFromParent($declarations, $existingCodes, $theme);

        // 标记父级 is_class=1：被其它 code 通过 |parent:"xxx" 引用为父级的 code，
        // 自动标记为分组（is_class=1），无论父级是新建还是已存在 DB 记录。
        $this->markParentAsClass($declarations, $existingCodes, $theme);

        $inserted = 0;
        $items = array();
        foreach ($declarations as $code => $decl) {
            if (isset($existingCodes[$code])) {
                continue;
            }

            $row = $this->buildRow($code, $decl, $theme);
            $newId = (int) Data::insertData($row);
            if ($newId <= 0) {
                // insert 失败（异常已由 Model 层抛出，这里兜底防止计数错位）
                continue;
            }
            $inserted++;
            $items[] = array(
                'code'  => $code,
                'name'  => $row['name'],
                'group' => $row['data_group'],
            );
            audit()->writeAdminLog(
                (int) auth('admin')->id(),
                AdminLogAction::CREATE,
                1,
                '[sync] ' . (string) $row['name'] . ' (' . $code . ')'
            );
        }

        return array(
            'scanned'  => count($declarations),
            'inserted' => $inserted,
            'skipped'  => count($declarations) - $inserted,
            'items'    => $items,
        );
    }

    /**
     * 扫描主题目录下所有顶层 .dwt 与 inc/*.tpl 文件，解析 data 调用声明。
     *
     * 扫描范围严格限定：仅 `theme/<theme>/*.dwt` 与 `theme/<theme>/inc/*.tpl`，
     * 不递归其它子目录（用户决策，避免越权建数据）。
     *
     * @param string $themeDir 主题目录绝对路径（已 is_dir 校验）
     * @return array code => array(field => value, '_group'=>, '_parent'=>, '_item'=>)
     */
    private function scanTheme($themeDir)
    {
        $files = array();

        // 顶层 *.dwt（glob 在无匹配时返回空数组，出错才返回 false，(array) 强转兜底）
        foreach ((array) glob($themeDir . '/*.dwt') as $f) {
            $files[] = $f;
        }
        // inc/*.tpl
        foreach ((array) glob($themeDir . '/inc/*.tpl') as $f) {
            $files[] = $f;
        }

        $declarations = array();
        foreach ($files as $file) {
            $basename = basename($file);
            $defaultGroup = ($basename === 'index.dwt') ? 'index' : 'common';
            $content = $this->readFile($file);
            if ($content === '') {
                continue;
            }
            $this->parseDeclarations($content, $defaultGroup, $declarations);
        }

        return $declarations;
    }

    /**
     * 解析 parent 继承 group：声明了 _parent 的 code，group 强制继承父级 group。
     *
     * 规则（用户要求）：
     * - 只要声明了 parent，group **必须**等于父级 group，忽略自身显式声明的 group。
     * - 父级 group 来源优先级：①本次扫描结果里的父级 → ②DB 已存在的父级记录 → ③都找不到则保持自身 group。
     * - 支持多级继承（A→B→C）：多趟扫描直到稳定，避免顺序依赖。
     * - 循环引用安全：值不变即停止，不会死循环。
     *
     * @param array  $declarations  引用，会被修改 _group 字段
     * @param array  $existingCodes 已存在 code 集合（code => id），用于判断父级是否在 DB 里
     * @param string $theme         当前主题
     * @return void
     */
    private function inheritGroupFromParent(array &$declarations, array $existingCodes, $theme)
    {
        if (empty($declarations)) {
            return;
        }

        // 第一阶段：解析扫描结果内的继承（多级继承，多趟扫描直到稳定）
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach ($declarations as $code => &$decl) {
                if ($decl['_parent'] === '') {
                    continue;
                }
                $parentCode = $decl['_parent'];
                if (isset($declarations[$parentCode])) {
                    $parentGroup = $declarations[$parentCode]['_group'];
                    if ($decl['_group'] !== $parentGroup) {
                        $decl['_group'] = $parentGroup;
                        $changed = true;
                    }
                }
            }
            unset($decl); // 解除引用，避免后续 foreach 意外修改
        }

        // 第二阶段：父级不在扫描结果里但已存在于 DB 的，批量查 DB 获取父级 group
        $needDbLookup = array();
        foreach ($declarations as $decl) {
            if ($decl['_parent'] === '') {
                continue;
            }
            $parentCode = $decl['_parent'];
            // 父级既不在扫描结果里，又是已存在的 DB 记录 → 需要查 DB
            if (!isset($declarations[$parentCode]) && isset($existingCodes[$parentCode])) {
                $needDbLookup[$parentCode] = true;
            }
        }

        if (empty($needDbLookup)) {
            return;
        }

        $parentGroups = Data::findGroupsByCodesAndTheme(array_keys($needDbLookup), $theme);
        if (empty($parentGroups)) {
            return;
        }

        foreach ($declarations as &$decl) {
            if ($decl['_parent'] === '') {
                continue;
            }
            $parentCode = $decl['_parent'];
            if (isset($parentGroups[$parentCode]) && $parentGroups[$parentCode] !== '') {
                $decl['_group'] = $parentGroups[$parentCode];
            }
        }
        unset($decl);
    }

    /**
     * 标记父级 is_class=1：被其它 code 通过 |parent:"xxx" 引用为父级的 code，
     * 自动标记为分组（is_class=1）。
     *
     * 业务语义：dou_data 表 is_class=1 表示该条记录是「分组/容器」，下面有子项。
     * 当模板里出现 {$data.child.name|parent:"xxx"} 时，说明 xxx 是分组，应标 is_class=1。
     *
     * 两阶段处理：
     * - 父级在本次扫描结果里（新建）→ 标记 _is_class=1，buildRow 落库时写入
     * - 父级是已存在 DB 记录但 is_class=0 → 批量 UPDATE 为 1
     * - 父级既不在扫描结果也不在 DB → 忽略（无效引用，可能 code 写错）
     *
     * 注意：只做「正向标记」（有子项引用就标 1），不做反向清除。
     * 如果用户删除了所有引用该 code 为父级的模板调用，该 code 的 is_class 不会自动改回 0，
     * 需用户在后台手动调整。这是有意为之，避免误清用户手动设置的分组标记。
     *
     * @param array  $declarations  引用，会被修改（新增 _is_class 字段）
     * @param array  $existingCodes 已存在 code 集合（code => id）
     * @param string $theme         当前主题
     * @return void
     */
    private function markParentAsClass(array &$declarations, array $existingCodes, $theme)
    {
        if (empty($declarations)) {
            return;
        }

        // 收集所有被引用为父级的 code（去重）
        $parentCodes = array();
        foreach ($declarations as $decl) {
            if ($decl['_parent'] !== '') {
                $parentCodes[$decl['_parent']] = true;
            }
        }

        if (empty($parentCodes)) {
            return;
        }

        // 第一阶段：父级在本次扫描结果里 → 标记 _is_class=1（buildRow 落库时写入）
        $needDbUpdate = array();
        foreach ($parentCodes as $parentCode => $_) {
            if (isset($declarations[$parentCode])) {
                $declarations[$parentCode]['_is_class'] = 1;
            } elseif (isset($existingCodes[$parentCode])) {
                // 父级是已存在 DB 记录，但不在本次扫描结果里 → 需要 UPDATE
                $needDbUpdate[$parentCode] = true;
            }
            // 既不在扫描结果也不在 DB → 忽略（无效引用）
        }

        if (empty($needDbUpdate)) {
            return;
        }

        // 第二阶段：批量 UPDATE 已存在父级记录的 is_class=1
        Data::markAsClassByCodes(array_keys($needDbUpdate), $theme);
    }

    /**
     * 解析单个模板文件内容中的所有 `{$data.<code>.<field>[|mod:"arg"]*}` 调用。
     *
     * 同 code 多次声明：合并字段；同字段 default 冲突时以最后一次为准（覆盖）。
     * 同次调用内同修饰器重复：以最后一次为准（与上一规则一致）。
     *
     * @param string $content       模板文件内容
     * @param string $defaultGroup  当前文件推导出的默认 data_group
     * @param array  $declarations  引用回填，结构 code => array(...)
     * @return void
     */
    private function parseDeclarations($content, $defaultGroup, array &$declarations)
    {
        // 匹配 {$data.<code>.<field> ... }，定界符内允许任意修饰器链
        // code/field 限定为 [a-z0-9_]，与 createCode 生成规则对齐
        $pattern = '/\{\$data\.([a-z0-9_]+)\.([a-z0-9_]+)([^}]*)\}/i';
        if (!preg_match_all($pattern, $content, $matches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($matches as $m) {
            $code = $m[1];
            $field = strtolower($m[2]);
            $modifierChain = $m[3];

            if (!isset(self::$fieldMap[$field])) {
                // 非落库字段（如 id/content/is_class），跳过
                continue;
            }

            if (!isset($declarations[$code])) {
                // _group 仅在首次出现时按文件名设置；后续文件即使再次扫到该 code，
                // 也不重置 _group（保持"首次出现为准"语义，避免被后续文件意外覆盖）。
                $declarations[$code] = array(
                    '_group'  => $defaultGroup,
                    '_parent' => '',
                    '_item'   => '',
                );
            }

            // 解析修饰器链：default / parent / group / item
            $this->applyModifiers($declarations[$code], $modifierChain, $field);
        }
    }

    /**
     * 解析修饰器链片段，把识别到的修饰器值写入声明数组。
     *
     * 识别的修饰器：
     *   - default:"xxx"  写入对应 field 列（name/text/image/link）
     *   - parent:"xxx"   写入 _parent（→ parent_code）
     *   - group:"xxx"    写入 _group（→ data_group，覆盖文件默认）
     *   - item:"xxx"     写入 _item（→ data_item）
     *
     * 未识别的修饰器（escape/truncate/nofilter 等）原样忽略，不影响解析。
     * 修饰器参数仅支持双引号或单引号包裹的字符串字面量。
     *
     * @param array  $decl         引用回填的声明数组
     * @param string $modifierChain 修饰器链原文（如 '|default:"关于我们"|group:"index"'）
     * @param string $field         当前调用的 field 名
     * @return void
     */
    private function applyModifiers(array &$decl, $modifierChain, $field)
    {
        // 拆分修饰器：每个修饰器形如 |name:"arg" 或 |name:'arg' 或 |name（无参）
        // 此处用正则一次性抓所有 (name, quoted_arg) 对；未带引号的裸参不识别（够用）
        $modPattern = '/\|([a-z_]+)\s*(?::\s*(?:"([^"]*)"|\'([^\']*)\'))?/i';
        if (!preg_match_all($modPattern, $modifierChain, $modMatches, PREG_SET_ORDER)) {
            return;
        }

        foreach ($modMatches as $mod) {
            $modName = strtolower($mod[1]);
            $modArg = isset($mod[2]) ? $mod[2] : (isset($mod[3]) ? $mod[3] : '');

            switch ($modName) {
                case 'default':
                    // 写入对应字段列（已通过 fieldMap 校验过）
                    $decl[self::$fieldMap[$field]] = $modArg;
                    break;
                case 'parent':
                    $decl['_parent'] = $modArg;
                    break;
                case 'group':
                    $decl['_group'] = $modArg;
                    break;
                case 'item':
                    $decl['_item'] = $modArg;
                    break;
                // 其他修饰器（escape/truncate/nofilter/...）忽略
            }
        }
    }

    /**
     * 把扫描出的声明数组组装成 dou_data 行。
     *
     * 默认值：
     *   - name 默认空（即使没声明 default，也建空值条目，便于后台可见可编辑）
     *   - text/image/link 默认空
     *   - sort=50
     *   - is_class：由 markParentAsClass() 标记。被其它 code 通过 |parent:"xxx" 引用为
     *     父级的 code，_is_class 会被设为 1（表示是分组/容器），否则默认 0。
     *   - is_locked=1：自动生成的条目默认锁定，避免后续再次同步时被误覆盖，
     *     同时提示用户这是「自动生成」条目，需手动解锁后才能改 code / 删除。
     *
     * @param string $code
     * @param array  $decl   扫描声明
     * @param string $theme  当前主题
     * @return array
     */
    private function buildRow($code, array $decl, $theme)
    {
        return array(
            'parent_code' => (string) $decl['_parent'],
            'theme'       => $theme,
            'data_group'  => (string) $decl['_group'],
            'data_item'   => (string) $decl['_item'],
            'name'        => isset($decl['name']) ? (string) $decl['name'] : '',
            'code'        => $code,
            'image'       => isset($decl['image']) ? (string) $decl['image'] : '',
            'text'        => isset($decl['text']) ? (string) $decl['text'] : '',
            'link'        => isset($decl['link']) ? (string) $decl['link'] : '',
            'is_class'    => isset($decl['_is_class']) ? (int) $decl['_is_class'] : 0,
            'is_locked'   => 1,
            'sort'        => 50,
        );
    }

    /**
     * 安全读文件（避免大文件内存溢出；模板文件本就小，简单读即可）。
     *
     * @param string $file
     * @return string
     */
    private function readFile($file)
    {
        $content = @file_get_contents($file);
        return $content === false ? '' : $content;
    }
}

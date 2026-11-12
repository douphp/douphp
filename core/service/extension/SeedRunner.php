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
 * Release Date: 2026-11-02
 */

namespace Dou\Core\Service\Extension;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Upgrade\CacheClearService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 扩展初始化（seed）执行器：模块与主题共用的单一实现。
 *
 * 物料目录（$materialsRoot）：
 *   - 模块：站点根 `_action/`（包根即站点根镜像，安装时落盘后整包删除）
 *   - 主题：`theme/<slug>/_action/`（装/升完成后整目录删除）
 *
 * 目录契约（引擎只认这几个固定位置，其它文件一律不执行、不拷贝）：
 *   resources/seed.json         安装期骨架数据（install / local，推荐；存在时忽略 install.sql）
 *   resources/sql/install.sql   旧版安装期骨架数据（仅 seed.json 不存在时执行）
 *   resources/sql/upgrade.sql   升级期增量数据（update，只允许补齐/新增）
 *   resources/images/**         素材根，与站点根 images/** 同构镜像（目标已存在则跳过）
 *
 * 可选文件：
 *   manifest.php   返回数组；可声明 seed 阶段开关与 requires 依赖：
 *                    'seed' => array('install' => bool, 'update' => bool, 'images' => bool)
 *                    'requires' => array('tables' => array('data'), 'modules' => array('data'))
 *   install.php    安装期 PHP 钩子（模块沿用既有的 install.php 契约）
 *   upgrade.php    升级期 PHP 钩子（模块沿用既有的 upgrade.php 契约）
 *
 * 执行顺序（固定）：manifest 校验 -> 依赖检查 -> images 镜像 -> sql -> 钩子 -> 清模板缓存。
 * 素材先于 SQL，保证 SQL 里登记 dou_file 的文件已经就位。
 *
 * 硬约定：
 *   - resources/sql/*.sql 禁止 DDL（CREATE/ALTER/DROP/TRUNCATE/RENAME）：结构归模块
 *     storage/backup/<m>.sql + SchemaSync 管理，且禁止 DDL 才能保证 seed 可整体事务回滚；
 *   - 主题 install.sql 只允许操作本主题作用域（dou_data WHERE theme='<slug>'），
 *     由包作者按标准头部写入，引擎不代写；
 *   - 升级期（update）的数据库改动不可回滚（主题还原点为文件级），只允许补齐/新增。
 *
 * 钩子注入变量（EXTR_SKIP，不会覆盖钩子自身已定义的变量）：
 *   $prefix $extensionType $extensionId $phase $mode
 *   $rootPath $materialsRoot $resourcesRoot $imagesRoot $seed $wrong
 *   另加调用方通过 $extraVars 传入的兼容变量（模块：$cloudId/$moduleType/$moduleMeta/$moduleId/$moduleVersion）。
 */
class SeedRunner
{
    /** 安装阶段（含本地安装 local） */
    const PHASE_INSTALL = 'install';

    /** 升级阶段 */
    const PHASE_UPDATE = 'update';

    /**
     * 禁止出现在 seed SQL 里的 DDL 关键字（语句首关键字匹配）。
     *
     * @var array
     */
    private static $ddlKeywords = array('CREATE', 'ALTER', 'DROP', 'TRUNCATE', 'RENAME');

    /** @var string 站点根目录（结尾带 /） */
    private $rootPath;

    /** @var string 物料根目录（结尾带 /，不存在时为空串） */
    private $materialsRoot;

    /** @var string module|theme */
    private $extensionType;

    /** @var string 扩展标识（模块标识 / 主题 slug） */
    private $extensionId;

    /**
     * 过程说明（跳过原因 / 素材写入条数），由调用方并入安装步骤日志。
     *
     * @var array
     */
    private $notes = array();

    /**
     * @param string $rootPath 站点根目录
     * @param string $materialsRoot 物料根目录（模块站点根 _action/，主题 theme/<slug>/_action/）
     * @param string $extensionType module|theme
     * @param string $extensionId 扩展标识
     */
    public function __construct($rootPath, $materialsRoot, $extensionType, $extensionId)
    {
        $this->rootPath = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';
        $materialsRoot = rtrim(str_replace('\\', '/', (string) $materialsRoot), '/');
        $this->materialsRoot = $materialsRoot === '' ? '' : $materialsRoot . '/';
        $this->extensionType = (string) $extensionType === 'theme' ? 'theme' : 'module';
        $this->extensionId = (string) $extensionId;
    }

    /**
     * 过程说明列表（空数组表示无附加说明）。
     *
     * @return array
     */
    public function notes()
    {
        return $this->notes;
    }

    /**
     * 执行指定阶段的初始化。
     *
     * 失败一律抛异常（由调用方并入错误收集并触发既有的回滚语义）；
     * 钩子内的 `$wrong[] = '...'` 由调用方在结束后统一检查。
     *
     * @param string $phase install|update
     * @param string $mode install|local|update（仅透传给钩子）
     * @param array $wrong 钩子共享的错误收集数组（引用）
     * @param array $extraVars 额外注入钩子的兼容变量（模块契约使用）
     * @return void
     * @throws \RuntimeException
     */
    public function run($phase, $mode, array &$wrong, array $extraVars = array())
    {
        $phase = (string) $phase;
        $phase = $phase === self::PHASE_UPDATE ? self::PHASE_UPDATE : self::PHASE_INSTALL;

        if ($this->materialsRoot === '' || !is_dir($this->materialsRoot)) {
            return;
        }

        $manifest = $this->readManifest();
        $seed = isset($manifest['seed']) && is_array($manifest['seed']) ? $manifest['seed'] : array();

        // 阶段开关：显式 false 表示该阶段不执行 seed（例如只提供 resources/images 的主题包）
        if (isset($seed[$phase]) && $seed[$phase] === false) {
            $this->notes[] = '扩展包声明跳过 ' . $phase . ' 阶段初始化。';
            return;
        }

        // 依赖检查：缺依赖时跳过 seed（不判安装失败），并把原因写进步骤日志
        $missing = $this->missingRequires($manifest);
        if ($missing) {
            $this->notes[] = '扩展初始化已跳过：缺少依赖 ' . implode('、', $missing) . '。';
            return;
        }

        // 1. 素材镜像（先落文件，SQL 才能登记真实存在的图片）
        if (!isset($seed['images']) || $seed['images'] !== false) {
            $this->mirrorImages();
        }

        // 2. 骨架数据：主题安装优先 seed.json，其次旧版 install.sql；升级只读 upgrade.sql
        $seedJson = $this->resourcesRoot() . 'seed.json';
        if ($phase === self::PHASE_INSTALL && $this->extensionType === 'theme' && is_file($seedJson)) {
            $jsonRunner = new SeedJsonRunner($this->rootPath, $this->materialsRoot, $this->extensionId);
            $stats = $jsonRunner->run($seedJson);
            $this->notes[] = '已执行 seed.json 骨架数据：新增 ' . $stats['inserted'] . ' 行、跳过 ' . $stats['skipped']
                . ' 行、登记图片 ' . $stats['files'] . ' 张、翻译 ' . $stats['translations'] . ' 条（install.sql 已忽略）。';
        } else {
            $sqlName = $phase === self::PHASE_UPDATE ? 'upgrade' : 'install';
            $sqlFile = $this->sqlRoot() . $sqlName . '.sql';
            if (is_file($sqlFile)) {
                $this->runSqlFile($sqlFile);
            }
        }

        // 3. PHP 钩子
        $hookFile = $this->materialsRoot . ($phase === self::PHASE_UPDATE ? 'upgrade.php' : 'install.php');
        if (is_file($hookFile)) {
            $this->runHook($hookFile, $phase, $mode, $wrong, $extraVars);
        }

        // 4. 模板缓存（与主题旧 action.php 末尾行为对齐）
        $this->clearCache();
    }

    /**
     * 物料根目录（结尾带 /）。
     *
     * @return string
     */
    public function materialsRoot()
    {
        return $this->materialsRoot;
    }

    /**
     * 物料资源根 resources/（结尾带 /）。
     *
     * @return string
     */
    public function resourcesRoot()
    {
        return $this->materialsRoot === '' ? '' : $this->materialsRoot . 'resources/';
    }

    /**
     * seed SQL 目录 resources/sql/（结尾带 /）。
     *
     * @return string
     */
    public function sqlRoot()
    {
        return $this->resourcesRoot() === '' ? '' : $this->resourcesRoot() . 'sql/';
    }

    /**
     * 素材根 resources/images/（结尾带 /）。
     *
     * @return string
     */
    public function imagesRoot()
    {
        return $this->resourcesRoot() === '' ? '' : $this->resourcesRoot() . 'images/';
    }

    /**
     * 读取并校验可选的 manifest.php。
     *
     * @return array 缺失时返回空数组
     * @throws \RuntimeException 文件存在但返回值 / 字段非法
     */
    private function readManifest()
    {
        $file = $this->materialsRoot . 'manifest.php';
        if (!is_file($file)) {
            return array();
        }

        $manifest = include $file;
        if (!is_array($manifest)) {
            throw new \RuntimeException('扩展包 manifest.php 非法：必须返回数组（' . $this->label() . '）。');
        }
        foreach (array('seed', 'requires') as $key) {
            if (isset($manifest[$key]) && !is_array($manifest[$key])) {
                throw new \RuntimeException('扩展包 manifest.php 非法：' . $key . ' 必须是数组（' . $this->label() . '）。');
            }
        }

        return $manifest;
    }

    /**
     * 依赖检查：返回缺失项的可读描述（空数组表示全部满足）。
     *
     * requires.tables  ：表必须已存在（如主题依赖 data 模块的 dou_data）
     * requires.modules ：模块必须在已安装账本中
     *
     * @param array $manifest
     * @return array
     */
    private function missingRequires(array $manifest)
    {
        if (empty($manifest['requires']) || !is_array($manifest['requires'])) {
            return array();
        }

        $requires = $manifest['requires'];
        $missing = array();

        $tables = isset($requires['tables']) ? (array) $requires['tables'] : array();
        foreach ($tables as $table) {
            $table = (string) $table;
            if ($table !== '' && !DB::tableExist($table)) {
                $missing[] = '数据表 ' . $table;
            }
        }

        $modules = isset($requires['modules']) ? (array) $requires['modules'] : array();
        if ($modules) {
            $installed = (array) Config::get('module.all_module', array());
            foreach ($modules as $module) {
                $module = (string) $module;
                if ($module !== '' && !in_array($module, $installed, true)) {
                    $missing[] = '模块 ' . $module;
                }
            }
        }

        return $missing;
    }

    /**
     * 把 resources/images/** 镜像到站点根 images/**（目标已存在则跳过，保护用户替换过的图片）。
     *
     * @return void
     * @throws \RuntimeException 任一文件落盘失败
     */
    private function mirrorImages()
    {
        $source = $this->imagesRoot();
        if ($source === '' || !is_dir($source)) {
            return;
        }

        $failed = array();
        $copied = $this->copyTreeSkipExisting($source, $this->rootPath . 'images/', '', $failed);
        if ($failed) {
            throw new \RuntimeException('素材写入失败（' . $this->label() . '）：' . implode('、', array_slice($failed, 0, 5)));
        }
        if ($copied > 0) {
            $this->notes[] = '已写入素材 ' . $copied . ' 个文件。';
        }
    }

    /**
     * 递归拷贝目录，目标文件已存在时跳过。
     *
     * @param string $sourceDir 源目录（结尾带 /）
     * @param string $targetDir 目标目录（结尾带 /）
     * @param string $relative 相对路径前缀（用于错误定位）
     * @param array $failed 失败项（相对路径）
     * @return int 实际写入的文件数
     */
    private function copyTreeSkipExisting($sourceDir, $targetDir, $relative, array &$failed)
    {
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0777, true) && !is_dir($targetDir)) {
            $failed[] = rtrim($relative, '/') === '' ? 'images/' : 'images/' . rtrim($relative, '/');
            return 0;
        }

        $copied = 0;
        $handle = @opendir($sourceDir);
        if (!$handle) {
            $failed[] = $relative === '' ? 'images/' : 'images/' . rtrim($relative, '/');
            return 0;
        }
        while (($item = @readdir($handle)) !== false) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $sourcePath = $sourceDir . $item;
            $targetPath = $targetDir . $item;
            $itemRelative = $relative === '' ? $item : $relative . '/' . $item;
            if (is_dir($sourcePath)) {
                $copied += $this->copyTreeSkipExisting($sourcePath . '/', $targetPath . '/', $itemRelative, $failed);
                continue;
            }
            if (file_exists($targetPath)) {
                continue;
            }
            if (!@copy($sourcePath, $targetPath)) {
                $failed[] = 'images/' . $itemRelative;
                continue;
            }
            $copied++;
        }
        @closedir($handle);

        return $copied;
    }

    /**
     * 执行 seed SQL：禁止 DDL -> 前缀替换 -> 事务内整文件执行。
     *
     * @param string $file SQL 文件绝对路径
     * @return void
     * @throws \RuntimeException DDL / 执行失败
     */
    private function runSqlFile($file)
    {
        $sql = (string) @file_get_contents($file);
        if (trim($sql) === '') {
            return;
        }

        $this->assertNoDdl($sql, $file);

        $prefix = DB::getPrefix();
        // 与既有导入口径一致：SQL 里用 dou_ 占位，执行前换成站点实际前缀（用 str_replace 避免前缀里的 $ 被当作正则反向引用）
        $sql = str_replace('dou_', $prefix, $sql);
        $label = basename($file);

        DB::transaction(function () use ($sql, $label) {
            if (!DB::fnExecute($sql)) {
                throw new \RuntimeException('骨架数据执行失败：' . $label . '（' . $this->label() . '），已回滚，请检查 SQL。');
            }
        });

        $this->notes[] = '已执行骨架数据 ' . $label . '。';
    }

    /**
     * 结构变更一律不允许出现在 seed SQL 里（结构归 SchemaSync，且保证可整体回滚）。
     *
     * @param string $sql
     * @param string $file
     * @return void
     * @throws \RuntimeException 命中 DDL 关键字
     */
    private function assertNoDdl($sql, $file)
    {
        $connection = DB::getFacadeRoot();
        $statements = method_exists($connection, 'fnSplit') ? $connection->fnSplit($sql) : array($sql);
        foreach ((array) $statements as $statement) {
            $statement = ltrim((string) $statement);
            if ($statement === '') {
                continue;
            }
            if (preg_match('/^(' . implode('|', self::$ddlKeywords) . ')\b/i', $statement, $matches)) {
                throw new \RuntimeException(
                    '骨架数据文件禁止结构变更（' . basename($file) . '）：命中 ' . strtoupper($matches[1])
                    . ' 语句。结构请交给模块 storage/backup/<module>.sql 与 SchemaSync 处理（' . $this->label() . '）。'
                );
            }
        }
    }

    /**
     * 在隔离作用域内执行 PHP 钩子（异常向上抛，输出被吞掉）。
     *
     * @param string $file
     * @param string $phase
     * @param string $mode
     * @param array $wrong
     * @param array $extraVars
     * @return void
     */
    private function runHook($file, $phase, $mode, array &$wrong, array $extraVars)
    {
        $seed = new SeedContext(array(
            'rootPath' => $this->rootPath,
            'materialsRoot' => $this->materialsRoot,
            'resourcesRoot' => $this->resourcesRoot(),
            'imagesRoot' => $this->imagesRoot(),
            'extensionType' => $this->extensionType,
            'extensionId' => $this->extensionId,
            'phase' => $phase,
        ));

        // 保留字在前，$extraVars 在后会被覆盖；先合并再补保留字，保证引擎变量权威
        $vars = array_merge((array) $extraVars, array(
            'prefix' => DB::getPrefix(),
            'extensionType' => $this->extensionType,
            'extensionId' => $this->extensionId,
            'phase' => $phase,
            'mode' => (string) $mode,
            'rootPath' => $this->rootPath,
            'materialsRoot' => $this->materialsRoot,
            'resourcesRoot' => $this->resourcesRoot(),
            'imagesRoot' => $this->imagesRoot(),
            'seed' => $seed,
        ));

        $bufferLevel = ob_get_level();
        ob_start();
        try {
            $this->includePayload($file, $vars, $wrong);
        } catch (\Exception $e) {
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw $e;
        } catch (\Throwable $e) {
            // PHP 5.6 无 \Throwable，本分支仅解析占位；PHP 7+ 承接 Error
            while (ob_get_level() > $bufferLevel) {
                ob_end_clean();
            }
            throw $e;
        }
        while (ob_get_level() > $bufferLevel) {
            ob_end_clean();
        }
    }

    /**
     * 实际 include 钩子（方法作用域即钩子作用域，$wrong 引用可直接追加）。
     *
     * @param string $file
     * @param array $vars
     * @param array $wrong
     * @return void
     */
    private function includePayload($file, array $vars, array &$wrong)
    {
        extract($vars, EXTR_SKIP);
        include $file;
    }

    /**
     * 清空模板编译缓存。
     *
     * @return void
     */
    private function clearCache()
    {
        $cacheClear = new CacheClearService();
        $cacheClear->clearSiteCache($this->rootPath);
    }

    /**
     * 日志里的扩展标识（模块 / 主题 + 名称）。
     *
     * @return string
     */
    private function label()
    {
        return ($this->extensionType === 'theme' ? '主题 ' : '模块 ') . $this->extensionId;
    }
}

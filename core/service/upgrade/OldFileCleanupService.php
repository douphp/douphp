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
 * Release Date: 2026-10-16
 */

namespace Dou\Core\Service\Upgrade;

use Dou\Core\Support\FileHelper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 旧系统文件清理：删除「新版覆盖旧版后仍残留」的旧式文件与目录（双端统一）。
 *
 * 判定基准为新系统白名单，但实现只做「抓取关键旧目录/入口，存在即删」，
 * 不逐文件全量比对、不解析模块 installed 清单。之所以安全：新系统里任何模块
 * （含扩展模块 case/solution/member）的文件都只落在子目录（front/、admin/controller|view、
 * theme/、api/、languages/、storage/ 等），不会落在站点根或 admin 主目录；因此
 * 「根/admin 主目录仅留 index.php + 删除旧式目录」的规则天然兜住未知模块的旧残留。
 *
 * 分四组（对应需求一/二/三）：
 *   组一：站点根 *.php —— 除 index.php 外全删（旧式模块入口、llms.php、扩展模块残留入口）
 *   组二：admin 主目录 *.php —— 除 index.php（后台唯一路由入口）外全删（旧式扁平控制器）
 *   组三：admin/templates/ —— 旧后台模板整目录删除（新后台模板事实源为 admin/view/）
 *   组四：与新系统不重叠的旧目录 —— include/ data/ cache/ m/ 及 admin/{include,css,js,images}
 *
 * 全程存在性判断 + 跳过软链接 + @ 容错，绝不因单个删除失败中断升级；重复执行幂等。
 *
 * 2026-10 起本类为云/向导双端单一来源（Runner 统一调用），不再有内联副本。
 */
class OldFileCleanupService
{
    /** @var string 站点根目录（绝对路径，结尾带 /） */
    private $rootPath;

    /** @var string 后台主目录（绝对路径，结尾带 /） */
    private $adminPath;

    /** @var string[] 站点根 / admin 主目录需保留的入口文件名 */
    private $entryKeep = array('index.php');

    /**
     * @var string[] 与新系统不重叠、需整目录删除的站点根旧目录（目录名，不含结尾 /）
     */
    private $rootLegacyDirs = array(
        'include',
        'data',
        'cache',
        'm',
    );

    /**
     * @var string[] 需整目录删除的后台旧子目录（相对后台主目录，不含结尾 /）。
     *               新后台资源在 admin/view/{css,js,images}；admin/editor 属新系统保留，不删。
     *               按 ADMIN_PATH 解析，兼容用户自定义后台目录名。
     */
    private $adminLegacySubdirs = array(
        'templates',
        'include',
        'css',
        'js',
        'images',
    );

    /**
     * @var string[] 新系统保留的顶层目录名：兜底防误删，绝不出现在删除目标里
     */
    private $protectedTopDirs = array(
        'core', 'config', 'front', 'api', 'admin', 'images',
        'languages', 'theme', 'miniprogram', 'storage', 'upgrade',
    );

    /**
     * @param string $rootPath  站点根目录（结尾带 /）
     * @param string $adminPath 后台主目录（可为旧环境的相对值，如 'admin'，构造时归一化为绝对路径）
     */
    public function __construct($rootPath, $adminPath = '')
    {
        $this->rootPath = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';

        // 旧环境（1.x init.php）的 ADMIN_PATH 可能为相对值（如 'admin'），补全为绝对路径并统一结尾带 /，
        // 否则下方 glob / 子目录拼接全部落空；新环境本就是绝对路径，本规范化等值幂等无副作用。
        $adminPath = str_replace('\\', '/', (string) $adminPath);
        if ($adminPath === '') {
            $adminPath = $this->rootPath . 'admin/';
        }
        if (strpos($adminPath, $this->rootPath) !== 0) {
            $adminPath = $this->rootPath . trim($adminPath, '/');
        }
        $this->adminPath = rtrim($adminPath, '/') . '/';
    }

    /**
     * 执行全部清理。任一步失败均静默继续，不抛异常、不中断升级收尾。
     *
     * @return void
     */
    public function cleanup()
    {
        @set_time_limit(0);

        $this->deleteDirScopedPhp($this->rootPath);
        $this->deleteAdminScopedPhp();
        $this->deleteLegacyDirs();
    }

    /**
     * 组一：删除站点根目录下除保留入口外的所有 *.php（仅一层，不递归）。
     *
     * @param string $dir
     * @return void
     */
    private function deleteDirScopedPhp($dir)
    {
        $files = @glob($dir . '*.php');
        if (!is_array($files)) {
            return;
        }
        foreach ($files as $file) {
            if (is_link($file) || !is_file($file)) {
                continue;
            }
            if (in_array(basename($file), $this->entryKeep, true)) {
                continue;
            }
            @unlink($file);
        }
    }

    /**
     * 组二：删除 admin 主目录下除 index.php 外的所有 *.php（仅一层，不递归）。
     *
     * @return void
     */
    private function deleteAdminScopedPhp()
    {
        $this->deleteDirScopedPhp($this->adminPath);
    }

    /**
     * 组三 + 组四：删除旧后台模板目录与所有不重叠的旧目录（整目录递归删除）。
     *
     * 站点根旧目录按 rootPath 解析；后台旧子目录按 adminPath 解析，兼容自定义后台目录名。
     *
     * @return void
     */
    private function deleteLegacyDirs()
    {
        // 站点根旧目录
        foreach ($this->rootLegacyDirs as $name) {
            $norm = strtolower(str_replace('\\', '/', $name));
            // 硬保护：裸名的新系统保留顶层目录绝不删除（兜住清单误配）
            if (in_array($norm, $this->protectedTopDirs, true)) {
                continue;
            }
            // 安全护栏：删 data/ 前必须确认安装标记已迁到新位置，否则跳过。
            // core/bootstrap.php 判「未安装」的兜底链含 data/install.lock；若迁移未落地就删 data/，
            // 站点会被判为未安装而跳转安装程序（锁死）。宁可留下 data/ 残留，也不冒锁死风险。
            if ($norm === 'data' && !$this->installStateMigrated()) {
                continue;
            }
            $this->removeDir($this->rootPath . $name);
        }

        // 后台旧子目录（相对 adminPath）
        foreach ($this->adminLegacySubdirs as $name) {
            $this->removeDir($this->adminPath . $name);
        }
    }

    /**
     * 安装标记是否已迁到新位置（storage/state/install.lock 或 storage/install.lock）。
     *
     * 不依赖 STORAGE_PATH 常量（升级自举上下文未必定义），统一用 rootPath 拼接。
     *
     * @return bool
     */
    private function installStateMigrated()
    {
        return file_exists($this->rootPath . 'storage/state/install.lock')
            || file_exists($this->rootPath . 'storage/install.lock');
    }

    /**
     * 存在且非软链接时整目录递归删除；静默容错。
     *
     * @param string $abs 绝对路径（不含结尾 /）
     * @return void
     */
    private function removeDir($abs)
    {
        if (!is_dir($abs) || is_link($abs)) {
            return;
        }
        FileHelper::delDir($abs, false);
    }
}

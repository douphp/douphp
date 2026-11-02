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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * seed 钩子上下文（install.php / upgrade.php 内通过 $seed 使用）。
 *
 * 只暴露「只读路径查询 + 存在性断言」，不放写库能力：写库走 DB 门面，
 * 让钩子里的每一句 SQL 都可见、可审计。
 *
 * 用法示例：
 * <pre>
 * if ($seed->tableExists('data') && !$seed->fileExists('images/data/m148/about.jpg')) {
 *     $wrong[] = '演示图缺失：images/data/m148/about.jpg';
 * }
 * $sql = $seed->sqlRoot() . 'extra.sql';
 * </pre>
 */
class SeedContext
{
    /** @var string 站点根目录（结尾带 /） */
    private $rootPath;

    /** @var string 物料根 _action/（结尾带 /） */
    private $materialsRoot;

    /** @var string 资源根 resources/（结尾带 /） */
    private $resourcesRoot;

    /** @var string 素材根 resources/images/（结尾带 /） */
    private $imagesRoot;

    /** @var string module|theme */
    private $extensionType;

    /** @var string 扩展标识 */
    private $extensionId;

    /** @var string install|update */
    private $phase;

    /**
     * @param array $options rootPath / materialsRoot / resourcesRoot / imagesRoot / extensionType / extensionId / phase
     */
    public function __construct(array $options = array())
    {
        $this->rootPath = isset($options['rootPath']) ? (string) $options['rootPath'] : '';
        $this->materialsRoot = isset($options['materialsRoot']) ? (string) $options['materialsRoot'] : '';
        $this->resourcesRoot = isset($options['resourcesRoot']) ? (string) $options['resourcesRoot'] : '';
        $this->imagesRoot = isset($options['imagesRoot']) ? (string) $options['imagesRoot'] : '';
        $this->extensionType = isset($options['extensionType']) ? (string) $options['extensionType'] : '';
        $this->extensionId = isset($options['extensionId']) ? (string) $options['extensionId'] : '';
        $this->phase = isset($options['phase']) ? (string) $options['phase'] : '';
    }

    /**
     * 站点根目录（结尾带 /）。
     *
     * @return string
     */
    public function rootPath()
    {
        return $this->rootPath;
    }

    /**
     * 物料根 _action/（结尾带 /）。
     *
     * @return string
     */
    public function materialsRoot()
    {
        return $this->materialsRoot;
    }

    /**
     * 资源根 resources/（结尾带 /）。
     *
     * @return string
     */
    public function resourcesRoot()
    {
        return $this->resourcesRoot;
    }

    /**
     * seed SQL 目录 resources/sql/（结尾带 /）。
     *
     * @return string
     */
    public function sqlRoot()
    {
        return $this->resourcesRoot === '' ? '' : $this->resourcesRoot . 'sql/';
    }

    /**
     * 素材根 resources/images/（结尾带 /）。
     *
     * @return string
     */
    public function imagesRoot()
    {
        return $this->imagesRoot;
    }

    /**
     * 当前阶段（install|update）。
     *
     * @return string
     */
    public function phase()
    {
        return $this->phase;
    }

    /**
     * module|theme。
     *
     * @return string
     */
    public function extensionType()
    {
        return $this->extensionType;
    }

    /**
     * 扩展标识（模块标识 / 主题 slug）。
     *
     * @return string
     */
    public function extensionId()
    {
        return $this->extensionId;
    }

    /**
     * 数据表是否存在。
     *
     * @param string $table 不含前缀的表名
     * @return bool
     */
    public function tableExists($table)
    {
        return DB::tableExist((string) $table);
    }

    /**
     * 字段是否存在。
     *
     * @param string $table 不含前缀的表名
     * @param string $field
     * @return bool
     */
    public function fieldExists($table, $field)
    {
        return DB::fieldExist((string) $table, (string) $field);
    }

    /**
     * 站点文件是否存在（相对站点根的路径，如 images/data/m148/about.jpg）。
     *
     * @param string $relative
     * @return bool
     */
    public function fileExists($relative)
    {
        $relative = ltrim(str_replace('\\', '/', (string) $relative), '/');
        if ($relative === '' || $this->rootPath === '') {
            return false;
        }

        return is_file($this->rootPath . $relative);
    }
}

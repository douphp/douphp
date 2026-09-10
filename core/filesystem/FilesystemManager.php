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

namespace Dou\Core\Filesystem;

use Dou\Core\Filesystem\Adapter\LocalAdapter;
use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 文件系统管理器：解析 disk 名 → {@see Disk} 实例。
 *
 * 解析顺序：
 *   ① upload_defaults（全局上传策略缺省值）
 *   ② 命名约定推导：`{module}_icon` → `images/{module}/icon/`，标准名（[a-z0-9_]+）→ `images/{name}/`
 *   ③ `filesystems.disks.{name}` 显式声明（覆盖前两步）
 *   ④ 约定不命中且未显式声明 → 抛 InvalidArgumentException
 *
 * 因此 disks 配置只需声明「无法由约定推导的项」（disk 名 ≠ 路径名，或需覆盖上传策略），
 * 标准模块磁盘（如 article / product / data）按约定自动生成，无须每个模块手写一行。
 *
 * 业务侧通过 {@see Storage} 静态门面拿到本类实例与 Disk。
 */
class FilesystemManager
{
    /**
     * 已解析的 Disk 实例缓存（按 disk 名）。
     *
     * @var array<string, Disk>
     */
    private $disks = array();

    /**
     * 自定义驱动工厂（按 driver 名注册）。
     *
     * @var array<string, callable>
     */
    private $customDrivers = array();

    /**
     * 按名取磁盘（不传名取默认磁盘）。
     *
     * @param string|null $name
     * @return Disk
     */
    public function disk($name = null)
    {
        if ($name === null || $name === '') {
            $name = $this->getDefaultDriverName();
        }

        if (isset($this->disks[$name])) {
            return $this->disks[$name];
        }

        $config = $this->getDiskConfig($name);
        $disk = $this->resolveDisk($name, $config);
        $this->disks[$name] = $disk;

        return $disk;
    }

    /**
     * 按相对站点根的路径动态构造磁盘（不查 filesystems.disks 配置，仅作 ad-hoc 视图）。
     *
     * 用于像 `theme/{theme}/images/` / `images/slide/` 这类无固定磁盘配置项的动态目录。
     *
     * @param string $relativeRoot
     * @param array $overrides 可覆盖上传策略（upload_max_kb / allow_extensions 等）
     * @return Disk
     */
    public function build($relativeRoot, array $overrides = array())
    {
        $config = array_merge(
            $this->getUploadDefaults(),
            array('driver' => 'local', 'root' => PathNormalizer::normalizeRoot($relativeRoot)),
            $overrides
        );

        return $this->resolveDisk('__ad_hoc__:' . $config['root'], $config);
    }

    /**
     * 注册自定义驱动工厂。
     *
     * @param string $driverName
     * @param callable $factory function(array $config): FilesystemAdapter
     * @return void
     */
    public function extend($driverName, $factory)
    {
        $this->customDrivers[$driverName] = $factory;
    }

    /**
     * 取磁盘配置：upload_defaults < 约定推导 < disks[name] 显式声明；都不命中时抛错。
     *
     * @param string $name
     * @return array
     */
    public function getDiskConfig($name)
    {
        $disks = Config::get('filesystems.disks', array());
        $disks = is_array($disks) ? $disks : array();

        $declared = isset($disks[$name]) ? (array) $disks[$name] : array();
        $convention = $this->resolveConventionDisk($name);

        if ($declared === array() && $convention === null) {
            throw new \InvalidArgumentException('Filesystem disk not configured: ' . $name);
        }

        $config = array_merge(
            $this->getUploadDefaults(),
            $convention !== null ? $convention : array(),
            $declared
        );
        if (!isset($config['driver']) || $config['driver'] === '') {
            $config['driver'] = 'local';
        }

        return $config;
    }

    /**
     * 按命名约定推导磁盘配置：`{base}_icon` → `images/{base}/icon/`；标准名 → `images/{name}/`。
     *
     * 命中返回 driver+root 的最小磁盘片段，由 {@see getDiskConfig()} 与 upload_defaults / 显式声明合并；
     * 名称为空、含特殊字符等无法由约定表达的情况返回 null，由调用方决定是否落到显式声明或抛错。
     *
     * @param string $name
     * @return array|null
     */
    private function resolveConventionDisk($name)
    {
        $name = (string) $name;
        if ($name === '') {
            return null;
        }

        if (preg_match('/^([a-z0-9]+(?:_[a-z0-9]+)*)_icon$/', $name, $m)) {
            return array('driver' => 'local', 'root' => 'images/' . $m[1] . '/icon/');
        }

        if (preg_match('/^[a-z0-9_]+$/', $name)) {
            return array('driver' => 'local', 'root' => 'images/' . $name . '/');
        }

        return null;
    }

    /**
     * 默认磁盘名（filesystems.default，缺省为 local）。
     *
     * @return string
     */
    public function getDefaultDriverName()
    {
        $name = Config::get('filesystems.default', 'local');
        if (!is_string($name) || $name === '') {
            $name = 'local';
        }

        return $name;
    }

    /**
     * 全部 disk 名（不含 ad-hoc）。
     *
     * @return array
     */
    public function getDiskNames()
    {
        $disks = Config::get('filesystems.disks', array());
        if (!is_array($disks)) {
            return array();
        }

        return array_keys($disks);
    }

    /**
     * 上传默认策略。
     *
     * @return array
     */
    public function getUploadDefaults()
    {
        $defaults = Config::get('filesystems.upload_defaults', array());
        if (!is_array($defaults)) {
            return array();
        }

        return $defaults;
    }

    /**
     * 实际构造 Disk 实例（含驱动选择）。
     *
     * @param string $cacheKey
     * @param array $config
     * @return Disk
     */
    private function resolveDisk($cacheKey, array $config)
    {
        $driver = isset($config['driver']) ? $config['driver'] : 'local';

        if (isset($this->customDrivers[$driver])) {
            $factory = $this->customDrivers[$driver];
            $adapter = call_user_func($factory, $config);
        } elseif ($driver === 'local') {
            $root = isset($config['root']) ? (string) $config['root'] : '';
            $url = isset($config['url']) ? (string) $config['url'] : '';
            $adapter = new LocalAdapter($root, $url);
        } else {
            throw new \InvalidArgumentException('Unknown filesystem driver: ' . $driver);
        }

        return new Disk($adapter, $config);
    }
}

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

namespace Dou\Install\Service;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 安装锁文件（storage/install.lock）读写。
 *
 * Router 会在每次请求开始时检查 isLocked()，已锁定则强制走 LockController。
 * FinishService::finalize() 在安装成功后写入安装锁。
 */
class InstallLockService
{
    /** @var string */
    private $lockFile;

    public function __construct()
    {
        $this->lockFile = ROOT_PATH . 'storage/install.lock';
    }

    /**
     * 安装锁文件路径。
     *
     * @return string
     */
    public function lockFile()
    {
        return $this->lockFile;
    }

    /**
     * 是否已安装（锁文件存在即视为已安装）。
     *
     * @return bool
     */
    public function isLocked()
    {
        return file_exists($this->lockFile);
    }

    /**
     * 写入安装锁。
     *
     * @return bool
     */
    public function lock()
    {
        $fp = @fopen($this->lockFile, 'w+');
        if (!$fp) {
            return false;
        }
        fwrite($fp, 'DOUPHP INSTALLED');
        fclose($fp);
        return true;
    }
}

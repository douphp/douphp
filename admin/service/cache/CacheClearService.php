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

namespace Dou\Admin\Service\Cache;

use Dou\Core\Service\BaseService;
use Dou\Core\Support\FileHelper;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台缓存目录清理（扩展点：后续可在此增加还原等操作）。
 */
class CacheClearService extends BaseService
{
    /**
     */
    public function __construct()
    {
    }

    /**
     * 删除缓存目录（及子目录内容）。
     *
     * @param string $dir
     * @return void
     */
    public function clearCache($dir)
    {
        FileHelper::delDir($dir, true);
    }
}

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
 * 清空 storage/cache/template 目录，让升级后立即重新编译模板与生成缓存（双端统一）。
 *
 * 替代旧 Action::dou_clear_cache。保留模板编译根目录本身，仅删除其下内容。
 */
class CacheClearService
{
    /**
     * 清空 {rootPath}storage/cache/template 下所有内容（保留目录本身）。
     *
     * @param string $rootPath 站点根目录（结尾带 /）
     * @return void
     */
    public function clearSiteCache($rootPath)
    {
        FileHelper::delDir(rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/storage/cache/template', true);
    }
}

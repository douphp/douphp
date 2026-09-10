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

use Dou\Core\Foundation\Facade\StaticFacade;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 存储静态门面：底层为 {@see FilesystemManager} 容器单例。
 *
 * 业务侧用法：
 *   use Dou\Core\Filesystem\Storage;
 *   Storage::disk('article')->url('xx.jpg');
 *   Storage::build('theme/default/images/')->url('logo.png');
 *   带扩展名/大小校验的上传请走 AttachmentService 或 Validator。
 *
 * 测试可走 {@see StaticFacade::swap()} 替换底层实例。
 *
 * @method static Disk disk(string|null $name = null)
 * @method static Disk build(string $relativeRoot, array $overrides = array())
 * @method static void extend(string $driverName, callable $factory)
 * @method static array getDiskConfig(string $name)
 * @method static string getDefaultDriverName()
 * @method static array getDiskNames()
 * @method static array getUploadDefaults()
 */
class Storage extends StaticFacade
{
    /**
     * 容器解析键：FilesystemManager 类名。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return FilesystemManager::class;
    }
}

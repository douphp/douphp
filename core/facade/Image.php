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

namespace Dou\Core\Facade;

use Dou\Core\Foundation\Facade\StaticFacade;
use Dou\Core\Infra\Image\Driver\GdDriver;
use Dou\Core\Infra\Image\ImageEditor;
use Dou\Core\Infra\Image\ImageManager;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Image 静态门面：底层为 {@see ImageManager} 容器单例。
 *
 * @method static GdDriver driver()
 * @method static ImageEditor open(string $absolutePath)
 * @method static array|false info(string $absolutePath)
 * @method static bool resize(string $srcAbs, string $dstAbs, int $width, int $height, int $quality = 90)
 * @method static bool thumb(string $srcAbs, string $thumbAbs, int $width, int $height, int $quality = 90)
 * @method static bool watermark(string $srcAbs, string $dstAbs, array $options, int $quality = 90)
 * @method static string buildThumbPath(string $sourceRelative, string $thumbSubdir = '')
 */
class Image extends StaticFacade
{
    /**
     * 容器中以 ImageManager FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return ImageManager::class;
    }
}

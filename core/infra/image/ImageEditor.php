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

namespace Dou\Core\Infra\Image;

use Dou\Core\Infra\Image\Driver\GdDriver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 单张图片链式编辑会话（无状态命令缓冲；调 save() 才落盘）。
 *
 * 业务侧：
 *   Image::open($abs)->resize(200, 0)->save($dstAbs, 80);
 *   Image::open($abs)->thumb(120, 80)->save($thumbAbs);
 *   Image::open($abs)->watermark(array('type' => 'text', 'value' => '示例'))->save();
 *
 * 复杂多步操作直接走 ImageManager 的工具方法（resize / thumb / watermark）即可。
 */
class ImageEditor
{
    /** @var GdDriver */
    private $driver;

    /** @var string */
    private $srcAbs;

    /** @var array 待执行的命令栈，每条 [op, args...] */
    private $ops = array();

    /**
     * @param GdDriver $driver
     * @param string $srcAbs
     */
    public function __construct(GdDriver $driver, $srcAbs)
    {
        $this->driver = $driver;
        $this->srcAbs = (string) $srcAbs;
    }

    /**
     * 调整尺寸（宽/高均可为 0 表示自动按比例）。
     *
     * @param int $width
     * @param int $height
     * @return $this
     */
    public function resize($width, $height)
    {
        $this->ops[] = array('resize', (int) $width, (int) $height);

        return $this;
    }

    /**
     * 缩略图（语义上与 resize 一致，便于阅读区分；输出文件名仍由 save() 决定）。
     *
     * @param int $width
     * @param int $height
     * @return $this
     */
    public function thumb($width, $height)
    {
        $this->ops[] = array('resize', (int) $width, (int) $height);

        return $this;
    }

    /**
     * 加水印。
     *
     * @param array $options 见 {@see GdDriver::watermark()}
     * @return $this
     */
    public function watermark(array $options)
    {
        $this->ops[] = array('watermark', $options);

        return $this;
    }

    /**
     * 落盘。
     *
     * 单一 resize：直接以 srcAbs → dstAbs 执行；
     * 多步操作：每一步走临时绝对路径，再合并到最终 dstAbs。
     *
 * 当前实现仅支持「单 resize 或 单 watermark」串行；如需更复杂的链式组合，调用方
 * 拆成两次 Image::open(...)->save(...) 即可。
     *
     * @param string|null $dstAbs 默认与 srcAbs 同路径（原地覆盖）
     * @param int $quality 0-100
     * @return bool
     */
    public function save($dstAbs = null, $quality = 90)
    {
        $dst = $dstAbs === null ? $this->srcAbs : (string) $dstAbs;
        $current = $this->srcAbs;
        $tempCleanup = null;

        $count = count($this->ops);
        for ($i = 0; $i < $count; $i++) {
            $op = $this->ops[$i];
            $isLast = ($i === $count - 1);
            $target = $isLast ? $dst : tempnam(sys_get_temp_dir(), 'dou_img_');
            if (!$isLast && $tempCleanup === null) {
                $tempCleanup = $target;
            }
            switch ($op[0]) {
                case 'resize':
                    $ok = $this->driver->resize($current, $target, $op[1], $op[2], $quality);
                    break;
                case 'watermark':
                    $ok = $this->driver->watermark($current, $target, $op[1], $quality);
                    break;
                default:
                    $ok = false;
                    break;
            }
            if (!$ok) {
                if ($tempCleanup !== null) {
                    @unlink($tempCleanup);
                }

                return false;
            }
            $current = $target;
        }
        if ($tempCleanup !== null && $tempCleanup !== $dst) {
            @unlink($tempCleanup);
        }

        return true;
    }

    /**
     * 读取源图信息。
     *
     * @return array|false
     */
    public function info()
    {
        return $this->driver->info($this->srcAbs);
    }
}

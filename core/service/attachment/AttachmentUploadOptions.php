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

namespace Dou\Core\Service\Attachment;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 附件上传选项（值对象，链式 setter，调用方按需声明）。
 *
 * 业务侧用法：
 *   $opt = AttachmentUploadOptions::create()
 *       ->withImageWidth(1200)
 *       ->withThumbnail(200, 150)
 *       ->withWatermark('enable');
 *   attachment()->store('article', $id, $file, 'main', $opt);
 *
 * 默认值含义：
 *   disk           - 留空时取 $module 作为 disk 名（多数模块一致）
 *   directory      - 在 disk root 下的相对子目录（空表示磁盘根）
 *   basename       - 自定义文件主名（不含扩展名）；空表示按 item_id_random 自动生成
 *   imgWidth/Height-  上传后缩放尺寸；0 表示不缩放
 *   thumbWidth/Height- 缩略图尺寸；都为 0 表示不生成缩略图
 *   watermark      - 'enable' / 'img' / 'text' 或具体水印文本，空表示不加
 *   primaryKey     - 读取已有 number 时主键列（默认 id；user 模块的 sn 等场景显式指定）
 *   allowExtensions- 允许扩展名（逗号分隔），空表示走 disk 配置默认
 *   uploadMaxKb    - 上传字节上限（KB），0 表示走 disk 默认
 *   imageQuality   - 图片输出质量 0-100，0 表示走 disk 默认
 */
class AttachmentUploadOptions
{
    /** @var string */
    private $disk = '';

    /** @var string */
    private $directory = '';

    /** @var string */
    private $basename = '';

    /** @var int */
    private $imgWidth = 0;

    /** @var int */
    private $thumbWidth = 0;

    /** @var int */
    private $thumbHeight = 0;

    /** @var string */
    private $watermark = '';

    /** @var string */
    private $primaryKey = 'id';

    /** @var string */
    private $allowExtensions = '';

    /** @var int */
    private $uploadMaxKb = 0;

    /** @var int */
    private $imageQuality = 0;

    /** @var string */
    private $businessField = '';

    /** @var string */
    private $uploaderKind = '';

    /** @var int */
    private $uploaderId = 0;

    /**
     * 工厂入口，便于链式调用。
     *
     * @return self
     */
    public static function create()
    {
        return new self();
    }

    /**
     * 指定 disk 名（不调时按 module 名约定）。
     *
     * @param string $disk
     * @return $this
     */
    public function withDisk($disk)
    {
        $this->disk = (string) $disk;

        return $this;
    }

    /**
     * 指定 disk root 内的相对子目录。
     *
     * @param string $directory
     * @return $this
     */
    public function withDirectory($directory)
    {
        $this->directory = (string) $directory;

        return $this;
    }

    /**
     * 指定文件主名（不含扩展名）。
     *
     * @param string $basename
     * @return $this
     */
    public function withBasename($basename)
    {
        $this->basename = (string) $basename;

        return $this;
    }

    /**
     * 上传后缩放到指定宽度（高度按比例自动）。
     *
     * @param int $width
     * @return $this
     */
    public function withImageWidth($width)
    {
        $this->imgWidth = (int) $width;

        return $this;
    }

    /**
     * 缩略图尺寸；任一为 0 表示自动按比例缩放。
     *
     * @param int $width
     * @param int $height
     * @return $this
     */
    public function withThumbnail($width, $height)
    {
        $this->thumbWidth = (int) $width;
        $this->thumbHeight = (int) $height;

        return $this;
    }

    /**
     * 水印：'enable' 时默认 img/text 自动选；显式传文本则强制 text 类型。
     *
     * @param string $watermark
     * @return $this
     */
    public function withWatermark($watermark)
    {
        $this->watermark = (string) $watermark;

        return $this;
    }

    /**
     * 业务表主键列（user 模块的 sn、order 模块的 order_id 等场景显式指定）。
     *
     * @param string $primaryKey
     * @return $this
     */
    public function withPrimaryKey($primaryKey)
    {
        $this->primaryKey = (string) $primaryKey;

        return $this;
    }

    /**
     * 允许扩展名（逗号分隔）；空时走 disk 配置。
     *
     * @param string $extensions
     * @return $this
     */
    public function withAllowExtensions($extensions)
    {
        $this->allowExtensions = (string) $extensions;

        return $this;
    }

    /**
     * 上传文件 KB 上限；0 时走 disk 配置。
     *
     * @param int $kb
     * @return $this
     */
    public function withUploadMaxKb($kb)
    {
        $this->uploadMaxKb = (int) $kb;

        return $this;
    }

    /**
     * 图片输出质量；0 时走 disk 配置。
     *
     * @param int $quality
     * @return $this
     */
    public function withImageQuality($quality)
    {
        $this->imageQuality = (int) $quality;

        return $this;
    }

    /**
     * @return string
     */
    public function getDisk()
    {
        return $this->disk;
    }

    /**
     * @return string
     */
    public function getDirectory()
    {
        return $this->directory;
    }

    /**
     * @return string
     */
    public function getBasename()
    {
        return $this->basename;
    }

    /**
     * @return int
     */
    public function getImageWidth()
    {
        return $this->imgWidth;
    }

    /**
     * @return int
     */
    public function getThumbWidth()
    {
        return $this->thumbWidth;
    }

    /**
     * @return int
     */
    public function getThumbHeight()
    {
        return $this->thumbHeight;
    }

    /**
     * @return string
     */
    public function getWatermark()
    {
        return $this->watermark;
    }

    /**
     * @return string
     */
    public function getPrimaryKey()
    {
        return $this->primaryKey;
    }

    /**
     * @return string
     */
    public function getAllowExtensions()
    {
        return $this->allowExtensions;
    }

    /**
     * @return int
     */
    public function getUploadMaxKb()
    {
        return $this->uploadMaxKb;
    }

    /**
     * @return int
     */
    public function getImageQuality()
    {
        return $this->imageQuality;
    }

    /**
     * 业务表里用于读取已有 file number 的列名（缺省按 type 推断；分类 icon 上传等场景显式指定）。
     *
     * @param string $field
     * @return $this
     */
    public function withBusinessField($field)
    {
        $this->businessField = (string) $field;

        return $this;
    }

    /**
     * @return string
     */
    public function getBusinessField()
    {
        return $this->businessField;
    }

    /**
     * 上传者身份：owned 写入时落 dou_file 的 uploader_type / uploader_id。
     *
     * shell 层（admin/front/api）取定 guard 后显式传入，core 不感知 auth 上下文。
     *
     * @param string $kind 'admin' / 'user' / 'work'
     * @param int $id 对应身份表主键
     * @return $this
     */
    public function withUploader($kind, $id)
    {
        $this->uploaderKind = (string) $kind;
        $this->uploaderId = (int) $id;

        return $this;
    }

    /**
     * @return string
     */
    public function getUploaderKind()
    {
        return $this->uploaderKind;
    }

    /**
     * @return int
     */
    public function getUploaderId()
    {
        return $this->uploaderId;
    }
}

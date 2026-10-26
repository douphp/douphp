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

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 上传参数归一与策略解析（前后台共用，纯静态、无身份依赖）。
 *
 * 只回答「允许什么、上限多少、标识如何归一」；「谁能传、传给谁、是否归属本人」
 * 属于决策层，必须留在 front/admin 各自的控制器或服务里。
 *
 * 白名单与上限口径：请求值只能收紧平台基线，不能放宽。
 */
class AttachmentRules
{
    /** @var string 单图（重复上传即替换） */
    const TYPE_MAIN = 'main';
    /** @var string 相册（追加） */
    const TYPE_GALLERY = 'gallery';
    /** @var string 附件（追加，不做图片处理） */
    const TYPE_ATTACH = 'attach';
    /** @var string 编辑器插图（仅后台） */
    const TYPE_CONTENT = 'content';

    /** @var array 前台可用类型（落库枚举） */
    private static $frontTypes = array('main', 'gallery', 'attach');

    /** @var array 后台可用类型（落库枚举，含编辑器插图） */
    private static $adminTypes = array('main', 'gallery', 'attach', 'content');

    /** @var string 附件扩展名兜底（配置缺失时） */
    const ATTACH_EXTENSIONS = 'pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar,7z';
    /** @var string 图片扩展名兜底（配置缺失时） */
    const IMAGE_EXTENSIONS = 'jpg,jpeg,gif,png,webp,ico';

    /** @var int 附件体积兜底（KB） */
    const ATTACH_MAX_KB = 20480;
    /** @var int 图片体积兜底（KB） */
    const IMAGE_MAX_KB = 2048;

    /**
     * 平台扩展名基线。
     *
     * 附件走 filesystems.attach_uploads，图片走 filesystems.upload_defaults；
     * 两者缺失时回退类内兜底值。
     *
     * @param string $type main / gallery / attach / content
     * @return string 逗号分隔的小写扩展名
     */
    public static function baselineExtensions($type)
    {
        if ($type === self::TYPE_ATTACH) {
            $attach = (array) Config::get('filesystems.attach_uploads', array());

            return isset($attach['allow_extensions'])
                ? (string) $attach['allow_extensions']
                : self::ATTACH_EXTENSIONS;
        }

        $allow = (string) Config::get('filesystems.upload_defaults.allow_extensions', '');

        return $allow !== '' ? $allow : self::IMAGE_EXTENSIONS;
    }

    /**
     * 平台单文件上限（KB）：按类型取配置基线，再与 PHP ini 取小。
     *
     * 只与 PHP ini 取小，不混用「以图片基线为基线」的 effectiveUploadMaxKb()，
     * 否则附件的 20480 会被错压到 2048。
     *
     * @param string $type
     * @return int
     */
    public static function platformMaxKb($type)
    {
        if ($type === self::TYPE_ATTACH) {
            $platform = (int) Config::get('filesystems.attach_uploads.upload_max_kb', 0);
            if ($platform <= 0) {
                $platform = self::ATTACH_MAX_KB;
            }
        } else {
            $platform = (int) Config::get('filesystems.upload_defaults.upload_max_kb', 0);
            if ($platform <= 0) {
                $platform = self::IMAGE_MAX_KB;
            }
        }

        $phpKb = (int) UploadedFile::phpUploadMaxKb();

        return $phpKb > 0 ? min($platform, $phpKb) : $platform;
    }

    /**
     * 解析允许扩展名：请求值只能取平台基线的子集。
     *
     * @param string $type
     * @param string $requested 逗号分隔
     * @return string
     */
    public static function resolveExtensions($type, $requested)
    {
        $baseline = self::baselineExtensions($type);
        $requested = strtolower(trim((string) $requested));
        if ($requested === '') {
            return $baseline;
        }

        $allowed = array_map('trim', explode(',', $baseline));
        $result = array();
        foreach (explode(',', $requested) as $ext) {
            $ext = strtolower(trim($ext));
            if ($ext !== '' && in_array($ext, $allowed, true)) {
                $result[] = $ext;
            }
        }

        return empty($result) ? $baseline : implode(',', array_values(array_unique($result)));
    }

    /**
     * 解析单文件上限（KB）：请求值只能收紧，不能放宽。
     *
     * @param string $type
     * @param int $requested 请求值，<=0 表示未指定
     * @return int
     */
    public static function resolveMaxKb($type, $requested)
    {
        $platform = self::platformMaxKb($type);
        $requested = (int) $requested;

        return $requested > 0 ? min($requested, $platform) : $platform;
    }

    /**
     * 模块名归一：[a-z0-9_]。
     *
     * @param string $module
     * @return string
     */
    public static function normalizeModule($module)
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) $module)));
    }

    /**
     * DOM 前缀归一：[a-z0-9_]。
     *
     * @param string $prefix
     * @return string
     */
    public static function normalizePrefix($prefix)
    {
        return preg_replace('/[^a-z0-9_]/', '', strtolower(trim((string) $prefix)));
    }

    /**
     * 前台类型白名单：main / gallery / attach。
     *
     * @param string $type
     * @return string 不合法返回空串
     */
    public static function normalizeType($type)
    {
        $type = strtolower(trim((string) $type));

        return in_array($type, self::$frontTypes, true) ? $type : '';
    }

    /**
     * 后台类型归一：`attachment → attach`（前端组件名与历史落库枚举的映射）。
     *
     * 后台历史上不做白名单拦截，此处只做枚举归一，保持调用方原有容错行为。
     *
     * @param string $type
     * @return string
     */
    public static function normalizeTypeForAdmin($type)
    {
        $type = strtolower(trim((string) $type));

        return $type === 'attachment' ? self::TYPE_ATTACH : $type;
    }

    /**
     * 后台类型是否为已知枚举。
     *
     * @param string $type
     * @return bool
     */
    public static function isAdminType($type)
    {
        return in_array((string) $type, self::$adminTypes, true);
    }

    /**
     * 业务主键归一：字符串、≤20 位；不合法按空串（走草稿）。
     *
     * @param string $itemId
     * @return string
     */
    public static function normalizeItemId($itemId)
    {
        $itemId = trim((string) $itemId);
        if ($itemId === '' || strlen($itemId) > 20 || !preg_match('/^[A-Za-z0-9_\-]+$/', $itemId)) {
            return '';
        }

        return $itemId;
    }

    /**
     * 派生草稿键：同一全局 token 下，不同 prefix（字段）互不可见。
     *
     * 上传、删除、表单提交三处必须使用同一算法；算法变更会导致历史草稿全部失联。
     *
     * @param string $draftToken 全局草稿 token
     * @param string $prefix 字段 DOM 前缀
     * @return string 32 位 md5
     */
    public static function draftKey($draftToken, $prefix)
    {
        return md5((string) $draftToken . '_' . (string) $prefix);
    }
}

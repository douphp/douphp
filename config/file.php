<?php

/**
 * DouPHP
 * ------------------------------------------------------------------------------------
 * 版权所有 2013-2026 漳州豆壳网络科技有限公司，并保留所有权利。
 * 网站地址：http://www.douphp.com
 * ------------------------------------------------------------------------------------
 * 这不是一个自由软件！您只能在遵守授权协议前提下对程序代码进行修改和使用；
 * 不允许对程序代码以任何形式任何目的的再发布。
 * 授权协议：http://www.douphp.com/license.html
 * ------------------------------------------------------------------------------------
 * Author: DouCo Co.,Ltd.
 * Release Date: 2026-09-04
 */

/**
 * 磁盘与上传默认配置（由 Init 合并入 Config）
 *
 * 仅声明无法由 {@see \Dou\Core\Filesystem\FilesystemManager} 命名约定推导的项；
 * 标准模块磁盘按约定自动生成，无须每个模块手写一行：
 *   {module}_icon    → images/{module}/icon/
 *   标准名 [a-z0-9_]+ → images/{name}/
 *
 * 字段说明：
 *   driver           - 驱动名（local；未来 oss / s3 由扩展点注册）
 *   root             - 相对站点根（ROOT_PATH）的磁盘根，结尾自动补 /
 *   url              - 对外 URL 前缀，留空走 ROOT_URL + root
 *   upload_max_kb    - 单文件上传上限（KB）
 *   allow_extensions - 允许的扩展名清单（逗号分隔，小写）
 *   image_quality    - 图片压缩质量（0-100；0 沿用全站 site.quality）
 *   thumb_directory  - 缩略图相对子目录（空表示与原图同目录）
 *
 * 业务侧用法：
 *   Storage::disk('article')->url('123.jpg');
 *   Storage::build('theme/' . $theme . '/images/')->url('logo.png'); // 动态目录
 *   带校验的上传请走 attachment()->store(...) / Validator，勿依赖 Disk::putFile 做门禁。
 */
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

return [
    'filesystems' => [
        'default' => 'local',
        'upload_defaults' => [
            'upload_max_kb' => 2048,
            // 默认不含 svg：SVG 可内嵌脚本，直链访问有存储型 XSS 风险；确需时在 disks.{name} 显式开启
            'allow_extensions' => 'jpg,jpeg,gif,png,webp,ico',
            'image_quality' => 100,
            'thumb_directory' => '',
        ],
        'disks' => [
            // disk 名（local）与路径名（upload）不一致，必须显式声明
            'local' => ['driver' => 'local', 'root' => 'images/upload/'],

            // root 走约定（images/avatar_admin/），仅覆盖上传上限
            'avatar_admin' => ['upload_max_kb' => 100],
        ],
    ],
];

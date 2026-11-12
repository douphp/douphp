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
 * Release Date: 2026-11-11
 */

namespace Dou\Core\Service\Aigc;

use Dou\Core\Filesystem\Storage;
use Dou\Core\Service\Ai\RemoteUrlPolicy;
use Dou\Core\Service\BaseService;
use Dou\Core\Web\Http\Client;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 生成产物落盘（私有磁盘 aigc_works）+ 上游媒体下载。
 *
 * 所有上游 URL 必须经 RemoteUrlPolicy 校验，杜绝 SSRF；文件不对外直链，
 * 前台统一通过 /aigc/file 做归属校验后输出。
 */
class MediaStore extends BaseService
{
    const DISK = 'aigc_works';

    /** 单文件下载上限（字节） */
    const MAX_BYTES = 209715200;

    /**
     * 写入二进制内容。
     *
     * @param string $directory 子目录（image/video/audio/ppt/excel）
     * @param string $extension 扩展名（不含点）
     * @param string $binary
     * @return array {ok, path, error}
     */
    public function putBinary($directory, $extension, $binary)
    {
        $extension = $this->sanitizeExtension($extension);
        if ($binary === '' || $extension === '') {
            return array('ok' => false, 'path' => '', 'error' => 'empty binary or extension');
        }

        $directory = trim((string) $directory, '/');
        $filename = $this->randomName() . '.' . $extension;
        $relative = ($directory !== '' ? $directory . '/' : '') . $filename;

        $ok = Storage::disk(self::DISK)->put($relative, $binary);
        if (!$ok) {
            return array('ok' => false, 'path' => '', 'error' => 'write failed');
        }

        return array('ok' => true, 'path' => $relative, 'error' => '');
    }

    /**
     * 下载上游媒体到私有磁盘。
     *
     * @param string $url
     * @param string $directory
     * @param string $extension 扩展名（可空，按 URL / Content-Type 推断）
     * @param int $maxBytes
     * @return array {ok, path, extension, content_type, error}
     */
    public function download($url, $directory, $extension = '', $maxBytes = self::MAX_BYTES)
    {
        $url = trim((string) $url);
        if ($url === '') {
            return array('ok' => false, 'path' => '', 'extension' => '', 'content_type' => '', 'error' => 'empty url');
        }

        // data: URI 直接解码
        if (strpos($url, 'data:') === 0) {
            if (!preg_match('#^data:([^;]+);base64,(.+)$#s', $url, $matches)) {
                return array('ok' => false, 'path' => '', 'extension' => '', 'content_type' => '', 'error' => 'invalid data uri');
            }
            $binary = base64_decode($matches[2], true);
            if ($binary === false || strlen($binary) > $maxBytes) {
                return array('ok' => false, 'path' => '', 'extension' => '', 'content_type' => '', 'error' => 'data uri too large');
            }
            $ext = $extension !== '' ? $extension : $this->extensionFromMime($matches[1]);
            $put = $this->putBinary($directory, $ext, $binary);

            return array(
                'ok' => $put['ok'],
                'path' => $put['path'],
                'extension' => $ext,
                'content_type' => $matches[1],
                'error' => $put['error'],
            );
        }

        $resolve = RemoteUrlPolicy::resolvePublic($url);
        if ($resolve === false) {
            return array('ok' => false, 'path' => '', 'extension' => '', 'content_type' => '', 'error' => 'url rejected');
        }

        $response = Client::request('GET', $url, array(), array(), array(
            'timeout' => 120,
            'connect_timeout' => 10,
            'max_bytes' => (int) $maxBytes,
            'resolve' => $resolve,
            'return_meta' => true,
        ));
        if (!is_array($response)) {
            return array('ok' => false, 'path' => '', 'extension' => '', 'content_type' => '', 'error' => 'http request failed');
        }

        $httpCode = isset($response['http_code']) ? (int) $response['http_code'] : 0;
        $body = isset($response['body']) ? (string) $response['body'] : '';
        $contentType = isset($response['content_type']) ? strtolower((string) $response['content_type']) : '';
        if ($httpCode !== 200 || $body === '' || !empty($response['too_large'])) {
            return array('ok' => false, 'path' => '', 'extension' => '', 'content_type' => $contentType, 'error' => 'http ' . $httpCode);
        }

        $ext = $extension !== '' ? $extension : $this->extensionFromMime($contentType);
        if ($ext === '') {
            $ext = $this->extensionFromUrl($url);
        }
        if ($ext === '') {
            return array('ok' => false, 'path' => '', 'extension' => '', 'content_type' => $contentType, 'error' => 'unknown extension');
        }

        $put = $this->putBinary($directory, $ext, $body);

        return array(
            'ok' => $put['ok'],
            'path' => $put['path'],
            'extension' => $ext,
            'content_type' => $contentType,
            'error' => $put['error'],
        );
    }

    /**
     * 删除相对文件。
     *
     * @param string $relative
     * @return void
     */
    public function delete($relative)
    {
        $relative = trim((string) $relative, '/');
        if ($relative === '') {
            return;
        }
        Storage::disk(self::DISK)->delete($relative);
    }

    /**
     * 相对路径 → 绝对路径。
     *
     * @param string $relative
     * @return string
     */
    public function absolutePath($relative)
    {
        return (string) Storage::disk(self::DISK)->path(trim((string) $relative, '/'));
    }

    /**
     * 文件是否存在。
     *
     * @param string $relative
     * @return bool
     */
    public function exists($relative)
    {
        $relative = trim((string) $relative, '/');

        return $relative !== '' && Storage::disk(self::DISK)->exists($relative);
    }

    /**
     * 按 Content-Type 推断扩展名。
     *
     * @param string $mime
     * @return string
     */
    private function extensionFromMime($mime)
    {
        $mime = strtolower(trim((string) $mime));
        $map = array(
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'audio/mpeg' => 'mp3',
            'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            'audio/x-wav' => 'wav',
            'audio/ogg' => 'ogg',
            'application/pdf' => 'pdf',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        );

        return isset($map[$mime]) ? $map[$mime] : '';
    }

    /**
     * 按 URL 路径推断扩展名。
     *
     * @param string $url
     * @return string
     */
    private function extensionFromUrl($url)
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $allowed = array('jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm', 'mp3', 'wav', 'ogg');

        return in_array($ext, $allowed, true) ? $ext : '';
    }

    /**
     * 扩展名白名单清洗。
     *
     * @param string $extension
     * @return string
     */
    private function sanitizeExtension($extension)
    {
        $extension = strtolower(trim((string) $extension, '.'));
        if ($extension === 'jpeg') {
            $extension = 'jpg';
        }
        $allowed = array('jpg', 'png', 'gif', 'webp', 'mp4', 'mov', 'webm', 'mp3', 'wav', 'ogg', 'pptx', 'xlsx', 'pdf');

        return in_array($extension, $allowed, true) ? $extension : '';
    }

    /**
     * 随机文件名。
     *
     * @return string
     */
    private function randomName()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(8));
            } catch (\Exception $e) {
                // 继续降级
            }
        }

        return date('YmdHis') . substr(md5(uniqid('', true)), 0, 8);
    }
}

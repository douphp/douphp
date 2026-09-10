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

namespace Dou\Core\Web\Http;

use Dou\Core\Filesystem\FilesystemManager;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 已上传文件对象（封装 `$_FILES` 单字段条目）。
 *
 * 调用方可使用 isValid / extension / getMimeType 等显式方法；
 * 落盘走 Storage::disk($disk)->putFile / putFileAs，或 $file->store($disk, $dir)。
 *
 * 设计仅 PHP 5.6+ 语法（不使用 nullable type / scalar type hint / named arg）。
 */
class UploadedFile
{
    /** @var string 原始客户端文件名 */
    private $originalName;

    /** @var string 浏览器报送的 MIME 类型（不可信，仅参考） */
    private $clientMimeType;

    /** @var string 临时文件路径 */
    private $tmpName;

    /** @var int PHP 上传错误码（UPLOAD_ERR_OK 等） */
    private $errorCode;

    /** @var int 上传文件字节数 */
    private $size;

    /** @var bool 是否真实通过 HTTP 上传（用于 move_uploaded_file 路径） */
    private $isHttpUpload;

    /**
     * @param string $originalName
     * @param string $tmpName
     * @param string $clientMimeType
     * @param int $size
     * @param int $errorCode
     * @param bool $isHttpUpload
     */
    public function __construct($originalName, $tmpName, $clientMimeType = '', $size = 0, $errorCode = UPLOAD_ERR_OK, $isHttpUpload = true)
    {
        $this->originalName = (string) $originalName;
        $this->tmpName = (string) $tmpName;
        $this->clientMimeType = (string) $clientMimeType;
        $this->size = (int) $size;
        $this->errorCode = (int) $errorCode;
        $this->isHttpUpload = (bool) $isHttpUpload;
    }

    /**
     * 从形如 ['name' => ..., 'tmp_name' => ..., 'type' => ..., 'size' => ..., 'error' => ...] 构造。
     *
     * @param array $fileArray
     * @param bool $isHttpUpload
     * @return self
     */
    public static function fromArray(array $fileArray, $isHttpUpload = true)
    {
        return new self(
            isset($fileArray['name']) ? $fileArray['name'] : '',
            isset($fileArray['tmp_name']) ? $fileArray['tmp_name'] : '',
            isset($fileArray['type']) ? $fileArray['type'] : '',
            isset($fileArray['size']) ? (int) $fileArray['size'] : 0,
            isset($fileArray['error']) ? (int) $fileArray['error'] : UPLOAD_ERR_OK,
            $isHttpUpload
        );
    }

    /**
     * 从 $_FILES 全局（或调用方传入的 $newFiles 覆盖）取单个 UploadedFile。
     *
     * 多文件数组（name="xxx[]"，$_FILES[$field]['name'] 为数组）不在本方法处理范围——
     * 走 {@see Request::file()} 或调用方自行展开为多键后再调本方法。
     *
     * @param string $field
     * @param array $newFiles 调用方提供的 $_FILES 覆盖（如 box 上传内部预先展开的多文件数组）
     * @return self|null 字段不存在或为空时返回 null
     */
    public static function fromGlobals($field, array $newFiles = array())
    {
        $source = !empty($newFiles) ? $newFiles : $_FILES;
        if (!isset($source[$field])) {
            return null;
        }
        $entry = $source[$field];
        if (!is_array($entry) || !isset($entry['name'])) {
            return null;
        }
        if (is_array($entry['name'])) {
            // 多文件数组：取首个；多文件批处理走 Request::file()
            $first = array(
                'name' => isset($entry['name'][0]) ? $entry['name'][0] : '',
                'tmp_name' => isset($entry['tmp_name'][0]) ? $entry['tmp_name'][0] : '',
                'type' => isset($entry['type'][0]) ? $entry['type'][0] : '',
                'size' => isset($entry['size'][0]) ? (int) $entry['size'][0] : 0,
                'error' => isset($entry['error'][0]) ? (int) $entry['error'][0] : UPLOAD_ERR_OK,
            );
            if (self::isUnselected($first['name'], $first['tmp_name'], $first['error'])) {
                return null;
            }

            // 非 $_FILES 来源（如 $_NEW_FILES 内部测试）默认非 HTTP 上传
            return self::fromArray($first, $source === $_FILES);
        }

        $name = isset($entry['name']) ? $entry['name'] : '';
        $tmpName = isset($entry['tmp_name']) ? $entry['tmp_name'] : '';
        $error = isset($entry['error']) ? (int) $entry['error'] : UPLOAD_ERR_OK;
        if (self::isUnselected($name, $tmpName, $error)) {
            return null;
        }

        return self::fromArray($entry, $source === $_FILES);
    }

    /**
     * 多文件数组：把 $_FILES[$field]（name 为数组）展开为 UploadedFile 数组。
     *
     * @param string $field
     * @param array $newFiles
     * @return self[]
     */
    public static function allFromGlobals($field, array $newFiles = array())
    {
        $source = !empty($newFiles) ? $newFiles : $_FILES;
        if (!isset($source[$field]) || !is_array($source[$field])) {
            return array();
        }
        $entry = $source[$field];
        if (!isset($entry['name'])) {
            return array();
        }
        if (!is_array($entry['name'])) {
            $single = self::fromArray($entry, $source === $_FILES);

            return $single ? array($single) : array();
        }

        $files = array();
        $total = count($entry['name']);
        for ($i = 0; $i < $total; $i++) {
            $name = isset($entry['name'][$i]) ? $entry['name'][$i] : '';
            $tmp = isset($entry['tmp_name'][$i]) ? $entry['tmp_name'][$i] : '';
            $error = isset($entry['error'][$i]) ? (int) $entry['error'][$i] : UPLOAD_ERR_OK;
            if (self::isUnselected($name, $tmp, $error)) {
                continue;
            }
            $files[] = self::fromArray(array(
                'name' => $name,
                'tmp_name' => $tmp,
                'type' => isset($entry['type'][$i]) ? $entry['type'][$i] : '',
                'size' => isset($entry['size'][$i]) ? (int) $entry['size'][$i] : 0,
                'error' => $error,
            ), $source === $_FILES);
        }

        return $files;
    }

    /**
     * 上传是否成功（无 PHP 错误码且 tmp_name 存在）。
     *
     * @return bool
     */
    public function isValid()
    {
        return $this->errorCode === UPLOAD_ERR_OK && $this->tmpName !== '' && is_file($this->tmpName);
    }

    /**
     * 原始文件名（来自客户端，仅供展示与扩展名提取；不可信任作为路径）。
     *
     * @return string
     */
    public function getClientOriginalName()
    {
        return $this->originalName;
    }

    /**
     * 客户端文件名扩展（小写），无扩展返回 ''。
     *
     * @return string
     */
    public function getClientOriginalExtension()
    {
        $dot = strrpos($this->originalName, '.');
        if ($dot === false) {
            return '';
        }

        return strtolower(substr($this->originalName, $dot + 1));
    }

    /**
     * 与 getClientOriginalExtension 等价的便捷别名。
     *
     * @return string
     */
    public function extension()
    {
        return $this->getClientOriginalExtension();
    }

    /**
     * 浏览器报送的 MIME。
     *
     * @return string
     */
    public function getClientMimeType()
    {
        return $this->clientMimeType;
    }

    /**
     * 取 MIME（优先用 finfo 探测真实 MIME，失败回退到客户端值）。
     *
     * @return string
     */
    public function getMimeType()
    {
        if ($this->tmpName !== '' && function_exists('finfo_open')) {
            $finfo = @finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = @finfo_file($finfo, $this->tmpName);
                @finfo_close($finfo);
                if (is_string($mime) && $mime !== '') {
                    return $mime;
                }
            }
        }

        return $this->clientMimeType;
    }

    /**
     * 字节数。
     *
     * @return int
     */
    public function getSize()
    {
        return $this->size;
    }

    /**
     * KB（向上取整）。
     *
     * @return int
     */
    public function getSizeKb()
    {
        return (int) ceil($this->size / 1024);
    }

    /**
     * tmp 文件路径。
     *
     * @return string
     */
    public function getPathname()
    {
        return $this->tmpName;
    }

    /**
     * 与 getPathname 等价。
     *
     * @return string
     */
    public function getRealPath()
    {
        return $this->tmpName;
    }

    /**
     * 错误码（UPLOAD_ERR_*）。
     *
     * @return int
     */
    public function getError()
    {
        return $this->errorCode;
    }

    /**
     * 是否因 PHP / 表单体积上限被拒绝（此时 tmp 通常为空，isValid 为 false）。
     *
     * @return bool
     */
    public function isSizeLimitError()
    {
        return $this->errorCode === UPLOAD_ERR_INI_SIZE || $this->errorCode === UPLOAD_ERR_FORM_SIZE;
    }

    /**
     * PHP ini 容量（如 512K / 2M / 8）转 KB；-1 或无法解析视为 0（不限制）。
     *
     * @param mixed $value
     * @return int
     */
    public static function iniSizeToKb($value)
    {
        $raw = strtolower(trim((string) $value));
        if ($raw === '' || $raw === '-1') {
            return 0;
        }
        $unit = substr($raw, -1);
        $num = $raw;
        if ($unit === 'g' || $unit === 'm' || $unit === 'k') {
            $num = substr($raw, 0, -1);
        } else {
            $unit = '';
        }
        $bytes = (float) $num;
        if ($bytes < 0) {
            return 0;
        }
        if ($unit === 'g') {
            $bytes *= 1024 * 1024 * 1024;
        } elseif ($unit === 'm') {
            $bytes *= 1024 * 1024;
        } elseif ($unit === 'k') {
            $bytes *= 1024;
        }
        if ($bytes <= 0) {
            return 0;
        }

        return (int) floor($bytes / 1024);
    }

    /**
     * PHP 实际上传上限（KB）：upload_max_filesize 与 post_max_size 取小；任一侧 0 表示不限制。
     *
     * @return int
     */
    public static function phpUploadMaxKb()
    {
        $upload = self::iniSizeToKb(ini_get('upload_max_filesize'));
        $post = self::iniSizeToKb(ini_get('post_max_size'));
        if ($upload <= 0) {
            return $post;
        }
        if ($post <= 0) {
            return $upload;
        }

        return $upload < $post ? $upload : $post;
    }

    /**
     * 把 file_wrong 中的 d% 换成上限 KB。
     *
     * @param int $maxKb
     * @return string
     */
    public static function formatFileWrong($maxKb)
    {
        $kb = (int) $maxKb;
        if ($kb <= 0) {
            $kb = self::phpUploadMaxKb();
        }
        if ($kb <= 0) {
            $kb = 2048;
        }

        return preg_replace('/d%/Ums', (string) $kb, lang('file_wrong'));
    }

    /**
     * 与 store() 超限句对齐：file_out_size + N + KB。
     *
     * @param int $maxKb
     * @return string
     */
    public static function formatFileOutSize($maxKb)
    {
        $kb = (int) $maxKb;
        if ($kb <= 0) {
            $kb = self::phpUploadMaxKb();
        }
        if ($kb <= 0) {
            $kb = 2048;
        }

        return lang('file_out_size') . $kb . 'KB';
    }

    /**
     * 未选文件：error 为 OK 且 name/tmp 为空。超限时 tmp 为空但仍有错误码，不能当没选。
     *
     * @param string $name
     * @param string $tmpName
     * @param int $error
     * @return bool
     */
    private static function isUnselected($name, $tmpName, $error)
    {
        if ((int) $error !== UPLOAD_ERR_OK) {
            return false;
        }

        return $name === '' || $tmpName === '';
    }

    /**
     * 把上传的 tmp 文件移动到指定绝对目录 + 文件名。
     *
     * - 标准 HTTP 上传走 move_uploaded_file（保证安全）
     * - 非 HTTP 上传（fromArray 显式构造）走 rename + 删 tmp
     *
     * @param string $absoluteTargetDir 绝对路径目录
     * @param string $targetBasename 含或不含扩展名的最终文件名（caller 负责定夺）
     * @return bool
     */
    public function move($absoluteTargetDir, $targetBasename)
    {
        $dir = rtrim(str_replace('\\', '/', (string) $absoluteTargetDir), '/');
        // 目标文件名取 basename，杜绝 targetBasename 携带子路径 / `..` 穿越逃出目标目录。
        $name = basename(ltrim((string) $targetBasename, '/'));
        if ($dir === '' || $name === '') {
            return false;
        }
        if (!is_dir($dir)) {
            if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
                return false;
            }
        }
        $target = $dir . '/' . $name;
        if (!$this->isValid()) {
            return false;
        }

        if ($this->isHttpUpload) {
            // 真实 HTTP 上传必须经 move_uploaded_file 校验；is_uploaded_file 失败即拒绝，
            // 不回退到 copy+unlink，避免被伪造的 tmpName 复制任意本地文件落盘。
            if (!is_uploaded_file($this->tmpName)) {
                return false;
            }

            return @move_uploaded_file($this->tmpName, $target);
        }

        // 仅非 HTTP 路径（测试 / box 内部展开）允许 copy+unlink
        if (@copy($this->tmpName, $target)) {
            @unlink($this->tmpName);

            return true;
        }

        return false;
    }

    /**
     * 落到指定磁盘的指定目录（自动生成文件名）。
     *
     * @param string $disk
     * @param string $directory
     * @return string|false 相对磁盘 root 的路径
     */
    public function store($disk, $directory = '')
    {
        return $this->resolveDisk($disk)->putFile($directory, $this);
    }

    /**
     * 落到指定磁盘 + 目录 + 指定文件名。
     *
     * @param string $disk
     * @param string $directory
     * @param string $name
     * @return string|false
     */
    public function storeAs($disk, $directory, $name)
    {
        return $this->resolveDisk($disk)->putFileAs($directory, $this, $name);
    }

    /**
     * 解析磁盘（避免在 helper 未就绪期间硬依赖）。
     *
     * @param string $name
     * @return \Dou\Core\Filesystem\Disk
     */
    private function resolveDisk($name)
    {
        /** @var FilesystemManager $mgr */
        $mgr = app(FilesystemManager::class);

        return $mgr->disk($name);
    }
}

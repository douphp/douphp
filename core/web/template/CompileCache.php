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

namespace Dou\Core\Web\Template;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 编译缓存：负责「源模板 → 编译产物」的落盘路径、重编判定与原子写入。
 *
 * 重编判定（与旧 Smarty 编译缓存等价）：
 *   needsRecompile = forceCompile
 *       OR NOT exists(compilePath)
 *       OR storedCompileRevision != COMPILE_REVISION
 *       OR (compileCheck AND filemtime(source) > filemtime(compilePath))
 *
 * 编译语义或 Prefilter 行为变化时 bump {@see DouView::COMPILE_REVISION}，旧缓存按 rev 自动失效。
 */
class CompileCache
{
    /** @var string 编译产物落盘目录 */
    private $compileDir;

    /** @var bool 强制每次重编 */
    private $forceCompile;

    /** @var bool 检查源模板更新时间 */
    private $compileCheck;

    /** @var string 编译修订号（写入编译头 rev 段，用于失效判定） */
    private $compileRevision;

    /** @var string 产品版本（写入编译头展示，不参与失效判定） */
    private $productVersion;

    /** @var array 请求级编译头 rev 缓存：compilePath => string|null（省去每模板重复 fopen） */
    private static $revisionCache = array();

    /**
     * @param string $compileDir 编译目录
     * @param bool $forceCompile 强制重编
     * @param bool $compileCheck 检查源更新时间
     * @param string $compileRevision 编译修订号
     * @param string $productVersion 产品版本（展示用）
     */
    public function __construct($compileDir, $forceCompile, $compileCheck, $compileRevision, $productVersion = '1.0')
    {
        $this->compileDir = rtrim((string) $compileDir, '/\\');
        $this->forceCompile = (bool) $forceCompile;
        $this->compileCheck = (bool) $compileCheck;
        $this->compileRevision = (string) $compileRevision;
        $this->productVersion = (string) $productVersion;
    }

    /**
     * 取某模板资源名对应的编译产物绝对路径。
     *
     * @param string $resourceName 模板资源名（相对 template_dir）
     * @return string
     */
    public function compilePath($resourceName)
    {
        $resourceName = str_replace('\\', '/', (string) $resourceName);
        $filename = basename($resourceName) . '.php';

        return $this->compileDir . DIRECTORY_SEPARATOR . $filename;
    }

    /**
     * 是否需要重新编译。
     *
     * @param string $sourcePath 源模板绝对路径
     * @param string $compilePath 编译产物绝对路径
     * @return bool
     */
    public function needsRecompile($sourcePath, $compilePath)
    {
        if ($this->forceCompile) {
            return true;
        }
        if (!file_exists($compilePath)) {
            return true;
        }
        if ($this->readStoredRevision($compilePath) !== $this->compileRevision) {
            return true;
        }
        if ($this->compileCheck && @filemtime($sourcePath) > @filemtime($compilePath)) {
            return true;
        }

        return false;
    }

    /**
     * 原子写入编译产物（tempnam + rename，防并发半文件）。带版本头注释。
     *
     * @param string $compilePath 目标路径
     * @param string $compiledContent 编译后的 PHP 源码
     * @return bool
     */
    public function write($compilePath, $compiledContent)
    {
        $dir = dirname($compilePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }

        $header = '<?php /* DouView ' . $this->productVersion
            . ' | rev ' . $this->compileRevision
            . ' | compiled ' . date('Y-m-d H:i:s') . " */ ?>\n";
        $payload = $header . $compiledContent;

        $tmp = @tempnam($dir, 'dv_');
        if ($tmp === false) {
            $ok = @file_put_contents($compilePath, $payload) !== false;
            if ($ok) {
                self::$revisionCache[$compilePath] = $this->compileRevision;
            }
            return $ok;
        }

        if (@file_put_contents($tmp, $payload) === false) {
            @unlink($tmp);
            return false;
        }

        if (file_exists($compilePath)) {
            @unlink($compilePath);
        }
        if (!@rename($tmp, $compilePath)) {
            $ok = @file_put_contents($compilePath, $payload) !== false;
            @unlink($tmp);
            if ($ok) {
                self::$revisionCache[$compilePath] = $this->compileRevision;
            }
            return $ok;
        }
        @chmod($compilePath, 0644);
        self::$revisionCache[$compilePath] = $this->compileRevision;

        return true;
    }

    /**
     * 读取编译头中的 rev（编译修订号）。
     *
     * @param string $compilePath
     * @return string|null
     */
    private function readStoredRevision($compilePath)
    {
        if (array_key_exists($compilePath, self::$revisionCache)) {
            return self::$revisionCache[$compilePath];
        }

        return self::$revisionCache[$compilePath] = $this->parseStoredRevision($compilePath);
    }

    /**
     * 实际打开编译产物解析头部 rev。
     *
     * @param string $compilePath
     * @return string|null
     */
    private function parseStoredRevision($compilePath)
    {
        $fp = @fopen($compilePath, 'rb');
        if ($fp === false) {
            return null;
        }
        $line = fgets($fp, 256);
        fclose($fp);
        if ($line === false) {
            return null;
        }
        if (preg_match('~\|\s*rev\s+([^\s|]+)~', $line, $m)) {
            return $m[1];
        }
        // 旧格式：<?php /* DouView 4 | compiled ...（修订号紧跟 DouView）
        if (preg_match('~^<\?php /\* DouView (\d+) \|~', $line, $m)) {
            return $m[1];
        }

        return null;
    }
}

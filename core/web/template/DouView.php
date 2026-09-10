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

use Dou\Core\Web\Template\Contract\PrefilterContext;
use Dou\Core\Web\Template\Filter\StandardFilters;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * DouView 1.0 — DouPHP 自研编译式模板引擎（运行时主类）。
 *
 * 编译管线：Prefilter → {@see DouViewCompiler}（Lexer → Parser → CodeGenerator），
 * 运行期载体为 {@see RenderContext}（$ctx-> 访问）。业务侧日常通过 {@see \Dou\Core\Facade\View}
 * 门面调用本类。
 *
 * 对外实现 {@see TemplateRendererInterface}（assign + fetch）。编译语义或 Prefilter 行为变化时
 * bump {@see DouView::COMPILE_REVISION}，旧编译缓存按版本头 rev 自动失效重编。
 */
class DouView implements TemplateRendererInterface, PrefilterContext
{
    /** @var string 产品版本（$smarty.version、对外文档） */
    const VERSION = '1.0';

    /** @var string 编译修订号（仅编译缓存失效判据，与产品版本无关） */
    const COMPILE_REVISION = '9';

    /** @var string|array 模板根目录 */
    public $template_dir = 'templates';
    /** @var string 编译产物目录 */
    public $compile_dir = 'templates_c';
    /** @var bool 强制每次重编 */
    public $force_compile = false;
    /** @var bool 检查源更新时间 */
    public $compile_check = true;
    /** @var string 左定界符 */
    public $leftDelimiter = '{';
    /** @var string 右定界符 */
    public $rightDelimiter = '}';
    /** @var bool 全局自动 HTML 转义 */
    public $escapeHtml = false;

    /** @var array 模板变量作用域（render 期与 RenderContext::$vars 引用绑定） */
    public $vars = array();

    /** @var callable|null 前置过滤器 */
    private $prefilter = null;

    /** @var FilterRegistry */
    private $filters;

    /** @var DouViewCompiler|null */
    private $compiler = null;

    /** @var CompileCache|null */
    private $cache = null;

    /** @var RenderContext|null */
    private $context = null;

    /** @var array 模板扩展名白名单 */
    private $allowedExtensions = array('tpl', 'htm', 'html', 'dwt');

    public function __construct()
    {
        $this->filters = new FilterRegistry();
        StandardFilters::registerInto($this->filters);
    }

    /**
     * 给模板上下文写入变量。
     *
     * @param string|array $tpl_var
     * @param mixed|null $value
     * @return void
     */
    public function assign($tpl_var, $value = null)
    {
        if (is_array($tpl_var)) {
            foreach ($tpl_var as $key => $val) {
                if ((string) $key !== '') {
                    $this->vars[$key] = $val;
                }
            }
        } else {
            if ((string) $tpl_var !== '') {
                $this->vars[$tpl_var] = $value;
            }
        }
    }

    /**
     * 读取已 assign 的变量（供 prefilter / Init 在编译前取上下文）。
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function getAssigned($key, $default = null)
    {
        return isset($this->vars[$key]) ? $this->vars[$key] : $default;
    }

    /**
     * 设置全局自动 HTML 转义开关。
     *
     * @param bool $enable
     * @return void
     */
    public function setEscapeHtml($enable = true)
    {
        $this->escapeHtml = (bool) $enable;
        if ($this->compiler !== null) {
            $this->compiler->setEscapeHtml($this->escapeHtml);
        }
    }

    /**
     * 注册前置过滤器（编译前对源做变换）。callable 形如 function ($source, PrefilterContext $ctx) {}。
     *
     * @param callable $function
     * @return void
     */
    public function registerPrefilter($function)
    {
        $this->prefilter = $function;
    }

    /**
     * 对模板源执行已注册的前置过滤器。
     *
     * @param string $source
     * @return string
     */
    public function runPrefilter($source)
    {
        if ($this->prefilter && is_callable($this->prefilter)) {
            return call_user_func($this->prefilter, $source, $this);
        }

        return $source;
    }

    /**
     * 渲染模板为 HTML 字符串。
     *
     * @param string $template 模板资源名
     * @return string
     */
    public function fetch($template)
    {
        $old_error_level = error_reporting(error_reporting() & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_NOTICE & ~E_USER_WARNING & ~E_USER_DEPRECATED);

        $ctx = $this->getContext();
        $ctx->vars = &$this->vars;
        $ctx->resetLoopState();

        ob_start();
        $this->renderResource($template);
        $result = ob_get_clean();

        error_reporting($old_error_level);

        return $result;
    }

    /**
     * 渲染模板（直接输出到当前缓冲）。
     *
     * @param string $template
     * @return void
     */
    public function display($template)
    {
        echo $this->fetch($template);
    }

    /**
     * 解析、按需编译并 include 模板编译产物（输出到当前缓冲）。
     *
     * @param string $resourceName 模板资源名（相对 template_dir）
     * @return void
     */
    public function renderResource($resourceName)
    {
        $sourcePath = $this->resolveTemplatePath($resourceName);
        if ($sourcePath === false) {
            return;
        }

        $cache = $this->getCache();
        $compilePath = $cache->compilePath($resourceName);

        if ($cache->needsRecompile($sourcePath, $compilePath)) {
            $source = file_get_contents($sourcePath);
            $compiled = $this->compileSource($resourceName, $source);
            $cache->write($compilePath, $compiled);
        }

        $ctx = $this->getContext();
        include $compilePath;
    }

    /**
     * 编译模板源为 PHP（Prefilter → {@see DouViewCompiler}）。
     *
     * @param string $resourceName 资源名（错误定位 + $smarty.template）
     * @param string $source 模板源
     * @return string
     */
    public function compileSource($resourceName, $source)
    {
        $source = $this->runPrefilter($source);

        return $this->getCompiler()->compile($resourceName, $source);
    }

    /**
     * 解析模板资源名到绝对路径（含路径遍历/扩展名白名单/越界校验）。
     *
     * @param string $resourceName
     * @return string|false
     */
    private function resolveTemplatePath($resourceName)
    {
        $resourceName = (string) $resourceName;

        if (strpos($resourceName, '..') !== false || strpos($resourceName, "\0") !== false) {
            return false;
        }
        $decoded = urldecode($resourceName);
        if (strpos($decoded, '..') !== false || strpos($decoded, "\0") !== false) {
            return false;
        }

        $pathInfo = pathinfo($resourceName);
        if (!isset($pathInfo['extension']) || !in_array(strtolower($pathInfo['extension']), $this->allowedExtensions)) {
            return false;
        }

        if (preg_match('/^[\/\\\\]|^[a-zA-Z]:|[\/]{2,}|[\\\\]{2,}/', $resourceName)) {
            return false;
        }

        foreach ((array) $this->template_dir as $dir) {
            $fullpath = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $resourceName;
            if (file_exists($fullpath) && is_file($fullpath)) {
                $realPath = realpath($fullpath);
                $realDir = realpath($dir);
                // 前缀比较带目录分隔符，防同前缀兄弟目录（如 theme 与 theme_bak）误通过
                if ($realPath !== false && $realDir !== false
                    && strpos($realPath, rtrim($realDir, '/\\') . DIRECTORY_SEPARATOR) === 0
                ) {
                    return $fullpath;
                }
            }
        }

        return false;
    }

    /**
     * @return DouViewCompiler
     */
    private function getCompiler()
    {
        if ($this->compiler === null) {
            $this->compiler = new DouViewCompiler($this->escapeHtml, $this->leftDelimiter, $this->rightDelimiter);
        }

        return $this->compiler;
    }

    /**
     * @return RenderContext
     */
    private function getContext()
    {
        if ($this->context === null) {
            $this->context = new RenderContext($this, $this->filters);
        }

        return $this->context;
    }

    /**
     * @return CompileCache
     */
    private function getCache()
    {
        if ($this->cache === null) {
            $this->cache = new CompileCache(
                $this->compile_dir,
                $this->force_compile,
                $this->compile_check,
                self::COMPILE_REVISION,
                self::VERSION
            );
        }

        return $this->cache;
    }
}

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
 * 渲染上下文：编译产物运行期的唯一载体（编译产物以 $ctx-> 访问）。
 *
 * 承载模板作用域与循环/区段运行期数据，并提供运行期能力（修饰器分发、foreach @属性取值、
 * 子模板包含）。运行态从引擎门面剥离到本类，编译产物以 $ctx 为唯一载体。
 */
class RenderContext
{
    /** @var int 子模板包含深度上限（防递归自包含耗尽调用栈） */
    const MAX_INCLUDE_DEPTH = 64;

    /** @var array 模板变量作用域 */
    public $vars = array();
    /** @var array {foreach} 运行期数据（loop name => array('total','iteration')） */
    public $loops = array();
    /** @var array item 变量名 => 循环名映射（@属性支持） */
    public $loopVarmap = array();

    /** @var DouView 宿主引擎（子模板渲染回调） */
    private $engine;
    /** @var FilterRegistry 修饰器注册表 */
    private $filters;
    /** @var int 当前子模板包含深度 */
    private $includeDepth = 0;

    /**
     * @param DouView $engine 宿主引擎
     * @param FilterRegistry $filters 修饰器注册表
     */
    public function __construct(DouView $engine, FilterRegistry $filters)
    {
        $this->engine = $engine;
        $this->filters = $filters;
    }

    /**
     * 向作用域写入变量（{assign} / {include assign} 运行期入口）。
     *
     * @param string|array $name 变量名；为 array 时按 key => value 批量写入
     * @param mixed|null $value 当 $name 为字符串时使用
     * @return void
     */
    public function set($name, $value = null)
    {
        if (is_array($name)) {
            foreach ($name as $k => $v) {
                if ((string) $k !== '') {
                    $this->vars[$k] = $v;
                }
            }
        } elseif ((string) $name !== '') {
            $this->vars[$name] = $value;
        }
    }

    /**
     * 每次顶层 fetch 前清空循环运行态，避免同引擎实例连续渲染时残留 loopVarmap。
     *
     * @return void
     */
    public function resetLoopState()
    {
        $this->loops = array();
        $this->loopVarmap = array();
    }

    /**
     * 修饰器分发：首参为修饰器名，次参为待处理值，其余为冒号参数。
     *
     * @param string $name 修饰器名
     * @param mixed $value 待处理值
     * @return mixed
     */
    public function filter($name, $value)
    {
        $args = func_get_args();
        array_shift($args);
        $callable = $this->filters->get($name);
        if ($callable === null) {
            return $value;
        }

        return call_user_func_array($callable, $args);
    }

    /**
     * foreach @属性运行期取值（{$item@iteration} 等）。
     *
     * @param string $var_name 循环 item 变量名
     * @param string $property iteration/index/total/first/last/show
     * @return mixed
     */
    public function loopProp($var_name, $property)
    {
        if (!isset($this->loopVarmap[$var_name])) {
            return null;
        }
        $loop_name = $this->loopVarmap[$var_name];
        if (!isset($this->loops[$loop_name])) {
            return null;
        }
        $loop_data = $this->loops[$loop_name];

        switch ($property) {
            case 'iteration':
                return $loop_data['iteration'];
            case 'index':
                return $loop_data['iteration'] - 1;
            case 'total':
                return $loop_data['total'];
            case 'first':
                return $loop_data['iteration'] <= 1;
            case 'last':
                return $loop_data['iteration'] == $loop_data['total'];
            case 'show':
                return $loop_data['total'] > 0;
            default:
                return null;
        }
    }

    /**
     * 子模板包含：保存当前作用域，合并包含变量，渲染子模板，再恢复。
     *
     * 包含变量仅在子模板可见，不泄漏回父作用域；指定 $assignVar 时把子模板输出捕获后
     * 写入父变量而非直接输出。
     *
     * @param string $file 子模板资源名（相对 template_dir）
     * @param array $vars 传入子模板的变量
     * @param string|null $assignVar 输出捕获目标变量名（null 表示直接输出）
     * @return void
     */
    public function includeTemplate($file, array $vars = array(), $assignVar = null)
    {
        if ($this->includeDepth >= self::MAX_INCLUDE_DEPTH) {
            throw new \RuntimeException(
                'DouView: include depth exceeds ' . self::MAX_INCLUDE_DEPTH
                . " (possible recursive include of '$file')"
            );
        }

        if ($assignVar !== null) {
            ob_start();
        }

        $saved = $this->vars;
        $this->vars = array_merge($this->vars, $vars);
        $this->includeDepth++;
        try {
            $this->engine->renderResource($file);
        } finally {
            $this->includeDepth--;
            $this->vars = $saved;
        }

        if ($assignVar !== null) {
            $this->set($assignVar, ob_get_clean());
        }
    }
}

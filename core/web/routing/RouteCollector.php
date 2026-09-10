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

namespace Dou\Core\Web\Routing;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 声明式路由 fluent API 的累积器
 *
 * 每个路由文件 include 期间由 RouteManifestBuilder 安装一个 collector 实例，
 * 通过 {@see Route} 静态门面把 fluent 调用转译出来的 {@see RouteEntry} 推入本累积器；
 * include 结束后 {@see flush} 取出条目，append 到 manifest declared 段。
 *
 * 与 RouteManifestBuilder::buildDeclared 现有的「路由文件 return RouteEntry[] / 字段数组」
 * 契约并存：fluent 形态走副作用累积，return 形态走 builder 内置 normalization；二者
 * 在同一文件内可以混用；front/route 以 fluent 形态注册为主。
 */
class RouteCollector
{
    /** @var string 路由文件绝对路径（构造时传入） */
    private $file;

    /** @var string 相对仓库根的正斜杠路径，例 'front/route/health.php' */
    private $relPath;

    /** @var RouteEntry[] 累积的条目，按 fluent 调用顺序保留 */
    private $entries = array();

    /**
     * 组属性栈：每个 {@see RouteGroupRegistrar::group} 在执行回调前入栈、之后出栈。
     *
     * 每项形如 ['name' => 'api.', 'composite' => bool]。嵌套组的 name 前缀按入栈顺序
     * 拼接（{@see currentNamePrefix}），composite 任一层为真即生效（{@see currentComposite}）。
     *
     * @var array<int, array{name: string, composite: bool}>
     */
    private $groupStack = array();

    /**
     * @param string $file 路由文件绝对路径
     */
    public function __construct($file)
    {
        $this->file = (string) $file;
        $this->relPath = $this->deriveRelPath($this->file);
    }

    /**
     * 入栈一组组属性（由 {@see RouteGroupRegistrar::group} 在执行回调前调用）。
     *
     * @param array{name?: string, composite?: bool} $attrs
     * @return void
     */
    public function pushGroupAttributes(array $attrs)
    {
        $this->groupStack[] = array(
            'name' => isset($attrs['name']) ? (string) $attrs['name'] : '',
            'composite' => !empty($attrs['composite']),
        );
    }

    /**
     * 出栈最近一组组属性（由 {@see RouteGroupRegistrar::group} 在回调结束后调用）。
     *
     * @return void
     */
    public function popGroupAttributes()
    {
        array_pop($this->groupStack);
    }

    /**
     * 当前组栈累积的 name 前缀（按入栈顺序拼接；空栈为空串）。
     *
     * @return string
     */
    public function currentNamePrefix()
    {
        $prefix = '';
        foreach ($this->groupStack as $frame) {
            $prefix .= $frame['name'];
        }
        return $prefix;
    }

    /**
     * 当前组栈是否开启 compositeModule（任一层为真即真）。
     *
     * @return bool
     */
    public function currentComposite()
    {
        foreach ($this->groupStack as $frame) {
            if ($frame['composite']) {
                return true;
            }
        }
        return false;
    }

    /**
     * 取相对仓库根的正斜杠路径，用于 RouteEntry::$source 的字串拼装。
     *
     * @return string
     */
    public function relPath()
    {
        return $this->relPath;
    }

    /**
     * 拼装一条 declared 条目的 source 字串：'declared:<rel>:<name>'。
     *
     * 与 RouteManifestBuilder::buildDeclared 字段数组分支的兜底行为字节一致：
     * 旧路由文件里手抄 'declared:front/route/health.php:health.user' 与本方法产出相同。
     *
     * @param string $name 路由名
     * @return string
     */
    public function sourceFor($name)
    {
        return 'declared:' . $this->relPath . ':' . (string) $name;
    }

    /**
     * push 一条已完整构造的 RouteEntry 到累积器。
     *
     * 调用方由 {@see RouteGroupBuilder} 在 action() 终结时统一构造；本类不参与字段语义，
     * 仅做有序累积与最终 flush。
     *
     * @param RouteEntry $entry
     * @return void
     */
    public function push(RouteEntry $entry)
    {
        $this->entries[] = $entry;
    }

    /**
     * 取出全部累积条目并清空累积器。
     *
     * 一个 collector 实例只对应一个路由文件的 include 周期；flush 后不再复用。
     *
     * @return RouteEntry[]
     */
    public function flush()
    {
        $out = $this->entries;
        $this->entries = array();
        return $out;
    }

    /**
     * 推导路由文件相对仓库根的正斜杠路径。
     *
     * ROOT_PATH 在三端入口与 devtools/* 启动时一定 defined（admin/api/front 入口都 require
     * core/autoload.php 之前先 define ROOT_PATH）。极端 fallback：直接返回 basename。
     *
     * @param string $absPath
     * @return string
     */
    private function deriveRelPath($absPath)
    {
        $normalized = str_replace('\\', '/', $absPath);
        if (defined('ROOT_PATH')) {
            $root = str_replace('\\', '/', rtrim(ROOT_PATH, '/\\')) . '/';
            if (strpos($normalized, $root) === 0) {
                return substr($normalized, strlen($root));
            }
        }
        return basename($normalized);
    }
}

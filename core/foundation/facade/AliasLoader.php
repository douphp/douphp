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

namespace Dou\Core\Foundation\Facade;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 惰性根命名空间别名加载器。
 *
 * 将一组「根命名空间短名 => 目标 FQCN」的映射延迟到首次解析时，
 * 通过 {@see class_alias()} 把目标类暴露在根命名空间下；业务侧
 * 因此可以直接以 `\DB::table(...)`、`\Arr::get(...)` 这类短名调用，
 * 无需在文件顶部 `use Dou\Core\Facade\DB;`。
 *
 * 设计要点：
 * - 单一 spl_autoload_register 入口，且以 `prepend=false` 注册在队列末尾，
 *   确保 {@see core/autoload.php} 的 `Dou\*` 解析器优先生效。
 * - 命中映射时 class_alias 一次即生效；未命中静默返回 false，让 PHP
 *   继续沿用「Class not found」标准行为，不抛错。
 * - 单例：getInstance() 支持「追加合并」额外别名，方便插件 / 模块在
 *   后续启动阶段补充自己的短名。
 */
class AliasLoader
{
    /**
     * 别名映射表：alias => target FQCN
     *
     * @var array<string, string>
     */
    private $aliases = array();

    /**
     * 是否已向 SPL 队列注册过 load 回调
     *
     * @var bool
     */
    private $registered = false;

    /**
     * 单例
     *
     * @var self|null
     */
    private static $instance = null;

    /**
     * @param array<string, string> $aliases
     */
    private function __construct(array $aliases)
    {
        $this->aliases = $aliases;
    }

    /**
     * 获取单例；若已存在则将传入的 $aliases 追加合并到现有映射。
     *
     * @param array<string, string> $aliases
     * @return self
     */
    public static function getInstance(array $aliases = array())
    {
        if (self::$instance === null) {
            self::$instance = new self($aliases);
            return self::$instance;
        }

        if (!empty($aliases)) {
            self::$instance->aliases = array_merge(self::$instance->aliases, $aliases);
        }

        return self::$instance;
    }

    /**
     * 向 SPL 自动加载队列末尾注册 load 回调；幂等。
     *
     * @return void
     */
    public function register()
    {
        if ($this->registered) {
            return;
        }

        spl_autoload_register(array($this, 'load'), true, false);
        $this->registered = true;
    }

    /**
     * SPL 回调：命中映射时以 class_alias 将目标类挂为根命名空间短名。
     *
     * @param string $alias
     * @return bool
     */
    public function load($alias)
    {
        if (isset($this->aliases[$alias])) {
            return class_alias($this->aliases[$alias], $alias);
        }

        return false;
    }

    /**
     * 运行期追加单个别名（已注册到 SPL 的回调引用同一份 $aliases，无需重注册）。
     *
     * @param string $alias 根命名空间短名（不带前导反斜杠）
     * @param string $target 目标 FQCN
     * @return void
     */
    public function addAlias($alias, $target)
    {
        $this->aliases[(string) $alias] = (string) $target;
    }

    /**
     * 返回当前已登记的别名映射（主要供测试 / 调试）。
     *
     * @return array<string, string>
     */
    public function getAliases()
    {
        return $this->aliases;
    }
}

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
 * 声明式路由中间件 fluent DSL（被 RouteEntryBuilder / RouteResourceBuilder / RouteGroupBuilder 复用）
 *
 * 承载「在各端全局默认中间件栈之上的路由级细化」四种语义：
 *   - middleware([...])          追加：在默认栈末尾追加额外中间件别名（含参数如 'throttle:5,60'）
 *   - withoutMiddleware([...])   豁免：从默认栈中按别名过滤掉对应中间件实例
 *   - 参数糖 throttle/auth        覆盖：给默认栈中对应中间件实例传参（新建独占实例）
 *   - skipAllMiddleware()        跳全链：装空中间件链
 *
 * 参数糖的键必须与目标端的中间件别名一致，且对应中间件须实现 ParameterizedMiddleware；
 * 有效性由 devtools/route-audit.php 静态校验，不做请求期告警。
 *
 * 各 builder 把这些字段经 {@see middlewareFields} 一次写入它展开的每条 RouteEntry。
 */
trait RouteMiddlewareDsl
{
    /** @var string[] 路由级追加中间件别名 */
    protected $mwAppend = array();

    /** @var string[] 路由级豁免中间件别名 */
    protected $mwWithout = array();

    /** @var array<string,string> 路由级中间件参数覆盖（别名 => 参数串） */
    protected $mwParams = array();

    /** @var bool 路由级跳过全部中间件 */
    protected $mwSkipAll = false;

    /**
     * 追加中间件（在默认栈之上末尾追加）。支持 'alias' 或 'alias:p1,p2' 参数形态。
     *
     * @param string|string[] $aliases
     * @return $this
     */
    public function middleware($aliases)
    {
        foreach ((array) $aliases as $a) {
            $a = (string) $a;
            if ($a !== '') {
                $this->mwAppend[] = $a;
            }
        }
        return $this;
    }

    /**
     * 从默认栈中豁免（过滤）指定中间件别名。
     *
     * @param string|string[] $names
     * @return $this
     */
    public function withoutMiddleware($names)
    {
        foreach ((array) $names as $n) {
            $n = (string) $n;
            if ($n !== '') {
                $this->mwWithout[] = $n;
            }
        }
        return $this;
    }

    /**
     * 跳过全部中间件（装空链）。与 middleware/withoutMiddleware 互斥，优先级最高。
     *
     * @return $this
     */
    public function skipAllMiddleware()
    {
        $this->mwSkipAll = true;
        return $this;
    }

    /**
     * 参数糖：覆盖该路由的限流配额（仅 front / api 有 throttle 别名）。
     *
     * @param int $max 窗口内最大次数
     * @param int $window 窗口秒数
     * @return $this
     */
    public function throttle($max, $window = 60)
    {
        $this->mwParams['throttle'] = (string) (int) $max . ',' . (string) (int) $window;
        return $this;
    }

    /**
     * 参数糖：覆盖该路由的会员鉴权模式（仅 front / api 生效；admin 认证由默认栈承担）。
     *
     * @param string $mode 'public' | 'optional' | 'required' 等
     * @return $this
     */
    public function auth($mode)
    {
        $this->mwParams['user_auth'] = (string) $mode;
        return $this;
    }

    /**
     * 汇总中间件相关字段，供 builder 写入每条展开的 RouteEntry。
     *
     * @return array
     */
    protected function middlewareFields()
    {
        return array(
            'middleware' => $this->mwAppend,
            'without_middleware' => $this->mwWithout,
            'middleware_params' => $this->mwParams,
            'skip_all_middleware' => $this->mwSkipAll,
        );
    }
}

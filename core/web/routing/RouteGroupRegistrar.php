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
 * 组级属性注册器（Laravel 风格的 `Route::name('api.')->group(callable)`）
 *
 * 由 {@see Route::name()} / {@see Route::compositeModule()} 创建，链式收集组级
 * name 前缀与 compositeModule 标记，终结调用 {@see group} 时把属性入栈到当前
 * {@see RouteCollector}、执行声明回调、再出栈。组内的 resource / group / 单 verb
 * builder 在 register 时读取栈顶继承这些属性。
 *
 * 用法：
 *   Route::name('api.')->group(function () {
 *       Route::resource('book', BookController::class)->only(['index', 'show']);
 *   });
 *
 *   Route::name('admin.')->compositeModule()->group(function () {
 *       Route::resource('article', CategoryController::class)
 *           ->prefix('article/category')->sub('category'); // routeModule => article_category
 *   });
 */
class RouteGroupRegistrar
{
    /** @var RouteCollector */
    private $collector;

    /** @var string 组级 name 前缀（尾点表示前缀） */
    private $namePrefix = '';

    /** @var bool 组级 compositeModule 标记 */
    private $compositeModule = false;

    /**
     * @param RouteCollector $collector 当前路由文件累积器
     */
    public function __construct(RouteCollector $collector)
    {
        $this->collector = $collector;
    }

    /**
     * 设置组级 name 前缀（叠加到组内每条路由的默认 localBase）。
     *
     * @param string $prefix 如 'api.' / 'admin.'
     * @return self
     */
    public function name($prefix)
    {
        $this->namePrefix = (string) $prefix;
        return $this;
    }

    /**
     * 开启组级 compositeModule：组内凡有 ->sub() 的路由 routeModule 取复合名 module_sub。
     *
     * @return self
     */
    public function compositeModule()
    {
        $this->compositeModule = true;
        return $this;
    }

    /**
     * 终结：把组属性入栈，执行声明回调，再出栈。
     *
     * @param callable $routes 组内路由声明回调（无参）
     * @return void
     */
    public function group(callable $routes)
    {
        $this->collector->pushGroupAttributes(array(
            'name' => $this->namePrefix,
            'composite' => $this->compositeModule,
        ));
        try {
            $routes();
        } catch (\Exception $e) {
            $this->collector->popGroupAttributes();
            throw $e;
        }
        $this->collector->popGroupAttributes();
    }
}

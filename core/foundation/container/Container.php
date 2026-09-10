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

namespace Dou\Core\Foundation\Container;

use Dou\Core\Web\Http\FormRequest;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 轻量级 DI 容器（PHP 5.6+ 兼容）
 *
 * 支持：
 *   - 绑定接口到实现（含别名）
 *   - 单例注册（含 instance() 直注）
 *   - 构造函数自动注入（反射解析参数类型，附完整依赖链报错）
 *   - 工厂闭包绑定
 *   - 上下文绑定 when()->needs()->give()（按消费者类切换实现）
 *   - 状态查询 has/bound/resolved 与 reset 重置（用于测试隔离）
 */
class Container
{
    /** @var Container 全局单例 */
    private static $instance;

    /** @var array 绑定注册表 */
    private $bindings = array();

    /** @var array 单例实例缓存 */
    private $singletons = array();

    /** @var array 工厂闭包注册表 */
    private $factories = array();

    /** @var array 上下文绑定 [consumer][abstract] => concrete|callable */
    private $contextualBindings = array();

    /** @var array 别名映射 [alias] => target */
    private $aliases = array();

    /** @var string[] 当前构建栈，栈顶为正在构建的消费者类名 */
    private $buildStack = array();

    /**
     * 获取全局容器实例。
     *
     * @return static
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new static();
        }
        return self::$instance;
    }

    /**
     * 替换全局单例（仅供测试或托管启动使用）。
     *
     * @param Container|null $container
     * @return void
     */
    public static function setInstance($container = null)
    {
        // 隐式 nullable 兼容（5.6 无 ?Type、8.4 弃用隐式 nullable）：去类型提示，体内守护 Container|null。
        self::$instance = ($container instanceof Container) ? $container : null;
    }

    /**
     * 重置容器内部状态（清空全部绑定/单例/工厂/上下文/别名/构建栈）。
     * 主要供测试隔离与重启场景使用。
     *
     * @return void
     */
    public function reset()
    {
        $this->bindings = array();
        $this->singletons = array();
        $this->factories = array();
        $this->contextualBindings = array();
        $this->aliases = array();
        $this->buildStack = array();
    }

    /**
     * 绑定抽象到具体类（每次解析都创建新实例）。
     *
     * @param string $abstract 抽象类/接口/别名
     * @param string|null $concrete 具体类名（null 则 $abstract 即为具体类）
     */
    public function bind($abstract, $concrete = null)
    {
        $this->bindings[$abstract] = $concrete !== null ? $concrete : $abstract;
    }

    /**
     * 注册单例绑定（首次解析后缓存实例）。
     *
     * @param string $abstract
     * @param string|null $concrete
     */
    public function singleton($abstract, $concrete = null)
    {
        $this->bind($abstract, $concrete);
        // 标记为单例
        $this->singletons[$abstract] = null;
        $this->bindings[$abstract . '_singleton'] = true;
    }

    /**
     * 用工厂闭包注册绑定。
     *
     * 语义：「最后写入者胜出」——本次注册会覆盖先前对 $abstract 的 instance/单例缓存，
     * 后续 make() 将每次调用本工厂闭包，直至再被 instance() 覆盖。
     *
     * @param string $abstract
     * @param callable $factory 工厂闭包，接收容器实例作为参数
     */
    public function factory($abstract, $factory)
    {
        // 工厂覆盖先前绑定：清掉同 abstract 的实例缓存与单例标记，避免 make() 走原分支
        unset($this->singletons[$abstract]);
        unset($this->bindings[$abstract . '_singleton']);
        $this->factories[$abstract] = $factory;
    }

    /**
     * 注册已有实例为单例。
     *
     * 语义：「最后写入者胜出」——本次注册会覆盖先前对 $abstract 的 factory 绑定，
     * 之后 make($abstract) 始终返回此实例，直至再被 factory() 覆盖。
     *
     * @param string $abstract
     * @param mixed $instance
     */
    public function instance($abstract, $instance)
    {
        // 实例覆盖先前绑定：清掉同 abstract 的工厂，避免 make() 仍走原工厂返回他实例
        unset($this->factories[$abstract]);
        $this->singletons[$abstract] = $instance;
        $this->bindings[$abstract . '_singleton'] = true;
    }

    /**
     * 注册别名：解析 $alias 时直接转发到 $target。
     *
     * @param string $alias
     * @param string $target
     * @return void
     */
    public function alias($alias, $target)
    {
        $this->aliases[$alias] = $target;
    }

    /**
     * 容器是否注册了 $abstract（绑定/工厂/单例/别名其一）。
     *
     * @param string $abstract
     * @return bool
     */
    public function has($abstract)
    {
        if (isset($this->aliases[$abstract])) {
            return $this->has($this->aliases[$abstract]);
        }
        return isset($this->bindings[$abstract])
            || isset($this->factories[$abstract])
            || array_key_exists($abstract, $this->singletons);
    }

    /**
     * has() 的语义别名，便于阅读。
     *
     * @param string $abstract
     * @return bool
     */
    public function bound($abstract)
    {
        return $this->has($abstract);
    }

    /**
     * 单例是否已实例化。
     *
     * @param string $abstract
     * @return bool
     */
    public function resolved($abstract)
    {
        if (isset($this->aliases[$abstract])) {
            return $this->resolved($this->aliases[$abstract]);
        }
        return isset($this->singletons[$abstract]) && $this->singletons[$abstract] !== null;
    }

    /**
     * 移除指定单例实例（保留绑定，下次解析重建）。
     *
     * @param string $abstract
     * @return void
     */
    public function forgetInstance($abstract)
    {
        if (isset($this->aliases[$abstract])) {
            $abstract = $this->aliases[$abstract];
        }
        unset($this->singletons[$abstract]);
    }

    /**
     * 开始一段上下文绑定声明：when($consumer)->needs($abstract)->give($concrete)。
     *
     * 上下文绑定仅作用于 $consumer 类（通常是控制器/服务）的构造函数依赖解析；
     * 不影响其它消费者，也不影响普通 bind/singleton 注册。
     *
     * @param string $consumer 消费者类全限定名
     * @return ContextualBindingBuilder
     */
    public function when($consumer)
    {
        return new ContextualBindingBuilder($this, $consumer);
    }

    /**
     * 由 ContextualBindingBuilder 回调写入上下文绑定。
     *
     * @internal
     * @param string $consumer
     * @param string $abstract
     * @param string|callable $concrete
     * @return void
     */
    public function addContextualBinding($consumer, $abstract, $concrete)
    {
        if (!isset($this->contextualBindings[$consumer])) {
            $this->contextualBindings[$consumer] = array();
        }
        $this->contextualBindings[$consumer][$abstract] = $concrete;
    }

    /**
     * 解析并创建实例（自动注入构造函数依赖）。
     *
     * @param string $abstract 类名或已注册的绑定
     * @param array $parameters 额外构造函数参数（key => value）
     * @return mixed
     */
    public function make($abstract, $parameters = array())
    {
        // 0. 别名转发
        if (isset($this->aliases[$abstract])) {
            return $this->make($this->aliases[$abstract], $parameters);
        }

        // 1. 如果是工厂，调用工厂
        if (isset($this->factories[$abstract])) {
            return call_user_func($this->factories[$abstract], $this);
        }

        // 2. 如果是单例且已缓存，直接返回
        $is_singleton = isset($this->bindings[$abstract . '_singleton']) &&
                        $this->bindings[$abstract . '_singleton'];
        if ($is_singleton && array_key_exists($abstract, $this->singletons) &&
            $this->singletons[$abstract] !== null) {
            return $this->singletons[$abstract];
        }

        // 3. 解析具体类名
        $concrete = isset($this->bindings[$abstract]) ? $this->bindings[$abstract] : $abstract;

        // 4. 用反射解析构造函数
        $instance = $this->build($concrete, $parameters);

        // 5. 如果是单例，缓存
        if ($is_singleton) {
            $this->singletons[$abstract] = $instance;
        }

        return $instance;
    }

    /**
     * 调用对象方法并自动注入方法参数依赖。
     *
     * @param object $instance
     * @param string $method
     * @param array $contextOverrides 方法级参数覆盖（如 __scene）
     * @return mixed
     */
    public function call($instance, $method, $contextOverrides = array())
    {
        $reflector = new \ReflectionMethod($instance, $method);
        $dependencies = $this->resolveDependencies($reflector->getParameters(), $contextOverrides);
        return $reflector->invokeArgs($instance, $dependencies);
    }

    /**
     * 用反射实例化类并自动注入构造函数依赖。
     *
     * @param string $concrete
     * @param array $parameters
     * @return mixed
     */
    private function build($concrete, $parameters = array())
    {
        $reflector = new \ReflectionClass($concrete);

        if (!$reflector->isInstantiable()) {
            throw new \RuntimeException("[$concrete] is not instantiable.");
        }

        $constructor = $reflector->getConstructor();

        // 无构造函数，直接 new
        if ($constructor === null) {
            return new $concrete();
        }

        // 入栈当前消费者，供 resolveDependencies 查 contextual map；try/finally 保证
        // 无论正常返回还是抛 \Exception / \Error（如依赖类型不匹配的 TypeError）都会出栈，
        // 避免 buildStack 残留污染后续解析的 contextual map。
        $this->buildStack[] = $concrete;
        try {
            $dependencies = $this->resolveDependencies($constructor->getParameters(), $parameters);
        } finally {
            array_pop($this->buildStack);
        }

        return $reflector->newInstanceArgs($dependencies);
    }

    /**
     * 解析构造函数参数列表。
     *
     * @param \ReflectionParameter[] $params
     * @param array $overrides 手动传入的参数覆盖
     * @return array
     */
    private function resolveDependencies($params, $overrides = array())
    {
        $dependencies = array();

        foreach ($params as $param) {
            $name = $param->getName();

            // 优先使用手动传入的参数
            if (array_key_exists($name, $overrides)) {
                $dependencies[] = $overrides[$name];
                continue;
            }

            // 获取类型提示
            $class = $this->getParamClass($param);

            if ($class !== null) {
                $className = $class->getName();

                // 上下文绑定优先：当前栈顶消费者若声明过 needs($className)->give(X)，按 X 解析。
                $contextual = $this->resolveContextual($className);
                if ($contextual !== null) {
                    $dependencies[] = is_callable($contextual)
                        ? call_user_func($contextual, $this)
                        : $this->make($contextual);
                    continue;
                }

                if (is_subclass_of($className, FormRequest::class)) {
                    $scene = isset($overrides['__scene']) ? (string) $overrides['__scene'] : '';
                    $dependencies[] = $this->make($className, array('scene' => $scene));
                } else {
                    // 递归解析依赖类
                    $dependencies[] = $this->make($className);
                }
            } elseif ($param->isDefaultValueAvailable()) {
                $dependencies[] = $param->getDefaultValue();
            } elseif ($param->allowsNull()) {
                $dependencies[] = null;
            } else {
                $chain = !empty($this->buildStack) ? ' (chain: ' . implode(' -> ', $this->buildStack) . ')' : '';
                throw new \RuntimeException(
                    "Cannot resolve parameter [\$$name] in [{$param->getDeclaringClass()->getName()}]" . $chain . '.'
                );
            }
        }

        return $dependencies;
    }

    /**
     * PHP 5.6 / 8.0 兼容地获取参数的类型类（ReflectionClass）。
     *
     * 当类型对应的类不在磁盘（如可选模块未安装、构造期硬注入了缺席包的类）时，
     * 两个分支统一返回 null，交由后续 build() 第 387-398 行的"参数有无默认值 / 是否可空"
     * 分支处理，错误形态稳定为 `Cannot resolve parameter [$xxx]`，避免 PHP 8 下
     * `new ReflectionClass(...)` 直接冒泡 ReflectionException 让定位变怪。
     *
     * @param \ReflectionParameter $param
     * @return \ReflectionClass|null
     */
    private function getParamClass(\ReflectionParameter $param)
    {
        if (PHP_VERSION_ID >= 80000) {
            $type = $param->getType();
            if (!($type instanceof \ReflectionNamedType) || $type->isBuiltin()) {
                return null;
            }
            try {
                return new \ReflectionClass($type->getName());
            } catch (\ReflectionException $e) {
                return null;
            }
        }

        // PHP 5.6 / 7.x
        try {
            return $param->getClass();
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    /**
     * 查找当前构建栈顶消费者对 $abstract 的上下文绑定结果（无则返回 null）。
     *
     * 仅看栈顶（直接消费者），不向上回溯，避免“父级声明意外影响子依赖”。
     *
     * @param string $abstract
     * @return string|callable|null
     */
    private function resolveContextual($abstract)
    {
        if (empty($this->buildStack)) {
            return null;
        }
        $consumer = $this->buildStack[count($this->buildStack) - 1];
        if (isset($this->contextualBindings[$consumer][$abstract])) {
            return $this->contextualBindings[$consumer][$abstract];
        }
        return null;
    }
}

/**
 * 上下文绑定的链式构造器：when()->needs()->give()。
 *
 * 仅由 Container::when() 创建，不应直接 new。
 */
class ContextualBindingBuilder
{
    /** @var Container */
    private $container;

    /** @var string */
    private $consumer;

    /** @var string|null */
    private $abstract;

    /**
     * @param Container $container
     * @param string $consumer 消费者类全限定名
     */
    public function __construct(Container $container, $consumer)
    {
        $this->container = $container;
        $this->consumer = $consumer;
    }

    /**
     * 声明当前消费者需要的抽象。
     *
     * @param string $abstract
     * @return $this
     */
    public function needs($abstract)
    {
        $this->abstract = $abstract;
        return $this;
    }

    /**
     * 指定 $abstract 在当前消费者下的具体实现（类名或工厂闭包）。
     *
     * @param string|callable $concrete
     * @return void
     */
    public function give($concrete)
    {
        if ($this->abstract === null) {
            throw new \LogicException('ContextualBindingBuilder::give() called before needs().');
        }
        $this->container->addContextualBinding($this->consumer, $this->abstract, $concrete);
    }
}

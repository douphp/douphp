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

namespace Dou\Core\Orm;

use ArrayAccess;
use Countable;
use Dou\Core\Facade\DB;
use Dou\Core\Infra\Database\Connection;
use Dou\Core\Orm\Casts\CastResolver;
use Dou\Core\Orm\Relations\BelongsTo;
use Dou\Core\Orm\Relations\BelongsToMany;
use Dou\Core\Orm\Relations\HasMany;
use Dou\Core\Orm\Relations\HasOne;
use Dou\Core\Orm\Relations\Relation;
use Dou\Core\Support\Str;
use IteratorAggregate;
use JsonSerializable;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 轻量级 ActiveRecord 模型基类。
 *
 * 以 hydrated 实例、casts/accessors、with 预加载、HasOne/HasMany/BelongsTo 关系
 * 与声明式 prefetchers 统一读写契约。
 *
 * 设计原则：
 * - 入口双轨、默认静态：`Model::where()/with()/query()/create()`（__callStatic）为主，
 *   `new Model()` / 容器 DI 注入仍合法（构造无参）。
 * - 读：统一经 `query()->find()/first()/get()/with()` 取 hydrated Model / Collection；
 *   写：统一经 `fill()->save()` / `create()` / `query()->whereKey($id)->update()` / `destroy()`。
 * - 与 `DB::` 共存：只把「映射到实体记录/集合」的读写走 ORM；聚合/泛化/元信息/原生 SQL 仍用 `DB::`。
 * - 模板透明：实现 ArrayAccess / IteratorAggregate / Countable / JsonSerializable，
 *   常用调用面 `$row['x']`、`empty($row)`、`foreach`、`json_encode` 对实例透明。
 * - 兼容 PHP 5.6–8.x：无标量/返回类型声明、无 `??`，接口方法以 `#[\ReturnTypeWillChange]` 兼容 8.1+。
 */
abstract class Model implements ArrayAccess, IteratorAggregate, Countable, JsonSerializable
{
    const SKIP_ATTRIBUTE = '__DOU_SKIP_ATTRIBUTE__';

    /** @var string 主表名（子类必须声明） */
    protected $table = '';

    /** @var string 主键字段名 */
    protected $primary = 'id';

    /**
     * 分类下的业务记录表（通过 category_id 关联）；分类模型须显式声明，例如 'article'。
     *
     * @var string
     */
    protected $recordsTable = '';

    /** @var array 允许批量写入的字段（为空则不过滤） */
    protected $fillable = array();

    /** @var array 字段类型转换声明：field => cast（如 'created_at' => 'datetime'） */
    protected $casts = array();

    /** @var array 声明式预热：'url' / 'language' => 'f1,f2' / 'attachment' => 'image' */
    protected $prefetchers = array();

    /** @var array 默认预加载关系名列表 */
    protected $with = array();

    /** @var array toArray 时额外附加的 accessor 键（无对应原始字段时使用） */
    protected $appends = array();

    /** @var array 多语言字段：toArray 时经 language()->langValue 覆写为当前语言值（未命中保留原值） */
    protected $translatable = array();

    /** @var string 多语言模块名（默认取表名；分类模块须显式声明如 'article_category'） */
    protected $translatableModule = '';

    /** @var array 当前实例的原始属性 */
    protected $attributes = array();

    /** @var array 落库基线快照（hydrate / save 后同步），用于脏属性比对 */
    protected $original = array();

    /** @var array 已加载关系（name => Model|Collection|null） */
    protected $relations = array();

    /** @var bool 是否对应数据库已存在记录 */
    protected $exists = false;

    /** @var bool 是否自动维护时间戳（opt-in，默认关闭以保行为） */
    protected $timestamps = false;

    /** @var string 新增时写入的创建时间字段（DATETIME，贴合 created_at 约定） */
    protected $createdAtColumn = 'created_at';

    /** @var string 更新时写入的时间字段；空字符串表示不写更新时间 */
    protected $updatedAtColumn = '';

    /** @var callable|null 连接解析器 */
    protected static $connectionResolver;

    /** @var array 全局 scope 注册表：array(类名 => array(标识 => callable)) */
    protected static $globalScopes = array();

    /** @var array 已 boot 的类名集合（boot 仅跑一次） */
    protected static $bootedClasses = array();

    /** @var array 模型事件监听器：array(类名 => array(事件名 => array(callable))) */
    protected static $modelEvents = array();

    /**
     * @param array $attributes
     */
    public function __construct(array $attributes = array())
    {
        static::bootIfNotBooted();
        if (!empty($attributes)) {
            $this->setRawAttributes($attributes);
        }
    }

    // -----------------------------------------------------------------
    // 全局 scope（capability：默认不挂任何模型，子类覆写 boot() 显式注册）
    // -----------------------------------------------------------------

    /**
     * 首次实例化时 boot 一次。
     *
     * @return void
     */
    public static function bootIfNotBooted()
    {
        $class = get_called_class();
        if (!isset(self::$bootedClasses[$class])) {
            self::$bootedClasses[$class] = true;
            static::bootTraits();
            static::boot();
        }
    }

    /**
     * boot 钩子：默认空。子类覆写以 `static::addGlobalScope('id', function ($query) {...})`
     * 注册全局 scope（自动套用到该模型的 get()/first()/find()/paginate() 读路径）。
     *
     * @return void
     */
    protected static function boot()
    {
    }

    /**
     * 自动调用各 trait 的 boot{TraitBasename}() 静态钩子（Laravel 惯例）。
     *
     * 例：use PurgesRelatedOnDelete → bootPurgesRelatedOnDelete()，
     * 让 trait 自注册模型事件而无需 Model 手写 static::deleting(...)。
     *
     * @return void
     */
    protected static function bootTraits()
    {
        $class = get_called_class();
        foreach (self::classUsesRecursive($class) as $trait) {
            $method = 'boot' . self::classBasename($trait);
            if (method_exists($class, $method)) {
                call_user_func(array($class, $method));
            }
        }
    }

    /**
     * 递归收集类（含父类、嵌套 trait）使用的全部 trait 全限定名。
     *
     * @param string|object $class
     * @return array
     */
    protected static function classUsesRecursive($class)
    {
        if (is_object($class)) {
            $class = get_class($class);
        }

        $results = array();
        $classes = class_parents($class);
        $classes = $classes === false ? array() : $classes;
        $classes[$class] = $class;
        foreach ($classes as $cls) {
            $results += self::traitUsesRecursive($cls);
        }

        return array_unique($results);
    }

    /**
     * 递归收集单个类/trait 使用的 trait（含 trait 内嵌套 trait）。
     *
     * @param string $trait
     * @return array
     */
    protected static function traitUsesRecursive($trait)
    {
        $traits = class_uses($trait);
        $traits = $traits === false ? array() : $traits;
        foreach ($traits as $used) {
            $traits += self::traitUsesRecursive($used);
        }

        return $traits;
    }

    /**
     * 取全限定类名的短名（去命名空间）。
     *
     * @param string $class
     * @return string
     */
    protected static function classBasename($class)
    {
        $segments = explode('\\', $class);

        return end($segments);
    }

    // -----------------------------------------------------------------
    // 模型事件（Laravel 同款心智：deleting / deleted）
    // -----------------------------------------------------------------

    /**
     * 注册删除前事件监听器；回调形参为当前实例，返回 false 中止删除。
     *
     * @param callable $callback
     * @return void
     */
    public static function deleting($callback)
    {
        static::registerModelEvent('deleting', $callback);
    }

    /**
     * 注册删除后事件监听器；回调形参为当前实例。
     *
     * @param callable $callback
     * @return void
     */
    public static function deleted($callback)
    {
        static::registerModelEvent('deleted', $callback);
    }

    /**
     * 按调用类分桶登记模型事件监听器。
     *
     * @param string $event
     * @param callable $callback
     * @return void
     */
    protected static function registerModelEvent($event, $callback)
    {
        $class = get_called_class();
        if (!isset(self::$modelEvents[$class])) {
            self::$modelEvents[$class] = array();
        }
        if (!isset(self::$modelEvents[$class][$event])) {
            self::$modelEvents[$class][$event] = array();
        }
        self::$modelEvents[$class][$event][] = $callback;
    }

    /**
     * 派发模型事件：依次调用监听器，任一返回 false 即整体返回 false（中止）。
     *
     * @param string $event
     * @return bool
     */
    protected function fireModelEvent($event)
    {
        $class = get_class($this);
        if (empty(self::$modelEvents[$class][$event])) {
            return true;
        }
        foreach (self::$modelEvents[$class][$event] as $callback) {
            if (call_user_func($callback, $this) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * 注册一个全局 scope（按调用类分桶）。
     *
     * @param string $identifier 标识（供 withoutGlobalScope 精确移除）
     * @param callable $scope 形参 = 当前 Builder
     * @return void
     */
    public static function addGlobalScope($identifier, $scope)
    {
        $class = get_called_class();
        if (!isset(self::$globalScopes[$class])) {
            self::$globalScopes[$class] = array();
        }
        self::$globalScopes[$class][$identifier] = $scope;
    }

    /**
     * 取当前类已注册的全局 scope（标识 => callable）。
     *
     * @return array
     */
    public static function getGlobalScopes()
    {
        $class = get_called_class();

        return isset(self::$globalScopes[$class]) ? self::$globalScopes[$class] : array();
    }

    // -----------------------------------------------------------------
    // 连接
    // -----------------------------------------------------------------

    /**
     * 注入连接解析器（InitTrait 接线为 () => DB::getFacadeRoot()）。
     *
     * @param callable $resolver
     * @return void
     */
    public static function setConnectionResolver($resolver)
    {
        self::$connectionResolver = $resolver;
    }

    /**
     * 取底层连接（Connection 门面根实例）。
     *
     * @return Connection
     */
    public static function getConnection()
    {
        if (self::$connectionResolver !== null) {
            return call_user_func(self::$connectionResolver);
        }

        return DB::getFacadeRoot();
    }

    // -----------------------------------------------------------------
    // 元信息
    // -----------------------------------------------------------------

    /**
     * @return string
     */
    public function getTable()
    {
        if ($this->table !== '') {
            return $this->table;
        }
        $reflection = new \ReflectionClass($this);

        return Str::snake($reflection->getShortName());
    }

    /**
     * @return string
     */
    public function getKeyName()
    {
        return $this->primary;
    }

    /**
     * @return mixed
     */
    public function getKey()
    {
        return $this->getRawAttribute($this->primary);
    }

    /**
     * @return array
     */
    public function getWith()
    {
        return (array) $this->with;
    }

    /**
     * @return array
     */
    public function getPrefetchers()
    {
        return (array) $this->prefetchers;
    }

    /**
     * @return array
     */
    public function getCasts()
    {
        return (array) $this->casts;
    }

    // -----------------------------------------------------------------
    // 实例与查询工厂
    // -----------------------------------------------------------------

    /**
     * @return Builder
     */
    public function newQuery()
    {
        return new Builder($this);
    }

    /**
     * @param array $attributes
     * @param bool $exists
     * @return static
     */
    public function newInstance(array $attributes = array(), $exists = false)
    {
        $model = new static();
        $model->setRawAttributes($attributes);
        $model->exists = (bool) $exists;

        return $model;
    }

    /**
     * 从查询结果（原始行）水合一个实例。
     *
     * @param array $attributes
     * @return static
     */
    public function newFromBuilder(array $attributes = array())
    {
        return $this->newInstance($attributes, true);
    }

    /**
     * @param array $models
     * @return Collection
     */
    public function newCollection(array $models = array())
    {
        return new Collection($models);
    }

    /**
     * @param string $class
     * @return Model
     */
    protected function newRelatedInstance($class)
    {
        return new $class();
    }

    // -----------------------------------------------------------------
    // 静态入口
    // -----------------------------------------------------------------

    /**
     * @return Builder
     */
    public static function query()
    {
        $model = new static();

        return $model->newQuery();
    }

    /**
     * 子类声明的主表名（静态入口）。
     *
     * 供子类网关静态方法以 `DB::table(static::tableName())->...` 形态使用，
     * 将「读 $this->table」一处收敛到此助手，业务层永远看不到 `new static`。
     *
     * @return string
     */
    public static function tableName()
    {
        return (new static())->getTable();
    }

    /**
     * 创建并落库一条记录，返回水合后的实例；保存失败返回 null。
     *
     * @param array $attributes
     * @return static|null
     */
    public static function create(array $attributes)
    {
        $model = new static();
        $model->fill($attributes, 'insert');

        return $model->save() ? $model : null;
    }

    /**
     * 按主键删除记录（静态便利入口）。
     *
     * 标量与数组入参均逐条 find → 实例 delete()，从而触发 deleting / deleted 事件
     * （与 whereIn()->delete() / whereKey()->delete() 直发 SQL 不同，后者不触发事件）。
     *
     * @param int|array $id 单个主键或主键数组
     * @return bool|int 标量入参返回 bool；数组入参返回成功删除的条数
     */
    public static function destroy($id)
    {
        if (is_array($id)) {
            if (empty($id)) {
                return 0;
            }
            // 批量水合：N 次逐条 find 合并为 1 次 whereIn，再逐条 delete() 触发事件。
            $models = static::whereIn((new static())->getKeyName(), $id)->get();
            $count = 0;
            foreach ($models as $model) {
                if ($model->delete()) {
                    $count++;
                }
            }

            return $count;
        }

        $model = static::find($id);
        if (!$model) {
            return false;
        }

        return (bool) $model->delete();
    }

    /**
     * 静态调用转发到查询构造器：Model::where()/with()/orderBy()/count()/...
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public static function __callStatic($method, $parameters)
    {
        return call_user_func_array(array(static::query(), $method), $parameters);
    }

    /**
     * 实例调用转发到查询构造器（未命中真实方法时）：$model->where()/find()/...
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return call_user_func_array(array($this->newQuery(), $method), $parameters);
    }

    // -----------------------------------------------------------------
    // 属性读写
    // -----------------------------------------------------------------

    /**
     * @param array $attributes
     * @param bool $sync 是否同步基线快照（hydrate 时为真）
     * @return $this
     */
    public function setRawAttributes(array $attributes, $sync = true)
    {
        $this->attributes = $attributes;
        if ($sync) {
            $this->syncOriginal();
        }

        return $this;
    }

    /**
     * 把当前属性同步为基线快照（save / hydrate 后调用）。
     *
     * @return $this
     */
    public function syncOriginal()
    {
        $this->original = $this->attributes;

        return $this;
    }

    /**
     * 取相对基线的脏字段（新增键或值变化的键）。
     *
     * @return array
     */
    public function getDirty()
    {
        $dirty = array();
        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original)) {
                $dirty[$key] = $value;
            } else {
                $original = $this->original[$key];
                // 有 cast 的字段用宽松比较（DB 读出字符串 '1' 经 cast 变为 int 1，严格比较误报）;
                // 无 cast 的字段保持严格比较，避免 '0' != '' 等误判。
                $changed = isset($this->casts[$key]) ? ($original != $value) : ($original !== $value);
                if ($changed) {
                    $dirty[$key] = $value;
                }
            }
        }

        return $dirty;
    }

    /**
     * 是否存在脏字段（或指定字段是否脏）。
     *
     * @param string|null $key
     * @return bool
     */
    public function isDirty($key = null)
    {
        $dirty = $this->getDirty();
        if ($key === null) {
            return !empty($dirty);
        }

        return array_key_exists($key, $dirty);
    }

    /**
     * @return array
     */
    public function getAttributes()
    {
        return $this->attributes;
    }

    /**
     * 取原始（未经 cast/accessor）属性值。
     *
     * @param string $key
     * @return mixed
     */
    public function getRawAttribute($key)
    {
        return array_key_exists($key, $this->attributes) ? $this->attributes[$key] : null;
    }

    /**
     * 取属性值，顺序：已加载关系 → accessor → cast → 原始值 → 惰性关系。
     *
     * @param string $key
     * @return mixed
     */
    public function getAttribute($key)
    {
        if ($key === null || $key === '') {
            return null;
        }

        if (array_key_exists($key, $this->relations)) {
            return $this->relations[$key];
        }

        if ($this->hasGetMutator($key)) {
            $raw = array_key_exists($key, $this->attributes) ? $this->attributes[$key] : null;

            return $this->{'get' . Str::studly($key) . 'Attribute'}($raw);
        }

        if (array_key_exists($key, $this->attributes)) {
            $value = $this->attributes[$key];
            if (isset($this->casts[$key])) {
                return CastResolver::apply($this->casts[$key], $value, $this);
            }

            return $value;
        }

        if (method_exists($this, $key)) {
            return $this->getRelationshipFromMethod($key);
        }

        return null;
    }

    /**
     * 设置属性。优先级：setXxxAttribute 修改器 → 可逆 cast 写入转换 → 原样写入。
     *
     * @param string $key
     * @param mixed $value
     * @param array|null $context 批量填充时的完整来源数据（供多参修改器使用，默认当前属性）
     * @param string $writeMode insert|update 或空字符串
     * @return $this
     */
    public function setAttribute($key, $value, $context = null, $writeMode = '')
    {
        $method = 'set' . Str::studly($key) . 'Attribute';
        if (method_exists($this, $method)) {
            $data = is_array($context) ? $context : $this->attributes;
            $mutated = $this->callMutator($method, $value, $data, (string) $writeMode);
            if ($mutated !== self::SKIP_ATTRIBUTE) {
                $this->attributes[$key] = $mutated;
            }

            return $this;
        }

        if (isset($this->casts[$key])) {
            $this->attributes[$key] = CastResolver::applySet($this->casts[$key], $value, $this);

            return $this;
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * @param string $key
     * @return bool
     */
    protected function hasGetMutator($key)
    {
        return method_exists($this, 'get' . Str::studly($key) . 'Attribute');
    }

    /**
     * 调用关系方法并缓存结果。
     *
     * @param string $method
     * @return mixed
     */
    protected function getRelationshipFromMethod($method)
    {
        $relation = $this->$method();
        if (!($relation instanceof Relation)) {
            return null;
        }
        $results = $relation->getResults();
        $this->relations[$method] = $results;

        return $results;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return $this
     */
    public function setRelation($key, $value)
    {
        $this->relations[$key] = $value;

        return $this;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function relationLoaded($key)
    {
        return array_key_exists($key, $this->relations);
    }

    // -----------------------------------------------------------------
    // 魔术方法 / ArrayAccess / 迭代 / 序列化
    // -----------------------------------------------------------------

    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    public function __set($key, $value)
    {
        $this->setAttribute($key, $value);
    }

    public function __isset($key)
    {
        return $this->offsetExists($key);
    }

    public function __unset($key)
    {
        unset($this->attributes[$key], $this->relations[$key]);
    }

    /**
     * isset($model['x']) 语义：与数组一致——属性存在且非 null，或关系/accessor 命中且非 null。
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        if (array_key_exists($offset, $this->attributes)) {
            return true;
        }
        if (array_key_exists($offset, $this->relations)) {
            return $this->relations[$offset] !== null;
        }
        if ($this->hasGetMutator($offset)) {
            return $this->getAttribute($offset) !== null;
        }

        return false;
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        return $this->getAttribute($offset);
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        if ($offset === null) {
            return;
        }
        $this->setAttribute($offset, $value);
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        $this->__unset($offset);
    }

    #[\ReturnTypeWillChange]
    public function getIterator()
    {
        return new \ArrayIterator($this->toArray());
    }

    #[\ReturnTypeWillChange]
    public function count()
    {
        return $this->exists ? 1 : count($this->attributes);
    }

    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /**
     * 导出模板就绪数组：所有属性应用 cast/accessor，已加载关系递归 toArray，附加 appends。
     *
     * @return array
     */
    public function toArray()
    {
        $result = array();
        foreach ($this->attributes as $key => $value) {
            $result[$key] = $this->getAttribute($key);
        }
        foreach ((array) $this->appends as $key) {
            $result[$key] = $this->getAttribute($key);
        }
        foreach ($this->relations as $key => $value) {
            if ($value instanceof Model || $value instanceof Collection) {
                $result[$key] = $value->toArray();
            } else {
                $result[$key] = $value;
            }
        }

        if (!empty($this->translatable)) {
            $result = $this->applyTranslations($result);
        }

        return $result;
    }

    /**
     * 按声明的 $translatable 字段，用 language()->langValue 覆写当前语言值（依赖 language 预热缓存）。
     *
     * @param array $result
     * @return array
     */
    protected function applyTranslations(array $result)
    {
        $language = language();
        if ($language === null) {
            return $result;
        }
        $module = $this->translatableModule !== '' ? $this->translatableModule : $this->getTable();
        $itemId = (int) $this->getKey();
        if ($itemId <= 0) {
            return $result;
        }
        foreach ($this->translatable as $field) {
            if (array_key_exists($field, $result)) {
                $result[$field] = $language->langValue($result[$field], $module, $itemId, $field);
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------
    // 关系工厂
    // -----------------------------------------------------------------

    /**
     * @param string $related 关联模型类名
     * @param string $foreignKey 本表外键（如 category_id）
     * @param string|null $ownerKey 关联表键（默认关联表主键）
     * @return BelongsTo
     */
    public function belongsTo($related, $foreignKey, $ownerKey = null)
    {
        $instance = $this->newRelatedInstance($related);
        if ($ownerKey === null) {
            $ownerKey = $instance->getKeyName();
        }

        return new BelongsTo($this, $instance, $foreignKey, $ownerKey);
    }

    /**
     * @param string $related
     * @param string $foreignKey 关联表外键（如 user_id）
     * @param string|null $localKey 本表键（默认本表主键）
     * @return HasOne
     */
    public function hasOne($related, $foreignKey, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);
        if ($localKey === null) {
            $localKey = $this->getKeyName();
        }

        return new HasOne($this, $instance, $foreignKey, $localKey);
    }

    /**
     * @param string $related
     * @param string $foreignKey 关联表外键（如 user_id）
     * @param string|null $localKey 本表键（默认本表主键）
     * @return HasMany
     */
    public function hasMany($related, $foreignKey, $localKey = null)
    {
        $instance = $this->newRelatedInstance($related);
        if ($localKey === null) {
            $localKey = $this->getKeyName();
        }

        return new HasMany($this, $instance, $foreignKey, $localKey);
    }

    /**
     * @param string $related 关联模型类名
     * @param string $pivotTable 中间表名（逻辑名，不含前缀）
     * @param string $foreignPivotKey 中间表指向本模型的外键
     * @param string $relatedPivotKey 中间表指向关联模型的外键
     * @param string|null $parentKey 本表键（默认本表主键）
     * @param string|null $relatedKey 关联表键（默认关联表主键）
     * @return BelongsToMany
     */
    public function belongsToMany($related, $pivotTable, $foreignPivotKey, $relatedPivotKey, $parentKey = null, $relatedKey = null)
    {
        $instance = $this->newRelatedInstance($related);
        if ($parentKey === null) {
            $parentKey = $this->getKeyName();
        }
        if ($relatedKey === null) {
            $relatedKey = $instance->getKeyName();
        }

        return new BelongsToMany($this, $instance, $pivotTable, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey);
    }

    // -----------------------------------------------------------------
    // 通用 CRUD（实例语义）
    // -----------------------------------------------------------------

    /**
     * 列表页码：仅接受非负整数字符串，非法或小于 1 时返回 1。
     *
     * @param mixed $page
     * @return int
     */
    protected function pageFromParam($page)
    {
        if ($page === null || $page === false || $page === '') {
            return 1;
        }
        $s = (string) $page;
        if ($s === '' || preg_match('/^[0-9]+$/', $s) !== 1) {
            return 1;
        }
        $n = (int) $s;

        return $n > 0 ? $n : 1;
    }

    /**
     * 根据分类ID查询单条分类记录（跨表辅助，保持返回原始数组）。
     *
     * @param int $catId
     * @return array|null
     */
    public static function findCategoryById($catId)
    {
        $self = new static();

        return DB::table($self->getTable() . '_category')->where('id', (int) $catId)->find();
    }

    /**
     * 持久化当前实例：已存在则按主键写脏字段（UPDATE），否则 INSERT 并回填主键。
     *
     * @return bool
     */
    public function save()
    {
        if ($this->exists) {
            // 防数据损坏：已存在行的 UPDATE 必须有合法标量主键；
            // 主键为 null/''/数组会让 where(primary, ...) 退化为 IS NULL 或异常，可能误更新整批行。
            $key = $this->getKey();
            if ($key === null || $key === '' || is_array($key) || is_object($key)) {
                throw new \InvalidArgumentException(
                    'Model::save() cannot UPDATE row with empty/non-scalar primary key on table ' . $this->getTable()
                );
            }
            $this->touchTimestamps('update');
            $dirty = $this->getDirty();
            if (empty($dirty)) {
                return true;
            }
            $result = DB::table($this->getTable())
                ->where($this->primary, $key)
                ->update($dirty);
            if ($result !== false) {
                $this->syncOriginal();
            }

            return $result !== false;
        }

        $this->touchTimestamps('insert');
        $id = DB::table($this->getTable())->insert($this->attributes);
        // Connection::insert() 失败返回 false；无 AUTO_INCREMENT 主键的表返回 0（如 Plugin 以 slug 为主键）。
        // 仅 false 视为失败，0 表示插入成功但无自增 id 可回填（此时主键应由调用方在 fill 时显式设置）。
        if ($id === false) {
            return false;
        }
        if (!array_key_exists($this->primary, $this->attributes) || $this->attributes[$this->primary] === null || $this->attributes[$this->primary] === '') {
            $this->attributes[$this->primary] = $id;
        }
        $this->exists = true;
        $this->syncOriginal();

        return true;
    }

    /**
     * 按 opt-in 配置维护时间戳（默认关闭，零行为变化）。
     *
     * @param string $mode insert|update
     * @return void
     */
    protected function touchTimestamps($mode)
    {
        if (!$this->timestamps) {
            return;
        }
        $now = date('Y-m-d H:i:s');
        if ($mode === 'insert' && $this->createdAtColumn !== '') {
            $current = isset($this->attributes[$this->createdAtColumn]) ? $this->attributes[$this->createdAtColumn] : null;
            if ($current === null || $current === '' || $current === 0 || $current === '0') {
                $this->attributes[$this->createdAtColumn] = $now;
            }
        }
        if ($this->updatedAtColumn !== '') {
            $this->attributes[$this->updatedAtColumn] = $now;
        }
    }

    /**
     * 批量填充属性并返回自身（支持 `$model->fill($data)->save()` 链式）。
     *
     * 行为契约：
     * - `fill()` 受 `$fillable` 守卫（非空时仅写入白名单字段，mass assignment 安全）；
     *   未在 `$fillable` 内的键静默忽略（不抛异常）。
     * - 直赋 `$model->name = 'x'` 经 `__set` → `setAttribute`，不受 `$fillable` 守卫（开发者知情同意）。
     * - 每个键经 `setAttribute` 写入：命中 `setXxxAttribute` 修改器优先，否则走可逆 cast，再否则原样。
     *
     * @param array $data
     * @param string $writeMode insert|update 或空字符串
     * @return $this
     */
    public function fill(array $data, $writeMode = '')
    {
        if (empty($data)) {
            return $this;
        }

        $source = $data;
        if (!empty($this->fillable)) {
            $source = array_intersect_key($data, array_flip((array) $this->fillable));
        }

        foreach ($source as $field => $value) {
            $this->setAttribute($field, $value, $data, $writeMode);
        }

        return $this;
    }

    /**
     * 兼容不同签名的 mutator 调用。
     *
     * @param string $method
     * @param mixed $value
     * @param array $data
     * @param string $writeMode
     * @return mixed
     */
    protected function callMutator($method, $value, array $data, $writeMode)
    {
        $paramCount = $this->getMutatorParamCount($method);
        if ($paramCount >= 3) {
            return $this->$method($value, $data, $writeMode);
        }
        if ($paramCount === 2) {
            return $this->$method($value, $data);
        }

        return $this->$method($value);
    }

    /**
     * 获取 mutator 参数数量（带静态缓存）。
     *
     * @param string $method
     * @return int
     */
    protected function getMutatorParamCount($method)
    {
        static $paramCountCache = array();
        $cacheKey = get_class($this) . '::' . $method;

        if (!isset($paramCountCache[$cacheKey])) {
            $reflection = new \ReflectionMethod($this, $method);
            $paramCountCache[$cacheKey] = $reflection->getNumberOfParameters();
        }

        return (int) $paramCountCache[$cacheKey];
    }

    /**
     * 删除当前实例（按其主键），触发 deleting / deleted 事件。
     *
     * deleting 监听器返回 false 时中止删除（不发 SQL）；删除成功后触发 deleted。
     * 注意：Builder::whereIn()->delete() / whereKey()->delete() 为直发 SQL，
     * 不会触发这两个事件，带副作用的业务删除必须走 destroy() 或本方法。
     *
     * @return bool
     */
    public function delete()
    {
        $key = $this->getKey();
        // 与 save() UPDATE 守卫对齐：拒绝 null/''/array/object 主键，
        // 防 where(primary, array) 在 PHP 8 触发 strtoupper(array) 的 TypeError。
        if ($key === null || $key === '' || is_array($key) || is_object($key)) {
            return false;
        }
        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }
        $result = DB::table($this->getTable())->where($this->primary, $key)->delete();
        if ($result) {
            $this->exists = false;
            $this->fireModelEvent('deleted');
        }

        return (bool) $result;
    }

    /**
     * 检查 slug 是否已存在。
     *
     * @param string $slug
     * @param int $excludeId 排除的 ID，0 表示新增校验
     * @return bool
     */
    public static function slugExists($slug, $excludeId = 0)
    {
        $self = new static();
        $q = DB::table($self->getTable())->where('slug', $slug);
        if ($excludeId) {
            $q->where($self->primary, '!=', $excludeId);
        }

        return $q->exists();
    }

    /**
     * 点击量自增。
     *
     * @param int $id 主键值
     * @param int $step 增量，默认 1，须为正整数
     * @return int|false 影响行数；参数非法时为 false
     */
    public static function updateClick($id, $step = 1)
    {
        $id = (int) $id;
        $step = (int) $step;
        if ($id <= 0 || $step <= 0) {
            return false;
        }
        $self = new static();

        return DB::table($self->getTable())
            ->where($self->primary, $id)
            ->inc('click', $step);
    }

    /**
     * 分类下是否存在业务记录（须由子类声明 $recordsTable）。
     *
     * @param int $catId
     * @return bool
     */
    public static function hasRecords($catId)
    {
        $self = new static();
        if ($self->recordsTable === '') {
            return false;
        }

        return (bool) DB::table($self->recordsTable)->where('category_id', (int) $catId)->exists();
    }

    /**
     * 是否存在子分类。
     *
     * @param int $catId
     * @return mixed
     */
    public static function hasChildCategory($catId)
    {
        $self = new static();

        return DB::table($self->getTable())->where('parent_id', (int) $catId)->value('id');
    }
}

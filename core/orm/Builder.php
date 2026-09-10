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

use Dou\Core\Infra\Database\Connection;
use Dou\Core\Orm\Prefetch\PrefetchRunner;
use Dou\Core\Orm\Relations\Relation;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * ORM 查询构造器：在底层 Connection 链式查询之上叠加 with 预加载、模型水合与 prefetch。
 *
 * 链式方法（where/whereIn/order/limit/join/field/group/having/...）经 __call 透传底层 Connection；
 * 终结方法 get/first/find/paginate 返回水合后的 Model/Collection，并执行 eager load 与 prefetch。
 * 标量终结（count/sum/avg/max/min/value/exists/insert/update/delete）原样透传，便于与 `DB::` 共存。
 */
class Builder
{
    /** @var Model */
    protected $model;

    /** @var Connection 底层链式查询实例 */
    protected $query;

    /** @var array 待预加载关系名 */
    protected $eagerLoad = array();

    /**
     * 水合后回调栈：在 eager load + PrefetchRunner 之后、按入栈顺序对整批 Collection 触发。
     *
     * 边界硬规则：唯一合法用途是产出 user-scoped 派生字段（favorites / sale_price）。
     * 一切与 user_id 无关的批量预热必须走声明式 $prefetchers，禁止塞进闭包。
     *
     * @var array<int, callable>
     */
    protected $afterHydrate = array();

    /** @var array 该模型注册的全局 scope（标识 => callable） */
    protected $globalScopes = array();

    /** @var array 本次查询要跳过的全局 scope 标识集合（标识 => true） */
    protected $removedScopes = array();

    /** @var bool 是否跳过全部全局 scope */
    protected $removeAllScopes = false;

    /** @var bool 全局 scope 是否已应用（防终结读路径重复应用） */
    protected $appliedGlobalScopes = false;

    /**
     * 只读聚合方法白名单：透传底层前应用全局 scope，与 get()/first()/find() 行为对齐。
     *
     * 写方法（insert/update/delete/insertAll/setField/inc/dec/exp 等）与链式方法不在白名单，
     * 不受全局 scope 影响；$appliedGlobalScopes 守卫确保重复调用安全。
     *
     * @var array
     */
    protected $aggregateMethods = array(
        'count' => true, 'sum' => true, 'avg' => true,
        'max' => true, 'min' => true, 'value' => true,
        'exists' => true, 'column' => true,
    );

    /**
     * @param Model $model
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
        $this->query = Model::getConnection()->table($model->getTable());
        $this->globalScopes = $model->getGlobalScopes();

        $with = $model->getWith();
        if (!empty($with)) {
            $this->with($with);
        }
    }

    /**
     * 本次查询跳过指定标识的全局 scope。
     *
     * @param string $identifier
     * @return $this
     */
    public function withoutGlobalScope($identifier)
    {
        $this->removedScopes[$identifier] = true;

        return $this;
    }

    /**
     * 本次查询跳过全部全局 scope。
     *
     * @return $this
     */
    public function withoutGlobalScopes()
    {
        $this->removeAllScopes = true;

        return $this;
    }

    /**
     * 在终结读路径执行前应用全局 scope（一次性，$appliedGlobalScopes 守卫）。
     *
     * 仅作用于 get()/paginate()（first()/find() 经 get() 覆盖）；原生聚合透传
     * （__call → count/sum/value/exists/...）不自动套全局 scope，需要带全局 scope 的
     * 聚合请显式表达条件。
     *
     * @return void
     */
    protected function applyGlobalScopes()
    {
        if ($this->appliedGlobalScopes) {
            return;
        }
        $this->appliedGlobalScopes = true;
        if ($this->removeAllScopes || empty($this->globalScopes)) {
            return;
        }
        foreach ($this->globalScopes as $identifier => $scope) {
            if (isset($this->removedScopes[$identifier])) {
                continue;
            }
            call_user_func($scope, $this);
        }
    }

    /**
     * @return Model
     */
    public function getModel()
    {
        return $this->model;
    }

    /**
     * @return Connection
     */
    public function getQuery()
    {
        return $this->query;
    }

    /**
     * 注册水合后回调（仅用于 user-scoped 字段派生，见 $afterHydrate 边界规则）。
     *
     * @param callable $cb 形参 = 水合后的 Collection
     * @return $this
     */
    public function pushAfterHydrate(callable $cb)
    {
        $this->afterHydrate[] = $cb;

        return $this;
    }

    /**
     * 声明预加载关系，支持四种共存形态：
     * - with('category')                          单关系
     * - with('a', 'b') / with(array('a', 'b'))    多关系
     * - with('category.parent')                   嵌套（点号 → 顶层关系的 nested 子关系）
     * - with(array('brand' => function ($q) {})) 闭包约束（约束应用到该关系的 eager 查询）
     *
     * 归一化为 eagerLoad[topName] = array('nested' => array(...), 'constraint' => callable|null)。
     * nested 子项本身可为字符串或 'path' => callable，递归喂回关联模型的 with()。
     *
     * @param string|array $relations
     * @return $this
     */
    public function with($relations)
    {
        $relations = is_array($relations) ? $relations : func_get_args();
        foreach ($relations as $key => $value) {
            if (is_string($key)) {
                $this->addEagerLoad($key, is_callable($value) ? $value : null);
            } elseif (is_string($value) && $value !== '') {
                $this->addEagerLoad($value, null);
            }
        }

        return $this;
    }

    /**
     * 归一化单条预加载路径：拆分点号、附加 nested / constraint。
     *
     * @param string $path 关系路径（可含点号，如 'category.parent'）
     * @param callable|null $constraint 约束闭包（应用到路径最深一段）
     * @return void
     */
    protected function addEagerLoad($path, $constraint)
    {
        $path = (string) $path;
        if ($path === '') {
            return;
        }
        $dot = strpos($path, '.');
        $top = $dot === false ? $path : substr($path, 0, $dot);
        $rest = $dot === false ? '' : substr($path, $dot + 1);

        if (!isset($this->eagerLoad[$top])) {
            $this->eagerLoad[$top] = array('nested' => array(), 'constraint' => null);
        }
        if ($rest === '') {
            if ($constraint !== null) {
                $this->eagerLoad[$top]['constraint'] = $constraint;
            }
        } elseif ($constraint !== null) {
            $this->eagerLoad[$top]['nested'][$rest] = $constraint;
        } else {
            $this->eagerLoad[$top]['nested'][] = $rest;
        }
    }

    /**
     * 按模型主键约束：whereKey($id) → where(primary, $id)。
     *
     * @param mixed $id
     * @return $this
     */
    public function whereKey($id)
    {
        $this->assertScalarKey($id, 'whereKey');
        $this->query->where($this->model->getKeyName(), $id);

        return $this;
    }

    /**
     * 排序：orderBy('sort', 'DESC') → 底层 order('sort DESC')。
     *
     * @param string $field
     * @param string $direction
     * @return $this
     */
    public function orderBy($field, $direction = 'ASC')
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        $this->query->order($field . ' ' . $direction);

        return $this;
    }

    /**
     * 取集合：水合 + eager load + prefetch。
     *
     * @return Collection
     */
    public function get()
    {
        $this->applyGlobalScopes();
        $rows = $this->query->select();
        if (!is_array($rows)) {
            $rows = array();
        }

        return $this->hydrateRows($rows);
    }

    /**
     * 取首条：Model|null。
     *
     * @param string|null $field
     * @return Model|null
     */
    public function first($field = null)
    {
        if ($field !== null && $field !== '' && $field !== '*') {
            $this->query->field($field);
        }
        $this->query->limit(1);

        return $this->get()->first();
    }

    /**
     * 按主键查单条：Model|null。
     *
     * @param int $id
     * @param string $field
     * @return Model|null
     */
    public function find($id, $field = '*')
    {
        $this->assertScalarKey($id, 'find');
        $this->query->where($this->model->getKeyName(), $id);

        return $this->first($field);
    }

    /**
     * 主键入参守护：拒绝 null/''/数组/对象等非标量主键。
     *
     * 防止 `find(null)` 退化为 `WHERE id IS NULL` 误取首行、`find(array)` 在 PHP 8
     * 触发 strtoupper(array) 的 TypeError，以及 whereKey 误把整批行纳入 update/delete。
     *
     * @param mixed $id
     * @param string $method 调用方法名（用于异常信息）
     * @return void
     */
    private function assertScalarKey($id, $method)
    {
        if ($id === null || $id === '' || is_array($id) || is_object($id)) {
            throw new \InvalidArgumentException(
                'Builder::' . $method . '() expects a scalar primary key, got ' . gettype($id)
            );
        }
    }

    /**
     * 分页：返回 ['list' => Collection, 'pager' => array]。
     *
     * @param int $pageSize
     * @param int $page
     * @param string $pageUrl
     * @param string $get
     * @param bool $closeRewrite
     * @param string $recordCountReduce
     * @return array
     */
    public function paginate($pageSize = 10, $page = 1, $pageUrl = '', $get = '', $closeRewrite = false, $recordCountReduce = '')
    {
        $this->applyGlobalScopes();
        $result = $this->query->paginate($pageSize, $page, $pageUrl, $get, $closeRewrite, $recordCountReduce);
        $rows = isset($result['list']) && is_array($result['list']) ? $result['list'] : array();
        $result['list'] = $this->hydrateRows($rows);

        return $result;
    }

    /**
     * 水合原始行集为 Collection，并执行 eager load 与 prefetch。
     *
     * @param array $rows
     * @return Collection
     */
    protected function hydrateRows(array $rows)
    {
        $models = array();
        foreach ($rows as $row) {
            $models[] = $this->model->newFromBuilder($row);
        }
        $collection = $this->model->newCollection($models);

        if (!empty($models)) {
            $this->eagerLoadRelations($collection);
            PrefetchRunner::run($rows, $this->model);
            foreach ($this->afterHydrate as $callback) {
                call_user_func($callback, $collection);
            }
        }

        return $collection;
    }

    /**
     * @param Collection $models
     * @return void
     */
    protected function eagerLoadRelations(Collection $models)
    {
        foreach ($this->eagerLoad as $name => $options) {
            $this->loadRelation($models, $name, $options);
        }
    }

    /**
     * @param Collection $models
     * @param string $name
     * @param array $options 归一化预加载选项：array('nested' => array(), 'constraint' => callable|null)
     * @return void
     */
    protected function loadRelation(Collection $models, $name, array $options)
    {
        if (!method_exists($this->model, $name)) {
            return;
        }
        $relation = $this->model->$name();
        if (!($relation instanceof Relation)) {
            return;
        }
        $nested = isset($options['nested']) ? (array) $options['nested'] : array();
        $constraint = isset($options['constraint']) ? $options['constraint'] : null;
        $relation->setEagerOptions($nested, $constraint);

        $items = $models->all();
        $relation->addEagerConstraints($items);
        $results = $relation->getEager();
        $relation->match($items, $results, $name);
    }

    /**
     * 透传底层 Connection 链式 / 标量终结方法，并提供 scope 局部作用域探测。
     *
     * 调度优先级：
     *   1. Model 上存在 scope{Ucfirst(method)} 同名方法 → 调用 scope，注入 $this（Builder）+ 原参数；
     *      scope 返回 Builder 时透传新链，否则视为副作用 scope 返回 $this 保持链式。
     *   2. 透传底层 Connection：链式方法（返回 Connection 自身）返回 $this（Builder），
     *      标量 / 数组终结（count/sum/value/exists/insert/update/delete/select/...）原样返回。
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        $scope = 'scope' . ucfirst($method);
        if (method_exists($this->model, $scope)) {
            $result = call_user_func_array(
                array($this->model, $scope),
                array_merge(array($this), $parameters)
            );

            return $result instanceof self ? $result : $this;
        }

        // 只读聚合方法（count/sum/exists/...）：透传前应用全局 scope，保持与 get()/first() 一致。
        // 否则一旦模型注册了 addGlobalScope（如软删除/可见性），count() 与 get()->count() 结果会不一致。
        // $appliedGlobalScopes 守卫确保重复聚合调用只应用一次。
        if (isset($this->aggregateMethods[$method])) {
            $this->applyGlobalScopes();
        }

        $result = call_user_func_array(array($this->query, $method), $parameters);

        if ($result === $this->query) {
            return $this;
        }

        return $result;
    }
}

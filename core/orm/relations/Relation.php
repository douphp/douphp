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

namespace Dou\Core\Orm\Relations;

use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Collection;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 关系抽象基类：定义惰性取值（getResults）与批量预加载（addEagerConstraints/getEager/match）契约。
 */
abstract class Relation
{
    /** @var Model 父模型（声明关系的一方） */
    protected $parent;

    /** @var Model 关联模型原型 */
    protected $related;

    /** @var string 外键字段名 */
    protected $foreignKey;

    /** @var string 本端/关联端键字段名（BelongsTo 为 ownerKey，HasOne/HasMany 为 localKey） */
    protected $localKey;

    /** @var array 预加载收集到的键集合 */
    protected $eagerKeys = array();

    /** @var array 嵌套预加载关系（喂回关联模型 newQuery()->with(...)） */
    protected $nested = array();

    /** @var callable|null 关联 eager 查询的约束闭包 */
    protected $constraint;

    /**
     * @param Model $parent
     * @param Model $related
     * @param string $foreignKey
     * @param string $localKey
     */
    public function __construct(Model $parent, Model $related, $foreignKey, $localKey)
    {
        $this->parent = $parent;
        $this->related = $related;
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
    }

    /**
     * 注入 eager 选项：嵌套关系与约束闭包（由 Builder::loadRelation 透传）。
     *
     * @param array $nested
     * @param callable|null $constraint
     * @return $this
     */
    public function setEagerOptions(array $nested, $constraint = null)
    {
        $this->nested = $nested;
        $this->constraint = $constraint;

        return $this;
    }

    /**
     * 把嵌套预加载与约束闭包应用到关联模型查询。
     *
     * @param Builder $query
     * @return Builder
     */
    protected function applyEagerOptions($query)
    {
        if (!empty($this->nested)) {
            $query->with($this->nested);
        }
        if ($this->constraint !== null) {
            call_user_func($this->constraint, $query);
        }

        return $query;
    }

    /**
     * 收集父模型集合的关联键（用于批量 whereIn）。
     *
     * @param array $models
     * @return void
     */
    abstract public function addEagerConstraints(array $models);

    /**
     * 批量取关联结果集。
     *
     * @return Collection
     */
    abstract public function getEager();

    /**
     * 将关联结果回填到各父模型。
     *
     * @param array $models
     * @param Collection $results
     * @param string $name
     * @return void
     */
    abstract public function match(array $models, $results, $name);

    /**
     * 单父模型惰性取值。
     *
     * @return mixed
     */
    abstract public function getResults();

    /**
     * 收集集合中某字段去重非空值。
     *
     * @param array $models
     * @param string $key
     * @return array
     */
    protected function collectKeys(array $models, $key)
    {
        $keys = array();
        foreach ($models as $model) {
            if (!($model instanceof Model)) {
                continue;
            }
            $value = $model->getRawAttribute($key);
            if ($value !== null && $value !== '') {
                $keys[(string) $value] = $value;
            }
        }

        return array_values($keys);
    }
}

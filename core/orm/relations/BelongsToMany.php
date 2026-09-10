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

use Dou\Core\Orm\Collection;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 多对多关系：父模型与关联模型经中间表（pivot）关联。
 * 例：Product->belongsToMany(Tag::class, 'product_tag', 'product_id', 'tag_id')。
 *
 * 采用两步法（pivot → related，均走 whereIn）而非 JOIN：底层 Connection 的 join
 * 要求表别名 + 不带前缀的 alias.field 条件，两步法对表前缀透明、无别名约束，且
 * eager / lazy 路径一致。pivot 映射在 getEager() 时构建，供 match() 按父键分组回填。
 */
class BelongsToMany extends Relation
{
    /** @var string 中间表名（逻辑名，不含前缀） */
    protected $pivotTable;

    /** @var string 中间表指向父模型的外键 */
    protected $foreignPivotKey;

    /** @var string 中间表指向关联模型的外键 */
    protected $relatedPivotKey;

    /** @var string 父模型键（默认父主键） */
    protected $parentKey;

    /** @var string 关联模型键（默认关联主键） */
    protected $relatedKey;

    /** @var array 父键 => array(关联键, ...) 映射，getEager 时构建 */
    protected $pivotMap = array();

    /**
     * @param Model $parent
     * @param Model $related
     * @param string $pivotTable
     * @param string $foreignPivotKey
     * @param string $relatedPivotKey
     * @param string $parentKey
     * @param string $relatedKey
     */
    public function __construct(Model $parent, Model $related, $pivotTable, $foreignPivotKey, $relatedPivotKey, $parentKey, $relatedKey)
    {
        parent::__construct($parent, $related, $foreignPivotKey, $parentKey);
        $this->pivotTable = $pivotTable;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;
    }

    /**
     * @param array $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $this->eagerKeys = $this->collectKeys($models, $this->parentKey);
    }

    /**
     * @return Collection
     */
    public function getEager()
    {
        return $this->loadPivotRelations($this->eagerKeys);
    }

    /**
     * 两步法加载关联：查 pivot 表 → 建 pivotMap（父键 => array(关联键)）→ 查 related 表。
     *
     * eager / lazy 路径统一走此方法：eager 传入批量父键集合，lazy 传入单元素数组。
     * pivotMap 在此构建，供 match() 按父键分组回填。
     *
     * @param array $parentKeys 父模型键集合（已去重非空）
     * @return Collection
     */
    private function loadPivotRelations(array $parentKeys)
    {
        $this->pivotMap = array();
        if (empty($parentKeys)) {
            return $this->related->newCollection(array());
        }

        $pivotRows = Model::getConnection()->table($this->pivotTable)
            ->whereIn($this->foreignPivotKey, $parentKeys)
            ->select();
        if (!is_array($pivotRows) || empty($pivotRows)) {
            return $this->related->newCollection(array());
        }

        $relatedKeys = array();
        foreach ($pivotRows as $row) {
            $parentVal = isset($row[$this->foreignPivotKey]) ? (string) $row[$this->foreignPivotKey] : '';
            $relatedVal = isset($row[$this->relatedPivotKey]) ? $row[$this->relatedPivotKey] : null;
            if ($parentVal === '' || $relatedVal === null || $relatedVal === '') {
                continue;
            }
            $this->pivotMap[$parentVal][] = (string) $relatedVal;
            $relatedKeys[(string) $relatedVal] = $relatedVal;
        }
        if (empty($relatedKeys)) {
            return $this->related->newCollection(array());
        }

        $query = $this->related->newQuery()->whereIn($this->relatedKey, array_values($relatedKeys));
        $this->applyEagerOptions($query);

        return $query->get();
    }

    /**
     * @param array $models
     * @param Collection $results
     * @param string $name
     * @return void
     */
    public function match(array $models, $results, $name)
    {
        $relatedById = array();
        foreach ($results->all() as $result) {
            $relatedById[(string) $result->getRawAttribute($this->relatedKey)] = $result;
        }

        foreach ($models as $model) {
            if (!($model instanceof Model)) {
                continue;
            }
            $parentVal = (string) $model->getRawAttribute($this->parentKey);
            $items = array();
            if (isset($this->pivotMap[$parentVal])) {
                foreach ($this->pivotMap[$parentVal] as $relatedVal) {
                    if (isset($relatedById[$relatedVal])) {
                        $items[] = $relatedById[$relatedVal];
                    }
                }
            }
            $model->setRelation($name, $this->related->newCollection($items));
        }
    }

    /**
     * @return Collection
     */
    public function getResults()
    {
        $parentVal = $this->parent->getRawAttribute($this->parentKey);
        if ($parentVal === null || $parentVal === '') {
            return $this->related->newCollection(array());
        }

        return $this->loadPivotRelations(array($parentVal));
    }
}

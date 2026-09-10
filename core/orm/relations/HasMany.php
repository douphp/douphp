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
 * 一对多关系：关联模型持外键（foreignKey）指向父模型的键（localKey）。
 */
class HasMany extends Relation
{
    /**
     * @param array $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $this->eagerKeys = $this->collectKeys($models, $this->localKey);
    }

    /**
     * @return Collection
     */
    public function getEager()
    {
        if (empty($this->eagerKeys)) {
            return $this->related->newCollection(array());
        }
        $query = $this->related->newQuery()->whereIn($this->foreignKey, $this->eagerKeys);
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
        $dictionary = array();
        foreach ($results->all() as $result) {
            $dictionary[(string) $result->getRawAttribute($this->foreignKey)][] = $result;
        }

        foreach ($models as $model) {
            if (!($model instanceof Model)) {
                continue;
            }
            $local = (string) $model->getRawAttribute($this->localKey);
            $items = isset($dictionary[$local]) ? $dictionary[$local] : array();
            $model->setRelation($name, $this->related->newCollection($items));
        }
    }

    /**
     * @return Collection
     */
    public function getResults()
    {
        $local = $this->parent->getRawAttribute($this->localKey);
        if ($local === null || $local === '') {
            return $this->related->newCollection(array());
        }
        $query = $this->related->newQuery()->where($this->foreignKey, $local);
        $this->applyEagerOptions($query);

        return $query->get();
    }
}

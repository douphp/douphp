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
 * 从属关系：父模型持外键（foreignKey），指向关联模型的键（localKey 即 ownerKey）。
 * 例：ArticleModel->belongsTo(CategoryModel, 'category_id')。
 */
class BelongsTo extends Relation
{
    /**
     * @param array $models
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $this->eagerKeys = $this->collectKeys($models, $this->foreignKey);
    }

    /**
     * @return Collection
     */
    public function getEager()
    {
        if (empty($this->eagerKeys)) {
            return $this->related->newCollection(array());
        }
        $query = $this->related->newQuery()->whereIn($this->localKey, $this->eagerKeys);
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
            $dictionary[(string) $result->getRawAttribute($this->localKey)] = $result;
        }

        foreach ($models as $model) {
            if (!($model instanceof Model)) {
                continue;
            }
            $foreign = $model->getRawAttribute($this->foreignKey);
            $value = ($foreign !== null && isset($dictionary[(string) $foreign])) ? $dictionary[(string) $foreign] : null;
            $model->setRelation($name, $value);
        }
    }

    /**
     * @return Model|null
     */
    public function getResults()
    {
        $foreign = $this->parent->getRawAttribute($this->foreignKey);
        if ($foreign === null || $foreign === '') {
            return null;
        }
        $query = $this->related->newQuery()->where($this->localKey, $foreign);
        $this->applyEagerOptions($query);

        return $query->first();
    }
}

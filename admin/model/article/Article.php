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

namespace Dou\Admin\Model\Article;

use Dou\Admin\Model\Concerns\PurgesRelatedOnDelete;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Model\Concerns\ContentMutators;
use Dou\Core\Model\Concerns\HasCategoryFilter;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台文章数据模型
 */
class Article extends Model
{
    use ContentMutators;
    use HasCategoryFilter;
    use PurgesRelatedOnDelete;

    /**
     * 对应的数据表名（主键沿用默认 id）。
     *
     * @var string
     */
    protected $table = 'article';

    /** @var array 列表读取时格式化（image→附件URL、created_at→Y-m-d、status→语言串三元组） */
    protected $casts = array(
        'category_id' => 'int',
        'sort' => 'int',
        'image' => 'attachment',
        'created_at' => 'datetime:Y-m-d',
        'status' => 'data_lang:status_',
    );

    /** @var array 列表批量预热附件 URL */
    protected $prefetchers = array(
        'attachment' => 'image',
    );

    /**
     * 允许批量写入的数据表字段白名单（持久化层）。
     *
     * 说明：
     * - 此处仅声明 article 表真实字段，供 Model::fill() 过滤入库数据使用。
     * - 不承担请求参数校验职责；表单校验规则请见 ArticleFormRequest::rules()。
     * - 允许只在请求层存在的控制参数（如 content_remote_image_local）不应放在此处。
     *
     * @var array
     */
    protected $fillable = array(
        'operator_type',
        'operator_id',
        'category_id',
        'title',
        'slug',
        'defined',
        'content',
        'keywords',
        'description',
        'sort',
        'created_at',
        'image',
    );

    /**
     * 关联分类（category_id → article_category）。
     *
     * @return \Dou\Core\Orm\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(ArticleCategory::class, 'category_id');
    }

    /**
     * 按标题关键字模糊筛选；空字符串透传。
     *
     * @param Builder $query
     * @param string $keyword
     * @return Builder
     */
    public function scopeFilterByKeyword(Builder $query, $keyword)
    {
        $keyword = (string) $keyword;
        if ($keyword === '') {
            return $query;
        }

        return $query->where('title', 'LIKE', '%' . $keyword . '%');
    }

    /**
     * 默认列表排序：启用手动排序时 sort ASC, id DESC，否则纯 id DESC。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        $sort = Config::get('features.sort', false) ? 'sort ASC, id DESC' : 'id DESC';

        return $query->order($sort);
    }
}

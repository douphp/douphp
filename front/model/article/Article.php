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

namespace Dou\Front\Model\Article;

use Dou\Core\Model\Concerns\HasCategoryFilter;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;
use Dou\Front\Model\Concerns\HasAddTimeAccessors;
use Dou\Front\Model\Concerns\HasCateInfoAccessor;
use Dou\Front\Model\Concerns\HasContentLift;
use Dou\Front\Model\Concerns\HasContentListFields;
use Dou\Front\Model\Concerns\HasExportableContent;
use Dou\Front\Model\Concerns\HasRelatedQuery;
use Dou\Front\Model\Concerns\HasUrlAccessor;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台文章数据模型。
 *
 * 列表读取走 AR：with('category') 批量取分类、casts 格式化 image / defined / created_at、
 * prefetchers 预热 url / 多语言 / 附件，translatable 覆写 title / content / description 当前语言值。
 * 列表 Presenter 字段（url / name / description / cate_info / add_time_short / time）
 * 由 concerns trait 经 accessor + $appends 承接。
 */
class Article extends Model
{
    use HasAddTimeAccessors;
    use HasCateInfoAccessor;
    use HasCategoryFilter;
    use HasContentLift;
    use HasContentListFields;
    use HasExportableContent;
    use HasRelatedQuery;
    use HasUrlAccessor;

    protected $table = 'article';

    /** @var array 列表读取时格式化（image→附件URL、defined→键值对、created_at→Y-m-d） */
    protected $casts = array(
        'category_id' => 'int',
        'click' => 'int',
        'image' => 'attachment',
        'defined' => 'defined_pairs',
        'created_at' => 'datetime:Y-m-d',
    );

    /** @var array toArray 额外附加：列表 Presenter 派生字段 */
    protected $appends = array('add_time_short', 'time', 'name', 'description', 'url', 'favorites', 'cate_info');

    /** @var array title/content/description/keywords 随当前语言覆写（keywords 供详情页 SEO） */
    protected $translatable = array('title', 'content', 'description', 'keywords');

    /** @var array 列表批量预热：url 构建缓存、多语言、附件 */
    protected $prefetchers = array(
        'url' => true,
        'language' => 'title,content,description,keywords',
        'attachment' => 'image',
    );

    /**
     * 模块 schema：声明式描述本模块的可选能力（status / gallery / user 字段等），
     * 供 Reader 等读层按 hasGallery / hasUserFields 等键做按模块分支。
     *
     * @return array
     */
    public static function moduleSchema()
    {
        return array(
            'module' => 'article',
            'hasStatus' => true,
            'publishedValue' => '1',
            'hasGallery' => false,
            'hasUserFields' => false,
            'export' => array(
                'sitemap' => true,
                'llms' => true,
            ),
        );
    }

    /**
     * 对应分类 Model（供 HasExportableContent::categoriesForExport 取用）。
     *
     * @return string
     */
    public static function categoryClass()
    {
        return ArticleCategory::class;
    }

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
     * 已发布文章单行（AR：返回水合 Model，casts/translatable/prefetch 承接格式化）。
     *
     * @param int $articleId
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findPublishedById($articleId)
    {
        return static::published()->find((int) $articleId);
    }

    /**
     * 仅已发布文章（status = 1）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePublished(Builder $query)
    {
        return $query->where('status', 1);
    }

    /**
     * 按归档（年/月）时间窗筛选；$archive 为空时透传。
     *
     * @param Builder $query
     * @param array $archive ['start' => ts, 'end' => ts] 或空
     * @return Builder
     */
    public function scopeFilterByArchive(Builder $query, $archive)
    {
        if (empty($archive)) {
            return $query;
        }

        return $query
            ->where('created_at', '>=', date('Y-m-d H:i:s', (int) $archive['start']))
            ->where('created_at', '<=', date('Y-m-d H:i:s', (int) $archive['end']));
    }

    /**
     * 仅含主图的列表项（image 字段非空字符串）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeImageNotEmpty(Builder $query)
    {
        return $query->where('image', '!=', '');
    }

    /**
     * 默认排序（sort ASC, id DESC）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('sort ASC, id DESC');
    }
}

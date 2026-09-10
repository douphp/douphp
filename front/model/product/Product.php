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

namespace Dou\Front\Model\Product;

use Dou\Core\Facade\DB;
use Dou\Core\Model\Concerns\HasCategoryFilter;
use Dou\Core\Model\Concerns\HasDistinctValues;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;
use Dou\Front\Model\Brand\Brand;
use Dou\Front\Model\Concerns\HasAddTimeAccessors;
use Dou\Front\Model\Concerns\HasCateInfoAccessor;
use Dou\Front\Model\Concerns\HasContentLift;
use Dou\Front\Model\Concerns\HasContentListFields;
use Dou\Front\Model\Concerns\HasExportableContent;
use Dou\Front\Model\Concerns\HasForUserScope;
use Dou\Front\Model\Concerns\HasRelatedQuery;
use Dou\Front\Model\Concerns\HasUrlAccessor;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台商品数据模型。
 *
 * 列表读取走 AR：with('category') 批量取分类、casts 格式化 image / defined / created_at、
 * thumb / image_other accessor 提供缩略图与附加主图 URL，prefetchers 预热
 * url / 多语言 / 附件（缩略图）/ 相册首图。
 *
 * 价格 / 销售价 / 收藏态等会员视图字段经 forUser scope 在水合后整批补值（HasForUserScope）；
 * 详情读路径不挂 forUser，详情 toArray 保留原始 price、不含 sale_price。
 */
class Product extends Model
{
    use HasAddTimeAccessors;
    use HasCateInfoAccessor;
    use HasCategoryFilter;
    use HasContentLift;
    use HasContentListFields;
    use HasDistinctValues;
    use HasExportableContent;
    use HasForUserScope;
    use HasRelatedQuery;
    use HasUrlAccessor;

    protected $table = 'product';

    /** @var array */
    protected $casts = array(
        'category_id' => 'int',
        'click' => 'int',
        'stock' => 'int',
        'sales' => 'int',
        'image' => 'attachment',
        'defined' => 'defined_pairs',
        'created_at' => 'datetime:Y-m-d',
    );

    /** @var array toArray 额外附加：列表 Presenter 派生字段 */
    protected $appends = array('thumb', 'image_other', 'add_time_short', 'time', 'name', 'description', 'url', 'favorites', 'cate_info');

    /** @var array name/title/content/description 随当前语言覆写 */
    protected $translatable = array('name', 'title', 'content', 'description');

    /** @var array 列表批量预热：url 构建缓存、多语言、附件全图与缩略图、相册首图 */
    protected $prefetchers = array(
        'url' => true,
        'language' => 'name,title,content,description',
        'attachment' => 'image',
        'attachment_thumb' => 'image',
        'gallery_first' => true,
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
            'module' => 'product',
            'hasStatus' => true,
            'publishedValue' => '1',
            'hasGallery' => true,
            'hasUserFields' => true,
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
        return ProductCategory::class;
    }

    /**
     * 关联分类（category_id → product_category）。
     *
     * @return \Dou\Core\Orm\Relations\BelongsTo
     */
    public function category()
    {
        return $this->belongsTo(ProductCategory::class, 'category_id');
    }

    /**
     * 关联品牌（brand_id → brand）；brand 仅 product 模块有。
     * 配合 with('category', 'brand') 一次性预加载分类+品牌。
     *
     * @return \Dou\Core\Orm\Relations\BelongsTo
     */
    public function brand()
    {
        return $this->belongsTo(Brand::class, 'brand_id');
    }

    /**
     * 缩略图 URL：读原始 image 文件号，走 attachment 缩略图链路。
     *
     * @return string
     */
    public function getThumbAttribute()
    {
        $image = (string) $this->getRawAttribute('image');

        return attachment()->url($image, true);
    }

    /**
     * 附加主图 URL：相册首图（gallery_first 预热后命中进程内缓存）。
     *
     * @return string
     */
    public function getImageOtherAttribute()
    {
        return attachment()->galleryFirst($this->getTable(), (int) $this->getKey());
    }

    /**
     * 上架商品单行（AR：返回水合 Model，casts/translatable/prefetch 承接格式化）。
     *
     * @param int $productId
     * @return \Dou\Core\Orm\Model|null
     */
    public static function findPublishedById($productId)
    {
        return static::published()->find((int) $productId);
    }

    /**
     * 分类单行
     *
     * @param int $catId
     * @return array|false
     */
    public static function findCategoryByCatId($catId)
    {
        return DB::table('product_category')->where('id', (int) $catId)->find();
    }

    /**
     * 分类 parent_id
     *
     * @param int $catId
     * @return mixed
     */
    public static function getCategoryParentId($catId)
    {
        return DB::table('product_category')->where('id', (int) $catId)->value('parent_id');
    }

    /**
     * API 属性计算基础字段
     *
     * @param int $productId
     * @return array|false
     */
    public static function findApiAttributeBase($productId)
    {
        return static::field('id, category_id, price, point')
            ->where('id', (int) $productId)
            ->first();
    }

    /**
     * 第一个分类 id
     *
     * @return mixed
     */
    public static function findFirstCategoryId()
    {
        return DB::table('product_category')->field('id')->order('sort ASC, id ASC')->value('id');
    }

    /**
     * 仅上架商品（status = '1'）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePublished(Builder $query)
    {
        return $query->where('status', '1');
    }

    /**
     * 同分类随机相关推荐：覆写 HasRelatedQuery 默认实现，注入 forUser 提供
     * 会员视图字段（sale_price / favorites）；与列表页 forUser 语义一致。
     *
     * @param int|string $catId
     * @param int $number
     * @param int $userId 当前会员 ID（0 = 未登录）
     * @return \Dou\Core\Orm\Collection
     */
    public static function related($catId, $number = 4, $userId = 0)
    {
        return static::published()
            ->with('category')
            ->forUser((int) $userId)
            ->filterByCategory($catId)
            ->order('RAND()')
            ->limit((int) $number)
            ->get();
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
     * 按品牌筛选；$brandId <= 0 时透传。
     *
     * @param Builder $query
     * @param mixed $brandId
     * @return Builder
     */
    public function scopeFilterByBrand(Builder $query, $brandId)
    {
        $brandId = (int) $brandId;
        if ($brandId <= 0) {
            return $query;
        }

        return $query->where('brand_id', $brandId);
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

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

namespace Dou\Admin\Model\Product;

use Dou\Admin\Model\Concerns\PurgesRelatedOnDelete;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Model\Concerns\ContentMutators;
use Dou\Core\Model\Concerns\HasCategoryFilter;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 商品数据模型
 */
class Product extends Model
{
    use ContentMutators;
    use HasCategoryFilter;
    use PurgesRelatedOnDelete;

    /**
     * 对应的数据表名（主键沿用默认 id）。
     *
     * @var string
     */
    protected $table = 'product';

    /** @var array 列表读取时格式化（image→附件URL、created_at→Y-m-d、status→语言串、计数→int） */
    protected $casts = array(
        'category_id' => 'int',
        'sort' => 'int',
        'stock' => 'int',
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
     * - 此处仅声明 product 表真实字段，供 Model::fill() 过滤入库数据使用。
     * - 不承担请求参数校验职责；表单校验规则请见 ProductFormRequest::rules()。
     * - 允许只在请求层存在的控制参数（如 content_remote_image_local）不应放在此处。
     *
     * @var array
     */
    protected $fillable = array(
        'operator_type',
        'operator_id',
        'category_id',
        'brand_id',
        'title',
        'slug',
        'price',
        'promote_price',
        'level_price',
        'stock',
        'defined',
        'content',
        'image',
        'point',
        'keywords',
        'description',
        'sort',
        'created_at',
    );

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
     * 按标题关键字模糊筛选；空字符串透传。
     *
     * @param Builder $query
     * @param string $keyword
     * @return Builder
     */
    public function scopeFilterByKeyword(Builder $query, $keyword)
    {
        $keyword = is_string($keyword) ? trim($keyword) : '';
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
        $sort = !empty(Config::get('features.sort', false)) ? 'sort ASC, id DESC' : 'id DESC';

        return $query->order($sort);
    }

    /**
     * 仅上架商品（status = 1）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopePublished(Builder $query)
    {
        return $query->where('status', '1');
    }

    /**
     * 仅当前工作台拥有的商品（operator_type='work' AND operator_id=$workId）。
     *
     * 由 api 工作端 List / Edit / Update / Delete / UploadImage 共用，做权限边界。
     * 注意：$workId 必须由调用方按已登录工作台显式传入；本 scope 不做兜底，
     *      $workId <= 0 时落得到 operator_id = 0 的条件，结果集为空（不会越权）。
     *
     * @param Builder $query
     * @param int $workId
     * @return Builder
     */
    public function scopeOwnedByWork(Builder $query, $workId)
    {
        return $query
            ->where('operator_type', 'work')
            ->where('operator_id', (int) $workId);
    }

    /**
     * 删除前：商品关联的多语言图片字段（image、show_img）值列表
     *
     * @param int $productId
     * @return array
     */
    public static function getLanguageImageValues($productId)
    {
        return DB::table('language_value')
            ->field('value')
            ->where('module', 'product')
            ->where('item_id', (int) $productId)
            ->where('field', 'IN', array('image', 'show_img'))
            ->select();
    }

    /**
     * 删除商品所有多语言记录
     *
     * @param int $productId
     * @return void
     */
    public static function deleteLanguageValues($productId)
    {
        DB::table('language_value')
            ->where('module', 'product')
            ->where('item_id', (int) $productId)
            ->delete();
    }

    /**
     * 缩略图重生成：需原始 mysqli 结果集（与 File 逐条 thumb 流式输出配合）
     *
     * @return array query 资源, count
     */
    public static function getThumbFileQueryList()
    {
        $sql = 'SELECT file FROM ' . DB::table('file') . " WHERE module = 'product' AND thumb_size > 0 ORDER BY id ASC";
        $query = DB::query($sql);
        $count = DB::numRows($query);
        return array('query' => $query, 'count' => $count);
    }

    /**
     * 读取并确保商品有型号串，无则生成并回写
     *
     * @param string|int $id
     * @return string
     */
    public static function ensureOrCreateModelNumber($id)
    {
        $model = DB::table(static::tableName())->field('model')->where('id', $id)->value('model');
        if ($model) {
            return $model;
        }
        $model = static::generateUniqueModelSn();
        static::where('id', $id)->update(array('model' => $model));
        return $model;
    }

    /**
     * 生成数据库内唯一的商品 model 字段值。
     *
     * @return string
     */
    private static function generateUniqueModelSn()
    {
        $model_sn = Str::randomByType('number', 5);
        if (DB::table('product')->where('model', $model_sn)->find()) {
            return static::generateUniqueModelSn();
        }

        return $model_sn;
    }

    /**
     * 将指定商品的 model 字段设为指定值
     *
     * @param string|int $productId
     * @param string $modelValue
     * @return void
     */
    public static function setModelById($productId, $modelValue)
    {
        static::where('id', $productId)->update(array('model' => $modelValue));
    }

    /**
     * 将同一型号串下的所有商品 model 置空
     *
     * @param string $model
     * @return void
     */
    public static function clearModelByModelString($model)
    {
        static::where('model', $model)->update(array('model' => ''));
    }

    /**
     * 将单个商品的 model 置空
     *
     * @param string|int $productId
     * @return void
     */
    public static function clearModelById($productId)
    {
        static::where('id', $productId)->update(array('model' => ''));
    }

    /**
     * 删除商品前：清理图库附件与多语言记录（覆写默认主图清理）。
     *
     * @return void
     */
    protected function purgeRelatedOnDelete()
    {
        foreach ((array) self::getGalleryFileNumberRows($this->getKey()) as $row) {
            attachment()->delete($row['number']);
        }
        $this->purgeLanguageOnDelete();
    }

    /**
     * 删除前：商品图库 file 表记录（仅取 number 字段）
     *
     * @param string|int $productId
     * @return array
     */
    public static function getGalleryFileNumberRows($productId)
    {
        return DB::table('file')
            ->field('number')
            ->where('module', 'product')
            ->where('item_id', $productId)
            ->order('id ASC')
            ->select();
    }
}

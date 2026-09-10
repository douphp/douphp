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

use Dou\Core\Model\Concerns\HasCategoryTree;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台文章分类数据模型（与 CategoryController 对应）
 */
class ArticleCategory extends Model
{
    use HasCategoryTree;

    /**
     * 对应的数据表名。
     *
     * @var string
     */
    protected $table = 'article_category';

    /**
     * 对应数据表主键字段名。
     *
     * @var string
     */
    protected $primary = 'id';

    /**
     * 分类下的业务记录表（通过 category_id 关联）。
     *
     * @var string
     */
    protected $recordsTable = 'article';

    /**
     * 允许批量写入的数据表字段白名单（持久化层）。
     *
     * 说明：
     * - 此处仅声明 article_category 表真实字段，供 Model::fill() 过滤入库数据使用。
     * - 不承担请求参数校验职责；表单校验规则请见 Article\CategoryFormRequest::rules()。
     *
     * @var array
     */
    protected $fillable = array(
        'name',
        'slug',
        'parent_id',
        'icon',
        'keywords',
        'description',
        'sync_to_nav',
        'sort',
    );
}

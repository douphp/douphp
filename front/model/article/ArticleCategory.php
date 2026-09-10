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

use Dou\Core\Model\Concerns\HasCategoryTree;
use Dou\Core\Orm\Model;
use Dou\Front\Model\Concerns\HasCategoryWithItems;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台文章分类数据模型（供 Article::with('category') 关联取用 / ArticleCategory::withItems() 取分类树挂内容）。
 */
class ArticleCategory extends Model
{
    use HasCategoryTree;
    use HasCategoryWithItems;

    protected $table = 'article_category';

    protected $primary = 'id';

    /** @var array name 随当前语言覆写（依赖 language 预热） */
    protected $translatable = array('name');

    protected $translatableModule = 'article_category';

    /** @var array 列表/关联读取时批量预热 name 多语言值 */
    protected $prefetchers = array(
        'language' => 'name',
    );

    protected $casts = array(
        'id' => 'int',
        'parent_id' => 'int',
        'sort' => 'int',
    );
}

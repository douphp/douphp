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

namespace Dou\Front\Service\Index;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Str;
use Dou\Front\Model\Article\Article;
use Dou\Front\Model\Product\Product;
use Dou\Front\Model\Product\ProductCategory;
use Dou\Front\Model\Show\Show;
use Dou\Front\Service\Seo\SchemaService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台首页业务层（首页数据）
 */
class IndexService extends BaseService
{
    /** @var SchemaService */
    private $schema;

    /**
     * @param SchemaService $schema
     */
    public function __construct(SchemaService $schema)
    {
        $this->schema = $schema;
    }

    /**
     * 构建首页数据（供控制器使用；不含导航、全站 SEO 等纯展示 assign）
     *
     * @param int $userId 当前登录会员（影响会员价 / 收藏视图）
     * @return array index、show_list、recommend_product、new_product、recommend_article、code_head
     */
    public function buildIndexData($userId = 0)
    {
        $userId = (int) $userId;
        $about = DB::table('page')->where('id', '1')->find();
        if (!$about || !is_array($about)) {
            $about = array(
                'name' => '',
                'description' => '',
                'content' => '',
            );
        }

        $about = language()->langBox($about, 'page', 'name, description, content');

        $aboutContent = '';
        if (!empty($about['description'])) {
            $aboutContent = $about['description'];
        } elseif (!empty($about['content'])) {
            $aboutContent = Str::excerpt($about['content'], 300, false);
        }

        $index = array(
            'about_name' => isset($about['name']) ? $about['name'] : '',
            'about_content' => $aboutContent,
            'about_link' => route('page.show', ['id' => '1']),
            'cur' => true,
        );

        $homeProduct = Config::get('pagination.home_product', 10);
        $homeArticle = Config::get('pagination.home_article', 10);

        $schema = $this->schema->index();
        $codeHead = $schema ? $schema . Config::get('site.code_head', '') : Config::get('site.code_head', '');

        $productOn = (bool) Config::get('features.product', false);
        $articleOn = (bool) Config::get('features.article', false);

        return array(
            'index' => $index,
            'show_list' => Show::showList(),
            'recommend_product' => $productOn ? Product::published()->with('category')->forUser($userId)->applyDefaultOrder()->limit($homeProduct)->get() : array(),
            'new_product' => $productOn ? Product::published()->with('category')->forUser($userId)->order('id DESC')->limit($homeProduct)->get() : array(),
            'recommend_article' => $articleOn ? Article::published()->with('category')->applyDefaultOrder()->limit($homeArticle)->get() : array(),
            'product_category' => $productOn ? ProductCategory::tree() : array(),
            'code_head' => $codeHead,
        );
    }
}

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

namespace Dou\Front\Service\Article;

use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Support\Str;
use Dou\Front\Model\Article\Article;
use Dou\Front\Model\Article\ArticleCategory;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台文章业务层（列表 / 详情）
 */
class ArticleService extends BaseService
{
    /** @var MarkdownRenderer */
    private $markdown;

    /**
     * @param MarkdownRenderer $markdown
     */
    public function __construct(MarkdownRenderer $markdown)
    {
        $this->markdown = $markdown;
    }

    /**
     * 构建文章列表 / 分类 / 归档页数据（供控制器使用）
     *
     * @param int $catId 分类 id（0 表示全部分类语境）
     * @param int $page 当前页
     * @param int $pageSize 每页显示数量，0 表示使用配置值
     * @param string|null $year 年参数
     * @param string|null $month 月参数
     * @return array
     */
    public function buildArticleListData($catId, $page, $pageSize = 10, $archive = [])
    {
        if ($archive) {
            $pageUrl = route('article.category') . '/' . $archive['year'];
            if ($archive['month']) {
                $pageUrl .= '/' . $archive['month'];
            }
        } else {
            $pageUrl = route('article.category', ['category_id' => $catId]);
        }

        $result = Article::with('category')
            ->published()
            ->filterByArchive($archive)
            ->filterByCategory($catId)
            ->applyDefaultOrder()
            ->paginate((int) $pageSize, (int) $page, $pageUrl);

        $articleList = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $catName = isset($row['category']['name']) ? $row['category']['name'] : '';

            $articleList[] = array(
                'id' => $row['id'],
                'category_id' => $row['category_id'],
                'title' => $row['title'],
                'defined' => $row['defined'],
                'image' => $row['image'],
                'created_at' => $row['created_at'],
                'add_time_short' => $row['add_time_short'],
                'time' => $row['time'],
                'click' => $row['click'],
                'description' => Str::excerpt($row['description'], 200, false),
                'url' => route('article.show', ['id' => $row['id']]),
                'cate_info' => array(
                    'category_id' => $row['category_id'],
                    'name' => $catName,
                    'url' => route('article.category', ['category_id' => $row['category_id']]),
                ),
            );
        }

        return array(
            'article_list' => $articleList,
            'pager' => $result['pager']
        );
    }

    /**
     * 获取格式化后的文章主体（不含点击统计，统计由 Controller 调用 recordArticleView）
     *
     * @param int $id 已通过 RouteIdValidator::column 校验的文章主键
     * @return array|null 文章主体数组，无效时 null
     */
    public function buildArticleShowData($id)
    {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }

        $article = Article::findPublishedById($id);
        if (!$article) {
            return null;
        }

        // AR：casts(image/created_at/defined)、appends(time)、translatable(title/content/keywords/description) 已在 toArray 承接
        $data = $article->toArray();
        $data['content'] = $this->markdown->toHtml($data['content']);

        return $data;
    }

    /**
     * 分类单行（供 controller 用 `langBox` 二次格式化）。
     *
     * @param int $catId
     * @return array|false
     */
    public function findCategoryById($catId)
    {
        $category = ArticleCategory::whereKey((int) $catId)->first();

        return $category ? $category->getAttributes() : false;
    }

    /**
     * 访问统计：文章点击量 +1
     *
     * @param int $id 文章主键
     * @return int|false 影响行数；$id 非法时为 false
     */
    public function recordArticleView($id)
    {
        return Article::updateClick((int) $id);
    }
}

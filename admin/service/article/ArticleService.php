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

namespace Dou\Admin\Service\Article;

use Dou\Admin\Model\Article\Article;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Support\Util;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台文章业务服务。
 *
 * 职责：
 * - 组织文章列表/表单展示数据（供 Controller assign）。
 * - 承载新增、更新、删除、批量操作等业务流程。
 * - 协调 Model、文件处理与后台日志，不直接承担请求校验。
 *
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
     * 列表页数据：查询分页并补齐模板展示字段。
     *
     * @param string $catId
     * @param string $keyword
     * @param int $page
     * @return array list、pager
     */
    public function buildArticleListData($catId, $keyword, $page)
    {
        $pageUrl = route('admin.article');
        $catId = (int) $catId;
        if ($catId > 0) {
            $pageUrl .= '&category_id=' . $catId;
        }
        if ($keyword !== '') {
            $pageUrl .= '&keyword=' . $keyword;
        }

        $result = Article::with('category')
            ->filterByCategory($catId)
            ->filterByKeyword($keyword)
            ->field('id, title, category_id, image, sort, status, created_at')
            ->applyDefaultOrder()
            ->paginate(15, $page, Util::normalizeQueryString($pageUrl));

        $articleList = array();
        foreach ($result['list'] as $model) {
            $row = $model->toArray();
            $articleList[] = array(
                'id' => $row['id'],
                'category_id' => $row['category_id'],
                'category' => isset($row['category']) ? $row['category'] : array(),
                'title' => $row['title'],
                'image' => $row['image'],
                'sort' => $row['sort'],
                'status' => $row['status'],
                'created_at' => $row['created_at'],
            );
        }

        return array(
            'list' => $articleList,
            'pager' => $result['pager'],
        );
    }

    /**
     * 新增页默认数据：初始化 created_at 与自定义参数模板。
     *
     * @return array
     */
    public function buildArticleDefaultData()
    {
        $article = array(
            'id' => '',
            'title' => '',
            'category_id' => '',
            'slug' => '',
            'keywords' => '',
            'description' => '',
            'image' => '',
            'sort' => '50',
            'created_at' => date('Y-m-d H:i', time()),
            'defined' => '',
        );

        if (Config::get('defined.article')) {
            $article['defined'] = str_replace(',', "\n", Config::get('defined.article'));
        }

        return $article;
    }

    /**
     * 新增提交：处理正文/图片后入库并记录后台日志。
     *
     * 字段白名单与校验规则定义在 ArticleFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=store。
     * @see \Dou\Admin\Request\Article\ArticleFormRequest::rules()
     *
     * @param array $data
     * @param string $draftToken
     * @param int $adminId
     * @return int 新增记录主键
     */
    public function insert(array $data, $draftToken, $adminId)
    {
        $draftToken = (string) $draftToken;
        $adminId = (int) $adminId;
        if ($draftToken === '' || $adminId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.article'));
        }

        $content = isset($data['content']) ? xss()->content($data['content']) : '';
        if (!empty($data['content_remote_image_local'])) {
            $content = attachment()->storeDraftContentImages('article', $content, 'admin', $adminId, $draftToken, 'content', '');
        }
        $data['content'] = $content;
        $data['operator_type'] = 'admin';
        $data['operator_id'] = $adminId;

        $article = Article::create($data);
        if (!$article) {
            throw new DomainException(lang('article_add_wrong'), route('admin.article'));
        }
        $newId = (int) $article->getKey();

        $imageNumber = attachment()->store('article', $newId, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withUploader('admin', $adminId));
        if ($imageNumber !== '') {
            Article::whereKey($newId)->update(array('image' => $imageNumber));
        }

        attachment()->claimByToken('article', $draftToken, $newId, 'admin', $adminId);

        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $data['title']);

        return $newId;
    }

    /**
     * 编辑页数据：读取记录并转换为模板展示格式。
     *
     * @param int $id
     * @return array|null
     */
    public function buildArticleEditData($id = '')
    {
        $model = Article::find($id);
        if (!$model) {
            return null;
        }

        // 编辑表单需要原始列值，再按表单语义逐项格式化（不走列表 cast）
        $article = $model->getAttributes();
        $article['file_number'] = $article['image'];
        $article['image'] = attachment()->url($article['image']);
        $article['created_at'] = date('Y-m-d H:i', strtotime($article['created_at']));
        $article['item_content'] = $this->markdown->toHtml($article['content']);
        $definedRaw = isset($article['defined']) ? $article['defined'] : '';
        $article['defined'] = ($definedRaw !== '' && $definedRaw !== null)
            ? str_replace(',', "\n", (string) $definedRaw)
            : '';

        return $article;
    }

    /**
     * 更新提交：校验目标记录存在后处理正文/图片并写回。
     *
     * 字段白名单与校验规则定义在 ArticleFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=update。
     * @see \Dou\Admin\Request\Article\ArticleFormRequest::rules()
     *
     * @param array $data
     * @param int $adminId
     * @return void
     */
    public function update(array $data, $adminId)
    {
        $adminId = (int) $adminId;
        $id = isset($data['id']) ? (int) $data['id'] : 0;
        if ($id <= 0 || $adminId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.article'));
        }

        $article = Article::find($id);
        if (!$article) {
            throw new DomainException(lang('article_not_exist'), route('admin.article'));
        }

        $image = attachment()->store('article', $id, UploadedFile::fromGlobals('image'), 'main', AttachmentUploadOptions::create()->withUploader('admin', $adminId));
        $content = '';
        if (isset($data['content'])) {
            $content = xss()->content($data['content']);
            if (!empty($data['content_remote_image_local'])) {
                $content = attachment()->storeContentImages('article', $id, $content, 'content', '', AttachmentUploadOptions::create()->withUploader('admin', $adminId));
            }
        }
        $data['content'] = $content;

        if ($image) {
            $data['image'] = $image;
        }

        $article->fill($data, 'update')->save();

        audit()->writeAdminLog($adminId, AdminLogAction::UPDATE, 1, (string) $data['title']);
    }

    /**
     * 单条删除：按 confirm 分支执行二次确认或实际删除。
     *
     * @param string $id
     * @param array $data
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 记录不存在时抛出
     */
    public function delete($id, array $data)
    {
        $model = Article::find($id, 'title, image');
        if (!$model) {
            throw new DomainException(lang('article_not_exist'), route('admin.article'));
        }
        $article = $model->getAttributes();

        if (isset($data['confirm'])) {
            Article::destroy((int) $id);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $article['title']);
            return array(
                'message' => lang('article_del_succes'),
                'back_url' => route('admin.article'),
            );
        }

        $delCheck = preg_replace('/d%/Ums', $article['title'], lang('del_check'));
        return array(
            'message' => $delCheck,
            'back_url' => route('admin.article'),
            'timeout' => '30',
            'confirm_url' => route('admin.article.destroy', array('id' => (int) $id)),
        );
    }

    /**
     * 批量操作：支持批量删除与批量转移分类。
     *
     * @param array $data
     * @return array{message: string, back_url: string}
     * @throws DomainException 参数不合法或动作类型不支持时抛出
     */
    public function action(array $data)
    {
        $action = Arr::get($data, 'action', '');

        if (empty($data['checkbox']) || !is_array($data['checkbox'])) {
            throw new DomainException(lang('article_select_empty'), route('admin.article'));
        }

        $ids = Check::intIds($data['checkbox']);
        if (empty($ids)) {
            throw new DomainException(lang('article_select_empty'), route('admin.article'));
        }

        if ($action === 'del_all') {
            Article::destroy($ids);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, 'bulk:ARTICLE (' . count($ids) . ' items)', 'article');
            return array('message' => lang('del_succes'), 'back_url' => route('admin.article'));
        }

        if ($action === 'category_move') {
            Article::whereIn('id', $ids)->update(array('category_id' => (int) Arr::get($data, 'new_cat_id', 0)));
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::UPDATE, 1, 'category_move:ARTICLE (' . count($ids) . ' items)', 'article');
            return array('message' => lang('category_move_batch_succes'), 'back_url' => route('admin.article'));
        }

        throw new DomainException(lang('select_empty'), route('admin.article'));
    }
}

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
 * Release Date: 2026-10-12
 */

namespace Dou\Api\Service\Article;

use Dou\Admin\Model\Article\Article;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 开放平台文章发布服务。
 *
 * 与后台 Admin\Service\Article\ArticleService 的 insert() / update() 链条对齐，
 * 去除草稿令牌 / 后台会话依赖，适配开放平台应用身份：
 * - 正文按客户端声明的 content_format 处理：markdown 源码原样入库（读端 Parsedown
 *   safe mode 渲染），html 走 XSS 过滤（与后台 ueditor 保存链一致）；
 * - content_remote_image_local 开启时按 open.image_hosts 白名单转存正文远程图进附件库
 *   （空白名单 = 全部经下载器公网校验；非空白名单 = 仅名单内域名下载，其余保留远程）；
 * - operator_type='open' + operator_id=$appId 标记来源应用；
 * - 附件 uploader 身份取签发应用的后台管理员（dou_file.uploader_type 仅接受 admin/work/user）。
 *
 * 权属边界：updatePublish / remove 仅允许操作本应用发布的文章（operator_type='open'
 * 且 operator_id=当前应用 id），未命中统一视为「不存在」（404），避免跨应用越权与存在性枚举。
 */
class ArticlePublishService extends BaseService
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
     * 发布新文章。
     *
     * @param array $data 已通过 ArticlePublishFormRequest 校验的字段
     * @param int $appId 当前开放应用 id（写入 operator_id）
     * @param int $uploaderAdminId 附件归属管理员 id（open_app.admin_id，缺省 0）
     * @return int 新文章 id
     * @throws DomainException 分类不可解析 / 入库失败
     */
    public function publish(array $data, $appId, $uploaderAdminId = 0)
    {
        $content = $this->prepareContent(
            isset($data['content']) ? $data['content'] : '',
            isset($data['content_format']) ? $data['content_format'] : ''
        );

        $row = array(
            'category_id' => $this->resolveCategory(isset($data['category']) ? $data['category'] : ''),
            'operator_type' => 'open',
            'operator_id' => (int) $appId,
            'title' => trim((string) (isset($data['title']) ? $data['title'] : '')),
            'slug' => trim((string) (isset($data['slug']) ? $data['slug'] : '')),
            'content' => $content,
            'keywords' => trim((string) (isset($data['keywords']) ? $data['keywords'] : '')),
            'description' => (string) (isset($data['description']) ? $data['description'] : ''),
            'status' => $this->resolveStatus($data),
            'created_at' => date('Y-m-d H:i:s'),
        );

        if (isset($data['sort']) && $data['sort'] !== '') {
            $row['sort'] = (int) $data['sort'];
        }

        $article = Article::create($row);
        if (!$article) {
            throw new DomainException(lang('open_publish_failed'));
        }
        $articleId = (int) $article->getKey();

        if (!empty($data['content_remote_image_local']) && $content !== '') {
            $localized = $this->localizeContentImages($articleId, $content, $uploaderAdminId);
            if ($localized !== $content) {
                Article::whereKey($articleId)->update(array('content' => $localized));
            }
        }

        return $articleId;
    }

    /**
     * 更新本应用发布的文章（部分更新：未提供的字段保持原值）。
     *
     * 字段加工规则：
     * - title / slug / category：provide 且非空白才覆盖（空白值忽略，避免写坏必填列）；
     * - keywords / description / content：provide 即覆盖（允许清空）；
     * - sort / status：provide 且非空才覆盖；
     * - content_format 缺省视为 html（走 XSS 过滤）。
     *
     * @param int $id
     * @param array $data 已通过 ArticlePublishFormRequest 校验的字段
     * @param int $appId 当前开放应用 id（权属边界）
     * @param int $uploaderAdminId 附件归属管理员 id
     * @return bool false = 文章不存在或不属于当前应用
     * @throws DomainException 分类不可解析
     */
    public function updatePublish($id, array $data, $appId, $uploaderAdminId = 0)
    {
        $article = $this->ownedArticle($id, $appId);
        if ($article === null) {
            return false;
        }

        $update = array();

        if (array_key_exists('category', $data) && trim((string) $data['category']) !== '') {
            $update['category_id'] = $this->resolveCategory($data['category']);
        }
        if (array_key_exists('title', $data) && trim((string) $data['title']) !== '') {
            $update['title'] = trim((string) $data['title']);
        }
        if (array_key_exists('slug', $data) && trim((string) $data['slug']) !== '') {
            $update['slug'] = trim((string) $data['slug']);
        }
        if (array_key_exists('keywords', $data)) {
            $update['keywords'] = trim((string) $data['keywords']);
        }
        if (array_key_exists('description', $data)) {
            $update['description'] = (string) $data['description'];
        }
        if (array_key_exists('sort', $data) && $data['sort'] !== '') {
            $update['sort'] = (int) $data['sort'];
        }
        if (array_key_exists('status', $data) && $data['status'] !== '') {
            $update['status'] = (int) $data['status'];
        }

        if (array_key_exists('content', $data)) {
            $content = $this->prepareContent(
                (string) $data['content'],
                isset($data['content_format']) ? $data['content_format'] : ''
            );
            if (!empty($data['content_remote_image_local']) && $content !== '') {
                $content = $this->localizeContentImages((int) $article->getKey(), $content, $uploaderAdminId);
            }
            $update['content'] = $content;
        }

        if (empty($update)) {
            return true;
        }

        return (bool) $article->fill($update, 'update')->save();
    }

    /**
     * 删除本应用发布的文章（关联清理由模型 PurgesRelatedOnDelete 承担）。
     *
     * @param int $id
     * @param int $appId 当前开放应用 id（权属边界）
     * @return bool false = 文章不存在或不属于当前应用
     */
    public function remove($id, $appId)
    {
        if ($this->ownedArticle($id, $appId) === null) {
            return false;
        }

        Article::destroy((int) $id);

        return true;
    }

    /**
     * 权属校验：取文章并核对来源（operator_type='open' 且 operator_id=本应用）。
     *
     * @param int $id
     * @param int $appId
     * @return Article|null 命中返回模型，否则 null（调用方按「不存在」处理）
     */
    private function ownedArticle($id, $appId)
    {
        $id = (int) $id;
        if ($id <= 0) {
            return null;
        }

        $model = Article::find($id);
        if (!$model) {
            return null;
        }

        $row = $model->getAttributes();
        if ((string) $row['operator_type'] !== 'open' || (int) $row['operator_id'] !== (int) $appId) {
            return null;
        }

        return $model;
    }

    /**
     * 保存端正文处理：按客户端声明的 content_format 决定。
     *
     * - markdown：源码原样入库（读端 Parsedown safe mode 渲染；XSS 过滤会破坏语法结构）；
     * - html（含缺省）：与后台 ueditor 保存链一致，经 xss()->content() 过滤。
     *
     * @param string|null $content
     * @param string $format
     * @return string
     */
    private function prepareContent($content, $format)
    {
        $content = $content !== null ? (string) $content : '';
        if (trim((string) $format) === 'markdown') {
            return $content;
        }

        $xss = xss();

        return $xss !== null ? $xss->content($content) : $content;
    }

    /**
     * 分类解析：数字按 id、字符串按 slug；均支持 open.default_category 兜底。
     *
     * 字符串形态未命中且 open.auto_create_category 开启时自动建分类（slug 列长 30，
     * 超长视为不可解析，避免截断入库）。
     *
     * @param mixed $category
     * @return int 分类 id
     * @throws DomainException 分类不可解析
     */
    private function resolveCategory($category)
    {
        $category = trim((string) $category);
        if ($category === '') {
            $category = trim((string) Config::get('open.default_category', ''));
        }
        if ($category === '') {
            throw new DomainException(lang('open_publish_category_required'));
        }

        if (ctype_digit($category)) {
            $exists = DB::table('article_category')->where('id', (int) $category)->value('id');
            if ($exists) {
                return (int) $category;
            }

            throw new DomainException(lang('open_publish_category_missing'));
        }

        if (strlen($category) > 30) {
            throw new DomainException(lang('open_publish_category_missing'));
        }

        $row = DB::table('article_category')->where('slug', $category)->find();
        if ($row) {
            return (int) $row['id'];
        }

        if (Config::get('open.auto_create_category', false)) {
            $newId = DB::table('article_category')->data(array(
                'slug' => $category,
                'name' => $category,
                'icon' => '',
                'keywords' => '',
                'description' => '',
                'parent_id' => 0,
            ))->insert();
            if ($newId) {
                return (int) $newId;
            }
        }

        throw new DomainException(lang('open_publish_category_missing'));
    }

    /**
     * 文档状态：provide 且非空取声明值，否则按 open.default_status（缺省 0 草稿）。
     *
     * @param array $data
     * @return int
     */
    private function resolveStatus(array $data)
    {
        if (isset($data['status']) && $data['status'] !== '') {
            return (int) $data['status'];
        }

        return (int) Config::get('open.default_status', 0);
    }

    /**
     * 按 open.image_hosts 白名单转存正文远程图，逐张替换为本地附件 URL。
     *
     * 跳过项（保持原样）：站内 URL（ROOT_URL 前缀）、宿主不在白名单（白名单非空时）、
     * 下载失败（SSRF 拒绝 / 网络错误）。扫描模式与 ContentImageImporter 对齐：
     * vditor 取 Markdown 图片语法，其余取 `<img src>`。
     *
     * @param int $articleId
     * @param string $content
     * @param int $uploaderAdminId
     * @return string
     */
    private function localizeContentImages($articleId, $content, $uploaderAdminId)
    {
        $content = (string) $content;
        if ($content === '') {
            return $content;
        }

        $hosts = array_filter(array_map('trim', (array) Config::get('open.image_hosts', array())), 'strlen');
        $pattern = (Config::get('site.editor', '') === 'vditor')
            ? '/\]\((https?:\/\/[^)\s]+)/i'
            : '/<img\b[^>]*\ssrc=["\']([^"\']+)["\'][^>]*>/i';
        $selfBase = rtrim((string) ROOT_URL, '/');

        $result = preg_replace_callback(
            $pattern,
            function ($matches) use ($articleId, $hosts, $selfBase, $uploaderAdminId) {
                $remoteUrl = $matches[1];
                if ($remoteUrl === '' || strpos($remoteUrl, $selfBase) === 0) {
                    return $matches[0];
                }
                if (!empty($hosts) && !$this->hostAllowed($remoteUrl, $hosts)) {
                    return $matches[0];
                }

                $localUrl = attachment()->storeFromUrl(
                    'article',
                    (int) $articleId,
                    $remoteUrl,
                    'content',
                    '',
                    '',
                    AttachmentUploadOptions::create()->withUploader('admin', (int) $uploaderAdminId),
                    'path'
                );
                if (!$localUrl) {
                    return $matches[0];
                }

                return str_replace($remoteUrl, $localUrl, $matches[0]);
            },
            $content
        );

        return $result === null ? $content : $result;
    }

    /**
     * 远程图宿主是否命中白名单（精确或子域后缀匹配）。
     *
     * @param string $url
     * @param string[] $hosts
     * @return bool
     */
    private function hostAllowed($url, array $hosts)
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        foreach ($hosts as $allowed) {
            $allowed = strtolower((string) $allowed);
            if ($allowed === '') {
                continue;
            }
            if ($host === $allowed || substr($host, -strlen($allowed) - 1) === '.' . $allowed) {
                return true;
            }
        }

        return false;
    }
}

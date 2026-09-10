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

namespace Dou\Front\Service\Page;

use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Support\Check;
use Dou\Front\Model\Page\Page;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台单页业务层（单页展示 / API 共用）
 */
class PageService extends BaseService
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
     * 获取格式化后的单页展示数据（含顶级页、可视化预览标记；供控制器使用）
     *
     * @param int $id 已通过 RouteIdValidator::page 校验的单页主键
     * @param string|null $editorCode 请求参数 editor_code（可视化预览）
     * @return array|null 含 page、top、top_id、editor_mode；无效时 null
     */
    public function buildPageShowData($id, $editorCode = null)
    {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }

        $pageModel = Page::find($id);
        $page = $pageModel ? $pageModel->getAttributes() : null;
        if (!$page || !is_array($page)) {
            return null;
        }

        // 多语言与正文
        $page = language()->langBox($page, 'page', 'name, content, keywords, description');
        $page['content'] = $this->markdown->toHtml($page['content']);

        // 顶级单页（侧栏/面包屑）
        $topId = (isset($page['parent_id']) && (int) $page['parent_id'] === 0) ? $id : (int) $page['parent_id'];
        $topModel = Page::find($topId);
        $top = $topModel ? $topModel->getAttributes() : null;
        if (!$top || !is_array($top)) {
            return null;
        }

        $top['url'] = route('page.show', ['id' => $topId]);
        $top = language()->langBox($top, 'page', 'name, content, keywords, description');

        // 可视化编辑预览
        $editorMode = false;
        $editorCodeParam = Check::number($editorCode) ? $editorCode : '';
        if (isset($page['mode'], $page['editor_code']) && $page['mode'] === 'visualize' && $editorCodeParam == $page['editor_code']) {
            $editorMode = true;
        }

        return array(
            'page' => $page,
            'top' => $top,
            'top_id' => $topId,
            'editor_mode' => $editorMode,
            'page_list' => Page::pageTree($topId, (int) $id),
        );
    }

    /**
     * 单页详情 JSON（小程序 / API）：点击量 +1 后返回 title 与 page。
     *
     * @param int $id
     * @return array|null 含 title 与 page 的详情结构
     */
    public function buildPageApiData($id)
    {
        $id = (int) $id;
        if ($id < 1) {
            return null;
        }

        $pageModel = Page::find($id);
        $page = $pageModel ? $pageModel->getAttributes() : null;
        if (!$page || !is_array($page)) {
            return null;
        }

        $oldClick = isset($page['click']) ? (int) $page['click'] : 0;
        Page::updateClick($id);
        $page['click'] = $oldClick + 1;

        $page = language()->langBox($page, 'page', 'name, content, keywords, description');
        $page['content'] = $this->markdown->toHtml($page['content']);

        $title = isset($page['name']) ? $page['name'] : '';

        return array(
            'title' => $title,
            'page' => $page,
        );
    }
}

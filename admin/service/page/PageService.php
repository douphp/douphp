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

namespace Dou\Admin\Service\Page;

use Dou\Admin\Model\Page\Page;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Str;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台单页业务服务（route=page/...）。
 *
 * 列表/表单数据用 build*Data；持久化入口为 insert()/update()，
 * 由 {@see \Dou\Admin\Controller\Page\PageController} 的 store()/update() 调用（控制器动作名不使用 insert）。
 * 删除、可视化保存等同名辅助方法与控制器 visualize / visualizeClear / delete 对应。
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
     * 列表页数据（树形列表）。
     *
     * @return array page_list
     */
    public function buildPageListData()
    {
        return array(
            'page_list' => Page::pageNolevel(),
        );
    }

    /**
     * 新增表单默认值与编辑器预览 HTML。
     *
     * @return array page、item_content
     */
    public function buildPageDefaultData()
    {
        $page = array(
            'id' => 0,
            'name' => '',
            'slug' => '',
            'parent_id' => 0,
            'content' => '',
            'keywords' => '',
            'description' => '',
            'mode' => 'editor',
            'editor_code' => '',
        );

        return array(
            'page' => $page,
            'item_content' => $this->markdown->toHtml(''),
        );
    }

    /**
     * 大文件下载字段展示（file 表 module=page、type=download），供 page.htm 使用。
     *
     * @param int $pageId 单页主键；小于 1 时返回空链接
     * @return array download_link
     */
    public function buildPageDownloadData($pageId)
    {
        $pageId = (int) $pageId;
        if ($pageId < 1) {
            return array('download_link' => '');
        }

        $relative = DB::table('file')
            ->where('module', 'page')
            ->where('item_id', $pageId)
            ->where('type', 'download')
            ->order('id ASC')
            ->value('file');
        if ($relative) {
            return array('download_link' => ROOT_URL . $relative);
        }

        return array('download_link' => '');
    }

    /**
     * 获取编辑表单的数据（从数据库获取并格式化字段）
     *
     * @param int $id
     * @return array|null
     */
    public function buildPageEditData($id)
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

        $editorCode = Arr::get($page, 'editor_code', '');
        $page['editor_url'] = route('page.show', ['id' => $id], ['query' => ['editor_code' => $editorCode]]);
        $page['item_content'] = $this->markdown->toHtml($page['content']);

        return $page;
    }

    /**
     * 新增提交：处理正文/图片后入库并记录后台日志。
     *
     * 字段白名单与校验规则定义在 PageFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=store。
     * @see \Dou\Admin\Request\Page\PageFormRequest::rules()
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
            throw new DomainException(lang('illegal'), route('admin.page'));
        }

        $editorCode = (isset($data['mode']) && $data['mode'] === 'visualize') ? $this->generateUniqueEditorCode() : '';

        $content = '';
        if (isset($data['content'])) {
            $content = xss() !== null ? xss()->content($data['content']) : $data['content'];
            if (!empty($data['content_remote_image_local'])) {
                $content = attachment()->storeDraftContentImages('page', $content, 'admin', $adminId, $draftToken, 'content', '');
            }
        }

        $page = Page::create(array(
            'slug' => $data['slug'],
            'parent_id' => Arr::get($data, 'parent_id', 0),
            'name' => $data['name'],
            'content' => $content,
            'keywords' => Arr::get($data, 'keywords', ''),
            'description' => Arr::get($data, 'description', ''),
            'editor_code' => $editorCode,
            'mode' => isset($data['mode']) ? $data['mode'] : 'editor',
        ));
        if (!$page) {
            throw new DomainException(lang('illegal'), route('admin.page'));
        }
        $newId = (int) $page->getKey();

        attachment()->claimByToken('page', $draftToken, $newId, 'admin', $adminId);

        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $data['name']);

        return $newId;
    }

    /**
     * 更新提交：校验目标存在后处理正文/图片并写回。
     *
     * 字段白名单与校验规则定义在 PageFormRequest::rules()。
     * Action 注入会按方法名自动绑定 scene=update。
     * @see \Dou\Admin\Request\Page\PageFormRequest::rules()
     *
     * @param array $data
     * @param int $adminId
     * @return void
     */
    public function update(array $data, $adminId)
    {
        $adminId = (int) $adminId;
        $id = (int) (Arr::get($data, 'id', 0));
        if ($id < 1 || $adminId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.page'));
        }

        $model = Page::find($id);
        if (!$model) {
            throw new DomainException(lang('illegal'), route('admin.page'));
        }

        $editorCode = '';
        if (isset($data['mode']) && $data['mode'] === 'visualize') {
            $editorCode = $this->getEditorCode($id);
            $editorCode = $editorCode ? $editorCode : $this->generateUniqueEditorCode();
        }

        $content = '';
        if (isset($data['content'])) {
            $content = $data['content'];
            if (!empty($data['content_remote_image_local'])) {
                $content = attachment()->storeContentImages('page', $id, $content, 'content', '', AttachmentUploadOptions::create()->withUploader('admin', $adminId));
            }
        }

        $model->fill(array(
            'slug' => $data['slug'],
            'parent_id' => Arr::get($data, 'parent_id', 0),
            'name' => $data['name'],
            'content' => $content,
            'keywords' => Arr::get($data, 'keywords', ''),
            'description' => Arr::get($data, 'description', ''),
            'editor_code' => $editorCode,
            'mode' => isset($data['mode']) ? $data['mode'] : 'editor',
        ), 'update')->save();

        audit()->writeAdminLog($adminId, AdminLogAction::UPDATE, 1, (string) $data['name']);
    }

    /**
     * 单条删除：确认页或执行删除
     *
     * @param int $id
     * @param array $data
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 不可删、有子页时抛出
     */
    public function delete($id, array $data)
    {
        $id = (int) $id;
        if ($id < 1) {
            throw new DomainException(lang('illegal'), route('admin.page'));
        }

        if ($id === 1) {
            throw new DomainException(lang('page_del_wrong'), route('admin.page'), '3');
        }

        $pageName = $this->getPageName($id);
        $firstChildId = $this->getFirstChildId($id);
        if ($firstChildId) {
            $msg = preg_replace('/d%/Ums', $pageName, lang('page_del_is_parent'));
            throw new DomainException($msg, route('admin.page'), '3');
        }

        if (isset($data['confirm'])) {
            $this->deletePage($id);
            audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $pageName);
            return array(
                'message' => lang('page_delete') . lang('success'),
                'back_url' => route('admin.page'),
            );
        }

        $delCheck = preg_replace('/d%/Ums', $pageName, lang('del_check'));
        return array(
            'message' => $delCheck,
            'back_url' => route('admin.page'),
            'timeout' => '30',
            'confirm_url' => route('admin.page.destroy', array('id' => $id)),
        );
    }

    /**
     * @param int $id
     * @return void
     */
    public function deletePage($id)
    {
        Page::destroy((int) $id);
    }

    /**
     * @param int $id
     * @return string
     */
    public function getPageName($id)
    {
        return Page::getPageNameById((int) $id);
    }

    /**
     * @param int $parentId
     * @return mixed
     */
    public function getFirstChildId($parentId)
    {
        return Page::getFirstChildIdByParentId((int) $parentId);
    }

    /**
     * 可视化编辑保存
     *
     * @param int $id
     * @param string $content XSS 处理后的内容
     * @return void
     */
    public function visualize($id, $content)
    {
        Page::whereKey((int) $id)->update(array('content' => $content));
    }

    /**
     * 清空可视化内容
     *
     * @param int $id
     * @return void
     */
    public function visualizeClear($id)
    {
        Page::whereKey((int) $id)->update(array('content' => ''));
    }

    /**
     * @param int $id
     * @return string
     */
    public function getEditorCode($id)
    {
        return Page::getEditorCodeById((int) $id);
    }

    /**
     * 生成 page 表中唯一的 editor_code。
     *
     * @return string
     */
    private function generateUniqueEditorCode()
    {
        $editor_code = Str::randomByType('number', 4) . time();
        if (Page::editorCodeExists($editor_code)) {
            return $this->generateUniqueEditorCode();
        }

        return $editor_code;
    }
}

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

use Dou\Admin\Model\Article\ArticleCategory;
use Dou\Admin\Service\Nav\NavCategorySyncService;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Admin\AdminLogAction;
use Dou\Core\Service\Attachment\AttachmentUploadOptions;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Arr;
use Dou\Core\Support\Check;
use Dou\Core\Web\Http\UploadedFile;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台文章分类业务服务（与 CategoryController 对应）
 *
 * 格式校验（required / alpha_dash / unique）已上移至 CategoryFormRequest；
 * 此处只做纯业务规则判断，
 * 失败时抛出 DomainException，由全局处理器统一输出提示。
 */
class CategoryService extends BaseService
{
    /** @var NavCategorySyncService */
    private $navCategorySync;

    /**
     * @param NavCategorySyncService $navCategorySync
     */
    public function __construct(NavCategorySyncService $navCategorySync)
    {
        $this->navCategorySync = $navCategorySync;
    }

    /**
     * 新增表单默认数据
     *
     * @return array
     */
    public function buildCategoryDefaultData()
    {
        return array(
            'id' => '',
            'name' => '',
            'slug' => '',
            'icon' => '',
            'parent_id' => 0,
            'keywords' => '',
            'description' => '',
            'sync_to_nav' => '0',
            'sort' => 50,
        );
    }

    /**
     * 编辑表单：按主键取分类
     *
     * @param int $catId
     * @return array|null
     */
    public function buildCategoryEditData($catId)
    {
        $catInfoModel = ArticleCategory::find((int) $catId);
        if (!$catInfoModel) {
            return null;
        }

        $catInfo = $catInfoModel->getAttributes();
        $catInfo['file_number'] = Check::fileNumber($catInfo['icon']) ? $catInfo['icon'] : '';
        $catInfo['icon'] = Check::fileNumber($catInfo['icon']) ? attachment()->url($catInfo['icon']) : $catInfo['icon'];

        return $catInfo;
    }

    /**
     * 新增提交（格式校验已由 CategoryFormRequest 完成）。
     *
     * @see \Dou\Admin\Request\Article\CategoryFormRequest::rules()
     *
     * @param array $data
     * @param int $adminId
     * @return int 新增分类主键
     */
    public function insert(array $data, $adminId)
    {
        $adminId = (int) $adminId;
        if ($adminId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.article.category'));
        }

        $iconMode = Config::get('site.open_icon', '');
        $data['icon'] = $iconMode === 'text' ? Arr::get($data, 'icon', '') : '';

        $category = ArticleCategory::create($data);
        if (!$category) {
            throw new DomainException(lang('illegal'), route('admin.article.category'));
        }
        $newCatId = (int) $category->getKey();

        if ($iconMode === 'image') {
            $icon = attachment()->store(
                'article_category',
                $newCatId,
                UploadedFile::fromGlobals('icon'),
                'main',
                AttachmentUploadOptions::create()
                    ->withPrimaryKey('id')
                    ->withBusinessField('icon')
                    ->withUploader('admin', $adminId)
            );
            if ($icon !== '') {
                ArticleCategory::whereKey($newCatId)->update(array('icon' => $icon));
            }
        }

        if (isset($data['sync_to_nav']) && (int) $data['sync_to_nav'] === 1) {
            $this->navCategorySync->syncForCategory('article_category', $newCatId);
        }

        audit()->writeAdminLog($adminId, AdminLogAction::CREATE, 1, (string) $data['name']);

        return $newCatId;
    }

    /**
     * 更新提交（格式校验已由 CategoryFormRequest 完成）。
     *
     * @see \Dou\Admin\Request\Article\CategoryFormRequest::rules()
     *
     * @param array $data
     * @param int $adminId
     * @return void
     */
    public function update(array $data, $adminId)
    {
        $adminId = (int) $adminId;
        $catId = isset($data['category_id']) ? (int) $data['category_id'] : 0;
        if ($catId <= 0 || $adminId <= 0) {
            throw new DomainException(lang('illegal'), route('admin.article.category'));
        }

        $category = ArticleCategory::find($catId);
        if (!$category) {
            throw new DomainException(lang('item_not_exist'), route('admin.article.category'));
        }

        $iconMode = Config::get('site.open_icon', '');
        $icon = (string) $category->getRawAttribute('icon');
        if ($iconMode === 'image') {
            $boxed = attachment()->store(
                'article_category',
                $catId,
                UploadedFile::fromGlobals('icon'),
                'main',
                AttachmentUploadOptions::create()
                    ->withPrimaryKey('id')
                    ->withBusinessField('icon')
                    ->withUploader('admin', $adminId)
            );
            if ($boxed !== '') {
                $icon = $boxed;
            }
        } elseif ($iconMode === 'text') {
            $icon = Arr::get($data, 'icon', '');
        }
        $data['icon'] = $icon;

        $category->fill($data, 'update')->save();

        if (isset($data['sync_to_nav']) && (int) $data['sync_to_nav'] === 1) {
            $this->navCategorySync->syncForCategory('article_category', $catId);
        }

        audit()->writeAdminLog($adminId, AdminLogAction::UPDATE, 1, (string) $data['name']);
    }

    /**
     * 删除：占用/子类拦截 → 二次确认 → 执行
     *
     * @param int $catId
     * @param array $data
     * @return array{message: string, back_url: string, timeout: string, confirm_url: string}
     * @throws DomainException 业务阻断时抛出
     */
    public function delete($catId, array $data)
    {
        $cateInfoModel = ArticleCategory::find((int) $catId);
        if (!$cateInfoModel) {
            throw new DomainException(lang('item_not_exist'), route('admin.article.category'));
        }
        $cateInfo = $cateInfoModel->getAttributes();
        $catNameStr = $cateInfo['name'];

        if (ArticleCategory::hasRecords($catId)) {
            throw new DomainException(lang('category_del_is_used'), route('admin.article.category'), '3');
        }
        if (ArticleCategory::hasChildCategory($catId)) {
            throw new DomainException(lang('category_del_is_parent'), route('admin.article.category'), '3');
        }

        if (!isset($data['confirm'])) {
            $delCheck = preg_replace('/d%/Ums', $catNameStr, lang('del_check'));
            return array(
                'message' => $delCheck,
                'back_url' => route('admin.article.category'),
                'timeout' => '30',
                'confirm_url' => route('admin.article.category.destroy', array('id' => (int) $catId)),
            );
        }

        if (!empty(Config::get('features.language', false))) {
            language()->deleteLang('article_category', $catId);
        }

        if (DB::table('nav')->where('guide', $catId)->where('module', 'article_category')->where('type', 'middle')->find()) {
            DB::table('nav')->where('guide', $catId)->where('module', 'article_category')->where('type', 'middle')->delete();
        }

        ArticleCategory::destroy((int) $catId);
        audit()->writeAdminLog((int) auth('admin')->id(), AdminLogAction::DELETE, 1, (string) $catNameStr);

        return array(
            'message' => lang('article_category_del_succes'),
            'back_url' => route('admin.article.category'),
        );
    }
}

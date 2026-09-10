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

namespace Dou\Admin\Service\Ai\Import;

use Dou\Admin\Service\Product\CategoryService;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 商品分类批量导入器：复用 CategoryService::insert 链路（含审计日志）。
 */
class ProductCategoryImporter extends BaseService implements ModuleImporter
{
    /** @var CategoryService */
    private $categoryService;

    /**
     * @param CategoryService $categoryService
     */
    public function __construct(CategoryService $categoryService)
    {
        $this->categoryService = $categoryService;
    }

    /**
     * {@inheritDoc}
     */
    public function module()
    {
        return 'product_category';
    }

    /**
     * {@inheritDoc}
     */
    public function requiredFields()
    {
        return array('name');
    }

    /**
     * {@inheritDoc}
     */
    public function allowedFields()
    {
        return array('name', 'keywords', 'description');
    }

    /**
     * {@inheritDoc}
     */
    public function import(array $item, array $context, $adminId)
    {
        $item['parent_id'] = isset($context['parent_id']) ? (int) $context['parent_id'] : 0;
        if (!isset($item['sort'])) {
            $item['sort'] = 50;
        }
        $item['sync_to_nav'] = 0;

        return $this->categoryService->insert($item, (int) $adminId);
    }
}

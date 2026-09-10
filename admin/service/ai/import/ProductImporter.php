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

use Dou\Admin\Service\Product\ProductService;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 商品批量导入器：复用 ProductService::insert 链路（含审计日志与草稿附件回收）。
 */
class ProductImporter extends BaseService implements ModuleImporter
{
    /** @var ProductService */
    private $productService;

    /**
     * @param ProductService $productService
     */
    public function __construct(ProductService $productService)
    {
        $this->productService = $productService;
    }

    /**
     * {@inheritDoc}
     */
    public function module()
    {
        return 'product';
    }

    /**
     * {@inheritDoc}
     */
    public function requiredFields()
    {
        return array('title');
    }

    /**
     * {@inheritDoc}
     */
    public function allowedFields()
    {
        return array('title', 'content', 'keywords', 'description', 'price', 'stock', 'defined');
    }

    /**
     * {@inheritDoc}
     */
    public function import(array $item, array $context, $adminId)
    {
        $item['category_id'] = isset($context['category_id']) ? (int) $context['category_id'] : 0;
        if (!isset($item['sort'])) {
            $item['sort'] = 50;
        }

        // AI 批量入库无附件草稿，token 仅满足 insert 链路的非空校验
        $draftToken = 'ai-batch-' . md5(uniqid('', true));

        return $this->productService->insert($item, $draftToken, (int) $adminId);
    }
}

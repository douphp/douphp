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

namespace Dou\Api\Service\Index;

use Dou\Admin\Model\Page\Page as AdminPageModel;
use Dou\Api\Service\Miniprogram\MiniprogramCatalogQuery;
use Dou\Core\Service\BaseService;
use Dou\Front\Model\Product\ProductCategory;
use Dou\Front\Model\Show\Show;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台首页业务层（首页数据）
 */
class IndexService extends BaseService
{
    /** @var MiniprogramCatalogQuery */
    private $miniprogramCatalog;

    /**
     * @param MiniprogramCatalogQuery $miniprogramCatalog
     */
    public function __construct(MiniprogramCatalogQuery $miniprogramCatalog)
    {
        $this->miniprogramCatalog = $miniprogramCatalog;
    }

    /**
     * @return array
     */
    public function buildIndexData()
    {
        $data = array();

        $data['show_list'] = is_array(Show::showList('miniprogram'))
            ? Show::showList('miniprogram')
            : Show::showList('pc');

        $data['recommend_product'] = $this->miniprogramCatalog->listing('product', 'ALL', 8, 'sort DESC');
        $data['recommend_article'] = $this->miniprogramCatalog->listing('article', 'ALL', 8, 'sort DESC');
        $data['product_category'] = ProductCategory::tree();
        $data['about'] = AdminPageModel::about();

        return $data;
    }
}

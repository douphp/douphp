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

namespace Dou\Admin\Service\Concerns;

use Dou\Core\Service\Metadata\MetadataService;
use Dou\Core\Service\Metadata\MetadataValues;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台列表：默认语言 metadata 装饰与列定义返回。
 */
trait DecoratesAdminMetadata
{
    /**
     * @param string $module
     * @param array $rows
     * @return array 启用的字段定义（供视图决定是否渲染列）
     */
    protected function decorateAdminMetadataRows($module, array &$rows)
    {
        MetadataValues::decorateRows($module, $rows, 'default');

        return array_values(MetadataService::instance()->schema($module));
    }
}

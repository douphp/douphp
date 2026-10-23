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

use Dou\Core\Service\Metadata\MetadataFormSaver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 内容模块后台服务：metadata 保存计划编排。
 */
trait SavesMetadata
{
    /**
     * @param string $module
     * @param array $data
     * @param int $itemId
     * @param mixed $existingRaw
     * @param int $adminId
     * @return array|null
     */
    protected function metadataBegin($module, array $data, $itemId, $existingRaw, $adminId)
    {
        $saver = new MetadataFormSaver();

        return $saver->begin($module, $data, $itemId, $existingRaw, $adminId);
    }

    /**
     * @param array|null $plan
     * @param int $itemId
     * @param int $adminId
     * @return void
     */
    protected function metadataCommit($plan, $itemId, $adminId)
    {
        if (empty($plan)) {
            return;
        }
        $saver = new MetadataFormSaver();
        $saver->commit($plan, $itemId, $adminId);
    }

    /**
     * @param array|null $plan
     * @return void
     */
    protected function metadataAbort($plan)
    {
        if (empty($plan)) {
            return;
        }
        $saver = new MetadataFormSaver();
        $saver->abort($plan);
    }

    /**
     * @param array|null $plan
     * @param int $itemId
     * @return void
     */
    protected function metadataPurge($plan, $itemId)
    {
        if (empty($plan)) {
            return;
        }
        $saver = new MetadataFormSaver();
        $saver->purge($plan, $itemId);
    }

    /**
     * 读取模型/数组上的 metadata 原始 JSON。
     *
     * @param mixed $row
     * @return string
     */
    protected function metadataExistingRaw($row)
    {
        if (is_object($row) && method_exists($row, 'getRawAttribute')) {
            return (string) $row->getRawAttribute('metadata');
        }
        if (is_array($row)) {
            return isset($row['metadata']) ? (string) $row['metadata'] : '';
        }

        return '';
    }
}

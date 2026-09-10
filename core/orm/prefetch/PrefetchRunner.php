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

namespace Dou\Core\Orm\Prefetch;

use Dou\Core\Facade\Url;
use Dou\Core\Infra\Log\Log;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 声明式预热执行器：读取模型 $prefetchers，按 key 派发到既有底层批量接口，
 * 一次 get()/paginate() 后跑一次，消除 cast/accessor 读取阶段的 N+1。
 *
 * 支持声明形态：
 * - 'url'                         → Url::warmupUrlCache($table, $rows)
 * - 'language' => 'f1,f2'         → language()->warmup($table, $ids, 'f1,f2')
 * - 'attachment' => 'image'       → attachment()->urlBatch($numbers)
 * - 'attachment' => array('a','b')→ 多字段合并 urlBatch
 * - 'attachment_thumb' => 'image' → attachment()->urlBatch($numbers, true)（缩略图缓存）
 * - 'gallery_first' => 'image'    → attachment()->galleryFirstMap($table, $ids)（按主键批量预热首图）
 */
class PrefetchRunner
{
    /**
     * @param array $rows 原始行集（关联数组）
     * @param Model $model 模型原型（提供 table / primary / prefetchers）
     * @return void
     */
    public static function run(array $rows, Model $model)
    {
        $prefetchers = $model->getPrefetchers();
        if (empty($prefetchers) || empty($rows)) {
            return;
        }

        $table = $model->getTable();
        $pk = $model->getKeyName();

        foreach ($prefetchers as $key => $value) {
            if (is_int($key)) {
                $name = $value;
                $arg = null;
            } else {
                $name = $key;
                $arg = $value;
            }

            switch ($name) {
                case 'url':
                    Url::warmupUrlCache($table, $rows);
                    break;
                case 'language':
                    $language = language();
                    if ($language === null || $arg === null || $arg === '') {
                        break;
                    }
                    $ids = array();
                    foreach ($rows as $row) {
                        if (isset($row[$pk]) && $row[$pk] !== '') {
                            $ids[] = $row[$pk];
                        }
                    }
                    if (!empty($ids)) {
                        $language->warmup($table, $ids, $arg);
                    }
                    break;
                case 'attachment':
                case 'attachment_thumb':
                    $fields = is_array($arg) ? $arg : array($arg);
                    $numbers = array();
                    foreach ($fields as $field) {
                        if ($field === null || $field === '') {
                            continue;
                        }
                        foreach ($rows as $row) {
                            if (isset($row[$field]) && $row[$field] !== '') {
                                $numbers[] = $row[$field];
                            }
                        }
                    }
                    if (!empty($numbers)) {
                        attachment()->urlBatch($numbers, $name === 'attachment_thumb');
                    }
                    break;
                case 'gallery_first':
                    $ids = array();
                    foreach ($rows as $row) {
                        if (isset($row[$pk]) && $row[$pk] !== '') {
                            $ids[] = $row[$pk];
                        }
                    }
                    if (!empty($ids)) {
                        attachment()->galleryFirstMap($table, $ids);
                    }
                    break;
                default:
                    // 未知 prefetcher 名称（如拼写错误）静默忽略难以排查：debug 下落盘，与 Connection::order() 丢弃非法片段同处理。
                    // 非 debug 下 minLevel 为 WARNING，本条不落盘（生产无噪声）。
                    Log::debug('PrefetchRunner: 未知 prefetcher 名称已忽略', array('channel' => 'system', 'name' => $name));
            }
        }
    }
}

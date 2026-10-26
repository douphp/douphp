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

use Dou\Admin\Service\Language\LanguageDraftService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 内容服务：原生字段多语言草稿编排。
 *
 * 事务由业务方法持有；本 trait 只负责 begin/commit/abort/purge 转发。
 */
trait SavesLanguageDrafts
{
    /**
     * @param string $module
     * @param array $data
     * @param int|string $itemId
     * @param int $adminId
     * @return array|null
     */
    protected function languageDraftBegin($module, array $data, $itemId, $adminId = 0)
    {
        return $this->languageDraftService()->begin($module, $data, $itemId, $adminId);
    }

    /**
     * @param array|null $plan
     * @param int|string $itemId
     * @param int $adminId
     * @return void
     */
    protected function languageDraftCommit($plan, $itemId, $adminId = 0)
    {
        if (empty($plan)) {
            return;
        }
        $this->languageDraftService()->commit($plan, $itemId, $adminId);
    }

    /**
     * @param array|null $plan
     * @return void
     */
    protected function languageDraftAbort($plan)
    {
        if (empty($plan)) {
            return;
        }
        $this->languageDraftService()->abort($plan);
    }

    /**
     * @param array|null $plan
     * @param int|string $itemId
     * @return void
     */
    protected function languageDraftPurge($plan, $itemId)
    {
        if (empty($plan)) {
            return;
        }
        $this->languageDraftService()->purge($plan, $itemId);
    }

    /**
     * @return LanguageDraftService
     */
    protected function languageDraftService()
    {
        return app(LanguageDraftService::class);
    }
}

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

namespace Dou\Admin\Model\Concerns;

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 删除副作用清理 concern。
 *
 * 通过 bootPurgesRelatedOnDelete() 自注册 deleting 事件（由 Model::bootTraits 自动调用），
 * 在删行前统一清理主图附件与多语言记录，使单删 destroy($id) 与批删 destroy($ids)
 * 共用同一套清理逻辑，Service 层不再各自维护 purgeXxx()。
 *
 * 默认行为：删除 `image` 字段对应附件 + 删除以表名为模块的多语言记录。
 * 模块差异以子模型覆写 purgeRelatedOnDelete() / shouldPurgeLanguage() 表达：
 * - Store：覆写 shouldPurgeLanguage() 返回 false（仅删附件）。
 * - Product / Health：覆写 purgeRelatedOnDelete() 改清理图库 / 关联表。
 */
trait PurgesRelatedOnDelete
{
    /**
     * trait boot 钩子：注册删除前清理回调。
     *
     * @return void
     */
    protected static function bootPurgesRelatedOnDelete()
    {
        static::deleting(function ($model) {
            $model->purgeRelatedOnDelete();
        });
    }

    /**
     * 删行前清理关联资源；子模型可覆写以表达模块差异。
     *
     * @return void
     */
    protected function purgeRelatedOnDelete()
    {
        $this->purgeAttachmentOnDelete();
        $this->purgeLanguageOnDelete();
    }

    /**
     * 删除主图附件（取原始字段值，避免 cast 成 URL）。
     *
     * @return void
     */
    protected function purgeAttachmentOnDelete()
    {
        $field = isset($this->purgeAttachmentField) ? $this->purgeAttachmentField : 'image';
        $image = $this->getRawAttribute($field);
        if ($image) {
            attachment()->delete($image);
        }
    }

    /**
     * 删除多语言记录（features.language 开启且模块名非空时）。
     *
     * @return void
     */
    protected function purgeLanguageOnDelete()
    {
        if (!$this->shouldPurgeLanguage()) {
            return;
        }
        $module = $this->resolveLanguageModule();
        if ($module !== '') {
            language()->deleteLang($module, (int) $this->getKey());
        }
    }

    /**
     * 是否清理多语言记录；无多语言的模块（如 store）覆写返回 false。
     *
     * @return bool
     */
    protected function shouldPurgeLanguage()
    {
        return !empty(Config::get('features.language', false));
    }

    /**
     * 解析多语言模块名：默认取表名，可由 $languageModule 属性覆盖。
     *
     * @return string
     */
    protected function resolveLanguageModule()
    {
        if (isset($this->languageModule) && $this->languageModule !== '') {
            return $this->languageModule;
        }

        return $this->getTable();
    }
}

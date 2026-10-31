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

use Dou\Core\Service\Metadata\MetadataTranslationStore;
use Dou\Core\Service\Metadata\MetadataValues;
use Dou\Core\Support\LanguageValuePurger;

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
 * 模块差异以子模型覆写 purgeRelatedOnDelete() 表达：
 * - Product / Health：覆写 purgeRelatedOnDelete() 改清理图库 / 关联表。
 *
 * 多语言清理不依赖 features.language 开关：由 {@see LanguageValuePurger} 按表存在性兜底，
 * 语言模块关闭 / 卸载后历史 language_value 行仍能随内容删除。
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
        $this->purgeMetadataOnDelete();
        $this->purgeLanguageOnDelete();
    }

    /**
     * 清理扩展字段附件与 field='metadata' 的翻译行。
     *
     * 与通用语言行清理分开执行：即使模块没有原生多语言字段，
     * 仍需清掉扩展字段的覆盖值与附件，避免残留。
     *
     * @return void
     */
    protected function purgeMetadataOnDelete()
    {
        $module = $this->getTable();
        $itemId = (int) $this->getKey();
        if ($module === '' || $itemId <= 0) {
            return;
        }

        $translations = new MetadataTranslationStore();
        $translations->delete($module, $itemId);
        MetadataValues::purgeByModule($module, $itemId);
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
     * 删除多语言记录（按 module + item_id 精确清理，不依赖功能开关）。
     *
     * @return void
     */
    protected function purgeLanguageOnDelete()
    {
        $module = $this->resolveLanguageModule();
        $itemId = (int) $this->getKey();
        if ($module !== '' && $itemId > 0) {
            LanguageValuePurger::purge($module, $itemId);
        }
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

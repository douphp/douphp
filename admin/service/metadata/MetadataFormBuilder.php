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

namespace Dou\Admin\Service\Metadata;

use Dou\Core\Service\BaseService;
use Dou\Core\Service\Metadata\MetadataSchema;
use Dou\Core\Service\Metadata\MetadataService;
use Dou\Core\Service\Metadata\MetadataTranslationStore;
use Dou\Core\Service\Metadata\MetadataValues;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 内容编辑页 metadata 表单数据构建（语言面板 + 控件名预计算）。
 *
 * 所有名称、选中态、槽位都在 PHP 侧算好，模板只做变量输出，避免模板内函数调用。
 */
class MetadataFormBuilder extends BaseService
{
    /**
     * @param string $module
     * @param string $raw 内容行 metadata 原始 JSON
     * @param int $itemId
     * @return array
     */
    public function build($module, $raw, $itemId = 0)
    {
        $schema = MetadataService::instance()->schema($module);
        if (empty($schema)) {
            return array(
                'present' => 0,
                'module' => (string) $module,
                'item_id' => (int) $itemId,
                'panes' => array(),
                'show_tabs' => false,
            );
        }

        $store = new MetadataTranslationStore();
        $defaultLang = $store->defaultLanguage();
        $defaultMap = MetadataValues::decode($raw);
        $translations = (int) $itemId > 0 ? $store->all($module, (int) $itemId) : array();

        $languages = array(array(
            'name' => lang('metadata_default_language', '默认语言'),
            'language_pack' => $defaultLang,
            'is_default' => true,
        ));
        foreach ($store->languagePacks() as $language) {
            $languages[] = array(
                'name' => $language['name'],
                'language_pack' => $language['language_pack'],
                'is_default' => false,
            );
        }

        $panes = array();
        foreach ($languages as $language) {
            $pack = $language['language_pack'];
            $isDefault = !empty($language['is_default']);
            $map = $isDefault
                ? $defaultMap
                : (isset($translations[$pack]) ? $translations[$pack] : array());

            $fields = array();
            foreach ($schema as $slug => $definition) {
                $inherit = !$isDefault && !array_key_exists($slug, $map);
                if ($inherit) {
                    // 继承态仍填充默认值，供单字段抽屉预填；inherit 保持 checked，主表单不会误提交。
                    $value = array_key_exists($slug, $defaultMap) ? $defaultMap[$slug] : null;
                } else {
                    $value = array_key_exists($slug, $map) ? $map[$slug] : null;
                }
                $fields[] = self::buildField($definition, $pack, $isDefault, $value, $inherit);
            }

            $panes[] = array(
                'name' => $language['name'],
                'language_pack' => $pack,
                'is_default' => $isDefault,
                'fields' => $fields,
            );
        }

        $langButtons = $this->buildFieldLangButtons($module, (int) $itemId, $schema, $panes, $defaultLang, $store, $translations);
        if (!empty($panes[0]['fields'])) {
            foreach ($panes[0]['fields'] as $index => $field) {
                $panes[0]['fields'][$index]['lang_buttons'] = isset($langButtons[$field['slug']])
                    ? $langButtons[$field['slug']]
                    : '';
            }
        }

        return array(
            'present' => 1,
            'module' => (string) $module,
            'item_id' => (int) $itemId,
            'default_lang' => $defaultLang,
            'panes' => $panes,
            'show_tabs' => false,
        );
    }

    /**
     * 默认 pane 每个字段的原生风格语言按钮（draft 模式）。
     *
     * @param string $module
     * @param int $itemId
     * @param array $schema
     * @param array $panes
     * @param string $defaultLang
     * @param MetadataTranslationStore $store
     * @param array $translations
     * @return array slug => HTML
     */
    private function buildFieldLangButtons($module, $itemId, array $schema, array $panes, $defaultLang, MetadataTranslationStore $store, array $translations)
    {
        $defaultFields = array();
        if (!empty($panes[0]['fields'])) {
            foreach ($panes[0]['fields'] as $field) {
                $defaultFields[$field['slug']] = $field;
            }
        }

        $languagePacks = array();
        foreach ($store->languagePacks() as $language) {
            $languagePacks[] = (string) $language['language_pack'];
        }

        $buttons = array();
        $targets = array();
        $labels = array();
        $hasValue = array();
        $fields = array();

        foreach ($schema as $slug => $definition) {
            $type = $definition['type'];
            $labels[$slug] = $definition['label'];

            foreach ($languagePacks as $pack) {
                $hasValue[$slug][$pack] = isset($translations[$pack]) && array_key_exists($slug, $translations[$pack]);
            }

            if ($type === 'gallery') {
                // 多图字段暂不支持多语言，不生成按钮也不提示
                continue;
            }

            $fields[] = $slug;
            $target = array(
                'kind' => $type === 'input' ? 'text' : $type,
                'target' => 'metadata[%s][' . $slug . ']',
                'source' => 'metadata[' . $defaultLang . '][' . $slug . ']',
                'inherit' => 'metadata_inherit[%s][' . $slug . ']',
            );

            if ($type === 'editor') {
                $target['target'] = 'metadata_editor_%s_' . $slug;
                $target['source'] = 'metadata_editor_' . $defaultLang . '_' . $slug;
                $target['target_type'] = 'editor';
                // 草稿弹窗内 AI 翻译按钮读取原文用编辑器 id（douEditor 直接命中）
                $target['ai_field'] = 'metadata_editor_' . $defaultLang . '_' . $slug;
                $target['ai_type'] = 'content';
            } elseif ($type === 'input' || $type === 'textarea') {
                // 草稿弹窗内 AI 翻译按钮按实际控件 name 读取原文
                $target['ai_field'] = 'metadata[' . $defaultLang . '][' . $slug . ']';
                $target['ai_type'] = $type === 'textarea' ? 'textarea' : 'text';
            } elseif ($type === 'checkbox') {
                $target['target'] = 'metadata[%s][' . $slug . '][]';
                $target['source'] = 'metadata[' . $defaultLang . '][' . $slug . '][]';
            } elseif ($type === 'image' || $type === 'file') {
                $target['preview'] = isset($defaultFields[$slug]['url']) ? $defaultFields[$slug]['url'] : '';
                if ($type === 'file') {
                    $effective = MetadataSchema::effectiveFileOptions($definition);
                    $target['accept'] = '.' . str_replace(',', ',.', $effective['extensions']);
                } else {
                    $target['accept'] = 'image/*';
                }
            }

            $targets[$slug] = $target;
        }

        if (empty($fields)) {
            return $buttons;
        }

        $generated = language()->buildLangButtons($module, $itemId, $fields, array(
            'draft' => true,
            'draft_create' => true,
            'default_pack' => $defaultLang,
            'exclude_pack' => $defaultLang,
            'targets' => $targets,
            'labels' => $labels,
            'has_value' => $hasValue,
            'upload_url' => route('admin.metadata.draft.upload'),
            'release_url' => route('admin.metadata.draft.release'),
        ));

        foreach ($generated as $slug => $html) {
            $buttons[$slug] = $html;
        }

        return $buttons;
    }

    /**
     * @param array $definition
     * @param string $lang
     * @param bool $isDefault
     * @param mixed $value
     * @param bool $inherit
     * @return array
     */
    private static function buildField(array $definition, $lang, $isDefault, $value, $inherit)
    {
        $slug = $definition['slug'];
        $type = $definition['type'];
        $decorated = MetadataValues::decorate(array($slug => $definition), array($slug => $value));
        $field = $decorated[$slug];

        $inputName = 'metadata[' . $lang . '][' . $slug . ']';
        $field['lang'] = $lang;
        $field['is_default'] = $isDefault;
        $field['inherit'] = $inherit;
        $field['input_name'] = $inputName;
        $field['hidden_name'] = $inputName;
        $field['file_name'] = 'mf_' . $lang . '_' . $slug;
        $field['inherit_name'] = 'metadata_inherit[' . $lang . '][' . $slug . ']';
        $field['clear_name'] = 'metadata_clear[' . $lang . '][' . $slug . ']';
        $field['editor_id'] = 'metadata_editor_' . $lang . '_' . $slug;
        $field['editor_input_name'] = $inputName;
        $field['file_number'] = '';
        $field['file_url'] = '';
        $field['accept'] = $type === 'image' ? 'image/*' : '';
        $field['checked'] = false;
        $field['slots'] = array();

        if ($type === 'image') {
            $field['file_number'] = is_string($value) ? $value : '';
            $field['file_url'] = $field['url'];
        } elseif ($type === 'file') {
            $field['file_number'] = is_string($value) ? $value : '';
            $field['file_url'] = $field['url'];
            $effective = MetadataSchema::effectiveFileOptions($definition);
            $field['accept'] = '.' . str_replace(',', ',.', $effective['extensions']);
        } elseif ($type === 'gallery') {
            $numbers = is_array($value) ? array_values($value) : array();
            $limit = MetadataSchema::limitFor($definition);
            $slotCount = max($limit, count($numbers));
            $slotCount = min($slotCount, MetadataSchema::MAX_MULTI_LIMIT);
            for ($i = 0; $i < $slotCount; $i++) {
                $number = isset($numbers[$i]) ? (string) $numbers[$i] : '';
                $url = '';
                foreach ($field['items'] as $item) {
                    if ($item['number'] === $number) {
                        $url = $item['url'];
                        break;
                    }
                }
                $field['slots'][] = array(
                    'index' => $i,
                    'input_name' => 'metadata[' . $lang . '][' . $slug . '][' . $i . ']',
                    'file_name' => 'mf_' . $lang . '_' . $slug . '_' . $i,
                    'number' => $number,
                    'value' => $url,
                    'url' => $url,
                );
            }
        } elseif ($type === 'switch') {
            $field['checked'] = ((string) $value === '1' || $value === 1);
        }

        $field['required_display'] = !empty($definition['required']);
        $field['tip'] = isset($definition['tip']) ? $definition['tip'] : '';
        // 短控件把多语言/辅助生成按钮放到控件右侧同一行，长控件（多行文本/富文本/附件等）按钮单独成行
        $field['action_inline'] = in_array($type, array('input', 'number', 'date', 'select'), true);

        return $field;
    }
}

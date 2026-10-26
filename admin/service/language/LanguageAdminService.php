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

namespace Dou\Admin\Service\Language;

use Dou\Admin\Contract\AdminLanguageContract;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Language\LanguageService as CoreLanguageService;
use Dou\Core\Service\Noop\NullLanguageService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台通用语言能力服务（由容器解析 AdminLanguageContract 后供 language() 取用）。
 *
 * 在 core 通用语言能力基础上补充后台专用能力：
 * - buildLangButtons
 * - buildLangList
 */
class LanguageAdminService extends CoreLanguageService implements AdminLanguageContract
{
    /**
     * 后台多语言按钮渲染数据：按字段 + 语言包枚举生成 a 标签列表。
     *
     * draft 模式用于“本地草稿”：按钮不再即时 AJAX 保存，而是携带目标控件描述，
     * 由抽屉在关闭时把值写回主表单/隐藏 pane。
     *
     * @param string $module
     * @param string|int $itemId
     * @param string|array $fieldList 逗号分隔字段名，或字段名数组
     * @param array $options draft / targets / labels / has_value / default_pack / exclude_pack / draft_create
     * @return array
     */
    public function buildLangButtons($module, $itemId = '', $fieldList = '', array $options = array())
    {
        $fields = is_array($fieldList)
            ? array_values($fieldList)
            : explode(',', str_replace(' ', '', (string) $fieldList));
        $fields = array_values(array_filter(array_map('strval', $fields), 'strlen'));

        if (!Config::get('features.language', false)) {
            return NullLanguageService::emptyLangButtonFields(implode(',', $fields));
        }

        $draft = !empty($options['draft']);
        $draftCreate = !empty($options['draft_create']);
        $targets = isset($options['targets']) && is_array($options['targets']) ? $options['targets'] : array();
        $labels = isset($options['labels']) && is_array($options['labels']) ? $options['labels'] : array();
        $hasValue = isset($options['has_value']) && is_array($options['has_value']) ? $options['has_value'] : array();
        $previews = isset($options['previews']) && is_array($options['previews']) ? $options['previews'] : array();
        $defaultPack = isset($options['default_pack'])
            ? (string) $options['default_pack']
            : (string) Config::get('site.language', 'zh_cn');
        $excludePack = isset($options['exclude_pack']) ? (string) $options['exclude_pack'] : '';
        $uploadUrl = isset($options['upload_url']) ? (string) $options['upload_url'] : '';
        $releaseUrl = isset($options['release_url']) ? (string) $options['release_url'] : '';

        $token = csrf()->token();
        $_LANG = lang_all();
        $languageList = DB::table('language')
            ->field('name, language_pack')
            ->order('sort ASC, id DESC')
            ->select();

        $btnLang = array();
        foreach ($fields as $field) {
            $btnLang[$field] = '';
            $desc = isset($targets[$field]) && is_array($targets[$field]) ? $targets[$field] : array();
            $kind = isset($desc['kind']) ? (string) $desc['kind'] : $this->langFieldType($field);
            $prefixedFieldKey = $module . '_' . $field;
            $title = !empty($labels[$field])
                ? (string) $labels[$field]
                : (!empty($_LANG[$prefixedFieldKey]) ? $_LANG[$prefixedFieldKey] : (!empty($_LANG[$field]) ? $_LANG[$field] : $field));

            foreach ((array) $languageList as $lang) {
                $pack = (string) $lang['language_pack'];
                if ($excludePack !== '' && $pack === $excludePack) {
                    continue;
                }

                if ($draft) {
                    if (!$itemId && !$draftCreate) {
                        $btnLang[$field] .= '<a href="javascript:;" data-dou-toggle="modal" data-dou-modal="message" data-title="' . $this->attr($_LANG['language_cue_title']) . '" data-html="' . $this->attr($_LANG['language_cue_content']) . '">' . $this->attr($lang['name']) . '</a>';
                        continue;
                    }

                    $target = isset($desc['target']) ? str_replace('%s', $pack, (string) $desc['target']) : 'language_draft[' . $pack . '][' . $field . ']';
                    $source = isset($desc['source']) ? str_replace('%s', $defaultPack, (string) $desc['source']) : $field;
                    $inherit = isset($desc['inherit']) ? str_replace('%s', $pack, (string) $desc['inherit']) : '';
                    $triggerId = isset($desc['trigger_id']) ? (string) $desc['trigger_id'] : ($pack . '_' . $field);

                    if (isset($hasValue[$field]) && is_array($hasValue[$field])) {
                        // 调用方已提供该字段的完整/部分状态：缺失 pack 视为未翻译，避免逐项查库
                        $cur = !empty($hasValue[$field][$pack]);
                    } else {
                        $cur = $itemId ? (bool) DB::table('language_value')
                            ->where('field', $field)
                            ->where('language_pack', $pack)
                            ->where('module', $module)
                            ->where('item_id', $itemId)
                            ->find() : false;
                    }

                    $attrs = ' data-draft="1"'
                        . ' data-draft-kind="' . $this->attr($kind) . '"'
                        . ' data-draft-target="' . $this->attr($target) . '"'
                        . ' data-draft-source="' . $this->attr($source) . '"'
                        . ' data-trigger-id="' . $this->attr($triggerId) . '"'
                        . ' data-field-type="' . $this->attr($kind) . '"';
                    if (!empty($desc['target_type'])) {
                        $attrs .= ' data-draft-target-type="' . $this->attr($desc['target_type']) . '"';
                    }
                    if ($inherit !== '') {
                        $attrs .= ' data-draft-inherit="' . $this->attr($inherit) . '"';
                    }
                    $preview = isset($previews[$field][$pack]) ? (string) $previews[$field][$pack] : '';
                    if ($preview === '' && !empty($desc['preview'])) {
                        $preview = (string) $desc['preview'];
                    }
                    if ($preview !== '') {
                        $attrs .= ' data-draft-preview="' . $this->attr($preview) . '"';
                    }
                    if (!empty($desc['options'])) {
                        $attrs .= ' data-draft-options="' . $this->attr(json_encode($desc['options'], JSON_UNESCAPED_UNICODE)) . '"';
                    }
                    if (!empty($desc['accept'])) {
                        $attrs .= ' data-draft-accept="' . $this->attr($desc['accept']) . '"';
                    }
                    if (!empty($desc['ai_field'])) {
                        $attrs .= ' data-draft-ai-field="' . $this->attr($desc['ai_field']) . '"'
                            . ' data-draft-ai-type="' . $this->attr(isset($desc['ai_type']) ? $desc['ai_type'] : 'text') . '"';
                    }
                    if ($uploadUrl !== '') {
                        $attrs .= ' data-draft-upload-url="' . $this->attr($uploadUrl) . '"';
                    }
                    if ($releaseUrl !== '') {
                        $attrs .= ' data-draft-release-url="' . $this->attr($releaseUrl) . '"';
                    }
                    $attrs .= ' data-draft-module="' . $this->attr($module) . '"'
                        . ' data-draft-slug="' . $this->attr($field) . '"'
                        . ' data-token="' . $this->attr($token) . '"';

                    $class = $cur ? ' class="cur"' : '';
                    $btnLang[$field] .= '<a href="javascript:;"' . $class . ' data-dou-toggle="modal" data-dou-modal="lang" data-lang="' . $this->attr($pack) . '" data-title="' . $this->attr($title) . '"' . $attrs . '>' . $this->attr($lang['name']) . '</a>';
                    continue;
                }

                if ($itemId) {
                    if (DB::table('language_value')->where('field', $field)->where('language_pack', $pack)->where('module', $module)->where('item_id', $itemId)->find()) {
                        $class = ' class="cur"';
                    } else {
                        $class = '';
                    }

                    $optionsAttr = !empty($desc['options'])
                        ? ' data-options="' . $this->attr(json_encode($desc['options'], JSON_UNESCAPED_UNICODE)) . '"'
                        : '';
                    $btnLang[$field] .= '<a href="javascript:;"' . $class . ' data-dou-toggle="modal" data-dou-modal="lang" data-lang="' . $this->attr($pack) . '" data-module="' . $this->attr($module) . '" data-item-id="' . $this->attr($itemId) . '" data-field="' . $this->attr($field) . '" data-title="' . $this->attr($title) . '" data-token="' . $this->attr($token) . '" data-type="' . $this->attr($kind) . '"' . $optionsAttr . '>' . $this->attr($lang['name']) . '</a>';
                } else {
                    $btnLang[$field] .= '<a href="javascript:;" data-dou-toggle="modal" data-dou-modal="message" data-title="' . $this->attr($_LANG['language_cue_title']) . '" data-html="' . $this->attr($_LANG['language_cue_content']) . '">' . $this->attr($lang['name']) . '</a>';
                }
            }
        }

        return $btnLang;
    }

    /**
     * HTML 属性转义。
     *
     * @param mixed $value
     * @return string
     */
    private function attr($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * 构建语言列表（用于设置页 Tab、语言管理「设置」切换项）。
     *
     * @param string $curLanguagePack 当前语言包标识；命中时该项 cur=true
     * @return array 形如 [{name, language_pack, cur}]；features.language 关闭时为空数组
     */
    public function buildLangList($curLanguagePack = '')
    {
        $langList = array();
        if (!Config::get('features.language', false)) {
            return $langList;
        }
        $langData = DB::table('language')->order('sort DESC, id DESC')->select();
        foreach ((array) $langData as $row) {
            $langList[] = array(
                'name' => $row['name'],
                'language_pack' => $row['language_pack'],
                'cur' => $row['language_pack'] == $curLanguagePack ? true : false,
            );
        }
        return $langList;
    }

    /**
     * 多语言弹窗控件类型：编辑器 / 上传 / 多行文本 / 单行文本。
     *
     * @param string $field
     * @return string content|file|textarea|text
     */
    private function langFieldType($field)
    {
        $field = is_scalar($field) ? (string) $field : '';
        if ($field === 'content' || strpos($field, 'content_') === 0) {
            return 'content';
        }
        if (in_array($field, array('image', 'file', 'show_img'), true)) {
            return 'file';
        }
        if (in_array($field, array('description', 'show_text', 'text', 'defined', 'answer', 'brief'), true)) {
            return 'textarea';
        }

        return 'text';
    }
}

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
 * - deleteLang
 * - buildLangButtons
 */
class LanguageAdminService extends CoreLanguageService implements AdminLanguageContract
{
    /**
     * 清理多语言值及相关语言文件（按 module + item_id 维度）。
     *
     * @param string $module
     * @param string|int $itemId
     * @return void
     */
    public function deleteLang($module = '', $itemId = '')
    {
        if (!Config::get('features.language', false)) {
            return;
        }

        $langFiles = DB::table('language_value')
            ->field('value')
            ->where('module', $module)
            ->where('item_id', $itemId)
            ->where('field', 'IN', array('image', 'show_img'))
            ->select();

        foreach ((array) $langFiles as $row) {
            attachment()->delete($row['value']);
        }

        DB::table('language_value')
            ->where('module', $module)
            ->where('item_id', $itemId)
            ->delete();
    }

    /**
     * 后台多语言按钮渲染数据：按字段 + 语言包枚举生成 a 标签列表。
     *
     * @param string $module
     * @param string|int $itemId
     * @param string $fieldList 逗号分隔字段名
     * @return array
     */
    public function buildLangButtons($module, $itemId = '', $fieldList = '')
    {
        if (!Config::get('features.language', false)) {
            return NullLanguageService::emptyLangButtonFields($fieldList);
        }

        $token = csrf()->token();
        $_LANG = lang_all();
        $fieldList = explode(',', str_replace(' ', '', $fieldList));
        $languageList = DB::table('language')
            ->field('name, language_pack')
            ->order('sort ASC, id DESC')
            ->select();

        $btnLang = array();
        foreach ($fieldList as $field) {
            $btnLang[$field] = '';
            foreach ((array) $languageList as $lang) {
                if ($itemId) {
                    if (DB::table('language_value')->where('field', $field)->where('language_pack', $lang['language_pack'])->where('module', $module)->where('item_id', $itemId)->find()) {
                        $class = ' class="cur"';
                    } else {
                        $class = '';
                    }

                    $prefixedFieldKey = $module . '_' . $field;
                    $title = !empty($_LANG[$prefixedFieldKey]) ? $_LANG[$prefixedFieldKey] : (!empty($_LANG[$field]) ? $_LANG[$field] : $field);
                    $type = $this->langFieldType($field);

                    $btnLang[$field] .= '<a href="javascript:;"' . $class . ' data-dou-toggle="modal" data-dou-modal="lang" data-lang="' . $lang['language_pack'] . '" data-module="' . $module . '" data-item-id="' . $itemId . '" data-field="' . $field . '" data-title="' . $title . '" data-token="' . $token . '" data-type="' . $type . '">' . $lang['name'] . '</a>';
                } else {
                    $btnLang[$field] .= '<a href="javascript:;" data-dou-toggle="modal" data-dou-modal="message" data-title="' . $_LANG['language_cue_title'] . '" data-html="' . $_LANG['language_cue_content'] . '">' . $lang['name'] . '</a>';
                }
            }
        }

        return $btnLang;
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

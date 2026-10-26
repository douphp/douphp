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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 原生多语言字段白名单：按模块声明可翻译字段与控件类型。
 *
 * 按钮构造、草稿保存、图片草稿上传共用本表，避免三处白名单漂移。
 */
class LanguageDraftFields
{
    const KIND_TEXT = 'text';
    const KIND_TEXTAREA = 'textarea';
    const KIND_EDITOR = 'editor';
    const KIND_IMAGE = 'image';

    /** @var array module => field => kind */
    private static $map = array(
        'product' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'article' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'cases' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'course' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'doc' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'download' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'equipment' => array(
            'name' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'gallery' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'item' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'professional' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'service' => array(
            'name' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'solution' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'support' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'video' => array(
            'title' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'brand' => array(
            'name' => self::KIND_TEXT,
            'class' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'team' => array(
            'name' => self::KIND_TEXT,
            'class' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
            'image' => self::KIND_IMAGE,
        ),
        'article_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'cases_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'course_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'doc_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'download_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'gallery_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'item_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'professional_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'solution_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'support_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'video_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'product_category' => array(
            'name' => self::KIND_TEXT,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'show' => array(
            'name' => self::KIND_TEXT,
            'image' => self::KIND_IMAGE,
            'link' => self::KIND_TEXT,
            'text' => self::KIND_TEXTAREA,
        ),
        'link' => array(
            'name' => self::KIND_TEXT,
            'image' => self::KIND_IMAGE,
            'url' => self::KIND_TEXT,
        ),
        'data' => array(
            'name' => self::KIND_TEXT,
            'image' => self::KIND_IMAGE,
            'text' => self::KIND_TEXTAREA,
            'link' => self::KIND_TEXT,
        ),
        'nav' => array(
            'name' => self::KIND_TEXT,
            'guide' => self::KIND_TEXT,
        ),
        'faq' => array(
            'ask' => self::KIND_TEXT,
            'answer' => self::KIND_TEXTAREA,
        ),
        'vote' => array(
            'name' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'image' => self::KIND_IMAGE,
        ),
        'book_item' => array(
            'name' => self::KIND_TEXT,
            'tag' => self::KIND_TEXT,
            'brief' => self::KIND_TEXTAREA,
            'content' => self::KIND_EDITOR,
            'image' => self::KIND_IMAGE,
        ),
        'form' => array(
            'name' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
        ),
        'form_field' => array(
            'name' => self::KIND_TEXT,
            'placeholder' => self::KIND_TEXT,
            'tip' => self::KIND_TEXT,
        ),
        'attribute' => array(
            'name' => self::KIND_TEXT,
        ),
        'area' => array(
            'area_name' => self::KIND_TEXT,
        ),
        'page' => array(
            'name' => self::KIND_TEXT,
            'content' => self::KIND_EDITOR,
            'keywords' => self::KIND_TEXT,
            'description' => self::KIND_TEXTAREA,
        ),
        'landing' => array(
            'name' => self::KIND_TEXT,
            'content_top' => self::KIND_EDITOR,
            'content_middle' => self::KIND_EDITOR,
            'content_bottom' => self::KIND_EDITOR,
        ),
        'coupon' => array(
            'name' => self::KIND_TEXT,
            'brief' => self::KIND_TEXTAREA,
        ),
        'certificate' => array(
            'name' => self::KIND_TEXT,
            'image' => self::KIND_IMAGE,
        ),
        'onepic' => array(
            'name' => self::KIND_TEXT,
            'image' => self::KIND_IMAGE,
        ),
        'partner' => array(
            'name' => self::KIND_TEXT,
            'image' => self::KIND_IMAGE,
        ),
    );

    /**
     * @param string $module
     * @return array field => kind
     */
    public static function fields($module)
    {
        $module = (string) $module;
        if (!isset(self::$map[$module])) {
            return array();
        }

        return self::$map[$module];
    }

    /**
     * @param string $module
     * @param string $field
     * @return string
     */
    public static function kind($module, $field)
    {
        return isset(self::$map[(string) $module][(string) $field])
            ? self::$map[(string) $module][(string) $field]
            : '';
    }

    /**
     * @param string $module
     * @param string $field
     * @return bool
     */
    public static function isImage($module, $field)
    {
        return self::kind($module, $field) === self::KIND_IMAGE;
    }

    /**
     * 是否已知模块。
     *
     * @param string $module
     * @return bool
     */
    public static function exists($module)
    {
        return isset(self::$map[(string) $module]);
    }
}

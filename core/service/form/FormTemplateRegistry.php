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

namespace Dou\Core\Service\Form;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 表单预置模板注册表。
 *
 * 清单维护在 config/form.php；本类只负责读取清单，供后台「新建表单」与 CLI 播种复用。
 */
class FormTemplateRegistry
{
    /** @var array|null 请求内模板清单缓存 */
    private static $templates = null;

    /**
     * 全部模板：key => array('name', 'content', 'success_message', 'fields')
     *
     * @return array
     */
    public static function all()
    {
        if (self::$templates !== null) {
            return self::$templates;
        }

        self::$templates = array();
        $file = self::configFile();
        if ($file !== '' && is_file($file)) {
            $config = include $file;
            if (is_array($config) && isset($config['templates']) && is_array($config['templates'])) {
                foreach ($config['templates'] as $key => $template) {
                    if (!is_string($key) || $key === '' || !is_array($template)) {
                        continue;
                    }
                    self::$templates[$key] = array(
                        'name' => isset($template['name']) ? (string) $template['name'] : $key,
                        'content' => isset($template['content']) ? (string) $template['content'] : '',
                        'success_message' => isset($template['success_message']) ? (string) $template['success_message'] : '',
                        'fields' => isset($template['fields']) && is_array($template['fields']) ? $template['fields'] : array(),
                    );
                }
            }
        }

        return self::$templates;
    }

    /**
     * 单个模板。
     *
     * @param string $key
     * @return array
     */
    public static function get($key)
    {
        $templates = self::all();

        return isset($templates[$key]) ? $templates[$key] : array();
    }

    /**
     * 是否存在。
     *
     * @param string $key
     * @return bool
     */
    public static function exists($key)
    {
        $templates = self::all();

        return isset($templates[$key]);
    }

    /**
     * 后台下拉选项：key => 模板名。
     *
     * @return array
     */
    public static function labels()
    {
        $labels = array();
        foreach (self::all() as $key => $template) {
            $labels[$key] = $template['name'];
        }

        return $labels;
    }

    /**
     * 配置文件路径。
     *
     * @return string
     */
    private static function configFile()
    {
        if (defined('CONFIG_PATH')) {
            return CONFIG_PATH . 'form.php';
        }

        return dirname(dirname(dirname(__DIR__))) . '/config/form.php';
    }
}

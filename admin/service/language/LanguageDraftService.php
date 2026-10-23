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

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Content\MarkdownRenderer;
use Dou\Core\Support\Check;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 原生字段多语言本地草稿：加载现有翻译、随主表单统一落库。
 *
 * 抽屉只改 hidden 草稿；create/update 事务内由业务服务调用 save()。
 */
class LanguageDraftService extends BaseService
{
    /** @var MarkdownRenderer */
    private $markdown;

    /**
     * @param MarkdownRenderer $markdown
     */
    public function __construct(MarkdownRenderer $markdown)
    {
        $this->markdown = $markdown;
    }

    /**
     * 编辑页草稿初值：pack 分组的字段列表，供通用模板输出 hidden。
     *
     * @param string $module
     * @param int $itemId
     * @param array $fields
     * @return array
     */
    public function load($module, $itemId, array $fields)
    {
        $itemId = (int) $itemId;
        if ($itemId <= 0 || empty($fields) || !Config::get('features.language', false)) {
            return array();
        }

        $rows = DB::table('language_value')
            ->where('module', (string) $module)
            ->where('item_id', $itemId)
            ->where('field', 'IN', array_values($fields))
            ->order('id ASC')
            ->select();

        $default = $this->defaultLanguage();
        $groups = array();
        foreach ((array) $rows as $row) {
            $pack = (string) $row['language_pack'];
            if ($pack === $default || !Check::languagePack($pack)) {
                continue;
            }
            $field = (string) $row['field'];
            $value = (string) $row['value'];
            if ($field === 'content' || $row['type'] === 'content') {
                $value = $this->markdown->toHtml($value);
            }
            if (!isset($groups[$pack])) {
                $groups[$pack] = array('language_pack' => $pack, 'fields' => array());
            }
            $groups[$pack]['fields'][] = array('field' => $field, 'value' => $value);
        }

        return array_values($groups);
    }

    /**
     * 从 POST 数据提取可保存草稿（只处理 present 标记）。
     *
     * @param array $data
     * @param array $fields
     * @return array pack => field => value
     */
    public function drafts(array $data, array $fields)
    {
        if (!Config::get('features.language', false)) {
            return array();
        }
        $raw = isset($data['language_draft']) && is_array($data['language_draft']) ? $data['language_draft'] : array();
        $default = $this->defaultLanguage();
        $allowed = array_flip($fields);

        $drafts = array();
        foreach ($raw as $pack => $values) {
            $pack = (string) $pack;
            if ($pack === $default || !Check::languagePack($pack) || !is_array($values)) {
                continue;
            }
            foreach ($values as $field => $value) {
                $field = (string) $field;
                if (!isset($allowed[$field])) {
                    continue;
                }
                $drafts[$pack][$field] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $drafts;
    }

    /**
     * 事务内保存草稿：空值删除该语言字段，非空 upsert。
     *
     * @param string $module
     * @param int $itemId
     * @param array $drafts pack => field => value
     * @return void
     */
    public function save($module, $itemId, array $drafts)
    {
        $module = (string) $module;
        $itemId = (int) $itemId;
        if ($itemId <= 0 || empty($drafts) || !Config::get('features.language', false)) {
            return;
        }

        foreach ($drafts as $pack => $values) {
            foreach ($values as $field => $value) {
                $type = $this->fieldType($field);
                $value = (string) $value;
                if ($type === 'content') {
                    $value = $this->markdown->toStore($value);
                }

                $existing = DB::table('language_value')
                    ->where('language_pack', (string) $pack)
                    ->where('module', $module)
                    ->where('item_id', $itemId)
                    ->where('field', (string) $field)
                    ->order('id DESC')
                    ->find();

                if ($value === '') {
                    if ($existing) {
                        DB::table('language_value')->where('id', (int) $existing['id'])->delete();
                    }
                    continue;
                }

                if ($existing) {
                    DB::table('language_value')->where('id', (int) $existing['id'])->data(array(
                        'value' => $value,
                        'type' => $type,
                    ))->update();
                } else {
                    DB::table('language_value')->insert(array(
                        'language_pack' => (string) $pack,
                        'module' => $module,
                        'item_id' => $itemId,
                        'field' => (string) $field,
                        'value' => $value,
                        'type' => $type,
                        'created_at' => date('Y-m-d H:i:s'),
                    ));
                }
            }
        }
    }

    /**
     * @param string $field
     * @return string content|textarea|text
     */
    private function fieldType($field)
    {
        if ($field === 'content' || strpos($field, 'content_') === 0) {
            return 'content';
        }
        if (in_array($field, array('description', 'show_text', 'text', 'answer', 'brief'), true)) {
            return 'textarea';
        }

        return 'text';
    }

    /**
     * @return string
     */
    private function defaultLanguage()
    {
        return (string) Config::get('site.language', 'zh_cn');
    }
}

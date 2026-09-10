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

namespace Dou\Front\Model\Show;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 前台幻灯数据模型（show 表）。
 *
 * showList() 为静态门面：直返成品数组（不返回 Builder）。
 */
class Show extends Model
{
    protected $table = 'show';
    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'sort' => 'int',
        'image' => 'attachment',
    );

    /** @var array */
    protected $translatable = array('name', 'link', 'image', 'text');

    /** @var string */
    protected $translatableModule = 'show';

    /** @var array */
    protected $prefetchers = array(
        'attachment' => 'image',
        'language' => 'name,link,image,text',
    );

    /**
     * 按终端类型过滤（type 字段；空串不过滤）。
     *
     * @param Builder $query
     * @param string $type pc / miniprogram 等
     * @return Builder
     */
    public function scopeForType(Builder $query, $type = 'pc')
    {
        if ($type === '') {
            return $query;
        }

        return $query->where('type', $type);
    }

    /**
     * 默认排序：sort ASC, id ASC。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeOrderBySort(Builder $query)
    {
        return $query->order('sort ASC, id ASC');
    }

    /**
     * 幻灯 / 横幅列表（成品数组）。
     *
     * @param string $type pc / miniprogram 等；空串返回全部
     * @return array
     */
    public static function showList($type = 'pc')
    {
        $query = DB::table('show');
        if ($type) {
            $query->where('type', $type);
        }
        $rows = $query->order('sort ASC, id ASC')->select();

        $show_list = array();
        foreach ((array) $rows as $row) {
            $row = language()->langBox($row, 'show', 'name, image, link, text');

            $text_array = array();
            if (preg_match("(\r)", $row['text'])) {
                $show_text = str_replace("\r\n", "\r", $row['text']);
                $text_array = explode("\r", $show_text);
            }

            $show_list[] = array(
                'id' => $row['id'],
                'name' => $row['name'],
                'link' => $row['link'],
                'image' => attachment()->url($row['image']),
                'text' => $row['text'],
                'text_array' => $text_array,
                'sort' => $row['sort'],
            );
        }

        return $show_list;
    }
}

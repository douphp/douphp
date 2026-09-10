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

namespace Dou\Core\Service\Attachment;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 把 `.file` 号 / 直接 URL / 站点相对路径解析为可访问 URL。
 *
 * 输入分类：
 *   1) `xxx.file` 号 → 查 dou_file.file → ROOT_URL + 路径（thumb=true 时拼 `_thumb`）
 *   2) `http(s)://...` 完整 URL → 透传
 *   3) 其它字符串 → 视为相对站点根的路径，拼 ROOT_URL
 */
class UrlResolver
{
    /**
     * 请求级 number→url 缓存（键：'0:'/'1:'(thumb) + number）。
     *
     * urlBatch() 填充、url() 命中即返回，使「批量预热后逐字段 cast 读取」零额外查询。
     *
     * @var array
     */
    protected static $urlCache = array();

    /**
     * 生成缓存尾（基于 file_update_time，24h 内有效）。
     *
     * @param string $fileUpdateTime
     * @return string
     */
    public function cacheTag($fileUpdateTime = '')
    {
        if ($fileUpdateTime === '' || $fileUpdateTime === null) {
            $fileUpdateTime = Config::get('site.file_update_time', '');
        }
        $interval = time() - (int) $fileUpdateTime;
        if ($fileUpdateTime && $interval < 86400) {
            return '?cache=' . $fileUpdateTime;
        }

        return '';
    }

    /**
     * 单个 number / URL → 公开 URL。
     *
     * @param mixed $number
     * @param bool $thumb
     * @return string
     */
    public function url($number, $thumb = false)
    {
        if ($number === null || !is_string($number)) {
            return '';
        }
        if (strrchr($number, '.file') === '.file') {
            $cacheKey = ($thumb ? '1:' : '0:') . $number;
            if (array_key_exists($cacheKey, self::$urlCache)) {
                return self::$urlCache[$cacheKey];
            }

            $file = DB::getOne("SELECT file FROM " . DB::tableName('file') . " WHERE number = '$number'");
            if (empty($file)) {
                self::$urlCache[$cacheKey] = '';
                return '';
            }
            if ($thumb) {
                $parts = explode('.', $file);
                $url = count($parts) < 2
                    ? ROOT_URL . $file . $this->cacheTag()
                    : ROOT_URL . $parts[0] . '_thumb.' . $parts[1] . $this->cacheTag();
            } else {
                $url = ROOT_URL . $file . $this->cacheTag();
            }
            self::$urlCache[$cacheKey] = $url;

            return $url;
        }
        if (empty($number)) {
            return '';
        }
        if (strpos($number, 'http') === 0) {
            return $number;
        }

        return ROOT_URL . $number;
    }

    /**
     * 批量解析。
     *
     * @param mixed $numbers
     * @param bool $thumb
     * @return array
     */
    public function urlBatch($numbers, $thumb = false)
    {
        $db = DB::getFacadeRoot();
        $map = array();
        $fileNumbers = array();
        foreach ((array) $numbers as $number) {
            if (!$number || !is_string($number)) {
                continue;
            }
            if (strrchr($number, '.file') === '.file') {
                $fileNumbers[] = $number;
                continue;
            }
            if (strpos($number, 'http') === 0) {
                $map[$number] = $number;
            } elseif ($thumb) {
                $parts = explode('.', $number, 2);
                $map[$number] = isset($parts[1])
                    ? ROOT_URL . $parts[0] . '_thumb.' . $parts[1] . $this->cacheTag()
                    : ROOT_URL . $number;
            } else {
                $map[$number] = ROOT_URL . $number;
            }
        }
        if (!empty($fileNumbers)) {
            // 预置请求过的 .file 号为空串，未命中也不会再触发单条查询
            foreach ($fileNumbers as $fileNumber) {
                $prefix = $thumb ? '1:' : '0:';
                if (!array_key_exists($prefix . $fileNumber, self::$urlCache)) {
                    self::$urlCache[$prefix . $fileNumber] = '';
                }
            }
            $rows = $db->table('file')
                ->field('number, file')
                ->where('number', 'IN', $fileNumbers)
                ->select();
            foreach ((array) $rows as $row) {
                if ($thumb) {
                    $parts = explode('.', $row['file']);
                    if (count($parts) < 2) {
                        $map[$row['number']] = ROOT_URL . $row['file'] . $this->cacheTag();
                    } else {
                        $map[$row['number']] = ROOT_URL . $parts[0] . '_thumb.' . $parts[1] . $this->cacheTag();
                    }
                } else {
                    $map[$row['number']] = ROOT_URL . $row['file'] . $this->cacheTag();
                }
            }
        }

        // 回填请求级缓存，供后续 url() 命中
        $prefix = $thumb ? '1:' : '0:';
        foreach ($map as $number => $resolved) {
            self::$urlCache[$prefix . $number] = $resolved;
        }

        return $map;
    }
}

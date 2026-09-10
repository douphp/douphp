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

namespace Dou\Core\Support;

use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 无状态工具方法静态集合。
 */
class Util
{
    /**
     * 将字符编码数组转换为字符串。
     *
     * @param mixed $codes 参数 codes。
     * @return string
     */
    public static function fromCharCodes($codes)
    {
        $code = '';
        foreach ((array) $codes as $row) {
            $code .= chr($row);
        }

        return $code;
    }

    /**
     * 按站点配置格式化价格。
     *
     * @param mixed $price 参数 price。
     * @return string|null
     */
    public static function formatPrice($price = '')
    {
        if ($price === null || $price === '') {
            return '';
        }

        if (preg_match("/^[0-9.]+$/", $price)) {
            $price = number_format($price, Config::get('site.price_decimal', 0), '.', '');
            $price = rtrim(rtrim($price, '0'), '.');
            $priceFormat = preg_replace('/d%/Ums', $price, Config::get('site.price_format', 'd%'));

            return $priceFormat;
        }
    }

    /**
     * 判断是否为当前导航节点。
     *
     * @param mixed $module 参数 module。
     * @param mixed $id 参数 id。
     * @param mixed $currentModule 参数 currentModule。
     * @param string $currentId 参数 currentId。
     * @param string $currentParentId 参数 currentParentId。
     * @return bool
     */
    public static function isCurrent($module, $id, $currentModule, $currentId = '', $currentParentId = '')
    {
        if (($id == $currentId || $id == $currentParentId) && $module == $currentModule) {
            return true;
        } elseif (!$id && $module == $currentModule) {
            return true;
        }

        return false;
    }

    /**
     * 把时间值规范化为 Unix 时间戳。
     *
     * 兼容三种输入：int 时间戳、'Y-m-d H:i:s'（及 strtotime 可解析）字符串、null/''/0。
     * 用于时间字段统一为 DATETIME 后，读取侧把明文时间还原为时间戳参与运算。
     *
     * @param mixed $value int 时间戳或 DATETIME 字符串
     * @return int|null 无效或空值返回 null
     */
    public static function toTimestamp($value)
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value > 0 ? (int) $value : null;
        }

        $ts = strtotime((string) $value);

        return $ts === false ? null : $ts;
    }

    /**
     * 将时间戳拆分为年月日数组。
     *
     * @param mixed $time 参数 time。
     * @return array
     */
    public static function toDateParts($time)
    {
        $data['ymd'] = date("Y-m-d", $time);
        $data['y'] = date("Y", $time);
        $data['m'] = date("m", $time);
        $data['d'] = date("d", $time);

        return $data;
    }

    /**
     * 规范化查询字符串，首个 & 转换为 ?。
     *
     * @param string $queryString 参数 queryString。
     * @return string|null
     */
    public static function normalizeQueryString($queryString = '')
    {
        if ($queryString) {
            if (strpos($queryString, '?')) {
                return $queryString;
            } else {
                return preg_replace('/&/', '?', $queryString, 1);
            }
        }
    }

    /**
     * 把后台 / API 入口相对链接（index.php[?route=...] 形态）补成绝对地址。
     *
     * 出站 route() 已直出绝对地址；本方法服务仍以相对 `index.php?route=...` 字面量存在的旧链接
     * （Service 的 LIST_URL 常量、redirect() 目标、message()->respond() 回跳地址，及其
     * `. '&page=' . $page` 一类拼接结果），在伪静态深路径（admin/product/35/edit）下相对解析会失效，
     * 故按当前端基址补绝对：admin → ADMIN_URL、api → API_URL、其余 → ROOT_URL。
     * 已是绝对地址 / 站点根绝对路径 / 锚点 / javascript: / mailto: / 非入口相对链接一律原样返回。
     *
     * @param string $url
     * @return string
     */
    public static function absolutizeEntryUrl($url)
    {
        $url = (string) $url;
        if ($url === '') {
            return $url;
        }
        $first = $url[0];
        if ($first === '/' || $first === '#'
            || preg_match('#^([a-z][a-z0-9+.\-]*:)?//#i', $url)
            || stripos($url, 'javascript:') === 0
            || stripos($url, 'mailto:') === 0) {
            return $url;
        }
        if (strncmp($url, 'index.php', 9) !== 0) {
            return $url;
        }
        if (defined('IS_ADMIN') && constant('IS_ADMIN') && defined('ADMIN_URL')) {
            return ADMIN_URL . $url;
        }
        if (defined('IS_API') && constant('IS_API') && defined('API_URL')) {
            return API_URL . $url;
        }
        if (defined('ROOT_URL')) {
            return ROOT_URL . $url;
        }
        return $url;
    }

    /**
     * 解析“键：值”定义列表。
     *
     * 幂等：入参已是 cast 'defined_pairs' 输出的数组结构时直接返回，避免
     * Controller 在 Model::toArray() 之后再调 getDefinedFormatted() 触发
     * "strpos() expects parameter 1 to be string, array given"。
     *
     * @param mixed $defined 参数 defined。
     * @return array
     */
    public static function parseDefinedPairs($defined)
    {
        if (is_array($defined)) {
            return $defined;
        }

        $formatDefined = array();

        if ($defined) {
            if (strpos($defined, ',') !== false) {
                $definedArray = explode(',', $defined);
            } else {
                $defined = str_replace(array("\r\n", "\r"), "\n", $defined);
                $definedArray = explode("\n", $defined);
            }

            foreach ((array) $definedArray as $row) {
                $row = str_replace(":", "：", $row);
                $array = explode('：', $row);

                if (isset($array[1])) {
                    $formatDefined[] = array(
                        "arr" => $array[0],
                        "value" => $array[1]
                    );
                }
            }
        }

        return $formatDefined;
    }

    /**
     * 解析 QQ 列表字符串。
     *
     * @param mixed $im 参数 im。
     * @return array
     */
    public static function parseQqList($im)
    {
        $imList = array();
        if ($im) {
            $imExplode = explode(',', $im);
            foreach ((array) $imExplode as $value) {
                if (strpos($value, '/') !== false) {
                    $arr = explode('/', $value);
                    $list['number'] = $arr['0'];
                    $list['nickname'] = $arr['1'];
                    $imList[] = $list;
                } else {
                    $imList[]['number'] = $value;
                }
            }
        }

        return $imList;
    }

    /**
     * 规范化 URL 主机字符串。
     *
     * @param mixed $url 参数 url。
     * @return string
     */
    public static function normalizeUrlHost($url)
    {
        if (preg_match('#(https?\://[^/]+)(/.*)?#', $url, $matches)) {
            $url = $matches[1];
        }

        return trim(str_replace('www.', '', str_replace('https://', '', str_replace('http://', '', $url))), '/');
    }

    /**
     * 确保 URL 带有 http/https 协议前缀，缺失时补 http://。
     *
     * @param string $url 参数 url。
     * @return string
     */
    public static function ensureHttp($url)
    {
        if (strpos($url, 'http://') !== false || strpos($url, 'https://') !== false) {
            return trim($url);
        }

        return 'http://' . trim($url);
    }

    /**
     * 判断文本是否包含 Markdown 语法特征。
     *
     * @param mixed $content 参数 content。
     * @return bool
     */
    public static function containsMarkdownSyntax($content)
    {
        if (trim($content) === '') {
            return false;
        }

        $patterns = array(
            '/^#{1,6}\s+/m',
            '/^```|^~~~/m',
            '/^>\s+/m',
            '/^(\*{3,}|-{3,}|_{3,})\s*$/m',
            '/^[\*\+\-]\s+/m',
            '/^\d+\.\s+/m',
            '/^-\s*\[[ x]\]\s+/mi',
            '/\|.*\|/',
            '/\[[^\]]*\]\([^)]+\)/',
            '/!\[[^\]]*\]\([^)]+\)/',
            '/\*\*[^*]+\*\*|__[^_]+__/',
            '/(?<!\*)\*[^*]+\*(?!\*)|(?<!_)_[^_]+_(?!_)/',
            '/~~[^~]+~~/',
            '/`[^`]+`/',
            '/\[\^[^\]]+\]/'
        );

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 构建用于 SQL `WHERE` 中的 IN 子句字符串，例如 IN ('1','2','3')。
     *
     * 仅接受整型 ID（内部强制 (int) 转换），用于已校验过 ID 数组的批量场景。
     *
     * @param mixed $ids 参数 ids。
     * @return string
     */
    public static function buildSqlInClause($ids)
    {
        $list = '';
        foreach ((array) $ids as $value) {
            $value = (int) $value;
            $list .= $list ? ",'$value'" : "'$value'";
        }

        return "IN ($list)";
    }

    /**
     * 解析归档年月参数为时间区间描述。
     *
     * 输入合法时返回 ['year','month','start','end','label']，否则返回空数组。
     *
     * @param mixed $year 参数 year。
     * @param mixed $month 参数 month。
     * @return array
     */
    public static function parseArchive($year, $month)
    {
        if ($year === null || $year === '') {
            return array();
        }

        $year = (int) $year;
        if ($month === null || $month === '') {
            $month = 0;
        } else {
            $month = (int) $month;
        }

        if ($year < 1970 || $year > 2100 || $month < 0 || $month > 12) {
            return array();
        }

        if ($month) {
            $start = strtotime("$year-$month-1 00:00:00");
            $end = strtotime("$year-$month-1 +1 month -1 second");
            $label = sprintf('%04d-%02d', $year, $month);
        } else {
            $start = strtotime("$year-1-1 00:00:00");
            $end = strtotime("$year-12-31 23:59:59");
            $label = sprintf('%04d', $year);
        }

        return array(
            'year' => $year,
            'month' => $month,
            'start' => $start,
            'end' => $end,
            'label' => $label,
        );
    }

    /**
     * 归档参数的中文展示标签。
     *
     * 空数组返回空串；含月返回「2026年01月」；仅年返回「2026年」。
     *
     * @param array $archive {@see self::parseArchive()} 返回值。
     * @return string
     */
    public static function archiveLabel(array $archive)
    {
        if (!$archive) {
            return '';
        }

        $year = isset($archive['year']) ? (int) $archive['year'] : 0;
        $month = isset($archive['month']) ? (int) $archive['month'] : 0;
        if ($year <= 0) {
            return '';
        }

        if ($month > 0) {
            return sprintf('%04d', $year) . '年' . sprintf('%02d', $month) . '月';
        }

        return sprintf('%04d', $year) . '年';
    }

    /**
     * 检测 PHP 文件中是否不含 SQL 危险关键字（用于主题扩展白名单加载）。
     *
     * 含任一禁词（insert/update/delete/create/truncate/drop/alter/into/load_file/outfile）即视为不安全。
     * 文件不可读时返回 false。
     *
     * @param string $file 参数 file。
     * @return bool
     */
    public static function isSqlSafePhpFile($file)
    {
        $content = @file_get_contents($file);
        if ($content === false) {
            return false;
        }

        $forbidden = array('insert', 'update', 'delete', 'create', 'truncate', 'drop', 'alter', 'into', 'load_file', 'outfile');
        foreach ($forbidden as $word) {
            if (stripos($content, $word) !== false) {
                return false;
            }
        }

        return true;
    }
}

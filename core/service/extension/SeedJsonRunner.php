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
 * Release Date: 2026-11-09
 */

namespace Dou\Core\Service\Extension;

use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 主题包 seed.json 执行器：解析、校验、素材定位与幂等写入。
 *
 * 文件位置：theme/<slug>/_action/resources/seed.json
 * 写入范围：dou_data（骨架数据）、dou_file（图片登记）、dou_language_value（翻译）。
 *
 * 约定：
 *   - 数据行按 (theme, code) 判重，已存在则跳过，不覆盖用户内容；
 *   - image 写站点相对路径，dou_data.image 存引擎生成的 dou_file.number；
 *   - locked 缺省 true，显式 false 时按 is_locked=0 写入；
 *   - 全部写入在同一事务内，任一失败整体回滚。
 *
 * 兼容 PHP 5.6 ~ 8.x。
 */
class SeedJsonRunner
{
    /** 当前支持的格式版本 */
    const VERSION = 1;

    /** sort 缺省值 */
    const DEFAULT_SORT = 50;

    /** @var array rows 允许字段（白名单，未知字段直接报错） */
    private static $allowedFields = array(
        'code', 'parent', 'group', 'name', 'text', 'link', 'image', 'item', 'locked', 'sort',
    );

    /** @var array 顶层允许字段 */
    private static $allowedTop = array('version', 'theme', 'rows');

    /** @var string 站点根目录（结尾带 /） */
    private $rootPath;

    /** @var string 物料根目录（结尾带 /） */
    private $materialsRoot;

    /** @var string 主题标识（目录名） */
    private $slug;

    /**
     * @param string $rootPath 站点根目录
     * @param string $materialsRoot 物料根（theme/<slug>/_action/）
     * @param string $slug 主题标识
     */
    public function __construct($rootPath, $materialsRoot, $slug)
    {
        $this->rootPath = rtrim(str_replace('\\', '/', (string) $rootPath), '/') . '/';
        $materialsRoot = rtrim(str_replace('\\', '/', (string) $materialsRoot), '/');
        $this->materialsRoot = $materialsRoot === '' ? '' : $materialsRoot . '/';
        $this->slug = (string) $slug;
    }

    /**
     * 只读校验 seed.json，返回规范化后的数据行；供运行时与 CLI 复用。
     *
     * @param string $file seed.json 绝对路径
     * @param string $slug 主题标识
     * @param string $rootPath 站点根目录
     * @param string $materialsRoot 物料根（theme/<slug>/_action/）
     * @return array
     * @throws \RuntimeException
     */
    public static function validateFile($file, $slug, $rootPath, $materialsRoot)
    {
        $runner = new self($rootPath, $materialsRoot, $slug);
        $data = $runner->load($file);

        return $data['rows'];
    }

    /**
     * 校验并写入 seed.json。
     *
     * @param string $file seed.json 绝对路径
     * @return array array('inserted'=>int,'skipped'=>int,'files'=>int,'translations'=>int)
     * @throws \RuntimeException
     */
    public function run($file)
    {
        $data = $this->load($file);
        $rows = $data['rows'];
        $stats = array('inserted' => 0, 'skipped' => 0, 'files' => 0, 'translations' => 0);

        if (!DB::tableExist('data') || !DB::tableExist('file')) {
            throw new \RuntimeException('seed.json（' . $this->slug . '）：缺少数据表 data / file，无法写入骨架数据。');
        }

        DB::transaction(function () use ($rows, &$stats) {
            foreach ($rows as $row) {
                $existingId = DB::table('data')
                    ->where('theme', $this->slug)
                    ->where('code', $row['code'])
                    ->value('id');

                if ($existingId) {
                    $stats['skipped']++;
                    continue;
                }

                $imageNumber = '';
                $imageSize = 0;
                if ($row['image'] !== '') {
                    $imageNumber = self::buildFileNumber($this->slug, $row['code']);
                    $imageSize = $this->imageSize($row['image']);
                }

                $id = DB::table('data')->insert(array(
                    'parent_code' => $row['parent'],
                    'theme' => $this->slug,
                    'data_group' => $row['group'],
                    'data_item' => $row['item'],
                    'name' => $row['name']['value'],
                    'code' => $row['code'],
                    'image' => $imageNumber,
                    'text' => $row['text']['value'],
                    'link' => $row['link']['value'],
                    'is_class' => $row['is_class'],
                    'is_locked' => $row['locked'] ? 1 : 0,
                    'sort' => $row['sort'],
                ));

                if (!$id) {
                    throw new \RuntimeException('seed.json（' . $this->slug . '）第 ' . $row['line'] . ' 行 ' . $row['code'] . '：数据行写入失败。');
                }

                $stats['inserted']++;

                if ($imageNumber !== '') {
                    $this->writeFile($imageNumber, $row['image'], (int) $id, $imageSize);
                    $stats['files']++;
                }

                $stats['translations'] += $this->writeTranslations((int) $id, $row);
            }
        });

        return $stats;
    }

    /**
     * 生成确定性的 dou_file.number。
     *
     * @param string $slug
     * @param string $code
     * @return string
     */
    public static function buildFileNumber($slug, $code)
    {
        $number = $slug . '_' . $code . '.file';
        if (strlen($number) > 80) {
            $number = $slug . '_' . substr(md5($code), 0, 12) . '.file';
        }

        return $number;
    }

    /**
     * 读取并规范化 seed.json。
     *
     * @param string $file
     * @return array array('rows'=>array)
     * @throws \RuntimeException
     */
    private function load($file)
    {
        if (!is_file($file)) {
            $this->fail('文件不存在：' . $file);
        }

        $raw = @file_get_contents($file);
        if ($raw === false) {
            $this->fail('文件不可读：' . $file);
        }
        if (substr($raw, 0, 3) === "\xEF\xBB\xBF") {
            $raw = substr($raw, 3);
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $message = function_exists('json_last_error_msg') ? json_last_error_msg() : ('error ' . json_last_error());
            $this->fail('JSON 解析失败：' . $message);
        }

        $unknownTop = array_diff(array_keys($data), self::$allowedTop);
        if ($unknownTop) {
            $this->fail('未知顶层字段：' . implode('、', $unknownTop));
        }
        if (!isset($data['version']) || $data['version'] !== self::VERSION) {
            $this->fail('version 必须为 ' . self::VERSION);
        }
        if (!isset($data['theme']) || !is_string($data['theme']) || $data['theme'] !== $this->slug) {
            $this->fail('theme 必须与主题目录名 ' . $this->slug . ' 一致');
        }
        if (!isset($data['rows']) || !is_array($data['rows'])) {
            $this->fail('rows 必须为数组');
        }
        if ($data['rows'] !== array_values($data['rows'])) {
            $this->fail('rows 必须为顺序数组');
        }

        $lineMap = $this->codeLineMap($raw);
        $rows = array();
        $byCode = array();

        foreach ($data['rows'] as $index => $rawRow) {
            if (!is_array($rawRow)) {
                $this->fail('rows 第 ' . ($index + 1) . ' 条必须为对象');
            }

            $code = isset($rawRow['code']) && is_string($rawRow['code']) ? $rawRow['code'] : '';
            $line = isset($lineMap[$code]) ? $lineMap[$code] : 0;

            $unknown = array_diff(array_keys($rawRow), self::$allowedFields);
            if ($unknown) {
                $this->fail('未知字段：' . implode('、', $unknown), $code, $line);
            }
            if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/', $code)) {
                $this->fail('code 缺失或格式非法', $code, $line);
            }
            if (isset($byCode[$code])) {
                $this->fail('code 重复', $code, $line);
            }

            $group = isset($rawRow['group']) ? $rawRow['group'] : '';
            if (!is_string($group) || trim($group) === '') {
                $this->fail('group 必填', $code, $line);
            }
            if (strlen($group) > 60) {
                $this->fail('group 超长', $code, $line);
            }
            if ($group === 'miniprogram') {
                $this->fail('group 不允许为 miniprogram', $code, $line);
            }

            $parent = isset($rawRow['parent']) ? $rawRow['parent'] : '';
            if (!is_string($parent)) {
                $this->fail('parent 必须为字符串', $code, $line);
            }
            if ($parent !== '' && $parent === $code) {
                $this->fail('parent 不能指向自身', $code, $line);
            }

            $item = isset($rawRow['item']) ? $rawRow['item'] : '';
            if (!is_string($item)) {
                $this->fail('item 必须为字符串', $code, $line);
            }
            if (strlen($item) > 60) {
                $this->fail('item 超长', $code, $line);
            }

            $image = isset($rawRow['image']) ? $rawRow['image'] : '';
            $image = $this->normalizeImage($image, $code, $line);

            $locked = true;
            if (array_key_exists('locked', $rawRow)) {
                if (!is_bool($rawRow['locked'])) {
                    $this->fail('locked 必须为布尔值', $code, $line);
                }
                $locked = $rawRow['locked'];
            }

            $sort = self::DEFAULT_SORT;
            if (array_key_exists('sort', $rawRow)) {
                if (!is_int($rawRow['sort']) || $rawRow['sort'] < 0 || $rawRow['sort'] > 255) {
                    $this->fail('sort 必须为 0~255 整数', $code, $line);
                }
                $sort = $rawRow['sort'];
            }

            $row = array(
                'code' => $code,
                'parent' => $parent,
                'group' => $group,
                'item' => $item,
                'image' => $image,
                'locked' => $locked,
                'sort' => $sort,
                'is_class' => 0,
                'line' => $line,
                'name' => $this->normalizeText(isset($rawRow['name']) ? $rawRow['name'] : null, 'name', $code, $line, true),
                'text' => $this->normalizeText(isset($rawRow['text']) ? $rawRow['text'] : '', 'text', $code, $line, false),
                'link' => $this->normalizeText(isset($rawRow['link']) ? $rawRow['link'] : '', 'link', $code, $line, false),
            );

            $rows[] = $row;
            $byCode[$code] = true;
        }

        // parent 必须指向同文件内的 code，并检测循环引用
        foreach ($rows as $row) {
            if ($row['parent'] !== '' && !isset($byCode[$row['parent']])) {
                $this->fail('parent 不存在：' . $row['parent'], $row['code'], $row['line']);
            }
            $visited = array($row['code'] => true);
            $cursor = $row['parent'];
            while ($cursor !== '') {
                if (isset($visited[$cursor])) {
                    $this->fail('parent 存在循环引用', $row['code'], $row['line']);
                }
                $visited[$cursor] = true;
                $cursor = $this->parentOf($rows, $cursor);
            }
        }

        // is_class 由子行派生
        $classCodes = array();
        foreach ($rows as $row) {
            if ($row['parent'] !== '') {
                $classCodes[$row['parent']] = true;
            }
        }
        foreach ($rows as $index => $row) {
            $rows[$index]['is_class'] = isset($classCodes[$row['code']]) ? 1 : 0;
        }

        return array('rows' => $this->sortParentsFirst($rows));
    }

    /**
     * 取指定 code 的 parent（不存在时返回空串）。
     *
     * @param array $rows
     * @param string $code
     * @return string
     */
    private function parentOf(array $rows, $code)
    {
        foreach ($rows as $row) {
            if ($row['code'] === $code) {
                return $row['parent'];
            }
        }

        return '';
    }

    /**
     * 父行先于子行插入，同时保持原有相对顺序。
     *
     * @param array $rows
     * @return array
     */
    private function sortParentsFirst(array $rows)
    {
        $ordered = array();
        $placed = array();
        $pending = $rows;

        while ($pending) {
            $progress = false;
            foreach ($pending as $index => $row) {
                if ($row['parent'] === '' || isset($placed[$row['parent']])) {
                    $ordered[] = $row;
                    $placed[$row['code']] = true;
                    unset($pending[$index]);
                    $progress = true;
                }
            }
            if (!$progress) {
                foreach ($pending as $row) {
                    $ordered[] = $row;
                }
                break;
            }
            $pending = array_values($pending);
        }

        return $ordered;
    }

    /**
     * 规范化 name / text / link：字符串或语言对象。
     *
     * @param mixed $value
     * @param string $field
     * @param string $code
     * @param int $line
     * @param bool $required
     * @return array array('value'=>string,'translations'=>array)
     */
    private function normalizeText($value, $field, $code, $line, $required)
    {
        if (is_string($value)) {
            if ($required && trim($value) === '') {
                $this->fail($field . ' 不能为空', $code, $line);
            }

            return array('value' => $value, 'translations' => array());
        }

        if (!is_array($value) || $value === array()) {
            $this->fail($field . ' 必须为字符串或语言对象', $code, $line);
        }
        if ($value === array_values($value)) {
            $this->fail($field . ' 语言对象必须使用语言包作为键', $code, $line);
        }

        $defaultPack = $this->defaultLanguagePack();
        if (!array_key_exists($defaultPack, $value)) {
            $this->fail($field . ' 缺少默认语言包 ' . $defaultPack, $code, $line);
        }

        $primary = '';
        $translations = array();
        foreach ($value as $pack => $text) {
            if (!is_string($pack) || !preg_match('/^[a-z_]+$/', $pack)) {
                $this->fail($field . ' 的语言包标识非法：' . $pack, $code, $line);
            }
            if (!is_string($text)) {
                $this->fail($field . ' 的语言值必须为字符串：' . $pack, $code, $line);
            }
            if ($pack === $defaultPack) {
                $primary = $text;
            } elseif ($text !== '') {
                $translations[$pack] = $text;
            }
        }

        if ($required && trim($primary) === '') {
            $this->fail($field . ' 的默认语言值不能为空', $code, $line);
        }

        return array('value' => $primary, 'translations' => $translations);
    }

    /**
     * 规范化图片路径并校验文件真实存在。
     *
     * @param mixed $value
     * @param string $code
     * @param int $line
     * @return string
     */
    private function normalizeImage($value, $code, $line)
    {
        if (!is_string($value)) {
            $this->fail('image 必须为字符串', $code, $line);
        }
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (strpos($value, '\\') !== false) {
            $this->fail('image 不能包含反斜杠', $code, $line);
        }
        if (strpos($value, '..') !== false) {
            $this->fail('image 不能包含 ..', $code, $line);
        }
        if (preg_match('#^[A-Za-z][A-Za-z0-9+.-]*://#', $value) || substr($value, 0, 2) === '//') {
            $this->fail('image 不能包含协议前缀', $code, $line);
        }
        if ($value[0] === '/') {
            $this->fail('image 必须为站点相对路径', $code, $line);
        }

        $isPackageImage = strpos($value, 'images/') === 0;
        $isThemeImage = strpos($value, 'theme/' . $this->slug . '/') === 0;
        if (!$isPackageImage && !$isThemeImage) {
            $this->fail('image 必须以 images/ 或 theme/' . $this->slug . '/ 开头', $code, $line);
        }
        if ($this->imageSource($value) === '') {
            $this->fail('image 文件不存在：' . $value, $code, $line);
        }

        return $value;
    }

    /**
     * 定位图片源文件（安装前物料 / 安装后站点根）。
     *
     * @param string $path
     * @return string 不存在时返回空串
     */
    private function imageSource($path)
    {
        $candidates = array();
        if (strpos($path, 'images/') === 0) {
            if ($this->materialsRoot !== '') {
                $candidates[] = $this->materialsRoot . 'resources/' . $path;
            }
            $candidates[] = $this->rootPath . $path;
        } elseif (strpos($path, 'theme/' . $this->slug . '/') === 0) {
            $candidates[] = $this->rootPath . $path;
        }

        foreach ($candidates as $file) {
            if (is_file($file)) {
                return $file;
            }
        }

        return '';
    }

    /**
     * 图片字节数：优先安装后的站点文件，其次物料源文件。
     *
     * @param string $path
     * @return int
     */
    private function imageSize($path)
    {
        $target = $this->rootPath . $path;
        if (is_file($target)) {
            return (int) filesize($target);
        }
        $source = $this->imageSource($path);
        if ($source !== '' && is_file($source)) {
            return (int) filesize($source);
        }

        return 0;
    }

    /**
     * 写 dou_file 记录。
     *
     * @param string $number
     * @param string $path
     * @param int $itemId
     * @param int $size
     * @return void
     * @throws \RuntimeException
     */
    private function writeFile($number, $path, $itemId, $size)
    {
        $existing = DB::table('file')->where('number', $number)->find();
        if ($existing) {
            if ((string) $existing['module'] === 'data' && (int) $existing['item_id'] === $itemId) {
                return;
            }
            throw new \RuntimeException('seed.json（' . $this->slug . '）：文件编号冲突 ' . $number . '。');
        }

        $now = date('Y-m-d H:i:s');
        $inserted = DB::table('file')->insert(array(
            'number' => $number,
            'file' => $path,
            'module' => 'data',
            'item_id' => $itemId,
            'type' => 'main',
            'size' => $size,
            'thumb_size' => 0,
            'storage_driver' => 'local',
            'status' => 'owned',
            'last_used_at' => $now,
            'created_at' => $now,
        ));
        if (!$inserted) {
            throw new \RuntimeException('seed.json（' . $this->slug . '）：图片登记失败 ' . $number . '。');
        }
    }

    /**
     * 写多语言翻译（默认语言已写入主表）。
     *
     * @param int $itemId
     * @param array $row
     * @return int 写入条数
     * @throws \RuntimeException
     */
    private function writeTranslations($itemId, array $row)
    {
        $fields = array('name' => 'text', 'text' => 'textarea', 'link' => 'text');
        $count = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($fields as $field => $type) {
            foreach ($row[$field]['translations'] as $pack => $value) {
                $inserted = DB::table('language_value')->insert(array(
                    'language_pack' => $pack,
                    'module' => 'data',
                    'item_id' => $itemId,
                    'field' => $field,
                    'value' => $value,
                    'type' => $type,
                    'created_at' => $now,
                ));
                if (!$inserted) {
                    throw new \RuntimeException('seed.json（' . $this->slug . '）：翻译写入失败 ' . $row['code'] . ' / ' . $pack . '。');
                }
                $count++;
            }
        }

        return $count;
    }

    /**
     * 站点默认语言包。
     *
     * @return string
     */
    private function defaultLanguagePack()
    {
        $pack = (string) Config::get('site.language.default', 'zh_cn');
        if (!preg_match('/^[a-z_]+$/', $pack)) {
            return 'zh_cn';
        }

        return $pack;
    }

    /**
     * code 在 JSON 原文中的行号（用于错误定位）。
     *
     * @param string $raw
     * @return array
     */
    private function codeLineMap($raw)
    {
        $map = array();
        if (preg_match_all('/"code"\s*:\s*"([A-Za-z0-9_-]+)"/', $raw, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $index => $item) {
                $map[$item[0]] = substr_count(substr($raw, 0, $matches[0][$index][1]), "\n") + 1;
            }
        }

        return $map;
    }

    /**
     * 抛出统一格式的异常。
     *
     * @param string $message
     * @param string $code
     * @param int $line
     * @return void
     * @throws \RuntimeException
     */
    private function fail($message, $code = '', $line = 0)
    {
        $prefix = 'seed.json（' . $this->slug . '）';
        if ($line > 0) {
            $prefix .= '第 ' . $line . ' 行';
        }
        if ($code !== '') {
            $prefix .= ' ' . $code;
        }

        throw new \RuntimeException($prefix . '：' . $message);
    }
}

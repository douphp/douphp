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

namespace Dou\Vendor\Excel;

use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * PHPExcel 适配门面：只负责 xlsx 读写，不承担任何数据库职责。
 *
 * - 导出：export() 接收 head / list 二维结构，直接向浏览器输出 xlsx。
 * - 导入：read() 把上传文件解析成二维数组并做单元格归一化（日期 / 公式 / 富文本），
 *   校验与入库一律由调用方完成，从根源上消除「通用写任意表」的原语。
 */
class Excel
{
    /** read() 结果码：成功 */
    const CODE_SUCCESS = 'success';

    /** read() 结果码：未提交文件 */
    const CODE_NO_FILE = 'no_file';

    /** read() 结果码：上传错误 */
    const CODE_UPLOAD_ERROR = 'upload_error';

    /** read() 结果码：文件超过大小上限 */
    const CODE_TOO_LARGE = 'too_large';

    /** read() 结果码：数据行数超过上限 */
    const CODE_TOO_MANY_ROWS = 'too_many_rows';

    /** read() 结果码：文件无法解析 */
    const CODE_UNREADABLE = 'unreadable';

    /** read() 结果码：文件内没有数据行 */
    const CODE_EMPTY = 'empty';

    /** read() 结果码：某行列数超出预期字段数 */
    const CODE_COLUMN_MISMATCH = 'column_mismatch';

    /**
     * 加载 PHPExcel 依赖。
     *
     * 嵌入 DouPHP 主系统时走 LIBRARY_PATH；作为独立包引用时回退到本包内 src。
     *
     * @return void
     */
    private static function loadPhpExcel()
    {
        if (defined('LIBRARY_PATH')) {
            require_once LIBRARY_PATH . 'excel/src/PHPExcel.php';
        } else {
            require_once dirname(__FILE__) . '/src/PHPExcel.php';
        }
    }

    /**
     * 导出数据为 Excel (.xlsx) 文件并直接输出到浏览器。
     *
     * data 形态：
     *  - head：一维数组，列标题文本。
     *  - list：二维数组，每个 cell 可为标量（字符串/数字），
     *          或图片描述符数组：[
     *              'image'    => 相对 ROOT_PATH 的路径或绝对路径（如 'storage/image/xxx.jpg'），
     *              'width'    => 像素，默认 60，
     *              'height'   => 像素，默认 60；只给一边时按等比缩放，
     *              'fallback' => 路径无效时落到该字符串，默认空串。
     *          ]
     *
     * options：
     *  - max_rows：导出行数上限，默认 20000；传 0 表示不限制。
     *
     * @param string $module      模块标识。
     * @param array  $data       导出数据，包含 head 与 list。
     * @param string $custom_name 自定义导出文件名（不含扩展名）。
     * @param array  $options    可选项。
     * @return void
     * @throws DomainException 导出数据超过行数上限时抛出
     */
    public static function export($module, $data = array(), $custom_name = '', array $options = array())
    {
        $head = (isset($data['head']) && is_array($data['head'])) ? array_values($data['head']) : array();
        $list = (isset($data['list']) && is_array($data['list'])) ? array_values($data['list']) : array();

        $maxRows = array_key_exists('max_rows', $options) ? (int) $options['max_rows'] : 20000;
        if ($maxRows > 0 && count($list) > $maxRows) {
            throw new DomainException(lang('excel_rows_exceeded', '导出数据超过上限，请先缩小筛选范围'));
        }

        set_time_limit(0);
        self::loadPhpExcel();

        // 创建一个处理对象实例
        $objExcel = new \PHPExcel();

        // 创建文件格式写入对象实例（xlsx，原生支持图片）
        $objWriter = \PHPExcel_IOFactory::createWriter($objExcel, 'Excel2007');

        // 设置当前的sheet索引，用于后续的内容操作，一般只有在使用多个sheet的时候才需要显示调用，缺省情况下，PHPExcel会自动创建第一个sheet被设置SheetIndex=0
        $objExcel->setActiveSheetIndex(0);
        $objActSheet = $objExcel->getActiveSheet();

        // STEP1.设置表格标题文字
        $column = empty($head) ? 25 : count($head); // 总列数
        $objActSheet->mergeCells('A1' . ':' . self::number_letter($column) . '1'); // 合并单元格
        if ($custom_name) {
            $objActSheet->setCellValue('A1', $custom_name); // 设置合并后的单元格内容
        } else {
            $objActSheet->setCellValue('A1', Config::get('site.site_name', '') . '-' . lang($module . '_list')); // 设置合并后的单元格内容
        }

        // 预扫描图片列：列号(1-based) => 该列内最大图片宽度(px)，用于后续固定列宽
        $imageColumns = self::collectImageColumns($list);

        // STEP2.设置表格标题栏内容并估算列宽
        // 不再使用 setAutoSize(true)：PHPExcel 会为估算宽度遍历全部单元格，行数多时开销很大。
        // 改为按表头 + 前 50 行内容估算固定宽度，误差可接受而性能稳定。
        $sampleRows = array_slice($list, 0, 50);
        foreach ($head as $key => $value) {
            $columnNumber = $key + 1;
            $columnLetter = self::number_letter($columnNumber);
            $dimension = $objActSheet->getColumnDimension($columnLetter);
            $dimension->setAutoSize(false);
            if (isset($imageColumns[$columnNumber])) {
                // px → Excel 字符宽度的近似换算（Calibri 11pt 下 1 字符 ≈ 7px）
                $dimension->setWidth($imageColumns[$columnNumber] / 7 + 2);
            } else {
                $dimension->setWidth(self::estimateColumnWidth($value, $sampleRows, $key));
            }
            $objActSheet->setCellValue($columnLetter . '2', $value);
        }

        // STEP3.列出表格内容
        foreach ($list as $row_number => $row) {
            foreach ((array)$row as $key => $value) {
                self::applyCellValue($objActSheet, self::number_letter($key + 1), $row_number + 3, $value);
            }
        }

        // 文件名
        if ($custom_name) {
            $outputFileName = $custom_name . '.xlsx';
        } else {
            $outputFileName = strtoupper($module) . '_LIST_' . date('Ymdhi') . '.xlsx';
        }

        // 先落临时文件再回传：
        // PHPExcel 1.8 在 PHP 8 下保存时会闷出零散警告，直接写 php://output
        // 会把警告文本混进 xlsx 导致文件损坏，这里用输出缓冲吞掉这些噪声。
        $tmpFile = self::createTempFile();
        if ($tmpFile !== '') {
            ob_start();
            try {
                $objWriter->save($tmpFile);
            } catch (\Exception $e) {
                ob_end_clean();
                @unlink($tmpFile);
                throw $e;
            }
            ob_end_clean();
            $contentLength = (int) @filesize($tmpFile);
        } else {
            $contentLength = 0;
        }

        // 文件直接输出到浏览器
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: ' . self::buildContentDisposition($outputFileName));
        header('Content-Transfer-Encoding: binary');

        if ($tmpFile !== '') {
            header('Content-Length: ' . $contentLength);
            readfile($tmpFile);
            @unlink($tmpFile);
            return;
        }

        // 临时目录不可写时的兼容分支
        $objWriter->save('php://output');
    }

    /**
     * 创建保存 xlsx 的临时文件。
     *
     * @return string 失败时返回空串（调用方回退到直接写 php://output）。
     */
    private static function createTempFile()
    {
        $dir = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '';
        if ($dir === '' || !is_dir($dir) || !is_writable($dir)) {
            return '';
        }

        $path = @tempnam($dir, 'dou_xlsx_');
        if ($path === false || $path === '') {
            return '';
        }

        return $path;
    }

    /**
     * 构建 Content-Disposition：ASCII 兼容名 + RFC 5987 的 UTF-8 文件名双写。
     *
     * 只写 filename= 时中文名会乱码；未过滤换行/引号时存在响应头注入风险。
     *
     * @param string $filename 原始文件名（含扩展名）。
     * @return string
     */
    private static function buildContentDisposition($filename)
    {
        $filename = str_replace(array("\r", "\n", '"'), '', (string) $filename);
        if ($filename === '') {
            $filename = 'export.xlsx';
        }

        $fallback = preg_replace('/[^\x20-\x7E]/', '_', $filename);
        if ($fallback === '' || $fallback === null) {
            $fallback = 'export.xlsx';
        }

        return 'attachment; filename="' . $fallback . '"; filename*=UTF-8\'\'' . rawurlencode($filename);
    }

    /**
     * 估算列宽：取表头与前 50 行内容的最大显示宽度，钳制在 10~40 字符。
     *
     * @param string $headText 表头文本。
     * @param array  $rows     样本行。
     * @param int    $key      列下标（0-based）。
     * @return float
     */
    private static function estimateColumnWidth($headText, array $rows, $key)
    {
        $width = self::textWidth($headText);
        foreach ($rows as $row) {
            if (!is_array($row) || !array_key_exists($key, $row)) {
                continue;
            }
            if (is_array($row[$key])) {
                continue; // 图片描述符不参与文本宽度估算
            }
            $width = max($width, self::textWidth($row[$key]));
        }

        $width = $width + 2;

        if ($width < 10) {
            return 10;
        }
        if ($width > 40) {
            return 40;
        }

        return $width;
    }

    /**
     * 文本显示宽度：ASCII 记 1，多字节字符记 2。
     *
     * @param mixed $text
     * @return int
     */
    private static function textWidth($text)
    {
        if (!is_scalar($text)) {
            return 0;
        }
        $text = (string) $text;
        if ($text === '') {
            return 0;
        }

        if (function_exists('mb_strlen')) {
            $total = mb_strlen($text, 'UTF-8');
            $ascii = strlen(preg_replace('/[^\x00-\x7F]/', '', $text));

            return $ascii + ($total - $ascii) * 2;
        }

        return strlen($text);
    }

    /**
     * 写入单个单元格：标量走 setCellValue，图片描述符（含 image 键的数组）走 insertImage。
     *
     * @param \PHPExcel_Worksheet $sheet        目标工作表对象。
     * @param string              $columnLetter 列字母，如 'A'、'B'。
     * @param int                 $rowNumber    行号（1-based）。
     * @param mixed               $value        单元格值；标量或图片描述符数组。
     * @return void
     */
    private static function applyCellValue($sheet, $columnLetter, $rowNumber, $value)
    {
        if (is_array($value) && isset($value['image'])) {
            self::insertImage($sheet, $columnLetter, $rowNumber, $value);
            return;
        }
        if (is_bool($value)) {
            $sheet->setCellValue($columnLetter . $rowNumber, $value);
            return;
        }
        $sheet->setCellValue($columnLetter . $rowNumber, self::clampCellText(is_scalar($value) ? (string) $value : ''));
    }

    /**
     * 单元格文本截断到 Excel 上限（32767 字符）。
     *
     * 富文本详情类字段可能远超上限，直写会让 Excel 判定工作簿损坏，
     * 这里按字符截断并补省略号；无 mbstring 时按字节退化为安全截断。
     *
     * @param string $value
     * @return string
     */
    private static function clampCellText($value)
    {
        if (!is_string($value) || strlen($value) <= 32767) {
            return $value;
        }

        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, 32760, 'UTF-8') . '…';
        }

        $value = substr($value, 0, 32760);
        // 去掉截断处的半个 UTF-8 字符，避免产出非法字节序列
        while ($value !== '' && (ord(substr($value, -1)) & 0xC0) === 0x80) {
            $value = substr($value, 0, -1);
        }
        if ($value !== '' && ord(substr($value, -1)) >= 0xC0) {
            $value = substr($value, 0, -1);
        }

        return $value . '…';
    }

    /**
     * 预扫描 list，统计每一列内出现过的最大图片宽度。
     *
     * @param array $list 二维列表数据。
     * @return array 列号(1-based) 到该列最大图片宽度(px) 的映射。
     */
    private static function collectImageColumns(array $list)
    {
        $columns = array();
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $key => $value) {
                if (!is_array($value) || !isset($value['image'])) {
                    continue;
                }
                $columnNumber = (int)$key + 1;
                $width = (isset($value['width']) && (int)$value['width'] > 0) ? (int)$value['width'] : 60;
                if (!isset($columns[$columnNumber]) || $width > $columns[$columnNumber]) {
                    $columns[$columnNumber] = $width;
                }
            }
        }
        return $columns;
    }

    /**
     * 在指定单元格插入图片；路径无效或后缀不在白名单时按 fallback 兜底。
     *
     * @param \PHPExcel_Worksheet $sheet        目标工作表对象。
     * @param string              $columnLetter 列字母。
     * @param int                 $rowNumber    行号（1-based）。
     * @param array               $descriptor   image / width / height / fallback。
     * @return void
     */
    private static function insertImage($sheet, $columnLetter, $rowNumber, array $descriptor)
    {
        $coord = $columnLetter . $rowNumber;
        $fallback = isset($descriptor['fallback']) ? (string)$descriptor['fallback'] : '';
        $rawPath = isset($descriptor['image']) ? (string)$descriptor['image'] : '';

        $abs = self::resolveImagePath($rawPath);
        $allowedExt = array('png', 'jpg', 'jpeg', 'gif');
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        if ($abs === '' || !is_file($abs) || !in_array($ext, $allowedExt, true)) {
            $sheet->setCellValue($coord, $fallback);
            return;
        }

        $hasWidth = isset($descriptor['width']) && (int)$descriptor['width'] > 0;
        $hasHeight = isset($descriptor['height']) && (int)$descriptor['height'] > 0;
        $width = $hasWidth ? (int)$descriptor['width'] : 60;
        $height = $hasHeight ? (int)$descriptor['height'] : 60;
        // 仅给一边时启用等比缩放，避免图片变形
        $proportional = !($hasWidth && $hasHeight);

        $drawing = new \PHPExcel_Worksheet_Drawing();
        $drawing->setPath($abs);
        $drawing->setCoordinates($coord);
        $drawing->setOffsetX(2);
        $drawing->setOffsetY(2);
        $drawing->setResizeProportional($proportional);
        $drawing->setWidthAndHeight($width, $height);
        $drawing->setWorksheet($sheet);

        // 行高自适应：px → pt 近似 0.75（96 DPI 下 1px ≈ 0.75pt）；保留同一行已有更大行高
        $rowDimension = $sheet->getRowDimension($rowNumber);
        $needHeight = $height * 0.75;
        if ($rowDimension->getRowHeight() < $needHeight) {
            $rowDimension->setRowHeight($needHeight);
        }
    }

    /**
     * 解析图片路径：以 / 或 盘符: 开头视为绝对路径，否则拼接 ROOT_PATH。
     *
     * @param string $path 原始路径。
     * @return string 解析后的绝对路径或原值。
     */
    private static function resolveImagePath($path)
    {
        $path = (string)$path;
        if ($path === '') {
            return '';
        }
        $first = $path[0];
        $isAbsolute = ($first === '/' || $first === '\\' || (isset($path[1]) && $path[1] === ':'));
        if ($isAbsolute) {
            return $path;
        }
        if (defined('ROOT_PATH')) {
            return ROOT_PATH . $path;
        }
        return $path;
    }

    /**
     * 把上传的 Excel 文件解析为二维数组，不做任何数据库写入。
     *
     * 返回值：
     *  - ok      bool   是否成功
     *  - code    string 结果码，成功为 CODE_SUCCESS，否则为 CODE_* 之一
     *  - rows    array  二维数组，内层按 Excel 列顺序排列（已做单元格归一化）
     *  - skipped int    跳过的空白行数
     *  - total   int    实际返回的数据行数
     *  - row     int    出错时的行号（仅 column_mismatch 使用，0 表示无）
     *
     * options：
     *  - sheet            读取的工作表索引，默认 0
     *  - header_rows      跳过前 N 行（表头），默认 1
     *  - max_size         文件大小上限（字节），默认 5MB；传 0 不限制
     *  - max_rows         数据行数上限，默认 5000；传 0 不限制
     *  - expected_columns 预期字段数；某行非空单元格超过该值时返回 column_mismatch
     *  - date_format      日期单元格输出格式，默认 'Y-m-d H:i:s'
     *
     * @param string $fileField 上传文件字段名（$_FILES 的键）。
     * @param array  $options   可选项。
     * @return array
     */
    public static function read($fileField, array $options = array())
    {
        $result = array(
            'ok' => false,
            'code' => self::CODE_SUCCESS,
            'rows' => array(),
            'skipped' => 0,
            'total' => 0,
            'row' => 0,
        );

        $fileField = (string) $fileField;
        if ($fileField === '' || !isset($_FILES[$fileField]) || !is_array($_FILES[$fileField])) {
            $result['code'] = self::CODE_NO_FILE;
            return $result;
        }

        $upload = $_FILES[$fileField];
        $error = isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            $result['code'] = self::CODE_UPLOAD_ERROR;
            return $result;
        }

        $tmpFile = isset($upload['tmp_name']) ? (string) $upload['tmp_name'] : '';
        if ($tmpFile === '' || !is_file($tmpFile)) {
            $result['code'] = self::CODE_NO_FILE;
            return $result;
        }

        $maxSize = array_key_exists('max_size', $options) ? (int) $options['max_size'] : 5242880;
        if ($maxSize > 0 && filesize($tmpFile) > $maxSize) {
            $result['code'] = self::CODE_TOO_LARGE;
            return $result;
        }

        $sheetIndex = isset($options['sheet']) ? (int) $options['sheet'] : 0;
        $headerRows = isset($options['header_rows']) ? max(0, (int) $options['header_rows']) : 1;
        $maxRows = array_key_exists('max_rows', $options) ? (int) $options['max_rows'] : 5000;
        $expectedColumns = isset($options['expected_columns']) ? (int) $options['expected_columns'] : 0;
        $dateFormat = isset($options['date_format']) ? (string) $options['date_format'] : 'Y-m-d H:i:s';

        set_time_limit(0);
        self::loadPhpExcel();

        // 按上传文件名判扩展名（tmp_name 通常是无后缀的 phpXXXX.tmp）
        $ext = strtolower(pathinfo(isset($upload['name']) ? (string) $upload['name'] : '', PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = strtolower(pathinfo($tmpFile, PATHINFO_EXTENSION));
        }

        // xlsx 先自检 zip：损坏包会让 PHPExcel 1.8 的 canRead() 在 PHP 8 下抛 ValueError
        // （Error 而非 Exception，cross-version 无法统一捕获），提前拦截更稳。
        if (($ext === 'xlsx' || $ext === 'xlsm' || $ext === 'xltx') && !self::isValidXlsx($tmpFile)) {
            $result['code'] = self::CODE_UNREADABLE;
            return $result;
        }

        $objRead = self::createReaderByExtension($ext);
        if ($objRead === null) {
            $result['code'] = self::CODE_UNREADABLE;
            return $result;
        }

        try {
            $objExcel = $objRead->load($tmpFile);
            $objActSheet = $objExcel->getSheet($sheetIndex);
        } catch (\Exception $e) {
            $result['code'] = self::CODE_UNREADABLE;
            return $result;
        }

        if (!$objActSheet) {
            $result['code'] = self::CODE_EMPTY;
            return $result;
        }

        // getHighestDataRow/Column 忽略「只设过格式但没有内容」的单元格，
        // 避免整列格式导致的虚假大行号。
        $highestRow = (int) $objActSheet->getHighestDataRow();
        $highestColumn = self::number_letter($objActSheet->getHighestDataColumn(), false);
        if ($highestColumn === false) {
            $highestColumn = 0;
        }

        $dataRowCount = $highestRow - $headerRows;
        if ($maxRows > 0 && $dataRowCount > $maxRows) {
            $objExcel->disconnectWorksheets();
            $result['code'] = self::CODE_TOO_MANY_ROWS;
            return $result;
        }

        $rows = array();
        $skipped = 0;
        for ($rowNumber = $headerRows + 1; $rowNumber <= $highestRow; $rowNumber++) {
            $row = array();
            $empty = true;
            $nonEmpty = 0;

            for ($columnNumber = 1; $columnNumber <= $highestColumn; $columnNumber++) {
                $cellId = self::number_letter($columnNumber) . $rowNumber;
                $value = self::readCellValue($objActSheet->getCell($cellId), $dateFormat);
                if ($value !== '' && $value !== null) {
                    $empty = false;
                    $nonEmpty++;
                }
                $row[] = $value;
            }

            if ($empty) {
                $skipped++;
                continue;
            }

            if ($expectedColumns > 0 && $nonEmpty > $expectedColumns) {
                $objExcel->disconnectWorksheets();
                $result['code'] = self::CODE_COLUMN_MISMATCH;
                $result['row'] = $rowNumber;
                return $result;
            }

            $rows[] = $row;
        }

        $objExcel->disconnectWorksheets();

        if (empty($rows)) {
            $result['code'] = self::CODE_EMPTY;
            $result['skipped'] = $skipped;
            return $result;
        }

        $result['ok'] = true;
        $result['rows'] = $rows;
        $result['skipped'] = $skipped;
        $result['total'] = count($rows);

        return $result;
    }

    /**
     * 按扩展名创建 Reader。
     *
     * 不用 IOFactory::createReaderForFile()：它会逐个试 canRead()，
     * 而 canRead() 在 PHP 8 下对损坏 zip 会抛 ValueError（Error），且会把
     * 任意文本误当成 HTML 表格。改成白名单式显式选型。
     *
     * @param string $ext 小写扩展名（不含点）。
     * @return \PHPExcel_Reader_IReader|null 不支持的扩展名返回 null。
     */
    private static function createReaderByExtension($ext)
    {
        $ext = strtolower((string) $ext);
        switch ($ext) {
            case 'xlsx':
            case 'xlsm':
            case 'xltx':
                return new \PHPExcel_Reader_Excel2007();
            case 'xls':
                return new \PHPExcel_Reader_Excel5();
            case 'ods':
                return new \PHPExcel_Reader_OOCalc();
            case 'csv':
                return new \PHPExcel_Reader_CSV();
            case 'slk':
                return new \PHPExcel_Reader_SYLK();
            case 'xml':
                return new \PHPExcel_Reader_Excel2003XML();
            case 'gnumeric':
            case 'gnm':
                return new \PHPExcel_Reader_Gnumeric();
        }

        return null;
    }

    /**
     * 校验 xlsx 是否为完整可读的 zip 包。
     *
     * @param string $path
     * @return bool
     */
    private static function isValidXlsx($path)
    {
        if (!class_exists('ZipArchive')) {
            return true; // 无 zip 扩展时交给 PHPExcel 自行判断
        }

        $zip = new \ZipArchive();
        $opened = @$zip->open($path);
        if ($opened !== true) {
            return false;
        }

        $hasContentTypes = $zip->locateName('[Content_Types].xml') !== false;
        $zip->close();

        return $hasContentTypes;
    }

    /**
     * 单元格取值归一化：公式取计算结果、富文本转字符串、日期转格式串、布尔转 1/0。
     *
     * @param \PHPExcel_Cell $cell       单元格对象。
     * @param string         $dateFormat 日期输出格式。
     * @return string|int|float
     */
    private static function readCellValue($cell, $dateFormat)
    {
        if ($cell === null) {
            return '';
        }

        // 只有公式才走计算引擎，其余直接取值，避免逐格触发重算的开销
        if ($cell->getDataType() === \PHPExcel_Cell_DataType::TYPE_FORMULA) {
            try {
                // 计算引擎在 PHP 8 下对部分边界公式会抛非致命警告，加 @ 避免污染输出
                $value = @$cell->getCalculatedValue();
            } catch (\Exception $e) {
                $value = $cell->getValue();
            }
        } else {
            $value = $cell->getValue();
        }

        if ($value instanceof \PHPExcel_RichText) {
            return $value->__toString();
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }
        if ($value === null) {
            return '';
        }
        if (is_object($value)) {
            return method_exists($value, '__toString') ? (string) $value : '';
        }

        // 日期在 xlsx 中以序列号存储，直接入库会变成 45000 这类数字
        if (is_numeric($value) && \PHPExcel_Shared_Date::isDateTime($cell)) {
            $timestamp = \PHPExcel_Shared_Date::ExcelToPHP((float) $value);
            if ($timestamp > 0) {
                return date($dateFormat, $timestamp);
            }
        }

        return $value;
    }

    /**
     * 在列号与列字母之间进行转换。
     *
     * @param int|string $value    数值列号或字母列标。
     * @param bool       $is_number 是否按数字转字母。
     * @return int|string|false
     */
    public static function number_letter($value, $is_number = true)
    {
        if ($is_number) {
            $num = (int) $value;
            if ($num <= 0) {
                return false;
            }
            $letter = '';
            while ($num > 0) {
                $remainder = ($num - 1) % 26;
                $letter = chr(65 + $remainder) . $letter;
                $num = (int) (($num - $remainder) / 26);
            }
            return $letter;
        }

        $letters = strtoupper((string) $value);
        $len = strlen($letters);
        if ($len === 0) {
            return false;
        }
        $num = 0;
        for ($i = 0; $i < $len; $i++) {
            $ord = ord($letters[$i]);
            if ($ord < 65 || $ord > 90) {
                return false;
            }
            $num = $num * 26 + ($ord - 64);
        }
        return $num;
    }
}

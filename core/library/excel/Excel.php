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

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}
class Excel
{
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
     * 注意：底层 PHPExcel 1.8 对大批量图片导出内存占用偏高，
     * 调用方应自行控制单次导出的图片行数。
     *
     * @param string $module      模块标识。
     * @param array  $data        导出数据，包含 head 与 list。
     * @param string $custom_name 自定义导出文件名（不含扩展名）。
     * @return void
     */
    public static function export($module, $data = array(), $custom_name = '')
    {
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
        $column = $data['head'] ? count($data['head']) : 25; // 总列数
        $objActSheet->mergeCells('A1' . ':' . self::number_letter($column) . '1'); // 合并单元格
        if ($custom_name) {
            $objActSheet->setCellValue('A1', $custom_name); // 设置合并后的单元格内容
        } else {
            $objActSheet->setCellValue('A1', Config::get('site.site_name', '') . '-' . lang($module . '_list')); // 设置合并后的单元格内容
        }

        // 预扫描图片列：列号(1-based) => 该列内最大图片宽度(px)，用于后续固定列宽
        // 否则 head 文本的 setAutoSize(true) 会把图片列压窄到几乎不可见
        $imageColumns = self::collectImageColumns(isset($data['list']) ? (array)$data['list'] : array());

        // STEP2.设置表格标题栏内容
        foreach ((array)$data['head'] as $key => $value) {
            $columnLetter = self::number_letter($key + 1);
            if (isset($imageColumns[$key + 1])) {
                $objActSheet->getColumnDimension($columnLetter)->setAutoSize(false);
                // px → Excel 字符宽度的近似换算（Calibri 11pt 下 1 字符 ≈ 7px）
                $objActSheet->getColumnDimension($columnLetter)->setWidth($imageColumns[$key + 1] / 7 + 2);
            } else {
                $objActSheet->getColumnDimension($columnLetter)->setAutoSize(true);
            }
            $objActSheet->setCellValue($columnLetter . '2', $value);
        }

        // STEP3.列出表格内容
        foreach ((array)$data['list'] as $row_number => $row) {
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

        // 文件直接输出到浏览器
        header('Pragma:public');
        header('Expires:0');
        header('Cache-Control:must-revalidate,post-check=0,pre-check=0');
        header('Content-Type:application/force-download');
        header('Content-Type:application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Type:application/octet-stream');
        header('Content-Type:application/download');
        header('Content-Disposition:attachment;filename=' . $outputFileName);
        header('Content-Transfer-Encoding:binary');
        $objWriter->save('php://output');
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
        $sheet->setCellValue($columnLetter . $rowNumber, is_scalar($value) ? (string) $value : '');
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
     * 从上传的 Excel 文件中读取数据并批量写入数据库。
     *
     * @param string $file            上传文件字段名。
     * @param string $table           目标数据表（不含前缀）。
     * @param string $field_name_list 固定写入的字段名列表。
     * @param array  $data            额外字段名与字段值配置。
     * @param int    $row_start       起始读取行号。
     * @param int    $sheet           读取的工作表索引。
     * @return string
     */
    public static function import($file, $table, $field_name_list, $data = array(), $row_start = 2, $sheet = 0)
    {
        set_time_limit(0);
        self::loadPhpExcel();

        //建立reader对象
        $objRead = new \PHPExcel_Reader_Excel2007();

        // 验证传入的EXCEL文件
        $file_url = $_FILES[$file]["tmp_name"];
        if (empty($file_url) or !file_exists($file_url))  return 'file_not_exists';
        if (!$objRead->canRead($file_url)) {
            $objRead = new \PHPExcel_Reader_Excel5();
            if (!$objRead->canRead($file_url)) {
                return 'no_excel';
            }
        }

        $objExcel = $objRead->load($file_url); // 建立excel对象
        $objActSheet = $objExcel->getSheet($sheet); // 获取指定的sheet表
        $highestColumn = self::number_letter($objActSheet->getHighestColumn(), false); // 取得最大的列号对应的数字
        $highestRow = $objActSheet->getHighestRow(); // 获取总行数

        for ($row_number = $row_start; $row_number <= $highestRow; $row_number++) { // 按行.读取内容
            $field_value_list = '';
            for ($column_number = 1; $column_number <= $highestColumn; $column_number++) { // 按列.读取内容
                $cell_id = self::number_letter($column_number) . $row_number; // 对应数字和字母转换
                $cell_value = $objActSheet->getCell($cell_id)->getValue();
                //$cell_value = $objActSheet->getCell($cell_id)->getCalculatedValue(); // 获取公式计算的值
                if ($cell_value instanceof \PHPExcel_RichText) { // 富文本转换字符串
                    $cell_value = $cell_value->__toString();
                }

                $field_value_list .= "'" . addslashes($cell_value) . "', ";
            }
            $field_value_list = rtrim($field_value_list, ', ');
            $sql = "INSERT INTO " . $GLOBALS['dou']->table_name($table) . " (" . $field_name_list . $data['field_name_list'] . ")" . " VALUES (" . $field_value_list . $data['field_value_list'] . ")";
            if (!$GLOBALS['dou']->query($sql)) {
                echo '导入数据库失败：' . $sql;
                exit;
            }
        }

        return 'success';
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

    /**
     * 将科学计数法字符串还原为普通十进制字符串。
     *
     * @param string $num 待转换的数字字符串。
     * @return string
     */
    public static function decimal_notation($num)
    {
        $parts = explode('E', $num);
        if (count($parts) != 2) {
            return $num;
        }
        $exp = abs(end($parts)) + 3;
        $decimal = number_format($num, $exp, '.', '');
        $decimal = rtrim($decimal, '0');

        return rtrim($decimal, '.');
    }
}

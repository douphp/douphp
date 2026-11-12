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
 * Release Date: 2026-11-11
 */

namespace Dou\Core\Service\Aigc\Document;

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * XLSX 生成器：AI 结构化表格 → PHPExcel 工作簿。
 *
 * 输入结构：{sheets:[{name, headers:[], rows:[[...]]}], summary}；
 * 输出临时 .xlsx 绝对路径，由调用方落盘后删除。
 */
class XlsxBuilder extends BaseService
{
    /**
     * 生成 XLSX。
     *
     * @param array $payload
     * @return array {ok, path, error}
     */
    public function build(array $payload)
    {
        if (!class_exists('PHPExcel')) {
            $file = LIBRARY_PATH . 'excel/src/PHPExcel.php';
            if (!is_file($file)) {
                return array('ok' => false, 'path' => '', 'error' => 'PHPExcel library missing');
            }
            require_once $file;
        }

        $sheets = isset($payload['sheets']) && is_array($payload['sheets']) ? array_values($payload['sheets']) : array();
        if (!$sheets) {
            $sheets = array(array('name' => 'Sheet1', 'headers' => array(), 'rows' => array()));
        }
        $sheets = array_slice($sheets, 0, 5);

        $book = new \PHPExcel();
        $book->getProperties()->setCreator('DouPHP')->setTitle(isset($payload['title']) ? (string) $payload['title'] : 'AI Export');

        $first = true;
        foreach ($sheets as $sheetData) {
            if ($first) {
                $sheet = $book->getActiveSheet();
                $first = false;
            } else {
                $sheet = $book->createSheet();
            }

            $name = isset($sheetData['name']) ? trim((string) $sheetData['name']) : '';
            if ($name !== '') {
                $name = str_replace(array('\\', '/', '?', '*', '[', ']', ':'), '', $name);
                $sheet->setTitle(function_exists('mb_substr') ? mb_substr($name, 0, 31, 'UTF-8') : substr($name, 0, 31));
            }

            $headers = isset($sheetData['headers']) && is_array($sheetData['headers']) ? array_slice($sheetData['headers'], 0, 20) : array();
            $rows = isset($sheetData['rows']) && is_array($sheetData['rows']) ? array_slice($sheetData['rows'], 0, 200) : array();
            $rowNo = 1;

            if ($headers) {
                $colNo = 1;
                foreach ($headers as $header) {
                    $sheet->setCellValueExplicitByColumnAndRow($colNo - 1, $rowNo, $this->safeCell($header), \PHPExcel_Cell_DataType::TYPE_STRING);
                    $colNo++;
                }
                $sheet->getStyle('A1:' . $this->columnLetter(count($headers)) . '1')->getFont()->setBold(true);
                $sheet->getStyle('A1:' . $this->columnLetter(count($headers)) . '1')->getFill()
                    ->setFillType(\PHPExcel_Style_Fill::FILL_SOLID)
                    ->getStartColor()->setARGB('FFEFF3F8');
                $sheet->freezePane('A2');
                $rowNo++;
            }

            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $colNo = 1;
                foreach (array_slice($row, 0, 20) as $value) {
                    $value = $this->safeCell($value);
                    if (is_numeric($value) && strlen((string) $value) < 16) {
                        $sheet->setCellValueExplicitByColumnAndRow($colNo - 1, $rowNo, $value + 0, \PHPExcel_Cell_DataType::TYPE_NUMERIC);
                    } else {
                        $sheet->setCellValueExplicitByColumnAndRow($colNo - 1, $rowNo, $value, \PHPExcel_Cell_DataType::TYPE_STRING);
                    }
                    $colNo++;
                }
                $rowNo++;
            }

            $maxCol = max(1, count($headers));
            for ($i = 0; $i < $maxCol; $i++) {
                $sheet->getColumnDimensionByColumn($i)->setWidth(18);
            }
        }

        $book->setActiveSheetIndex(0);

        $dir = STORAGE_PATH . 'tmp/aigc/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $path = $dir . 'xlsx_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8) . '.xlsx';

        try {
            $writer = \PHPExcel_IOFactory::createWriter($book, 'Excel2007');
            $writer->save($path);
        } catch (\Exception $e) {
            return array('ok' => false, 'path' => '', 'error' => $e->getMessage());
        }

        return array('ok' => is_file($path), 'path' => $path, 'error' => is_file($path) ? '' : 'xlsx write failed');
    }

    /**
     * 单元格值清洗（防公式注入 + 长度截断）。
     *
     * @param mixed $value
     * @return string
     */
    private function safeCell($value)
    {
        if (is_array($value) || is_object($value)) {
            return '';
        }
        $value = (string) $value;
        if (function_exists('mb_substr')) {
            $value = mb_substr($value, 0, 1000, 'UTF-8');
        } else {
            $value = substr($value, 0, 1000);
        }
        if ($value !== '' && strpos('=+-@', $value[0]) !== false) {
            $value = "'" . $value;
        }

        return $value;
    }

    /**
     * 列序号 → Excel 列字母。
     *
     * @param int $number 1-based
     * @return string
     */
    private function columnLetter($number)
    {
        $letters = '';
        $number = max(1, (int) $number);
        while ($number > 0) {
            $mod = ($number - 1) % 26;
            $letters = chr(65 + $mod) . $letters;
            $number = (int) (($number - $mod) / 26);
        }

        return $letters;
    }
}

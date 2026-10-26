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

namespace Dou\Admin\Service\Form;

use Dou\Admin\Model\Form\FormData;
use Dou\Core\Facade\DB;
use Dou\Core\Service\BaseService;
use Dou\Core\Service\Form\FormFieldSchema;
use Dou\Core\Service\Form\FormService as FormCore;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台表单提交数据（route=form/data/...）。
 *
 * 列表的字段列由该表单的字段定义驱动，展示值经 core FormService::formatData() 还原；
 * 附件列展示的是原始文件名（整页一次查询 dou_file）。
 */
class FormDataService extends BaseService
{
    /** @var FormCore */
    private $formCore;

    /**
     * @param FormCore $formCore
     */
    public function __construct(FormCore $formCore)
    {
        $this->formCore = $formCore;
    }

    // =================================================================
    // 列表 / 详情
    // =================================================================

    /**
     * 提交数据列表：字段列由该表单的字段定义驱动，列顺序即字段 sort 顺序。
     *
     * form_id 是必需参数（没有「全部表单」汇总视图）：参数缺失或表单不存在时由
     * 控制器重定向，进到这里的 form_id 恒为已存在的表单。
     *
     * @param int $formId
     * @param string $keyword
     * @param int $page
     * @return array form_data_list, field_list, col_count, pager, keyword, form_id
     */
    public function buildFormDataListPage($formId, $keyword, $page)
    {
        $formId = (int) $formId;
        $query = FormData::order('id DESC');
        $query->where('form_id', $formId);
        if ($keyword !== '') {
            $query->where('data', 'LIKE', '%' . $keyword . '%');
        }

        $pageUrl = route('admin.form.data', array('form_id' => $formId));
        if ($keyword !== '') {
            $pageUrl .= '&keyword=' . rawurlencode($keyword);
        }

        $result = $query->paginate(15, $page, Util::normalizeQueryString($pageUrl));

        $rows = array();
        foreach ($result['list'] as $rowModel) {
            $rows[] = $rowModel->getAttributes();
        }

        // 字段定义与整页附件名都只取一次，再逐行拼单元格
        $fieldList = $this->formCore->getFields($formId);
        $fileNames = $fieldList ? $this->loadDataFileNames($rows, $fieldList) : array();

        $dataList = array();
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $dataList[] = array(
                'id' => $id,
                'cells' => $this->buildDataCells(isset($row['data']) ? $row['data'] : '', $fieldList, $fileNames),
                'ip' => isset($row['ip']) ? (string) $row['ip'] : '',
                'if_read' => isset($row['if_read']) ? (int) $row['if_read'] : 0,
                'status' => isset($row['status']) ? (int) $row['status'] : 0,
                'status_text' => lang('form_data_status_' . (isset($row['status']) ? (int) $row['status'] : 0)),
                'created_at' => !empty($row['created_at']) ? date('Y-m-d H:i', strtotime($row['created_at'])) : '',
                'url' => route('admin.form.data.show', array('id' => $id, 'form_id' => $formId)),
                'delete_url' => route('admin.form.data.destroy', array('id' => $id, 'form_id' => $formId)),
            );
        }

        return array(
            'form_data_list' => $dataList,
            'field_list' => $fieldList,
            // 空态 colspan：id + 字段列 + IP + 已读 + 状态 + 提交时间 + 操作
            'col_count' => count($fieldList) + 6,
            'pager' => $result['pager'],
            'keyword' => $keyword,
            'form_id' => $formId,
        );
    }

    /**
     * 提交详情，并把该条标记为已读。
     *
     * @param int $id
     * @return array|null
     */
    public function getFormDataForView($id)
    {
        $id = (int) $id;
        $row = DB::table('form_data')->where('id', $id)->find();
        if (empty($row)) {
            return null;
        }

        $formId = (int) $row['form_id'];
        $formRow = DB::table('form')->where('id', $formId)->field('id, name, slug')->find();
        $formName = $formRow && isset($formRow['name']) ? language()->langValue($formRow['name'], 'form', $formId, 'name') : '';
        $formSlug = $formRow && isset($formRow['slug']) ? (string) $formRow['slug'] : '';

        $row['id'] = (int) $row['id'];
        $row['form_id'] = $formId;
        $row['user_id'] = isset($row['user_id']) ? (int) $row['user_id'] : 0;
        $row['if_read'] = isset($row['if_read']) ? (int) $row['if_read'] : 0;
        $row['status'] = isset($row['status']) ? (int) $row['status'] : 0;
        $row['status_text'] = lang('form_data_status_' . $row['status']);
        $row['source_url'] = isset($row['source_url']) ? (string) $row['source_url'] : '';
        $row['created_at'] = !empty($row['created_at']) ? date('Y-m-d H:i:s', strtotime($row['created_at'])) : '';
        $row['form'] = array(
            'form_id' => $formId,
            'name' => $formName,
            'slug' => $formSlug,
            'url' => route('form.show', array('slug' => $formSlug)),
            'data_url' => route('admin.form.data', array('form_id' => $formId)),
        );
        $row['items'] = $this->decodeData($formId, isset($row['data']) ? $row['data'] : '');

        if ($row['if_read'] === 0) {
            DB::table('form_data')->where('id', $id)->update(array('if_read' => 1));
            $row['if_read'] = 1;
        }

        return $row;
    }

    /**
     * 提交内容还原为「字段名 + 展示值」列表。
     *
     * @param int $formId
     * @param string $dataJson
     * @return array
     */
    public function decodeData($formId, $dataJson)
    {
        return $this->formCore->formatData((int) $formId, (string) $dataJson);
    }

    // =================================================================
    // 状态 / 删除
    // =================================================================

    /**
     * 「全部已处理」二次确认文案：d% 填充当前表单名。
     *
     * @param int $formId
     * @return string
     */
    public function buildMarkAllConfirm($formId)
    {
        $formId = (int) $formId;
        $row = DB::table('form')->where('id', $formId)->field('id, name')->find();
        $name = !empty($row) ? language()->langValue(isset($row['name']) ? $row['name'] : '', 'form', $formId, 'name') : '';

        return preg_replace('/d%/Ums', $name, lang('form_data_mark_all_confirm'));
    }

    /**
     * @param int $id
     * @return int 新状态 0/1
     */
    public function toggleFormDataStatus($id)
    {
        $id = (int) $id;
        $status = DB::table('form_data')->where('id', $id)->value('status');
        $status = $status ? 0 : 1;
        DB::table('form_data')->where('id', $id)->update(array('status' => $status));

        return $status;
    }

    /**
     * 全部标记为已读且已处理。
     *
     * 作用范围恒为单个表单：form_id 缺省（≤ 0）时一行也不更新，避免越权全表刷写。
     *
     * @param int $formId
     * @return int 受影响行数
     */
    public function markAllFormDataProcessed($formId)
    {
        $formId = (int) $formId;
        if ($formId <= 0) {
            return 0;
        }

        return (int) DB::table('form_data')
            ->where('form_id', $formId)
            ->update(array('if_read' => 1, 'status' => 1));
    }

    /**
     * @param int $id
     * @return void
     */
    public function deleteData($id)
    {
        $id = (int) $id;
        $numbers = $this->attachmentNumbers(array(array('id' => $id)));

        DB::transaction(function () use ($id) {
            FormData::destroy($id);
        });

        $this->purgeAttachmentFiles($numbers);
    }

    // =================================================================
    // 附件（FormService 删除整张表单时复用）
    // =================================================================

    /**
     * 提交记录的附件编号列表：按 module=form + item_id=form_data.id 反查，
     * 比解析 data JSON 更可靠（字段可能已被删除）。
     *
     * @param array $rows form_data 的 id 行集合
     * @return array number 列表
     */
    public function attachmentNumbers($rows)
    {
        $ids = array();
        foreach ((array) $rows as $row) {
            $id = (int) (is_array($row) ? (isset($row['id']) ? $row['id'] : 0) : $row);
            if ($id > 0) {
                $ids[] = (string) $id;
            }
        }
        if (empty($ids)) {
            return array();
        }

        $result = array();
        $numbers = DB::table('file')
            ->field('number')
            ->where('module', 'form')
            ->whereIn('item_id', $ids)
            ->select();
        foreach ((array) $numbers as $row) {
            if (!empty($row['number'])) {
                $result[] = (string) $row['number'];
            }
        }

        return $result;
    }

    /**
     * 删除附件行与物理文件（幂等；在事务提交后调用）。
     *
     * @param array $numbers
     * @return void
     */
    public function purgeAttachmentFiles(array $numbers)
    {
        foreach ($numbers as $number) {
            attachment()->delete($number);
        }
    }

    // =================================================================
    // 内部
    // =================================================================

    /**
     * 列表内所有附件编号 → 文件名（整页一次查询）。
     *
     * @param array $rows form_data 原始行
     * @param array $fieldList 该表单字段定义
     * @return array number => 文件名
     */
    private function loadDataFileNames(array $rows, array $fieldList)
    {
        $numbers = array();
        foreach ($rows as $row) {
            if (!isset($row['data'])) {
                continue;
            }
            $data = json_decode((string) $row['data'], true);
            if (!is_array($data)) {
                continue;
            }
            foreach ($fieldList as $field) {
                if (empty($field['is_upload']) || empty($data[$field['slug']])) {
                    continue;
                }
                foreach ((array) $data[$field['slug']] as $number) {
                    if (is_scalar($number) && (string) $number !== '') {
                        $numbers[] = (string) $number;
                    }
                }
            }
        }

        $numbers = array_values(array_unique($numbers));
        if (empty($numbers)) {
            return array();
        }

        $rows = DB::table('file')->field('number, file, original_name')->whereIn('number', $numbers)->select();
        $map = array();
        foreach ((array) $rows as $row) {
            $number = (string) $row['number'];
            $map[$number] = isset($row['original_name']) && $row['original_name'] !== ''
                ? (string) $row['original_name']
                : basename(isset($row['file']) ? (string) $row['file'] : '');
        }

        return $map;
    }

    /**
     * 单行按字段定义展开为单元格值，顺序与 $fieldList 一致。
     *
     * @param string $dataJson
     * @param array $fieldList
     * @param array $fileNames number => 文件名
     * @return array array(array('display' => string), ...)
     */
    private function buildDataCells($dataJson, array $fieldList, array $fileNames)
    {
        $data = json_decode((string) $dataJson, true);
        if (!is_array($data)) {
            $data = array();
        }

        $cells = array();
        foreach ($fieldList as $field) {
            $value = isset($data[$field['slug']]) ? $data[$field['slug']] : '';
            if (!empty($field['is_upload'])) {
                $names = array();
                foreach ((array) $value as $number) {
                    $number = (string) $number;
                    if ($number !== '' && isset($fileNames[$number])) {
                        $names[] = $fileNames[$number];
                    }
                }
                $display = implode('、', $names);
            } else {
                $display = FormFieldSchema::formatValue($field, $value);
            }
            $cells[] = array('display' => trim($display));
        }

        return $cells;
    }
}

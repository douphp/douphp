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

namespace Dou\Admin\Service\Ai\Import;

use Dou\Core\Infra\Log\Log;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 批量导入总线：按模块解析 Importer，逐条过滤白名单字段后走业务 insert 链路。
 *
 * 逐条独立提交（不包整批事务）：单条失败只记入 errors，不回滚已成功条目，
 * 与「AI 生成内容人工可逐条删除」的运营模式匹配。
 */
class BatchImportManager extends BaseService
{
    /**
     * 模块 → Importer 类映射。
     *
     * @var array
     */
    private static $importerMap = array(
        'product' => 'Dou\\Admin\\Service\\Ai\\Import\\ProductImporter',
        'product_category' => 'Dou\\Admin\\Service\\Ai\\Import\\ProductCategoryImporter',
    );

    /**
     * 模块是否已接入批量导入。
     *
     * @param string $module
     * @return bool
     */
    public function supports($module)
    {
        return isset(self::$importerMap[trim((string) $module)]);
    }

    /**
     * 批量入库。
     *
     * @param string $module 模块逻辑名
     * @param array $items AI 生成的条目数组
     * @param array $context 页面上下文（category_id / parent_id 等）
     * @param int $adminId 操作管理员
     * @return array {imported: int, failed: int, ids: array, errors: array}
     */
    public function import($module, array $items, array $context, $adminId)
    {
        $importer = $this->importerFor($module);

        $imported = 0;
        $failed = 0;
        $ids = array();
        $errors = array();

        foreach (array_values($items) as $index => $item) {
            if (!is_array($item)) {
                $failed++;
                $errors[] = '#' . ($index + 1) . ': ' . lang('ai_import_item_invalid');
                continue;
            }

            $missing = $this->missingFields($importer, $item);
            if ($missing) {
                $failed++;
                $errors[] = '#' . ($index + 1) . ': ' . lang('ai_import_missing_field') . ' ' . implode(', ', $missing);
                continue;
            }

            $filtered = array_intersect_key($item, array_flip($importer->allowedFields()));

            try {
                $newId = $importer->import($filtered, $context, (int) $adminId);
                $imported++;
                $ids[] = (int) $newId;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = '#' . ($index + 1) . ': ' . $e->getMessage();
                Log::error('AI batch import failed', array(
                    'channel' => 'ai',
                    'module' => $module,
                    'error' => $e->getMessage(),
                ));
            }
        }

        return array(
            'imported' => $imported,
            'failed' => $failed,
            'ids' => $ids,
            'errors' => $errors,
        );
    }

    /**
     * @param string $module
     * @return ModuleImporter
     */
    private function importerFor($module)
    {
        $module = trim((string) $module);
        if (!isset(self::$importerMap[$module])) {
            throw new \InvalidArgumentException('No importer for module: ' . $module);
        }

        return app(self::$importerMap[$module]);
    }

    /**
     * @param ModuleImporter $importer
     * @param array $item
     * @return array 缺失的必填字段名
     */
    private function missingFields(ModuleImporter $importer, array $item)
    {
        $missing = array();
        foreach ($importer->requiredFields() as $field) {
            if (!isset($item[$field]) || trim((string) $item[$field]) === '') {
                $missing[] = $field;
            }
        }

        return $missing;
    }
}

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
 * Release Date: 2026-09-09
 */

namespace Dou\Admin\Service\Ai;

use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 应用运行策略。
 */
class ApplicationPolicy extends BaseService
{
    /**
     * @param array $app
     * @param int $requested
     * @return int
     */
    public function batchCount(array $app, $requested)
    {
        $config = !empty($app['config']) ? json_decode((string) $app['config'], true) : array();
        if (!is_array($config)) {
            $config = array();
        }
        $min = isset($config['batch_min']) ? max(1, (int) $config['batch_min']) : 1;
        $max = isset($config['batch_max']) ? max($min, (int) $config['batch_max']) : 20;
        $max = min(50, $max);

        return max($min, min($max, (int) $requested));
    }

    /**
     * @param string $placement
     * @param string $taskType
     * @return bool
     */
    public function taskMatchesPlacement($placement, $taskType)
    {
        $map = array(
            'assist' => array('assist', 'polish', 'rewrite', 'image', 'banner'),
            'fill' => array('fill'),
            'batch' => array('batch'),
            'translate' => array('translate'),
        );

        return isset($map[$placement]) && in_array($taskType, $map[$placement], true);
    }

    /**
     * 根据内置模型代码约定区分文本与生成媒体模型。
     *
     * @param string $taskType
     * @param string $modelCode
     * @param string $providerCode
     * @return bool
     */
    public function modelSupportsTask($taskType, $modelCode, $providerCode)
    {
        $taskType = trim((string) $taskType);
        $modelCode = strtolower(trim((string) $modelCode));
        $mediaTask = $taskType === 'image' || $taskType === 'banner';
        $mediaModel = preg_match('/(?:image|dall-e|cogview|seedream|wanx?|video)/', $modelCode);

        return $mediaTask ? (bool) $mediaModel : !$mediaModel;
    }
}

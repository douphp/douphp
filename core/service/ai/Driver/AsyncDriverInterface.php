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

namespace Dou\Core\Service\Ai\Driver;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 异步任务驱动扩展契约：提交任务 + 轮询状态。
 *
 * 类 C 异步供应商（阿里百炼图像/视频、火山方舟视频）的驱动实现本接口；
 * 同步供应商（OpenAi/Qwen 文本）不实现，AsyncTaskPoller 据此拒绝异步提交。
 */
interface AsyncDriverInterface
{
    /**
     * 提交任务给供应商。
     *
     * @param array $config resolveConfig 产物
     * @param array $params 提交参数（prompt / 尺寸 / 时长等，驱动自定字段）
     * @return array {provider_task_id: string, status: string, raw: array}
     */
    public function submitTask(array $config, array $params);

    /**
     * 查询供应商任务状态。
     *
     * @param array $config resolveConfig 产物
     * @param string $providerTaskId 供应商任务 ID
     * @return array {
     *               status: pending|running|succeeded|failed,
     *               result: array|null 成功时的结果（urls 等），
     *               expires_at: string|null 结果过期时间（Y-m-d H:i:s，可选）,
     *               error: string 失败原因
     *               }
     */
    public function pollTask(array $config, $providerTaskId);
}

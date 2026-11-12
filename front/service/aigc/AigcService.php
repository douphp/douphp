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

namespace Dou\Front\Service\Aigc;

use Dou\Core\Domain\Order\OrderStatus;
use Dou\Core\Facade\DB;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\Aigc\FieldDefinition;
use Dou\Core\Service\Aigc\MediaStore;
use Dou\Core\Service\Aigc\QuotaService;
use Dou\Core\Service\BaseService;
use Dou\Core\Support\Util;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * aigc 前台数据层：应用中心 / 应用页 / 作品 / 套餐 / 会话历史。
 */
class AigcService extends BaseService
{
    /** @var AiGateway */
    private $gateway;

    /** @var QuotaService */
    private $quota;

    public function __construct(AiGateway $gateway, QuotaService $quota)
    {
        $this->gateway = $gateway;
        $this->quota = $quota;
    }

    /**
     * 应用中心列表（按类型分组）。
     *
     * @return array type => list
     */
    public function groupApps()
    {
        $rows = DB::table('aigc')
            ->where('status', 1)
            ->where('is_public', 1)
            ->order('sort ASC, id ASC')
            ->select();

        $groups = array();
        foreach ((array) $rows as $row) {
            $type = isset($row['type']) ? (string) $row['type'] : 'chat';
            if (!isset($groups[$type])) {
                $groups[$type] = array();
            }
            $groups[$type][] = $this->presentApp($row);
        }

        return $groups;
    }

    /**
     * 应用列表扁平输出。
     *
     * @return array
     */
    public function listApps()
    {
        $list = array();
        foreach ($this->groupApps() as $items) {
            foreach ($items as $item) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * 按 slug 取应用。
     *
     * @param string $slug
     * @return array|null
     */
    public function findBySlug($slug)
    {
        $slug = trim((string) $slug);
        if ($slug === '') {
            return null;
        }
        $row = DB::table('aigc')->where('slug', $slug)->where('status', 1)->find();

        return $row ? $this->presentApp($row) : null;
    }

    /**
     * 应用可用模型（id => name）。
     *
     * @param array $app
     * @return array
     */
    public function availableModels(array $app)
    {
        return $this->gateway->getAvailableModels(isset($app['model_ids']) ? (string) $app['model_ids'] : '');
    }

    /**
     * 应用表单字段（会员端渲染）。
     *
     * @param int $appId
     * @return array
     */
    public function fields($appId)
    {
        $rows = DB::table('aigc_field')
            ->where('app_id', (int) $appId)
            ->where('status', 1)
            ->order('sort ASC, id ASC')
            ->select();

        $fields = array();
        foreach ((array) $rows as $row) {
            $options = FieldDefinition::parseOptions(isset($row['option']) ? $row['option'] : '');
            $default = isset($row['default_value']) ? (string) $row['default_value'] : '';
            foreach ($options as $index => $option) {
                $options[$index]['selected'] = $option['value'] === $default || ($default === '' && $index === 0);
            }
            $row['options'] = $options;
            $row['is_multiple'] = FieldDefinition::isMultiple(isset($row['type']) ? $row['type'] : '');
            $fields[] = $row;
        }

        return $fields;
    }

    /**
     * 表单字段 → 生成参数（按字段定义校验与归一）。
     *
     * Web 表单与小程序 API 共用，避免两端各维护一份映射规则。
     *
     * @param array $app
     * @param array $post
     * @return array {ok, params, error}
     */
    public function buildGenerationParams(array $app, array $post)
    {
        $fields = $this->fields((int) $app['id']);
        $input = isset($post['fields']) && is_array($post['fields']) ? $post['fields'] : array();
        $known = array('prompt', 'text', 'size', 'n', 'duration', 'resolution', 'voice', 'speed', 'slides', 'sheets', 'style', 'format');
        $params = array();

        // 未配置字段：沿用旧版直传 prompt/text 等参数
        if (!$fields) {
            foreach ($known as $key) {
                if (isset($post[$key]) && !is_array($post[$key])) {
                    $params[$key] = trim((string) $post[$key]);
                }
            }

            return array('ok' => true, 'params' => $params, 'error' => '');
        }

        $extra = array();
        foreach ($fields as $field) {
            $slug = isset($field['slug']) ? (string) $field['slug'] : '';
            if ($slug === '') {
                continue;
            }
            if (array_key_exists($slug, $input)) {
                $raw = $input[$slug];
            } elseif (isset($post[$slug])) {
                $raw = $post[$slug];
            } else {
                $raw = isset($field['default_value']) ? $field['default_value'] : '';
            }

            $normalized = FieldDefinition::normalizeValue($field, $raw);
            if (empty($normalized['ok'])) {
                $reason = $normalized['error'] === 'numeric'
                    ? lang('aigc_field_numeric', '必须是数字')
                    : lang('aigc_field_required', '必填');
                $error = (isset($field['name']) ? $field['name'] : $slug) . '：' . $reason;

                return array('ok' => false, 'params' => array(), 'error' => $error);
            }

            $params[$slug] = $normalized['value'];
            if (!in_array($slug, $known, true) && $normalized['value'] !== '') {
                $extra[] = (isset($field['name']) ? $field['name'] : $slug) . '：' . $normalized['value'];
            }
        }

        if ($extra) {
            if (!empty($params['prompt'])) {
                $params['prompt'] = trim((string) $params['prompt']) . '；' . implode('；', $extra);
            } elseif (isset($params['text']) && $params['text'] !== '') {
                // 音频等以 text 为主参数的应用不把自定义字段拼进朗读文本
            } else {
                $params['prompt'] = implode('；', $extra);
            }
        }

        // 图片/视频驱动的 style 不是独立参数，拼进提示词以影响生成结果
        if (in_array(isset($app['type']) ? $app['type'] : '', array('image', 'video'), true)
            && !empty($params['style']) && !empty($params['prompt'])) {
            $params['prompt'] = trim((string) $params['prompt']) . '；风格：' . $params['style'];
        }

        return array('ok' => true, 'params' => $params, 'error' => '');
    }

    /**
     * 解析作品文件（归属校验 + 私有磁盘定位）。
     *
     * @param int $taskId
     * @param int $userId
     * @param int $index
     * @return array|null {path, mime, name}
     */
    public function resolveWorkFile($taskId, $userId, $index)
    {
        $task = DB::table('aigc_task')->where('id', (int) $taskId)->find();
        if (!$task || (int) $task['user_id'] !== (int) $userId) {
            return null;
        }

        $result = !empty($task['result']) ? json_decode((string) $task['result'], true) : array();
        $files = is_array($result) && isset($result['files']) && is_array($result['files']) ? $result['files'] : array();
        $index = (int) $index;
        if (!isset($files[$index]) || empty($files[$index]['path'])) {
            return null;
        }

        $media = new MediaStore();
        $path = $media->absolutePath((string) $files[$index]['path']);
        if (!$path || !is_file($path)) {
            return null;
        }

        $ext = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $mimeMap = array(
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
            'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pdf' => 'application/pdf',
        );
        $name = isset($files[$index]['name']) && $files[$index]['name'] !== ''
            ? (string) $files[$index]['name']
            : ('aigc_' . (int) $taskId . '.' . $ext);

        return array(
            'path' => $path,
            'mime' => isset($mimeMap[$ext]) ? $mimeMap[$ext] : 'application/octet-stream',
            'name' => $name,
        );
    }

    /**
     * 配额状态（格式化）。
     *
     * @param int $userId
     * @return array
     */
    public function quotaStatus($userId)
    {
        $status = $this->quota->status((int) $userId);
        $status['tokens_limit_format'] = $status['tokens_limit'] > 0 ? number_format($status['tokens_limit']) : lang('aigc_quota_unlimited', '不限');
        $status['requests_limit_format'] = $status['requests_limit'] > 0 ? number_format($status['requests_limit']) : lang('aigc_quota_unlimited', '不限');
        $status['tokens_remaining_format'] = $status['tokens_limit'] > 0 ? number_format(max(0, $status['tokens_remaining'])) : lang('aigc_quota_unlimited', '不限');
        $status['requests_remaining_format'] = $status['requests_limit'] > 0 ? number_format(max(0, $status['requests_remaining'])) : lang('aigc_quota_unlimited', '不限');

        return $status;
    }

    /**
     * 我的作品。
     *
     * @param int $userId
     * @param string $type
     * @param int $page
     * @param string $pageUrl
     * @return array {list, pager}
     */
    public function works($userId, $type, $page, $pageUrl)
    {
        $query = DB::table('aigc_task')->where('user_id', (int) $userId);
        if ($type !== '') {
            $query->where('task_type', (string) $type);
        }
        $query->order('id DESC');
        $result = $query->paginate(15, (int) $page, (string) $pageUrl);

        $list = array();
        foreach ((array) $result['list'] as $row) {
            $resultData = !empty($row['result']) ? json_decode((string) $row['result'], true) : array();
            $files = is_array($resultData) && isset($resultData['files']) && is_array($resultData['files']) ? $resultData['files'] : array();
            $list[] = array(
                'id' => (int) $row['id'],
                'type' => (string) $row['task_type'],
                'type_name' => lang('aigc_type_' . $row['task_type'], $row['task_type']),
                'status' => (string) $row['status'],
                'status_name' => lang('aigc_task_status_' . $row['status'], $row['status']),
                'error' => (string) $row['error'],
                'prompt' => $this->promptOf($row['request_payload']),
                'files' => $files,
                'file_count' => count($files),
                'created_at' => date('Y-m-d H:i', strtotime((string) $row['created_at'])),
            );
        }

        return array('list' => $list, 'pager' => $result['pager']);
    }

    /**
     * 套餐列表。
     *
     * @return array
     */
    public function packages()
    {
        $rows = DB::table('aigc_package')
            ->where('status', 1)
            ->order('sort ASC, id ASC')
            ->select();

        $list = array();
        foreach ((array) $rows as $row) {
            $list[] = array(
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'day' => (int) $row['day'],
                'tokens_limit' => (int) $row['tokens_limit'],
                'tokens_limit_format' => $row['tokens_limit'] > 0 ? number_format($row['tokens_limit']) : lang('aigc_quota_unlimited', '不限'),
                'requests_limit' => (int) $row['requests_limit'],
                'requests_limit_format' => $row['requests_limit'] > 0 ? number_format($row['requests_limit']) : lang('aigc_quota_unlimited', '不限'),
                'price' => $row['price'] > 0 ? Util::formatPrice($row['price']) : lang('aigc_package_free', '免费'),
                'content' => array_values(array_filter(preg_split('/\r\n|\r|\n/', (string) $row['content']))),
                'image' => !empty($row['image']) ? attachment()->url($row['image']) : '',
            );
        }

        return $list;
    }

    /**
     * 我的订阅列表。
     *
     * @param int $userId
     * @return array
     */
    public function subscriptions($userId)
    {
        $rows = DB::table('aigc_subscription')
            ->where('user_id', (int) $userId)
            ->order('id DESC')
            ->limit(20)
            ->select();

        $list = array();
        foreach ((array) $rows as $row) {
            $endTs = !empty($row['end_at']) ? strtotime((string) $row['end_at']) : null;
            $list[] = array(
                'id' => (int) $row['id'],
                'package_name' => (string) DB::table('aigc_package')->where('id', (int) $row['package_id'])->value('name'),
                'price' => Util::formatPrice($row['price']),
                'tokens_limit' => (int) $row['tokens_limit'],
                'tokens_used' => (int) $row['tokens_used'],
                'requests_limit' => (int) $row['requests_limit'],
                'requests_used' => (int) $row['requests_used'],
                'start_at' => date('Y-m-d', strtotime((string) $row['start_at'])),
                'end_at' => date('Y-m-d', strtotime((string) $row['end_at'])),
                'is_active' => $endTs !== null && $endTs >= time() && $row['order_status'] === OrderStatus::COMPLETED,
                'order_sn' => (string) $row['order_sn'],
            );
        }

        return $list;
    }

    /**
     * 会话列表。
     *
     * @param int $appId
     * @param int $userId
     * @return array
     */
    public function sessions($appId, $userId)
    {
        $rows = DB::table('aigc_session')
            ->where('app_id', (int) $appId)
            ->where('user_id', (int) $userId)
            ->order('updated_at DESC')
            ->limit(50)
            ->select();

        $list = array();
        foreach ((array) $rows as $row) {
            $list[] = array(
                'session_sn' => (string) $row['session_sn'],
                'title' => (string) $row['title'],
                'message_count' => (int) $row['message_count'],
                'updated_at' => date('Y-m-d H:i', strtotime((string) $row['updated_at'])),
            );
        }

        return $list;
    }

    /**
     * 应用行 → 展示结构。
     *
     * @param array $row
     * @return array
     */
    private function presentApp(array $row)
    {
        $config = !empty($row['config']) ? json_decode((string) $row['config'], true) : array();

        return array(
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'type' => (string) $row['type'],
            'type_name' => lang('aigc_type_' . $row['type'], $row['type']),
            'description' => (string) $row['description'],
            'icon' => !empty($row['icon']) ? attachment()->url($row['icon']) : '',
            'cover_image' => !empty($row['cover_image']) ? attachment()->url($row['cover_image']) : '',
            'model_ids' => (string) $row['model_ids'],
            'default_prompt' => (string) $row['default_prompt'],
            'config' => is_array($config) ? $config : array(),
            'is_free' => (int) $row['is_free'],
            'free_limit_type' => (string) $row['free_limit_type'],
            'free_limit_value' => (int) $row['free_limit_value'],
            'url' => route('aigc.app', array(), array('query' => array('slug' => $row['slug']))),
        );
    }

    /**
     * 任务 payload 中的 prompt。
     *
     * @param string $payload
     * @return string
     */
    private function promptOf($payload)
    {
        $data = json_decode((string) $payload, true);
        if (!is_array($data)) {
            return '';
        }
        if (isset($data['prompt'])) {
            return (string) $data['prompt'];
        }
        if (isset($data['text'])) {
            return (string) $data['text'];
        }

        return '';
    }
}

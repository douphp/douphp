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

namespace Dou\Admin\Service\Ai;

use Dou\Admin\Model\Ai\AiApplication;
use Dou\Admin\Service\Ai\Import\BatchImportManager;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\Ai\ImageInputSupport;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 创作应用的页面注入器。
 *
 * - fill / batch：按挂载模块注入 page_sub_actions（仅 link_ai）。
 * - assist / translate：全局应用，不按模块过滤；表单页凡有多语言槽即可使用。
 */
class AiToolbarBuilder extends BaseService
{
    /** @var BatchImportManager */
    private $importManager;

    /** @var AiGateway */
    private $gateway;

    /** @var array placement => 应用行列表 */
    private $globalCache = array();

    /** @var array model_id => bool 模型可解析出密钥配置缓存（0 = 默认模型；与运行时判定一致） */
    private static $configCache = array();

    /** @var array model_id => bool 图片输入能力缓存（0 = 默认模型；按 model_code 判定） */
    private static $imageInputCache = array();

    /**
     * @param BatchImportManager $importManager
     * @param AiGateway $gateway
     */
    public function __construct(BatchImportManager $importManager, AiGateway $gateway)
    {
        $this->importManager = $importManager;
        $this->gateway = $gateway;
    }

    /**
     * 模块是否属于 AI 创作可挂载面（link_ai 及其 _category 变体）。
     *
     * 仅用于 fill / batch 工具条；assist / translate 不走此过滤。
     *
     * @param string $module 模块逻辑名
     * @return bool
     */
    public function mountable($module)
    {
        $module = trim((string) $module);
        if ($module === '') {
            return false;
        }

        $base = substr($module, -9) === '_category' ? substr($module, 0, -9) : $module;

        return in_array($base, (array) Config::get('module.link_ai'), true);
    }

    /**
     * 是否存在启用中的全局 assist / translate 应用。
     *
     * @return bool
     */
    public function hasGlobalFormApps()
    {
        return (bool) $this->globalApps('assist') || (bool) $this->globalApps('translate');
    }

    /**
     * 列表页 page_actions：模块下启用的 batch 应用按钮。
     *
     * @param string $module 模块逻辑名（含 _category 变体）
     * @return array page_actions 条目数组
     */
    public function listActions($module)
    {
        if (!$this->importManager->supports($module)) {
            return array();
        }

        $actions = array();
        foreach ($this->mountedApps($module, 'batch') as $app) {
            $actions[] = $this->buttonFor($app);
        }

        return $actions;
    }

    /**
     * 表单页 page_actions：模块下启用的 fill 应用按钮。
     *
     * @param string $module 模块逻辑名
     * @return array page_actions 条目数组
     */
    public function formActions($module)
    {
        $actions = array();
        foreach ($this->mountedApps($module, 'fill') as $app) {
            $actions[] = $this->buttonFor($app);
        }

        return $actions;
    }

    /**
     * ai.assist.js 的页面配置 JSON。
     *
     * 列表：有 batch 按钮才输出。
     * 表单：有 fill 或全局 assist/translate 即输出；banner 面板另需存在 banner 任务的图像应用。
     *
     * @param string $module 模块逻辑名
     * @param string $context list|form
     * @param bool $banner 是否幻灯（banner）面板：图像应用只挂 task_type=banner
     * @return string JSON 字符串或空串
     */
    public function pageConfig($module, $context, $banner = false)
    {
        $module = trim((string) $module);

        if ($context === 'list') {
            $hasButtons = $this->importManager->supports($module) && (bool) $this->mountedApps($module, 'batch');
        } else {
            $hasButtons = ($module !== '' && (bool) $this->mountedApps($module, 'fill'))
                || $this->hasGlobalFormApps()
                || ($banner && (bool) $this->globalAssistApps('image', true));
        }
        if (!$hasButtons) {
            return '';
        }

        $payload = array(
            'module' => $module,
            'context' => $context,
        );

        if ($context === 'form') {
            // assist 按 type 拆分（文本 / 图像）：字段旁各一颗统一触发钮，
            // 弹窗页脚按应用名并列提交钮（分组维度是按钮位置，不是任务类型）；
            // 图像应用再按任务形态分流：banner 面板只挂 banner 任务，普通面板不挂 banner 任务
            $payload['assist'] = $this->appsPayload($this->globalAssistApps('text', $banner));
            $payload['assist_image'] = $this->appsPayload($this->globalAssistApps('image', $banner));
            $payload['translate'] = $this->appsPayload($this->globalApps('translate'));
            $payload['banner'] = $this->bannerPageConfig();
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * banner 弹窗素材上限与风格键，供 ai.assist.js 使用。
     *
     * @return array
     */
    private function bannerPageConfig()
    {
        $max = (int) Config::get('ai.banner.material_max', 4);
        $bytes = (int) Config::get('ai.banner.upload_max_bytes', 5242880);
        $styles = Config::get('ai.prompts.banner_styles', array());
        $keys = array();
        if (is_array($styles)) {
            foreach ($styles as $key => $phrase) {
                $key = trim((string) $key);
                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return array(
            'material_max' => $max > 0 ? $max : 4,
            'upload_max_bytes' => $bytes > 0 ? $bytes : 5242880,
            'styles' => $keys,
        );
    }

    /**
     * 全局 assist 应用按任务形态分流；图像应用（task_type=image/banner）再按任务形态细分。
     *
     * @param string $kind text=文本应用（排除图像）/ image=图像应用
     * @param bool $banner banner 面板（幻灯）：只挂 task_type=banner 的图像应用；普通面板反之
     * @return array
     */
    private function globalAssistApps($kind, $banner = false)
    {
        $list = array();
        foreach ($this->globalApps('assist') as $app) {
            $taskType = isset($app['task_type']) ? trim((string) $app['task_type']) : '';
            $isImage = $taskType === 'image' || $taskType === 'banner';
            if ($kind === 'image') {
                if (!$isImage) {
                    continue;
                }
                if ($banner ? $taskType !== 'banner' : $taskType === 'banner') {
                    continue;
                }
            } elseif ($isImage) {
                continue;
            }
            $list[] = $app;
        }

        return $list;
    }

    /**
     * @param array $apps
     * @return array
     */
    private function appsPayload(array $apps)
    {
        $list = array();
        foreach ($apps as $app) {
            $config = isset($app['config']) && $app['config'] ? json_decode((string) $app['config'], true) : array();
            if (!is_array($config)) {
                $config = array();
            }

            $list[] = array(
                'id' => (int) $app['id'],
                'name' => isset($app['name']) ? (string) $app['name'] : '',
                // banner 等应用的默认画布尺寸（WxH），ai.assist.js 据此预填弹窗宽高并切换对齐按钮
                'canvas_size' => isset($config['canvas_size']) ? (string) $config['canvas_size'] : '',
                // 标题文字水平对齐默认值 left/center/right（弹窗构图与布局可改选，缺省左对齐）
                'content_align' => isset($config['content_align']) ? (string) $config['content_align'] : 'left',
                // 模型是否支持图片输入（图生图）：弹窗据此决定是否显示「图片素材」栏
                'image_input' => $this->modelSupportsImageInput(isset($app['model_id']) ? (int) $app['model_id'] : 0),
            );
        }

        return $list;
    }

    /**
     * 应用绑定模型（0 = 系统默认模型）是否支持图片输入。
     *
     * @param int $modelId
     * @return bool 模型不存在时 false
     */
    private function modelSupportsImageInput($modelId)
    {
        $key = (int) $modelId;
        if (!isset(self::$imageInputCache[$key])) {
            $model = DB::table('ai_model');
            $model = $key > 0
                ? $model->where('id', $key)
                : $model->order('id ASC');
            $row = $model->field('model_code')->find();
            $code = $row && isset($row['model_code']) ? $row['model_code'] : '';
            self::$imageInputCache[$key] = ImageInputSupport::supported($code);
        }

        return self::$imageInputCache[$key];
    }

    /**
     * @param string $placement
     * @return array
     */
    private function globalApps($placement)
    {
        if (!isset($this->globalCache[$placement])) {
            $this->globalCache[$placement] = $this->fetchApps($placement, '');
        }

        return $this->globalCache[$placement];
    }

    /**
     * @param string $module
     * @param string $placement
     * @return array
     */
    private function mountedApps($module, $placement)
    {
        $module = trim((string) $module);
        if ($module === '') {
            return array();
        }

        return $this->fetchApps($placement, $module);
    }

    /**
     * @param string $placement
     * @param string $module 空 = 不按模块过滤
     * @return array
     */
    private function fetchApps($placement, $module)
    {
        $query = AiApplication::filterByPlacement($placement)
            ->filterByStatus('1')
            ->applyDefaultOrder();
        if ($module !== '') {
            $query = $query->filterByModule($module);
        }

        $list = array();
        foreach ($query->get() as $model) {
            $app = $model->getAttributes();
            // 应用绑定模型未配置可用密钥时，不注入任何按钮（覆盖全部任务类型）
            if (!$this->modelHasAvailableKey($app)) {
                continue;
            }
            $list[] = $app;
        }

        return $list;
    }

    /**
     * 应用绑定模型是否可解析出密钥配置（模型存在 + 供应商启用 + 存在可用密钥）。
     *
     * model_id=0 按系统默认模型解析，判定口径与 {@see AiGateway::getDefaultModel()}
     * 及 {@see GenerateService} 运行时一致，避免「按钮显示但生成必然失败」。
     *
     * @param array $app 应用行
     * @return bool
     */
    private function modelHasAvailableKey(array $app)
    {
        $modelId = isset($app['model_id']) ? (int) $app['model_id'] : 0;
        if (isset(self::$configCache[$modelId])) {
            return self::$configCache[$modelId];
        }

        $model = $modelId > 0
            ? $this->gateway->getModel($modelId)
            : $this->gateway->getDefaultModel();
        if (!$model) {
            return self::$configCache[$modelId] = false;
        }
        $provider = DB::table('ai_provider')
            ->where('id', (int) $model['provider_id'])
            ->find();
        if (!$provider || empty($provider['status'])) {
            return self::$configCache[$modelId] = false;
        }

        return self::$configCache[$modelId] = (bool) $this->gateway->getAvailableKey((int) $provider['id']);
    }

    /**
     * @param array $app
     * @return array page_actions 条目
     */
    private function buttonFor(array $app)
    {
        $config = $app['config'] ? json_decode($app['config'], true) : array();
        if (!is_array($config)) {
            $config = array();
        }

        return array(
            'href' => 'javascript:;',
            'text' => $app['name'],
            'style' => 'ai',
            'attrs' => array(
                'data-ai-app' => (string) (int) $app['id'],
                'data-ai-placement' => $app['placement'],
                'data-ai-name' => $app['name'],
                'data-ai-batch-min' => (string) (isset($config['batch_min']) ? (int) $config['batch_min'] : 1),
                'data-ai-batch-max' => (string) (isset($config['batch_max']) ? (int) $config['batch_max'] : 20),
            ),
        );
    }
}

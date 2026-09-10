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
use Dou\Admin\Service\Ai\Prompt\PromptComposer;
use Dou\Core\Facade\DB;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Foundation\Exception\DomainException;
use Dou\Core\Service\Ai\AiGateway;
use Dou\Core\Service\Ai\Driver\AsyncDriverInterface;
use Dou\Core\Service\Ai\Factory\DriverFactory;
use Dou\Core\Service\Ai\ImageSizeNormalizer;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台 AI 创作运行时：按应用形态执行生成并落账。
 *
 * - batch：结构化生成 items 数组 → BatchImportManager 批量入库；
 * - fill：结构化生成单对象 → 返回字段值供前端回填表单；
 * - assist：纯文本生成 → 返回单字段内容。
 * - translate：纯文本翻译 → 返回译文供多语言弹窗回填。
 */
class GenerateService extends BaseService
{
    /** @var AiGateway */
    private $gateway;

    /** @var SchemaBuilder */
    private $schemaBuilder;

    /** @var UsageRecorder */
    private $usageRecorder;

    /** @var BatchImportManager */
    private $importManager;

    /** @var PromptComposer */
    private $composer;

    /** @var ApplicationPolicy */
    private $applicationPolicy;

    /** @var BatchRequestGuard */
    private $batchRequestGuard;

    /**
     * @param AiGateway $gateway
     * @param SchemaBuilder $schemaBuilder
     * @param UsageRecorder $usageRecorder
     * @param BatchImportManager $importManager
     * @param PromptComposer $composer
     * @param ApplicationPolicy $applicationPolicy
     * @param BatchRequestGuard $batchRequestGuard
     */
    public function __construct(
        AiGateway $gateway,
        SchemaBuilder $schemaBuilder,
        UsageRecorder $usageRecorder,
        BatchImportManager $importManager,
        PromptComposer $composer,
        ApplicationPolicy $applicationPolicy,
        BatchRequestGuard $batchRequestGuard
    ) {
        $this->gateway = $gateway;
        $this->schemaBuilder = $schemaBuilder;
        $this->usageRecorder = $usageRecorder;
        $this->importManager = $importManager;
        $this->composer = $composer;
        $this->applicationPolicy = $applicationPolicy;
        $this->batchRequestGuard = $batchRequestGuard;
    }

    /**
     * batch：批量生成 + 入库。
     *
     * @param int $appId 应用 ID
     * @param string $prompt 管理员补充提示词
     * @param int $count 生成条数
     * @param array $context 页面上下文（category_id / parent_id）
     * @param int $adminId
     * @param string $ip
     * @return array {imported, failed, ids, errors}
     */
    public function generateBatch($appId, $prompt, $count, array $context, $adminId, $ip)
    {
        $app = $this->loadApp($appId, 'batch');
        if (!$this->importManager->supports($app['module'])) {
            throw new DomainException(lang('ai_generate_module_unsupported'));
        }

        $count = $this->applicationPolicy->batchCount($app, $count);
        $fingerprint = implode('|', array(
            (int) $adminId,
            (int) $app['id'],
            trim((string) $prompt),
            $count,
            json_encode($context),
        ));
        if (!$this->batchRequestGuard->acquire($fingerprint)) {
            throw new DomainException(lang('ai_generate_duplicate_batch'));
        }
        $schema = $this->schemaFor($app);

        $async = $this->submitAsyncIfNeeded($app, $prompt, $adminId, $ip, array(
            'placement' => 'batch',
            'module' => $app['module'],
            'count' => $count,
        ), array('count' => (string) $count));
        if ($async !== null) {
            return $async;
        }

        $messages = $this->composer->compose($app, array(
            'placeholders' => array('count' => (string) $count),
            'user_prompt' => $prompt,
        ));
        $result = $this->gateway->chatJson($messages, $schema, $this->preferredModelId($app));
        $this->usageRecorder->record($result, (int) $app['id'], $adminId, $ip, array(
            'placement' => 'batch',
            'module' => $app['module'],
            'count' => $count,
        ), $this->formatMessages($messages));

        if (!$result['success']) {
            $this->batchRequestGuard->forget($fingerprint);
            throw new DomainException(lang('ai_generate_failed') . ': ' . $result['error']);
        }

        $items = isset($result['data']['items']) && is_array($result['data']['items'])
            ? $result['data']['items']
            : array();
        if (!$items) {
            $this->batchRequestGuard->forget($fingerprint);
            throw new DomainException(lang('ai_generate_empty'));
        }

        return $this->importManager->import($app['module'], array_slice($items, 0, $count), $context, $adminId);
    }

    /**
     * fill：生成整表单字段值（不入库，前端回填后人工提交）。
     *
     * @param int $appId
     * @param string $prompt
     * @param int $adminId
     * @param string $ip
     * @return array 字段名 => 值
     */
    public function generateForm($appId, $prompt, $adminId, $ip)
    {
        $app = $this->loadApp($appId, 'fill');
        $schema = $this->schemaFor($app);

        $async = $this->submitAsyncIfNeeded($app, $prompt, $adminId, $ip, array(
            'placement' => 'fill',
            'module' => $app['module'],
        ));
        if ($async !== null) {
            return $async;
        }

        $messages = $this->composer->compose($app, array(
            'user_prompt' => $prompt,
        ));
        $result = $this->gateway->chatJson($messages, $schema, $this->preferredModelId($app));
        $this->usageRecorder->record($result, (int) $app['id'], $adminId, $ip, array(
            'placement' => 'fill',
            'module' => $app['module'],
        ), $this->formatMessages($messages));

        if (!$result['success']) {
            throw new DomainException(lang('ai_generate_failed') . ': ' . $result['error']);
        }

        return is_array($result['data']) ? $result['data'] : array();
    }

    /**
     * assist：生成单字段内容。全局形态，不按应用字段白名单过滤。
     *
     * 文本应用（type=chat）返回字段正文；图像应用（type=image）走文生图链路，
     * 返回任务结果数组（task_id / status / result），由前端按任务链路展示。
     *
     * @param int $appId
     * @param string $field 目标字段名
     * @param string $userPrompt 管理员输入的生成要求
     * @param string $currentValue 字段当前值
     * @param array $formSnapshot 当前表单快照（title/category/content/keywords/description）
     * @param string $currentModule 当前后台模块（仅用于字段中文名）
     * @param int $adminId
     * @param string $ip
     * @param string $size 图片尺寸（WxH，最终成品尺寸；极端比例底层自动换基础尺寸出图后裁切）
     * @param string $contentAlign 核心画面区方位 left/center/right（弹窗当次选择，可空）
     * @param array $banner banner 弹窗参数（title / subtitle / style / image_refs / material_mode，仅图像应用消费）
     * @return string|array 文本应用返回正文；图像应用返回任务结果数组
     */
    public function generateField(
        $appId,
        $field,
        $userPrompt,
        $currentValue,
        array $formSnapshot,
        $currentModule,
        $adminId,
        $ip,
        $size = '',
        $contentAlign = '',
        array $banner = array()
    ) {
        $app = $this->loadApp($appId, 'assist');

        $field = trim((string) $field);
        if ($field === '') {
            throw new DomainException(lang('illegal'));
        }

        if ($this->isImageApp($app)) {
            return $this->generateImageField($app, $field, $userPrompt, $formSnapshot, $currentModule, $adminId, $ip, $size, $contentAlign, $banner);
        }

        $fieldLabel = $this->fieldLabel($currentModule, $field);

        $messages = $this->composer->compose($app, array(
            'placeholders' => array('field_label' => $fieldLabel),
            'user_prompt' => $userPrompt,
            'form_snapshot' => $this->normalizeFormSnapshot($formSnapshot),
            'current_value' => $currentValue,
        ));
        $result = $this->gateway->chat($messages, $this->preferredModelId($app));
        $this->usageRecorder->record($result, (int) $app['id'], $adminId, $ip, array(
            'placement' => 'assist',
            'module' => trim((string) $currentModule),
            'field' => $field,
        ), $this->formatMessages($messages));

        if (!$result['success']) {
            throw new DomainException(lang('ai_generate_failed') . ': ' . $result['error']);
        }

        return (string) $result['content'];
    }

    /**
     * assist 图像应用：表单快照主题 + 生成要求 → 文生图 → 任务结果数组。
     *
     * 同步驱动结果已补录为 succeeded 任务行，异步驱动为提交态任务，
     * 两种形态统一返回 {mode, task_id, status, result, expires_at} 供前端轮询/展示。
     *
     * 尺寸规划：弹窗传入的 size 是最终成品尺寸。极端比例（宽高比 ≥1.9 或 ≤0.55，
     * 如 banner 1920x400）主流模型无此预设，底层自动换 16:9 基础尺寸出图
     * （经 ImageSizeNormalizer 映射到模型合法预设），并把 target_size/gen_size 落入
     * 任务 request_payload。生成后由管理员按成品比例手动裁切，系统不再自动裁切。
     *
     * @param array $app 应用行（type=image）
     * @param string $field 目标字段名（图片字段）
     * @param string $userPrompt 管理员输入的生成要求
     * @param array $formSnapshot 当前表单快照
     * @param string $currentModule 当前后台模块
     * @param int $adminId
     * @param string $ip
     * @param string $size 最终成品尺寸（WxH），非法格式按空处理
     * @param string $contentAlign 核心画面区方位 left/center/right（弹窗当次选择，可空）
     * @param array $banner banner 弹窗参数（title / subtitle / style / image_refs / material_mode）
     * @return array 任务结果数组
     */
    private function generateImageField(array $app, $field, $userPrompt, array $formSnapshot, $currentModule, $adminId, $ip, $size = '', $contentAlign = '', array $banner = array())
    {
        $built = $this->buildImagePrompt($app, $userPrompt, $formSnapshot, $size, $contentAlign, $banner);

        $prompt = $built['prompt'];
        if ($prompt === '') {
            throw new DomainException(lang('ai_image_prompt_empty'));
        }

        $params = array('prompt' => $prompt);
        if ($built['gen_size'] !== '') {
            $params['size'] = $built['gen_size'];
        }
        if ($built['target_size'] !== '' && $built['band_ratio'] > 0) {
            // 成品裁切依据：仅落任务行 request_payload，驱动不消费这两个键
            $params['target_size'] = $built['target_size'];
            $params['gen_size'] = $built['gen_size'];
        }

        if ($built['image_refs']) {
            $params['image_refs'] = $built['image_refs'];
        }

        $result = $this->gateway->generateImage($this->preferredModelId($app), $params, array(
            'app_id' => (int) $app['id'],
            'admin_id' => (int) $adminId,
        ));
        $this->usageRecorder->record($result, (int) $app['id'], $adminId, $ip, array(
            'placement' => 'assist',
            'module' => trim((string) $currentModule),
            'field' => $field,
            'task_type' => isset($app['task_type']) && trim((string) $app['task_type']) !== ''
                ? trim((string) $app['task_type']) : 'image',
        ), $prompt);

        unset($result['config']);

        if (!$result['success']) {
            throw new DomainException(lang('ai_generate_failed') . ': ' . $result['error']);
        }

        return $result;
    }

    /**
     * assist 提示词预览：只组装不下发模型。
     *
     * 文本应用返回 system/user 消息全文（[role] 分段）；图像应用返回最终单段提示词。
     * 参考图不参与预览（不读盘解析），素材用法指令相应不注入。
     *
     * @param int $appId
     * @param string $field 目标字段名
     * @param string $userPrompt 管理员输入的生成要求
     * @param string $currentValue 字段当前值
     * @param array $formSnapshot 当前表单快照
     * @param string $currentModule 当前后台模块
     * @param string $size 图片尺寸（WxH）
     * @param string $contentAlign 核心画面区方位 left/center/right（弹窗当次选择，可空）
     * @param array $banner banner 弹窗参数（title / subtitle / style；image_refs / material_mode 预览不消费）
     * @return array{prompt:string}
     */
    public function previewField(
        $appId,
        $field,
        $userPrompt,
        $currentValue,
        array $formSnapshot,
        $currentModule,
        $size = '',
        $contentAlign = '',
        array $banner = array()
    ) {
        $app = $this->loadApp($appId, 'assist');

        if ($this->isImageApp($app)) {
            $built = $this->buildImagePrompt($app, $userPrompt, $formSnapshot, $size, $contentAlign, $banner);

            return array('prompt' => $built['prompt']);
        }

        $fieldLabel = $this->fieldLabel($currentModule, $field);
        $messages = $this->composer->compose($app, array(
            'placeholders' => array('field_label' => $fieldLabel),
            'user_prompt' => $userPrompt,
            'form_snapshot' => $this->normalizeFormSnapshot($formSnapshot),
            'current_value' => $currentValue,
        ));

        return array('prompt' => $this->formatMessages($messages));
    }

    /**
     * 图像应用最终提示词组装（生成与预览共用）。
     *
     * 尺寸规划：弹窗传入的 size 是最终成品尺寸。极端比例（宽高比 ≥1.9 或 ≤0.55，
     * 如 banner 1920x400）主流模型无此预设，自动换 16:9 基础尺寸出图
     * （经 ImageSizeNormalizer 映射到模型合法预设）。参考图仅在上游支持图生图时解析。
     *
     * @param array $app 应用行（task_type=image/banner）
     * @param string $userPrompt 管理员输入的生成要求
     * @param array $formSnapshot 当前表单快照
     * @param string $size 最终成品尺寸（WxH），非法格式按空处理
     * @param string $contentAlign 核心画面区方位 left/center/right（可空）
     * @param array $banner banner 弹窗参数（title / subtitle / style / image_refs / material_mode）
     * @return array{prompt:string, target_size:string, gen_size:string, band_ratio:float, image_refs:string[]}
     */
    private function buildImagePrompt(array $app, $userPrompt, array $formSnapshot, $size, $contentAlign, array $banner = array())
    {
        $size = trim((string) $size);
        if (!preg_match('/^\d{1,5}x\d{1,5}$/', $size)) {
            $size = '';
        }
        if (!in_array($contentAlign, array('left', 'center', 'right'), true)) {
            $contentAlign = '';
        }

        // 极端比例 → 16:9 基础尺寸出图 + 裁切；常规比例按原尺寸直出
        $targetSize = $size;
        $genSize = $size;
        $bandRatio = 0.0;
        if ($size !== '') {
            list($w, $h) = explode('x', $size);
            $w = (int) $w;
            $h = (int) $h;
            $ratio = $w / max(1, $h);
            if ($ratio >= 1.9 || $ratio <= 0.55) {
                if ($ratio > 1) {
                    $gw = $w;
                    $gh = (int) round($w * 9 / 16);
                } else {
                    $gh = $h;
                    $gw = (int) round($h * 9 / 16);
                }
                $genSize = $gw . 'x' . $gh;

                // 映射到当前模型合法预设（如万相 1920*1080），保证上游一定接受
                $modelConfig = $this->gateway->resolveConfig($this->preferredModelId($app));
                if ($modelConfig) {
                    $normalized = ImageSizeNormalizer::normalize(
                        $modelConfig['model_code'],
                        $genSize,
                        isset($modelConfig['provider_code']) ? (string) $modelConfig['provider_code'] : ''
                    );
                    if ($normalized !== '') {
                        $genSize = $normalized;
                        if (preg_match('/^(\d+)\D+(\d+)$/', $normalized, $nm)) {
                            $gw = (int) $nm[1];
                            $gh = (int) $nm[2];
                        }
                    }
                }
                $bandRatio = $ratio > 1 ? $h / max(1, $gh) : $w / max(1, $gw);
            }
        }

        $bannerTitle = isset($banner['title']) ? trim((string) $banner['title']) : '';
        $bannerSubtitle = isset($banner['subtitle']) ? trim((string) $banner['subtitle']) : '';
        $bannerStyle = isset($banner['style']) ? trim((string) $banner['style']) : '';
        $imageRefs = isset($banner['image_refs']) && is_array($banner['image_refs']) ? $banner['image_refs'] : array();
        $materialMode = isset($banner['material_mode']) ? trim((string) $banner['material_mode']) : '';
        if (!in_array($materialMode, array('background', 'subject'), true)) {
            $materialMode = 'subject';
        }

        // 参考图须在拼 prompt 前解析：仅当上游确实会带图时才声明素材用法
        $resolvedRefs = array();
        if ($imageRefs) {
            $modelConfig = $this->gateway->resolveConfig($this->preferredModelId($app));
            if ($modelConfig && !empty($modelConfig['image_input'])) {
                $resolvedRefs = $this->resolveImageRefs($imageRefs);
            }
        }

        $prompt = $this->composer->imagePrompt($app, array(
            'user_prompt' => $userPrompt,
            'form_snapshot' => $this->normalizeFormSnapshot($formSnapshot),
            'banner' => array(
                'target_size' => $targetSize,
                'gen_size' => $genSize,
                'band_ratio' => $bandRatio,
                'content_align' => $contentAlign,
                'title' => $bannerTitle,
                'subtitle' => $bannerSubtitle,
                'style' => $bannerStyle,
                'material_mode' => $resolvedRefs ? $materialMode : '',
            ),
        ));

        return array(
            'prompt' => $prompt,
            'target_size' => $targetSize,
            'gen_size' => $genSize,
            'band_ratio' => $bandRatio,
            'image_refs' => $resolvedRefs,
        );
    }

    /**
     * chat 消息数组 → 可读全文（[role] 分段），供提示词预览与用量日志留档。
     *
     * @param array $messages
     * @return string
     */
    private function formatMessages(array $messages)
    {
        $parts = array();
        foreach ($messages as $message) {
            if (!is_array($message)) {
                continue;
            }
            $role = isset($message['role']) ? (string) $message['role'] : 'user';
            $content = isset($message['content']) ? (string) $message['content'] : '';
            $parts[] = '[' . $role . "]\n" . $content;
        }

        return implode("\n\n", $parts);
    }

    /**
     * 素材引用解析为上游可用的图片载体（data: URI）。
     *
     * 入参元素两类：
     * - data:image/...;base64,....（前端本地上传 FileReader 直传）→ 透传；
     * - .file 附件号（产品主图）→ 查 dou_file 取磁盘相对路径读文件转 base64。
     * 最多 4 张、单张原始不超过 10MB；解析失败的元素静默跳过（不阻断生成）。
     *
     * @param array $refs
     * @return string[]
     */
    private function resolveImageRefs(array $refs)
    {
        $resolved = array();
        foreach ($refs as $ref) {
            if (count($resolved) >= self::bannerMaterialMax()) {
                break;
            }
            $ref = trim((string) $ref);
            if ($ref === '') {
                continue;
            }
            if (strpos($ref, 'data:image/') === 0) {
                $resolved[] = $ref;
                continue;
            }
            if (strrchr($ref, '.file') !== '.file') {
                continue;
            }

            $path = DB::table('file')->where('number', $ref)->value('file');
            if (!$path) {
                continue;
            }

            $absolute = ROOT_PATH . ltrim(str_replace('\\', '/', (string) $path), '/');
            if (!is_file($absolute) || filesize($absolute) > self::bannerUploadMaxBytes()) {
                continue;
            }
            $data = file_get_contents($absolute);
            if ($data === false) {
                continue;
            }
            $resolved[] = 'data:' . $this->imageMimeByExtension($path) . ';base64,' . base64_encode($data);
        }

        return $resolved;
    }

    /**
     * @return int
     */
    private static function bannerMaterialMax()
    {
        $max = (int) Config::get('ai.banner.material_max', 4);

        return $max > 0 ? $max : 4;
    }

    /**
     * @return int
     */
    private static function bannerUploadMaxBytes()
    {
        $max = (int) Config::get('ai.banner.upload_max_bytes', 5242880);

        return $max > 0 ? $max : 5242880;
    }

    /**
     * @param string $path
     * @return string
     */
    private function imageMimeByExtension($path)
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = array(
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
        );

        return isset($map[$ext]) ? $map[$ext] : 'image/png';
    }

    /**
     * translate：把主表单字段原文译成目标语言（纯文本，不入库）。
     *
     * @param int $appId
     * @param string $field 目标字段名
     * @param string $sourceText 主表单当前字段值
     * @param string $targetLang 语言包代码（如 en_us）
     * @param string $targetLangName 语言显示名（如英语）
     * @param int $adminId
     * @param string $ip
     * @return string
     */
    public function generateTranslate($appId, $field, $sourceText, $targetLang, $targetLangName, $adminId, $ip)
    {
        $app = $this->loadApp($appId, 'translate');

        $field = trim((string) $field);
        $sourceText = trim((string) $sourceText);
        if ($sourceText === '') {
            throw new DomainException(lang('ai_translate_empty_source'));
        }

        $targetLang = trim((string) $targetLang);
        $targetLangName = trim((string) $targetLangName);
        if ($targetLangName === '') {
            $targetLangName = $targetLang;
        }

        $messages = $this->composer->compose($app, array(
            'placeholders' => array('target_lang' => $targetLangName),
            'source_text' => $sourceText,
        ));
        $result = $this->gateway->chat($messages, $this->preferredModelId($app));
        $this->usageRecorder->record($result, (int) $app['id'], $adminId, $ip, array(
            'placement' => 'translate',
            'field' => $field,
            'target_lang' => $targetLang,
        ), $this->formatMessages($messages));

        if (!$result['success']) {
            throw new DomainException(lang('ai_generate_failed') . ': ' . $result['error']);
        }

        return (string) $result['content'];
    }

    /**
     * @param int $appId
     * @param string $placement 期望形态
     * @return array 应用行
     */
    private function loadApp($appId, $placement)
    {
        $app = AiApplication::find((int) $appId);
        if (!$app || (int) $app['status'] !== 1 || $app['placement'] !== $placement) {
            throw new DomainException(lang('ai_generate_app_not_found'));
        }

        $row = $app->getAttributes();
        $config = $this->gateway->resolveConfig($this->preferredModelId($row));
        if (!$config || !$this->applicationPolicy->modelSupportsTask(
            isset($row['task_type']) ? $row['task_type'] : '',
            isset($config['model_code']) ? $config['model_code'] : '',
            isset($config['provider_code']) ? $config['provider_code'] : ''
        )) {
            throw new DomainException(lang('ai_model_task_mismatch'));
        }

        return $row;
    }

    /**
     * 是否图像应用：task_type 为 image / banner 时走文生图链路。
     *
     * @param array $app
     * @return bool
     */
    private function isImageApp(array $app)
    {
        return isset($app['task_type'])
            && in_array(trim((string) $app['task_type']), array('image', 'banner'), true);
    }

    /**
     * 应用输出 schema：按挂载模块与已选 field 现算。
     *
     * @param array $app
     * @return array
     */
    private function schemaFor(array $app)
    {
        $selected = $app['field'] ? json_decode($app['field'], true) : array();

        return $this->schemaBuilder->schemaFor(
            $app['module'],
            is_array($selected) ? $selected : array(),
            $app['placement']
        );
    }

    /**
     * @param string $module
     * @param string $field
     * @return string
     */
    private function fieldLabel($module, $field)
    {
        $module = trim((string) $module);
        $field = trim((string) $field);
        if ($module === '') {
            return $field;
        }

        foreach ($this->schemaBuilder->fieldsFor($module) as $info) {
            if ($info['name'] === $field) {
                return $info['label'];
            }
        }

        return $field;
    }

    /**
     * @param array $snapshot
     * @return array
     */
    private function normalizeFormSnapshot(array $snapshot)
    {
        $allowed = array('title', 'category', 'content', 'keywords', 'description');
        $clean = array();
        foreach ($allowed as $key) {
            if (!isset($snapshot[$key]) || !is_scalar($snapshot[$key])) {
                continue;
            }
            $value = trim((string) $snapshot[$key]);
            if ($value === '') {
                continue;
            }
            $clean[$key] = $value;
        }

        return $clean;
    }

    /**
     * 应用绑定模型（0 = 系统默认模型）。
     *
     * @param array $app
     * @return int|null
     */
    private function preferredModelId(array $app)
    {
        $modelId = isset($app['model_id']) ? (int) $app['model_id'] : 0;

        return $modelId > 0 ? $modelId : null;
    }

    /**
     * 目标模型为异步驱动（图像/视频生成）时提交任务并落账，返回结果数组；
     * 否则返回 null 表示走同步路径。
     *
     * @param array $app
     * @param string $prompt
     * @param int $adminId
     * @param string $ip
     * @param array $metadata
     * @param array $instructionVars
     * @return array|null
     */
    private function submitAsyncIfNeeded(array $app, $prompt, $adminId, $ip, array $metadata, array $instructionVars = array())
    {
        $modelId = $this->preferredModelId($app);
        $config = $this->gateway->resolveConfig($modelId);
        if (!$config) {
            return null;
        }

        $driver = DriverFactory::make($config);
        if (!($driver instanceof AsyncDriverInterface)) {
            return null;
        }

        $prompt = trim((string) $prompt);
        if ($prompt === '') {
            $prompt = $this->composer->instruction($app['placement'], $instructionVars);
        }

        $result = $this->gateway->submitAsyncTask($modelId, array('prompt' => $prompt), array(
            'app_id' => (int) $app['id'],
            'admin_id' => (int) $adminId,
            'task_type' => isset($app['task_type']) ? (string) $app['task_type'] : '',
        ));
        $this->usageRecorder->record($result, (int) $app['id'], $adminId, $ip, $metadata, $prompt);

        unset($result['config']);

        return $result;
    }
}

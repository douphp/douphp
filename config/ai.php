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

/**
 * AI 创作配置（由 Init 合并入 Config）
 *
 * 由 {@see \Dou\Core\Init\InitTrait::loadAiConfig()} 灌入 Config。
 *
 * 配置块：
 *   prompts        - 内置提示词正本（system / instruction / labels / banner_styles / banner_material）
 *                    改这里即同时作用于 zh_cn / zh_tw 后台；语言包只放界面文案
 *   image_input    - 支持图生图（参考图）的模型代码通配（fnmatch），如 qwen-image-3*
 *   image_size     - 图像尺寸归一化策略（policies 按模型族、protocol_defaults 按供应商）
 *   banner         - 弹窗素材上限（material_max 张数、upload_max_bytes 单张字节）
 */
if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

return [
    'ai' => [
        /**
         * user 消息各段落的标题。PromptComposer 用 PromptCatalog::label($key) 读取。
         * current_field 仍含 {field_label}，由 Composer 在拼段时替换。
         */
        'prompts' => [
            'labels' => [
                'site_knowledge' => '站点基础信息：',
                'form_snapshot' => '当前表单已填内容：',
                'current_field' => '当前字段「{field_label}」的内容：',
                'user_prompt' => '用户要求：',
                'app_task' => '本应用补充要求（与上文冲突时以本段为准）：',
                'source_text' => '待翻译原文：',
                'snapshot_title' => '标题',
                'snapshot_category' => '分类',
                'snapshot_content' => '正文',
                'snapshot_keywords' => '关键词',
                'snapshot_description' => '描述',
            ],

            /**
             * system 提示词：按 task_type（任务形态）取。
             * assist 为通用兜底；polish / rewrite 的正本由原内置应用的 default_prompt 迁入。
             */
            'system' => [
                'assist' => "你是企业官网内容助手。根据站点基础信息和当前表单已填内容，为指定字段产出可直接使用的文案。\n要求：\n- 只输出该字段的最终正文，不要解释、不要标题、不要 Markdown 代码块\n- 字段已有内容时在原文基础上完善，为空时结合表单上下文新写\n- 口吻与站点行业、品牌一致\n- 不要编造站点未提供的具体事实（地址、电话、奖项名称等）",
                'polish' => "你是企业官网字段润色助手。在保留原意、事实、专有名词和数字的前提下润色当前字段，使表达更精炼、更符合企业官网口吻。\n要求：\n- 只输出该字段的最终正文，不要解释、不要标题、不要 Markdown 代码块\n- 字段为空时，根据表单其它已填项与站点信息补一段可直接使用的短文案\n- 不要写成其它字段该有的体裁\n- 不要编造站点未提供的具体事实（地址、电话、奖项名称等）",
                'rewrite' => "你是企业官网内容撰写助手。按表单其它字段与站点信息重新撰写当前字段，视为从零生成。\n要求：\n- 只输出该字段的最终正文，不要解释、不要标题、不要 Markdown 代码块\n- 不要复述或改写当前字段里的旧文；旧文仅供参考，可以忽略\n- 字段为空时同样从零撰写\n- 口吻与站点行业、品牌一致\n- 不要编造站点未提供的具体事实（地址、电话、奖项名称等）",
                'image' => '企业官网商用配图，画面清晰、构图完整、光线自然，贴合站点行业与品牌调性，不含文字与水印',
                'banner' => '企业官网首页横幅广告图，宽幅画面、构图大气、光线自然，贴合站点行业与品牌调性，底图画满整幅画布；除下方指定的标题文字外，画面不出现任何文字、字母、数字、水印与 Logo',
                'translate' => "你是企业官网多语言翻译助手。请结合站点基础信息（行业、品牌、专有名词）把原文准确译入目标语言。\n要求：\n- 只输出译文，不要解释、不要引号、不要注明语言\n- 保留原文中的 HTML 标签与占位结构\n- 品牌名、产品型号、已是目标语言的专有名词可保持不译",
                'fill' => "你是企业官网内容生成助手。请根据站点基础信息与用户要求，按输出契约填写表单字段。\n要求：\n- 严格遵守 JSON Schema\n- 文案符合站点行业与品牌调性\n- 不要编造站点未提供的具体事实",
                'batch' => "你是企业官网批量内容生成助手。请根据站点基础信息与用户要求，按输出契约一次生成多条内容。\n要求：\n- 严格遵守 JSON Schema，条目放入 items 数组\n- 每条内容主题不重复，符合站点行业与品牌调性\n- 不要编造站点未提供的具体事实",
            ],

            /**
             * banner 弹窗「风格选择」预设（key 与前端按钮值一一对应；界面文案在语言包
             * ai_banner_style_*，这里只放创作指令短语）。
             */
            'banner_styles' => [
                'business' => '商务简约风格：大面积留白、克制的蓝灰配色、稳重专业',
                'tech' => '科技蓝调风格：深蓝背景、光线与线条元素、未来感科技氛围',
                'vibrant' => '活力渐变风格：明快渐变色彩、动感光效、年轻有朝气',
                'promo' => '电商促销风格：高饱和暖色调、热闹氛围、突出优惠与促销感',
                'fresh' => '自然清新风格：浅色调、植物与自然元素、干净通透',
                'industry' => '工业质感风格：金属质感、硬朗构图、深色调突出制造实力',
            ],

            /**
             * banner 参考图用法（弹窗「素材用法」二选一，仅实际带上参考图时插入）。
             */
            'banner_material' => [
                'background' => '参考图用法：以参考图整张作为横幅底图，保留其画面内容与场景，只做尺寸适配与边缘延展',
                'subject' => '参考图用法：只抠出参考图中的主体物，去掉参考图的背景与场景；底图另行生成，把抠出的主体贴合进新底图，不要把参考图整张当作底图',
            ],

            /**
             * instruction 提示词：按 placement（应用形态）取。
             * 键必须与 ai.placement 取值一致：assist / translate / fill / batch。
             */
            'instruction' => [
                'assist' => '请为「{field_label}」字段产出内容。只输出该字段正文。',
                'translate' => '请把下面内容翻译成「{target_lang}」。',
                'fill' => '请按输出契约生成一组完整的表单字段值，用于后台新增内容。',
                'batch' => '请一次性生成 {count} 条内容，放入 items 数组。',
            ],
        ],

        /**
         * 支持图生图（参考图输入）的模型代码，键为 fnmatch 通配。
         */
        'image_input' => [
            'qwen-image-2*',
            'qwen-image-3*',
            'qwen-image-max*',
        ],

        /**
         * 图像尺寸归一化。policies 键支持 fnmatch；未命中再走 protocol_defaults。
         *  - separator：尺寸分隔符
         *  - range：min_side / max_side / max_pixels（与 presets 二选一，range 优先）
         *  - presets：允许的尺寸列表
         *  - fallback：无法适配时回退
         */
        'image_size' => [
            'policies' => [
                'wan*-t2i-*' => [
                    'separator' => '*',
                    'range' => ['min_side' => 512, 'max_side' => 1440, 'max_pixels' => 2073600],
                    'fallback' => '1024*1024',
                ],
                'qwen-image-2*' => [
                    'separator' => '*',
                    'range' => ['min_side' => 512, 'max_side' => 2688, 'max_pixels' => 4194304],
                    'fallback' => '2048*2048',
                ],
                'qwen-image-3*' => [
                    'separator' => '*',
                    'range' => ['min_side' => 512, 'max_side' => 2688, 'max_pixels' => 4194304],
                    'fallback' => '2048*2048',
                ],
                'gpt-image-*' => [
                    'separator' => 'x',
                    'presets' => ['1024x1024', '1536x1024', '1024x1536', 'auto'],
                    'fallback' => '1024x1024',
                ],
                'dall-e-3' => [
                    'separator' => 'x',
                    'presets' => ['1024x1024', '1792x1024', '1024x1792'],
                    'fallback' => '1024x1024',
                ],
                'dall-e-2' => [
                    'separator' => 'x',
                    'presets' => ['256x256', '512x512', '1024x1024'],
                    'fallback' => '1024x1024',
                ],
                'cogview-*' => [
                    'separator' => 'x',
                    'presets' => ['1024x1024', '768x1344', '864x1152', '1344x768', '1152x864', '1440x720', '720x1440'],
                    'fallback' => '1024x1024',
                ],
            ],
            'protocol_defaults' => [
                'aliyun' => [
                    'separator' => '*',
                    'range' => ['min_side' => 512, 'max_side' => 1440, 'max_pixels' => 2073600],
                    'fallback' => '1024*1024',
                ],
                'bailian' => [
                    'separator' => '*',
                    'range' => ['min_side' => 512, 'max_side' => 1440, 'max_pixels' => 2073600],
                    'fallback' => '1024*1024',
                ],
            ],
        ],

        /**
         * banner 弹窗素材：张数上限、本地上传单张字节上限（与 GenerateService 解析参考图共用）。
         */
        'banner' => [
            'material_max' => 4,
            'upload_max_bytes' => 5242880,
        ],
    ],
];

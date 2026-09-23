<?php

/**
 * 主题图片尺寸配置（标准格式，短数组）。
 * width/height：裁剪工具预填的固定尺寸（正整数）；缺省表示无结构尺寸。
 * note：追加在自动生成文案后的补充提示。
 * tip：完整手工文案，存在时覆盖自动文案。
 */
return [
    'logo_img' => ['width' => 200, 'height' => 55, 'note' => '宽度可在 55~200 之间灵活调整，高度建议保持 55'],
    'banner_img' => ['width' => 1920, 'height' => 400, 'note' => '宽度建议不小于1920，以免影响横幅视觉效果'],
    'product_img' => ['width' => 1000, 'height' => 1000, 'note' => '不建议上传过大图片，以免影响访问速度'],
    'product_thumb' => ['width' => 300, 'height' => 300],
    'article_img' => ['width' => 750, 'height' => 500, 'note' => '不建议上传过大图片，以免影响访问速度'],
    'case_img' => ['width' => 750, 'height' => 500, 'note' => '不建议上传过大图片，以免影响访问速度'],
    'professional_img' => ['width' => 750, 'height' => 500, 'note' => '不建议上传过大图片，以免影响访问速度'],
];

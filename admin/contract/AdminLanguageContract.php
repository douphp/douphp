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

namespace Dou\Admin\Contract;

use Dou\Core\Contract\LanguageContract;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 后台扩展语言能力契约。
 *
 * 后台容器以本契约解析 language()；模块缺失或 features.language 关闭时仍由
 * {@see \Dou\Core\Service\Noop\NullLanguageService} 提供 no-op 兜底，避免业务调用面判空。
 */
interface AdminLanguageContract extends LanguageContract
{
    /**
     * 清理多语言值及相关语言文件（按 module + item_id 维度）。
     *
     * @param string $module
     * @param string|int $itemId
     * @return void
     */
    public function deleteLang($module = '', $itemId = '');

    /**
     * 后台多语言按钮渲染数据：按字段 + 语言包枚举生成 a 标签列表。
     *
     * @param string $module
     * @param string|int $itemId
     * @param string $fieldList 逗号分隔字段名
     * @return array|bool
     */
    public function buildLangButtons($module, $itemId = '', $fieldList = '');

    /**
     * 构建语言列表（用于设置页 Tab、语言管理「设置」切换项）。
     *
     * @param string $curLanguagePack 当前语言包标识；命中时该项 cur=true
     * @return array 形如 [{name, language_pack, cur}]；features.language 关闭时为空数组
     */
    public function buildLangList($curLanguagePack = '');
}

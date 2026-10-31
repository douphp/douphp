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
 * Release Date: 2026-10-30
 */

namespace Dou\Core\Service\Noop;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 语言草稿 Null 实现：language 模块文件缺失（未安装 / 升级包剔除 / 已卸载）时兜底。
 *
 * 取用入口只有两处，均先做「实现类是否仍在磁盘」判断，缺失时返回本类：
 *   - 后台控制器：{@see \Dou\Admin\Controller\BaseController::languageDraft()}
 *   - 内容服务：{@see \Dou\Admin\Service\Concerns\SavesLanguageDrafts::languageDraftService()}
 *
 * 字段清单依赖随模块删除的 LanguageDraftFields，故 buildFormState 只能给出空按钮与空草稿，
 * 表单自然不渲染语言面板；begin 返回 null 让调用方的 commit / abort / purge 直接短路。
 */
class NullLanguageDraftService
{
    /**
     * 无草稿能力时给出空按钮与空草稿，保持视图下标契约。
     *
     * @param string $module
     * @param int|string $itemId
     * @return array {btn_lang: array, lang_drafts: array}
     */
    public function buildFormState($module, $itemId = 0)
    {
        return array('btn_lang' => array(), 'lang_drafts' => array());
    }

    /**
     * 无 language_value 可读，返回空行集。
     *
     * @param string $module
     * @param int|string $itemId
     * @param array $fields
     * @return array
     */
    public function load($module, $itemId, array $fields = array())
    {
        return array();
    }

    /**
     * 表单载荷中不存在语言面板字段（按钮已空），恒返回空草稿。
     *
     * @param array $data
     * @param string $module
     * @return array
     */
    public function drafts(array $data, $module)
    {
        return array();
    }

    /**
     * 无草稿计划：返回 null 即「本次提交不涉及多语言」。
     *
     * @param string $module
     * @param array $data
     * @param int|string $itemId
     * @param int $adminId
     * @return array|null
     */
    public function begin($module, array $data, $itemId, $adminId = 0)
    {
        return null;
    }

    /**
     * @param array|null $plan
     * @param int|string $itemId
     * @param int $adminId
     * @return void
     */
    public function commit($plan, $itemId, $adminId = 0)
    {
        return;
    }

    /**
     * @param array|null $plan
     * @return void
     */
    public function abort($plan)
    {
        return;
    }

    /**
     * @param array|null $plan
     * @param int|string $itemId
     * @return void
     */
    public function purge($plan, $itemId)
    {
        return;
    }

    /**
     * 无多语言模块时不写入 language_value。
     *
     * @param string $module
     * @param int|string $itemId
     * @param string $pack
     * @param string $field
     * @param string $value
     * @param string $type
     * @return void
     */
    public function saveTranslation($module, $itemId, $pack, $field, $value, $type = 'text')
    {
        return;
    }
}

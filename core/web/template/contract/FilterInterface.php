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

namespace Dou\Core\Web\Template\Contract;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 修饰器契约：变量修饰器（|truncate、|escape、|date_format 等）在运行期被调用。
 *
 * 修饰器接收待处理值作为首参，其余为模板中冒号分隔的参数；返回处理后的值。
 * 标准修饰器集中在 {@see \Dou\Core\Web\Template\Filter\StandardFilters}，
 * 通过 {@see \Dou\Core\Web\Template\FilterRegistry} 注册名称到 callable 的映射。
 */
interface FilterInterface
{
    /**
     * 应用修饰器。
     *
     * @param mixed $value 待处理的值
     * @param array $args 冒号分隔的附加参数（已按运行期求值）
     * @return mixed
     */
    public function apply($value, array $args);
}

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

namespace Dou\Core\Service\Noop;

use Dou\Core\Contract\DataServiceContract;
use Dou\Core\Service\BaseService;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 碎片化数据访问 Null 实现：features.data 关闭或真实现类缺席时由本类兜底。
 *
 * 实现 {@see DataServiceContract} 全部签名并返回无害默认值，保证 data() 始终可解析，
 * data 模块缺席时不会触发 PHP 错误，也不会查询不存在 / 关闭的 data 表。
 */
class NullDataService extends BaseService implements DataServiceContract
{
    /**
     * data 模块不可用时：无参返空数组（用作整张 dict 的安全占位）；
     * 带 code / field 时返 `$default`，让调用面 `data()->get('x', 'y', '/default.png')` 直接落到默认值。
     *
     * @param string|null $code
     * @param string|null $field
     * @param mixed $default
     * @return mixed
     */
    public function get($code = null, $field = null, $default = null)
    {
        if ($code === null) {
            return array();
        }
        return $default;
    }

    /**
     * data 模块不可用时模块绑定查询恒返空数组，与真实现"未命中"分支一致。
     *
     * @param string $group
     * @param string $item
     * @param string $parent
     * @return array
     */
    public function query($group, $item = '', $parent = '')
    {
        return array();
    }
}

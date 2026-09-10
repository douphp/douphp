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

namespace Dou\Front\Model\Concerns;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 同分类随机相关推荐（终结型静态门面）：published + with('category') + filterByCategory + RAND() + limit。
 *
 * 需要会员视图（如 Product 的 sale_price / favorites）的子类追加 $userId 参数并覆写本方法、
 * 在链中插入 forUser($userId)；参考 Product::related()。
 */
trait HasRelatedQuery
{
    /**
     * 同分类随机相关推荐。
     *
     * @param int|string $catId 分类 ID（精确单分类经 filterByCategory 子树展开）
     * @param int $number limit
     * @return \Dou\Core\Orm\Collection
     */
    public static function related($catId, $number = 4)
    {
        return static::published()
            ->with('category')
            ->filterByCategory($catId)
            ->order('RAND()')
            ->limit((int) $number)
            ->get();
    }
}

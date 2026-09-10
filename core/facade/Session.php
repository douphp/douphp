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

namespace Dou\Core\Facade;

use Dou\Core\Foundation\Facade\StaticFacade;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Session 静态门面：底层为 {@see \Dou\Core\Infra\Session\Session} 容器单例。
 *
 * 用法：
 *   use Dou\Core\Facade\Session;
 *   Session::set('promotion_user_sn', $userSn);
 *   $verification = Session::arr('verification');
 *
 * 测试期用 {@see StaticFacade::swap()} / {@see StaticFacade::clearResolvedInstance()}
 * 替换为 mock，避免触碰真实 $_SESSION。
 *
 * @method static mixed get(string $key, $default = null, string $subKey = '')
 * @method static array arr(string $key)
 * @method static void set(string $key, $value, string $subKey = '')
 * @method static bool has(string $key, string $subKey = '')
 * @method static void del(string $key, string $subKey = '')
 * @method static void clear()
 * @method static void push(string $key, $value)
 * @method static mixed pull(string $key, $default = null)
 * @method static int increment(string $key, int $by = 1)
 * @method static int decrement(string $key, int $by = 1)
 * @method static void forget(string $key, $value)
 * @method static void setFlash(string $key, $value) $value 可为 string 文案，或 array('message','back_url','back_text') 结构
 * @method static mixed getFlash(string $key, $default = '') 返回与 setFlash 写入一致的 string 或 array
 * @method static array pullAllFlashes() 一次性取出并清除全部 flash 槽位，layout 渲染入口
 */
class Session extends StaticFacade
{
    /**
     * 容器中以底层 Session FQCN 为 key 注册。
     *
     * @return string
     */
    protected static function getAccessor()
    {
        return \Dou\Core\Infra\Session\Session::class;
    }
}

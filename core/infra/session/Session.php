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

namespace Dou\Core\Infra\Session;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * Session 读写服务（默认命名空间：$_SESSION[DOU_ID]）。
 *
 * 所有 public 方法在 DOU_ID 未定义时安全降级，避免异常 handler / Init 极早期
 * 通过 {@see \Dou\Core\Facade\Session} 调用时触发常量未定义警告。
 */
class Session
{
    /**
     * 读取会话字段
     *
     * @param string $key
     * @param mixed $default
     * @param string $subKey 二级键（如 verification/code）
     * @return mixed
     */
    public function get($key, $default = null, $subKey = '')
    {
        if (!defined('DOU_ID')) {
            return $default;
        }

        if (!isset($_SESSION[DOU_ID]) || !is_array($_SESSION[DOU_ID])) {
            return $default;
        }

        if ($subKey !== '') {
            if (!isset($_SESSION[DOU_ID][$key]) || !is_array($_SESSION[DOU_ID][$key])) {
                return $default;
            }
            return isset($_SESSION[DOU_ID][$key][$subKey]) ? $_SESSION[DOU_ID][$key][$subKey] : $default;
        }

        return isset($_SESSION[DOU_ID][$key]) ? $_SESSION[DOU_ID][$key] : $default;
    }

    /**
     * 读取数组字段（非数组时返回空数组）
     *
     * @param string $key
     * @return array
     */
    public function arr($key)
    {
        if (!defined('DOU_ID')) {
            return array();
        }

        $value = $this->get($key, array());
        return is_array($value) ? $value : array();
    }

    /**
     * 写入会话字段
     *
     * @param string $key
     * @param mixed $value
     * @param string $subKey 二级键
     * @return void
     */
    public function set($key, $value, $subKey = '')
    {
        if (!defined('DOU_ID')) {
            return;
        }

        if (!isset($_SESSION[DOU_ID]) || !is_array($_SESSION[DOU_ID])) {
            $_SESSION[DOU_ID] = array();
        }

        if ($subKey !== '') {
            if (!isset($_SESSION[DOU_ID][$key]) || !is_array($_SESSION[DOU_ID][$key])) {
                $_SESSION[DOU_ID][$key] = array();
            }
            $_SESSION[DOU_ID][$key][$subKey] = $value;
            return;
        }

        $_SESSION[DOU_ID][$key] = $value;
    }

    /**
     * 判断字段是否存在
     *
     * @param string $key
     * @param string $subKey 二级键
     * @return bool
     */
    public function has($key, $subKey = '')
    {
        if (!defined('DOU_ID')) {
            return false;
        }

        if (!isset($_SESSION[DOU_ID]) || !is_array($_SESSION[DOU_ID])) {
            return false;
        }

        if ($subKey !== '') {
            if (!isset($_SESSION[DOU_ID][$key]) || !is_array($_SESSION[DOU_ID][$key])) {
                return false;
            }
            return isset($_SESSION[DOU_ID][$key][$subKey]);
        }

        return isset($_SESSION[DOU_ID][$key]);
    }

    /**
     * 删除字段
     *
     * @param string $key
     * @param string $subKey 二级键
     * @return void
     */
    public function del($key, $subKey = '')
    {
        if (!defined('DOU_ID')) {
            return;
        }

        if (!isset($_SESSION[DOU_ID]) || !is_array($_SESSION[DOU_ID])) {
            return;
        }

        if ($subKey !== '') {
            if (!isset($_SESSION[DOU_ID][$key]) || !is_array($_SESSION[DOU_ID][$key])) {
                return;
            }
            unset($_SESSION[DOU_ID][$key][$subKey]);
            return;
        }

        unset($_SESSION[DOU_ID][$key]);
    }

    /**
     * 清空命名空间
     *
     * @return void
     */
    public function clear()
    {
        if (!defined('DOU_ID')) {
            return;
        }

        unset($_SESSION[DOU_ID]);
    }

    /**
     * 数组追加：把 $value 追加到命名空间下的数组字段尾部。
     *
     * 当字段不存在或非数组时，初始化为单元素数组后写回。
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function push($key, $value)
    {
        if (!defined('DOU_ID')) {
            return;
        }

        $arr = $this->arr($key);
        $arr[] = $value;
        $this->set($key, $arr);
    }

    /**
     * 取出并删除：返回 $key 当前值后立刻删除；不存在返回 $default。
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function pull($key, $default = null)
    {
        if (!defined('DOU_ID')) {
            return $default;
        }

        $value = $this->get($key, $default);
        $this->del($key);
        return $value;
    }

    /**
     * 计数器自增：当前值视为 int（默认 0），加 $by 后写回，返回新值。
     *
     * @param string $key
     * @param int $by
     * @return int
     */
    public function increment($key, $by = 1)
    {
        if (!defined('DOU_ID')) {
            return 0;
        }

        $current = (int) $this->get($key, 0);
        $next = $current + (int) $by;
        $this->set($key, $next);
        return $next;
    }

    /**
     * 计数器自减：increment 的对偶。
     *
     * @param string $key
     * @param int $by
     * @return int
     */
    public function decrement($key, $by = 1)
    {
        if (!defined('DOU_ID')) {
            return 0;
        }

        return $this->increment($key, -((int) $by));
    }

    /**
     * 从命名空间下的数组字段中移除等于 $value 的元素，松散比较去重并重排键。
     *
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function forget($key, $value)
    {
        if (!defined('DOU_ID')) {
            return;
        }

        $arr = $this->arr($key);
        if (empty($arr)) {
            return;
        }
        $arr = array_values(array_diff($arr, array($value)));
        $this->set($key, $arr);
    }

    /**
     * 写入一次性 flash 消息（与 PRG 模式 + Controller 端 RedirectResponse::with() 配套使用）。
     *
     * 底层落在 $_SESSION[DOU_ID]['_flash'][$key]，下一次 {@see getFlash()} 或 {@see pullAllFlashes()} 读取后立刻清除。
     *
     * @param string $key flash 槽位名（约定：success / error / info / warning）
     * @param mixed $value 简单 string 文案；或 WordPress notice 风结构
     *                     `array('message' => string, 'back_url' => string, 'back_text' => string)`，
     *                     模板渲染时由 admin BaseController::normalizeFlashes() / normalizeOne() 归一化为统一 array
     * @return void
     */
    public function setFlash($key, $value)
    {
        $this->set('_flash', $value, (string) $key);
    }

    /**
     * 读取并清除一次性 flash 消息（单槽对偶；layout 全量渲染请用 {@see pullAllFlashes()}）。
     *
     * 业务侧按 type 精准取某一槽位时使用；admin layout 全量渲染请用 {@see pullAllFlashes()}。
     *
     * 返回值与 {@see setFlash()} 写入的 $value 形态一致：string 或 array（含 message/back_url/back_text）。
     *
     * @param string $key flash 槽位名
     * @param mixed $default 槽位不存在时的默认值
     * @return mixed
     */
    public function getFlash($key, $default = '')
    {
        if (!$this->has('_flash', (string) $key)) {
            return $default;
        }
        $value = $this->get('_flash', $default, (string) $key);
        $this->del('_flash', (string) $key);
        return $value;
    }

    /**
     * 读取并清除全部 flash 槽位。
     *
     * 与 {@see getFlash()} 单槽对偶；用于 layout 渲染时一次性取出
     * `_flash` 容器下所有 type（success / error / info / warning / ...），
     * 调用后整个 `_flash` 命名空间被清空，保证"读一次就没了"的语义。
     *
     * 返回值键序遵循 PHP array 插入序（即 setFlash 写入顺序），
     * 模板侧 foreach 渲染顺序与此一致。
     *
     * @return array<string,mixed> 键为 flash type，值与 setFlash() 写入的 $value 形态一致
     */
    public function pullAllFlashes()
    {
        $all = $this->arr('_flash');
        $this->del('_flash');
        return $all;
    }
}

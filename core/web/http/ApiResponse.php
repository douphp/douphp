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

namespace Dou\Core\Web\Http;

use Dou\Core\Foundation\Api\ApiCodes;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * API 错误返回规范的统一响应工厂
 *
 * 输出结构：
 *   {
 *     "code":       字符串业务码（成功固定 'OK'）
 *     "message":    人类可读提示文案（客户端不得依赖于此分支判断）
 *     "data":       业务数据载体；失败时固定为 {}
 *     "errors":     字段错误集合；无字段错误固定为 {}
 *     "request_id": 请求追踪 ID
 *   }
 */
class ApiResponse
{
    /** @var string|null 当前请求的追踪 ID（同一请求复用） */
    private static $requestId = null;

    /**
     * 获取当前请求的 request_id（首次调用时生成并缓存）。
     *
     * @return string
     */
    public static function requestId()
    {
        if (self::$requestId === null) {
            self::$requestId = self::generateRequestId();
        }

        return self::$requestId;
    }

    /**
     * 设置当前请求的 request_id（一般由入口层或上游链路注入）。
     *
     * @param string $id
     * @return void
     */
    public static function setRequestId($id)
    {
        self::$requestId = (string) $id;
    }

    /**
     * 构造标准响应数据数组（不直接发送）。
     *
     * @param string $code 业务码
     * @param string $message 提示文案
     * @param mixed $data 业务数据（数组/对象/标量；空数组转 {}）
     * @param mixed $errors 字段错误集合；空数组转 {}
     * @return array
     */
    public static function payload($code, $message = '', $data = null, $errors = null)
    {
        return array(
            'code' => (string) $code,
            'message' => (string) $message,
            'data' => self::normalizeObjectLike($data),
            'errors' => self::normalizeObjectLike($errors),
            'request_id' => self::requestId(),
        );
    }

    /**
     * 构造成功响应（HTTP 200，code=OK）。
     *
     * @param array $data
     * @param string $message
     * @return JsonResponse
     */
    public static function success(array $data = array(), $message = '')
    {
        return new JsonResponse(self::payload(ApiCodes::OK, $message, $data, array()), 200);
    }

    /**
     * 构造业务失败响应。
     *
     * `errors` 与 `data` 语义区分：
     *   - errors：字段级校验错误集合，键名 = 字段名，值 = 错误描述；
     *   - data：业务失败时仍需要回传给前端的辅助数据（如登录失败时的 jump_url、
     *     额度不足时的当前配额详情、提交失败时的最新片段 HTML 等）。
     *
     * @param string $code 业务码（如 INVALID_PARAMS）
     * @param string $message 提示文案
     * @param array $errors 字段错误集合
     * @param int $httpStatus HTTP 状态码（默认 422）
     * @param array $data 失败时附带的业务数据（默认空）
     * @return JsonResponse
     */
    public static function error($code, $message = '', array $errors = array(), $httpStatus = 422, array $data = array())
    {
        return new JsonResponse(self::payload($code, $message, $data, $errors), (int) $httpStatus);
    }

    /**
     * 抛出携带标准成功响应的 HttpResponseException（短路返回）。
     *
     * @param array $data
     * @param string $message
     * @return never
     * @throws HttpResponseException
     */
    public static function throwSuccess(array $data = array(), $message = '')
    {
        throw new HttpResponseException(self::success($data, $message));
    }

    /**
     * 抛出携带标准失败响应的 HttpResponseException（短路返回）。
     *
     * @param string $code
     * @param string $message
     * @param array $errors
     * @param int $httpStatus
     * @param array $data 失败时附带的业务数据（默认空），透传至 {@see error()}
     * @return never
     * @throws HttpResponseException
     */
    public static function throwError($code, $message = '', array $errors = array(), $httpStatus = 422, array $data = array())
    {
        throw new HttpResponseException(self::error($code, $message, $errors, $httpStatus, $data));
    }

    /**
     * 将空数组转换为 stdClass，避免 json_encode 把 data/errors 渲染成 []。
     *
     * @param mixed $value
     * @return mixed
     */
    private static function normalizeObjectLike($value)
    {
        if ($value === null) {
            return new \stdClass();
        }
        if (is_array($value) && empty($value)) {
            return new \stdClass();
        }

        return $value;
    }

    /**
     * 生成 request_id（PHP 5.6+ 兼容；优先使用随机源）。
     *
     * @return string
     */
    private static function generateRequestId()
    {
        if (function_exists('random_bytes')) {
            try {
                return bin2hex(random_bytes(8));
            } catch (\Exception $e) {
                // fallback below
            }
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = openssl_random_pseudo_bytes(8);
            if ($bytes !== false) {
                return bin2hex($bytes);
            }
        }

        return substr(sha1(uniqid('', true) . mt_rand()), 0, 16);
    }
}

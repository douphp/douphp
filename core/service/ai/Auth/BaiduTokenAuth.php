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

namespace Dou\Core\Service\Ai\Auth;

use Dou\Core\Facade\DB;
use Dou\Core\Service\Ai\CredentialCipher;
use Dou\Core\Service\BaseService;
use Dou\Core\Web\Http\Client;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 百度文心 access_token 换发与缓存。
 *
 * 落位约定（见 plan.md 5.2）：
 * - ai_key.api_key         存 client_id（百度 API Key）
 * - ai_key.config.client_secret  存 client_secret（百度 Secret Key）
 * - ai_key.config.access_token / token_expires_at 存换发后的 token 缓存
 *
 * token 有效期 30 天，提前 5 分钟视为过期并重新换发；
 * 缓存与换发均只依赖数据库 + 普通 HTTPS 短请求，虚拟主机可用。
 */
class BaiduTokenAuth extends BaseService
{
    /** @var string access_token 换发端点 */
    const TOKEN_URL = 'https://aip.baidubce.com/oauth/2.0/token';

    /** @var int 提前过期窗口（秒），避免临界时刻用已失效 token */
    const EXPIRE_WINDOW_SECONDS = 300;

    /**
     * 取可用 access_token：缓存命中直接用，未命中/过期则换发并回写缓存。
     *
     * @param array $config resolveConfig 产物（api_key=client_id）
     * @param int $keyId ai_key 主键
     * @return string|null 失败返回 null（未配置 client_secret / 换发失败）
     */
    public function getToken(array $config, $keyId)
    {
        $keyId = (int) $keyId;
        if ($keyId <= 0) {
            return null;
        }

        $key = DB::table('ai_key')->where('id', $keyId)->find();
        if (!$key) {
            return null;
        }

        $cipher = new CredentialCipher();
        $configRaw = !empty($key['config']) ? $cipher->decrypt((string) $key['config']) : '';
        $keyConfig = $configRaw !== '' ? json_decode($configRaw, true) : array();
        if (!is_array($keyConfig)) {
            $keyConfig = array();
        }

        // 缓存命中且未过期
        if (!empty($keyConfig['access_token'])
            && !empty($keyConfig['token_expires_at'])
            && strtotime((string) $keyConfig['token_expires_at']) > time() + self::EXPIRE_WINDOW_SECONDS) {
            return (string) $keyConfig['access_token'];
        }

        // 换发（client_secret 未配置则失败）
        $clientSecret = isset($keyConfig['client_secret']) ? trim((string) $keyConfig['client_secret']) : '';
        $clientId = trim((string) $config['api_key']);
        if ($clientSecret === '' || $clientId === '') {
            return null;
        }

        $url = self::TOKEN_URL
            . '?grant_type=client_credentials'
            . '&client_id=' . rawurlencode($clientId)
            . '&client_secret=' . rawurlencode($clientSecret);

        $result = Client::request('POST', $url, array(), array(), array(
            'timeout' => 30,
            'return_meta' => true,
        ));

        if (!is_array($result) || (int) $result['http_code'] !== 200) {
            return null;
        }

        $decoded = json_decode(isset($result['body']) ? $result['body'] : '', true);
        if (!is_array($decoded) || empty($decoded['access_token'])) {
            return null;
        }

        $accessToken = (string) $decoded['access_token'];
        $expiresIn = isset($decoded['expires_in']) ? (int) $decoded['expires_in'] : 0;

        // 回写前重读最新 config 再合并 token 字段，避免覆盖换发期间管理员刚保存的 client_secret 等变更
        $fresh = DB::table('ai_key')->where('id', $keyId)->find();
        $configRaw = $fresh && !empty($fresh['config']) ? $cipher->decrypt((string) $fresh['config']) : '';
        $keyConfig = $configRaw !== '' ? json_decode($configRaw, true) : array();
        if (!is_array($keyConfig)) {
            $keyConfig = array();
        }
        $keyConfig['access_token'] = $accessToken;
        $keyConfig['token_expires_at'] = date('Y-m-d H:i:s', time() + ($expiresIn > 0 ? $expiresIn : 30 * 86400));

        // 回写缓存（仅更新 config，不动熔断等字段）
        DB::table('ai_key')
            ->where('id', $keyId)
            ->data(array(
                'config' => $cipher->encryptForStorage(json_encode($keyConfig, JSON_UNESCAPED_UNICODE), 65535),
            ))
            ->update();

        return $accessToken;
    }
}

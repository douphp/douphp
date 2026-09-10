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
 * Release Date: 2026-09-09
 */

namespace Dou\Core\Service\Ai;

use Dou\Core\Facade\DB;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 凭据存储加密器。
 */
class CredentialCipher
{
    const PREFIX = 'enc:v1:';

    const CREDENTIAL_COLUMN_BYTES = 16777215;

    /** @var string */
    private $key;

    /** @var string|null */
    private $legacyKey;

    /**
     * @param string|null $secret 空时使用 DOU_APP_KEY，缺省回退 DOU_SHELL
     */
    public function __construct($secret = null)
    {
        $useSite = ($secret === null);
        $secret = $useSite ? self::siteSecret() : (string) $secret;
        $this->key = self::deriveKey($secret);
        $this->legacyKey = null;
        if ($useSite) {
            $legacySecret = self::legacySecret();
            if ($legacySecret !== '') {
                $this->legacyKey = self::deriveKey($legacySecret);
            }
        }
    }

    /**
     * 将库内 AI 凭据从明文或旧密钥密文迁到当前 DOU_APP_KEY。
     *
     * @return void
     */
    public static function rewrapStoredCredentials()
    {
        self::ensureLegacyShellConstant();

        if (!DB::tableExist('ai_key')) {
            return;
        }

        $cipher = new self();
        foreach ((array) DB::table('ai_key')->field('id, api_key, config')->select() as $row) {
            $update = array();
            foreach (array('api_key', 'config') as $field) {
                $value = isset($row[$field]) ? (string) $row[$field] : '';
                if ($value === '') {
                    continue;
                }
                try {
                    $next = $cipher->rewrapIfNeeded($value, self::CREDENTIAL_COLUMN_BYTES);
                } catch (\RuntimeException $e) {
                    continue;
                }
                if ($next !== $value) {
                    $update[$field] = $next;
                }
            }
            if ($update) {
                DB::table('ai_key')->where('id', (int) $row['id'])->data($update)->update();
            }
        }
    }

    /**
     * @param string $plaintext
     * @return string
     */
    public function encrypt($plaintext)
    {
        $plaintext = (string) $plaintext;
        if ($plaintext === '') {
            return $plaintext;
        }
        if ($this->isEncryptedWithKey($plaintext, $this->key)) {
            return $plaintext;
        }
        if ($this->legacyKey && $this->isEncryptedWithKey($plaintext, $this->legacyKey)) {
            $plaintext = $this->decryptWithKey($plaintext, $this->legacyKey);
        }
        if (!function_exists('openssl_encrypt')) {
            throw new \RuntimeException('OpenSSL extension is required for AI credential encryption');
        }

        $iv = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt($plaintext, 'AES-256-CBC', $this->key, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new \RuntimeException('Unable to encrypt AI credential');
        }
        $mac = hash_hmac('sha256', $iv . $ciphertext, $this->key, true);

        return self::PREFIX . base64_encode($iv . $mac . $ciphertext);
    }

    /**
     * @param string $plaintext
     * @param int $maxBytes
     * @return string
     */
    public function encryptForStorage($plaintext, $maxBytes)
    {
        $encrypted = $this->encrypt($plaintext);
        if (strlen($encrypted) > (int) $maxBytes) {
            throw new \RuntimeException('Encrypted AI credential exceeds database column capacity');
        }

        return $encrypted;
    }

    /**
     * 明文加密，或把旧密钥密文重包为当前密钥。已是当前密钥则原样返回。
     *
     * @param string $stored
     * @param int $maxBytes
     * @return string
     */
    public function rewrapIfNeeded($stored, $maxBytes)
    {
        $stored = (string) $stored;
        if ($stored === '') {
            return $stored;
        }
        if ($this->isEncryptedWithKey($stored, $this->key)) {
            return $stored;
        }

        $plain = $this->decrypt($stored);

        return $this->encryptForStorage($plain, $maxBytes);
    }

    /**
     * @param string $stored
     * @return bool
     */
    public function isEncrypted($stored)
    {
        if ($this->isEncryptedWithKey($stored, $this->key)) {
            return true;
        }

        return $this->legacyKey ? $this->isEncryptedWithKey($stored, $this->legacyKey) : false;
    }

    /**
     * 兼容读取尚未迁移的明文凭据，以及仍由 DOU_SHELL 加密的密文。
     *
     * @param string $stored
     * @return string
     */
    public function decrypt($stored)
    {
        $stored = (string) $stored;
        $parts = $this->parsePayload($stored);
        if ($parts === null) {
            return $stored;
        }
        if ($this->macMatches($parts, $this->key)) {
            return $this->opensslDecrypt($parts, $this->key);
        }
        if ($this->legacyKey && $this->macMatches($parts, $this->legacyKey)) {
            return $this->opensslDecrypt($parts, $this->legacyKey);
        }

        throw new \RuntimeException('Invalid encrypted AI credential');
    }

    /**
     * @return string
     */
    private static function siteSecret()
    {
        $secret = self::definedSecret('DOU_APP_KEY');
        if ($secret === '') {
            $secret = self::definedSecret('DOU_SHELL');
        }
        if ($secret === '') {
            throw new \RuntimeException('Site app_key is required for AI credential encryption');
        }

        return $secret;
    }

    /**
     * upgrade/ 不走 InitTrait，进程里没有 DOU_SHELL；从 dou_config.hash_code 补上，否则旧密文无法重包。
     *
     * @return void
     */
    private static function ensureLegacyShellConstant()
    {
        if (defined('DOU_SHELL')) {
            return;
        }

        $hashCode = trim((string) DB::table('config')->where('name', 'hash_code')->value('value'));
        if ($hashCode !== '') {
            define('DOU_SHELL', $hashCode);
        }
    }

    /**
     * 仅当文件级密钥已存在、且与库内 hash_code 不同时，才把 DOU_SHELL 当作旧密钥。
     *
     * @return string
     */
    private static function legacySecret()
    {
        $primary = self::definedSecret('DOU_APP_KEY');
        $legacy = self::definedSecret('DOU_SHELL');
        if ($primary === '' || $legacy === '' || $legacy === $primary) {
            return '';
        }

        return $legacy;
    }

    /**
     * @param string $name
     * @return string
     */
    private static function definedSecret($name)
    {
        if (!defined($name)) {
            return '';
        }

        return trim((string) constant($name));
    }

    /**
     * @param string $secret
     * @return string
     */
    private static function deriveKey($secret)
    {
        return hash('sha256', 'douphp-ai-credential|' . $secret, true);
    }

    /**
     * @param string $stored
     * @param string $key
     * @return bool
     */
    private function isEncryptedWithKey($stored, $key)
    {
        $parts = $this->parsePayload($stored);
        if ($parts === null) {
            return false;
        }

        return $this->macMatches($parts, $key);
    }

    /**
     * @param string $stored
     * @param string $key
     * @return string
     */
    private function decryptWithKey($stored, $key)
    {
        $parts = $this->parsePayload($stored);
        if ($parts === null || !$this->macMatches($parts, $key)) {
            throw new \RuntimeException('Invalid encrypted AI credential');
        }

        return $this->opensslDecrypt($parts, $key);
    }

    /**
     * @param string $stored
     * @return array|null
     */
    private function parsePayload($stored)
    {
        $stored = (string) $stored;
        if (strpos($stored, self::PREFIX) !== 0) {
            return null;
        }
        $payload = base64_decode(substr($stored, strlen(self::PREFIX)), true);
        if ($payload === false || strlen($payload) < 49) {
            return null;
        }

        return array(
            'iv' => substr($payload, 0, 16),
            'mac' => substr($payload, 16, 32),
            'ciphertext' => substr($payload, 48),
        );
    }

    /**
     * @param array $parts
     * @param string $key
     * @return bool
     */
    private function macMatches(array $parts, $key)
    {
        $expected = hash_hmac('sha256', $parts['iv'] . $parts['ciphertext'], $key, true);

        return hash_equals($expected, $parts['mac']);
    }

    /**
     * @param array $parts
     * @param string $key
     * @return string
     */
    private function opensslDecrypt(array $parts, $key)
    {
        $plaintext = openssl_decrypt($parts['ciphertext'], 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $parts['iv']);
        if ($plaintext === false) {
            throw new \RuntimeException('Unable to decrypt AI credential');
        }

        return $plaintext;
    }
}

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

namespace Dou\Admin\Model\Ai;

use Dou\Core\Facade\DB;
use Dou\Core\Orm\Builder;
use Dou\Core\Orm\Model;
use Dou\Core\Service\Ai\CredentialCipher;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * AI 密钥表 ai_key
 */
class AiKey extends Model
{
    protected $table = 'ai_key';

    protected $primary = 'id';

    /** @var array */
    protected $casts = array(
        'id' => 'int',
        'provider_id' => 'int',
    );

    protected $fillable = array(
        'provider_id',
        'api_key',
        'alias',
        'expires_at',
        'config',
    );

    /**
     * @param mixed $value
     * @return string
     */
    protected function setApiKeyAttribute($value)
    {
        return (new CredentialCipher())->encryptForStorage((string) $value, 16777215);
    }

    /**
     * @param mixed $value
     * @return string
     */
    protected function getApiKeyAttribute($value)
    {
        return (new CredentialCipher())->decrypt((string) $value);
    }

    /**
     * @param mixed $value
     * @return string
     */
    protected function setConfigAttribute($value)
    {
        return (new CredentialCipher())->encryptForStorage((string) $value, 16777215);
    }

    /**
     * @param mixed $value
     * @return string
     */
    protected function getConfigAttribute($value)
    {
        return (new CredentialCipher())->decrypt((string) $value);
    }

    /**
     * 按别名关键字模糊筛选；空字符串透传。
     *
     * @param Builder $query
     * @param mixed $keyword
     * @return Builder
     */
    public function scopeFilterByKeyword(Builder $query, $keyword)
    {
        $keyword = is_scalar($keyword) ? trim((string) $keyword) : '';
        if ($keyword === '') {
            return $query;
        }

        return $query->where('alias', 'LIKE', '%' . $keyword . '%');
    }

    /**
     * 按 provider_id 筛选；<=0 透传。
     *
     * @param Builder $query
     * @param mixed $providerId
     * @return Builder
     */
    public function scopeFilterByProviderId(Builder $query, $providerId)
    {
        $providerId = (int) $providerId;
        if ($providerId <= 0) {
            return $query;
        }

        return $query->where('provider_id', $providerId);
    }

    /**
     * 默认列表排序（id DESC）。
     *
     * @param Builder $query
     * @return Builder
     */
    public function scopeApplyDefaultOrder(Builder $query)
    {
        return $query->order('id DESC');
    }

    /**
     * @param int $keyId
     * @return int
     */
    public static function countUsageLogsForKey($keyId)
    {
        return DB::table('ai_log')->where('key_id', (int) $keyId)->count();
    }

    /**
     * @param int $keyId
     * @return int
     */
    public static function countChatSessionsForKey($keyId)
    {
        return DB::table('chat_session')->where('key_id', $keyId)->count();
    }

    /**
     * @param mixed $id
     * @return string
     */
    public static function getAliasOrFallback($id)
    {
        $alias = DB::table(static::tableName())->where('id', (int) $id)->value('alias');

        return $alias ? $alias : sprintf(lang('ai_key_id_fallback'), (int) $id);
    }
}

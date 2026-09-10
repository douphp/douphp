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

namespace Dou\Core\Infra\Security;

use Dou\Core\Facade\DB;
use Dou\Core\Facade\Session;
use Dou\Core\Foundation\Configuration\Config;
use Dou\Core\Support\Check;
use Dou\Core\Support\Str;
use Dou\Vendor\Mail\Mail;
use Dou\Vendor\Sms\Sms;
use Dou\Vendor\Sms\Src\Alisms;

if (!defined('IN_DOUCO')) {
    die('Hacking attempt');
}

/**
 * 验证码生成与校验工具：
 * - 图形码：纯 GD 内置位图字体 + 逐字符旋转 + 正弦波形变 + 强随机 + HMAC 存储 + 一次性消费。
 * - 短信 / 邮件验证码：仍走 sendCaptcha + verification session。
 *
 * 业务侧统一调用 {@see verify()} 作单点校验入口（HMAC + TTL + 最小年龄 + 一次性消费）。
 */
class Captcha
{
    /** @var Alisms|null 懒加载的短信客户端实例 */
    protected $sms;

    /**
     * 字符集：去掉 0/O/1/I/L/S/5/Z 等视觉混淆字符，避免人眼误读。
     *
     * @var string
     */
    private static $charset = '23456789ABCDEFGHJKLMNPQRTUVWXY';

    /**
     * 懒加载短信客户端：仅在 core/library/sms 存在时返回实例，否则返回 null。
     *
     * @return Alisms|null
     */
    private function getSms()
    {
        if ($this->sms !== null) {
            return $this->sms;
        }
        if (!file_exists(LIBRARY_PATH . 'sms/Sms.php')) {
            return null;
        }
        $factory = new Sms();
        $this->sms = $factory->createClient();
        return $this->sms;
    }

    /**
     * 生成图形验证码并直接输出 PNG。
     *
     * 字体渲染：GD 内置位图字体 6（最大字号），逐字符 ±3° 微旋转，水平 + 垂直居中。
     * 「绝对不模糊」靠两点保证：
     * - 不放大（不调 imagecopyresized / imagecopyresampled），保持位图笔画原生锐利。
     * - 旋转幅度极小（±3°），imagerotate 抗锯齿模糊量在 1px 级以内，肉眼难察。
     *
     * 干扰：5 条 imagearc 弧线 + 50 像素椒盐噪点（叠加在字符之后，构成笔画穿插效果）。
     *
     * OCR 抗性主要靠应用层：HMAC 单次消费、TTL、最小提交间隔、蜜罐、限频中间件。
     *
     * @param int $captcha_width
     * @param int $captcha_height
     * @return bool
     */
    public function createCaptcha($captcha_width = 80, $captcha_height = 30)
    {
        $captcha_width = max(80, (int) $captcha_width);
        $captcha_height = max(30, (int) $captcha_height);

        $word = $this->createRandWord(4);

        $secret = self::secret();
        Session::set('captcha', hash_hmac('sha256', $word, $secret));
        Session::set('captcha_time', time());

        $im = imagecreatetruecolor($captcha_width, $captcha_height);
        $bg_color = imagecolorallocate($im, 235, 236, 237);
        imagefilledrectangle($im, 0, 0, $captcha_width, $captcha_height, $bg_color);
        $border_color = imagecolorallocate($im, 118, 151, 199);
        imagerectangle($im, 0, 0, $captcha_width - 1, $captcha_height - 1, $border_color);

        $charCount = strlen($word);
        $fontSize = 6;
        $charW = imagefontwidth($fontSize);
        $charH = imagefontheight($fontSize);
        $spacing = 2;
        $totalW = $charCount * $charW + ($charCount - 1) * $spacing;
        $startX = (int) (($captcha_width - $totalW) / 2);
        $baseY = (int) (($captcha_height - $charH) / 2);
        $hasRotate = function_exists('imagerotate');

        for ($i = 0; $i < $charCount; $i++) {
            $ch = substr($word, $i, 1);
            $cx = $startX + $i * ($charW + $spacing);
            $angle = mt_rand(-3, 3);

            if ($angle === 0 || !$hasRotate) {
                $tc = imagecolorallocate($im, mt_rand(0, 200), mt_rand(0, 120), mt_rand(0, 120));
                imagestring($im, $fontSize, $cx, $baseY, $ch, $tc);
                continue;
            }

            $cellW = $charW + 4;
            $cellH = $charH + 4;
            $cell = imagecreatetruecolor($cellW, $cellH);
            $cellBg = imagecolorallocate($cell, 235, 236, 237);
            imagefilledrectangle($cell, 0, 0, $cellW, $cellH, $cellBg);
            $tc = imagecolorallocate($cell, mt_rand(0, 200), mt_rand(0, 120), mt_rand(0, 120));
            imagestring($cell, $fontSize, 2, 2, $ch, $tc);

            $rotated = imagerotate($cell, $angle, $cellBg);
            imagedestroy($cell);
            $rw = imagesx($rotated);
            $rh = imagesy($rotated);
            $dstX = $cx - (int) (($rw - $charW) / 2);
            $dstY = (int) (($captcha_height - $rh) / 2);
            $this->blendChar($im, $rotated, $dstX, $dstY, 220);
            imagedestroy($rotated);
        }

        for ($i = 0; $i < 5; $i++) {
            $rand_color = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagearc(
                $im,
                mt_rand(-$captcha_width, $captcha_width),
                mt_rand(-$captcha_height, $captcha_height),
                mt_rand(30, $captcha_width * 2),
                mt_rand(20, $captcha_height * 2),
                mt_rand(0, 360),
                mt_rand(0, 360),
                $rand_color
            );
        }
        for ($i = 0; $i < 50; $i++) {
            $rand_color = imagecolorallocate($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255));
            imagesetpixel($im, mt_rand(0, $captcha_width), mt_rand(0, $captcha_height), $rand_color);
        }

        if (!headers_sent()) {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
            header('Content-Type: image/png');
        }

        imagepng($im);
        if (PHP_VERSION_ID < 80000) {
            imagedestroy($im);
        }

        return true;
    }

    /**
     * 像素级粘贴：跳过近似背景色的像素，避免每个字符的旋转后矩形 bbox 互相覆盖、
     * 也避免遮蔽下层弧线 / 噪点。阈值默认 220：临近 (235,236,237) 的浅色像素一律视为背景跳过。
     *
     * @param resource|\GdImage $dst
     * @param resource|\GdImage $src
     * @param int $dstX
     * @param int $dstY
     * @param int $threshold 三通道下限，>= threshold 视为背景
     * @return void
     */
    private function blendChar($dst, $src, $dstX, $dstY, $threshold = 220)
    {
        $sw = imagesx($src);
        $sh = imagesy($src);
        $dw = imagesx($dst);
        $dh = imagesy($dst);
        for ($y = 0; $y < $sh; $y++) {
            $py = $dstY + $y;
            if ($py < 0 || $py >= $dh) {
                continue;
            }
            for ($x = 0; $x < $sw; $x++) {
                $px = $dstX + $x;
                if ($px < 0 || $px >= $dw) {
                    continue;
                }
                $rgb = imagecolorat($src, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                if ($r >= $threshold && $g >= $threshold && $b >= $threshold) {
                    continue;
                }
                imagesetpixel($dst, $px, $py, $rgb);
            }
        }
    }

    /**
     * 校验图形验证码：HMAC 比较 + TTL + 最小年龄 + 一次性消费。
     *
     * 设计要点：
     * - $stored 与 $expect 都是 64 位 sha256 hex；hash_equals 防侧信道。
     * - $minAge 默认 1 秒：拦截「图片刚下发就立刻提交」的脚本（人填验证码 ≥ 1 秒）。
     * - $consume = true：无论验证成功失败均清掉 session 内 captcha；防一码多用。
     * - $secret 取文件级 DOU_APP_KEY，缺省回退到 DOU_SHELL，确保未升级配置的主机也能跑。
     *
     * @param mixed $input 用户提交的验证码原值
     * @param int $ttl 有效期（秒），默认 300
     * @param int $minAge 最小提交间隔（秒），默认 1
     * @param bool $consume 验证后是否清掉 session
     * @return bool
     */
    public function verify($input, $ttl = 300, $minAge = 1, $consume = true)
    {
        $stored = (string) Session::get('captcha', '');
        $createdAt = (int) Session::get('captcha_time', 0);
        $now = time();
        $age = $now - $createdAt;

        $expired = $stored === '' || $createdAt <= 0 || $age > (int) $ttl;
        $tooFresh = $age < (int) $minAge;
        $matched = false;
        if (!$expired && !$tooFresh) {
            $clean = strtoupper(trim((string) $input));
            if (Check::captcha($clean)) {
                $expect = hash_hmac('sha256', $clean, self::secret());
                $matched = hash_equals($stored, $expect);
            }
        }

        if ($consume || !$matched) {
            Session::del('captcha');
            Session::del('captcha_time');
        }

        return $matched;
    }

    /**
     * HMAC 签名密钥：优先取 DOU_APP_KEY，回退到 DOU_SHELL（兼容未升级配置的主机）。
     *
     * @return string
     */
    private static function secret()
    {
        if (defined('DOU_APP_KEY')) {
            $key = trim((string) DOU_APP_KEY);
            if ($key !== '') {
                return $key;
            }
        }
        if (defined('DOU_SHELL')) {
            $key = trim((string) DOU_SHELL);
            if ($key !== '') {
                return $key;
            }
        }

        return 'douphp';
    }

    /**
     * 生成随机字符串，使用 self::$charset 字符集（去除视觉混淆字符）。
     *
     * @param int $length
     * @return string
     */
    public function createRandWord($length = 5)
    {
        $length = max(1, (int) $length);
        $chars = self::$charset;
        $max = strlen($chars) - 1;
        $word = '';
        for ($i = 0; $i < $length; $i++) {
            $word .= $chars[self::secureRandomInt(0, $max)];
        }
        return $word;
    }

    /**
     * 安全整数随机：random_bytes → openssl_random_pseudo_bytes → mt_rand 三级降级。
     *
     * 用 4 字节熵 + & 0x7FFFFFFF 取正整数，再对范围取模；模偏置在 32-bit 熵下可忽略。
     *
     * @param int $min
     * @param int $max
     * @return int
     */
    public static function secureRandomInt($min, $max)
    {
        $min = (int) $min;
        $max = (int) $max;
        if ($max <= $min) {
            return $min;
        }

        $bytes = self::secureRandomBytes(4);
        $unpack = unpack('Nint', $bytes);
        $rand = $unpack['int'] & 0x7FFFFFFF;
        $range = $max - $min + 1;
        return $min + ($rand % $range);
    }

    /**
     * 安全字节随机源：random_bytes → openssl_random_pseudo_bytes → mt_rand。
     *
     * @param int $n
     * @return string
     */
    public static function secureRandomBytes($n)
    {
        $n = max(1, (int) $n);
        if (function_exists('random_bytes')) {
            try {
                $b = random_bytes($n);
                if (is_string($b) && strlen($b) === $n) {
                    return $b;
                }
            } catch (\Exception $e) {
            }
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $b = openssl_random_pseudo_bytes($n, $strong);
            if ($strong && is_string($b) && strlen($b) === $n) {
                return $b;
            }
        }
        $bytes = '';
        for ($i = 0; $i < $n; $i++) {
            $bytes .= chr(mt_rand(0, 255));
        }
        return $bytes;
    }

    /**
     * 下发短信 / 邮件验证码。
     *
     * @param string $type sms|email
     * @param string $account
     * @param string $captcha_token 客户端持有的图形 token（可选；与 storageCaptchaToken 配对）
     * @param string $check 业务策略：no_allow_phone_exist 等
     * @param string $storageCaptchaToken API 流程中由调用方显式传入
     * @return array 含 code / msg / verification 等字段
     */
    public function sendCaptcha($type, $account, $captcha_token, $check, $storageCaptchaToken = '')
    {
        if ($this->tokenCheck($captcha_token, $storageCaptchaToken) != 'ok') {
            return array('code' => 'fail', 'msg' => lang('illegal'));
        }

        $code = Str::randomByType('number', 6);

        if ($type == 'sms') {
            $telphone = Check::telphone($account) ? $account : '';
            if ($telphone === '') {
                return array('code' => 'fail', 'msg' => lang('user_telphone_cue'));
            }
            if (DB::table('user')->where('telphone', $telphone)->find() && $check == 'no_allow_phone_exist') {
                return array('code' => 'fail', 'msg' => lang('user_telphone_exist'));
            }

            $sms = $this->getSms();
            if ($sms === null) {
                return array('code' => 'fail', 'msg' => lang('mail_send_wrong'));
            }
            $TemplateCode = array('code' => $code);
            $msg = $sms->sendSms($telphone, Config::get('param.sms_TemplateCode'), $TemplateCode);
            if ($msg != 'success') {
                return array('code' => 'fail', 'msg' => lang('mail_send_wrong'));
            }
        } else {
            $email = Check::email($account) ? $account : '';
            if ($email === '') {
                return array('code' => 'fail', 'msg' => lang('user_email_cue'));
            }
            if (DB::table('user')->where('email', $email)->find() && $check == 'no_allow_phone_exist') {
                return array('code' => 'fail', 'msg' => lang('user_email_exist'));
            }

            $subject = Config::get('site.site_name', '') . lang('user_email_check');
            $body = preg_replace('/u%/Ums', $account, lang('verification_body'));
            $body = preg_replace('/c%/Ums', $code, $body);
            $msg = Mail::sendMail($email, $subject, $body, lang('mail_altbody'));
            if ($msg != 'success') {
                return array('code' => 'fail', 'msg' => lang('mail_send_wrong'));
            }
        }

        if (defined('IS_API')) {
            return array(
                'code' => 'success',
                'msg' => 'success',
                'verification' => array(
                    'account' => $account,
                    'ontime' => time(),
                    'code' => md5($code . DOU_SHELL),
                ),
            );
        }

        $verid = (string) Session::get('verid', 'verification');
        Session::set($verid, $account, 'account');
        Session::set($verid, time(), 'ontime');
        Session::set($verid, md5($code . DOU_SHELL), 'code');

        return array('code' => 'success', 'msg' => 'success');
    }

    /**
     * 颁发图形 token：8 位 hex；写入 session 槽供后续 sendCaptcha 校验配对。
     *
     * @return string
     */
    public function tokenSet()
    {
        $token = substr(bin2hex(self::secureRandomBytes(8)), 0, 8);
        Session::set($this->tempId(), $token);
        return $token;
    }

    /**
     * 校验图形 token：会话内 token 等值即视为通过。
     *
     * @param string $captcha_token
     * @param string $storageCaptchaToken API 流程显式传入；非空时优先采用
     * @return string ok|wrong
     */
    public function tokenCheck($captcha_token, $storageCaptchaToken = '')
    {
        $temp_id = $this->tempId();
        $stored = $storageCaptchaToken !== ''
            ? (string) $storageCaptchaToken
            : (string) Session::get($temp_id, '');
        if ($stored !== '' && (string) $captcha_token === $stored) {
            return 'ok';
        }
        return 'wrong';
    }

    /**
     * 图形 token 在 session 内的存储 key（与 DOU_SHELL 关联，保持端隔离）。
     *
     * @return string
     */
    public function tempId()
    {
        return substr(md5('captcha' . DOU_SHELL), 0, 16);
    }

    /**
     * 验证码窗口期检测（短信 / 邮件验证码 ontime 字段）。
     *
     * @param string $type front|api
     * @param mixed $value
     * @param int $timeout
     * @return bool
     */
    public function ontime($type = 'front', $value = 'verification', $timeout = 300)
    {
        if ($type === 'front') {
            $ontime = (int) Session::get($value, 0, 'ontime');
        } elseif ($type === 'api') {
            $ontime = is_numeric($value) ? (int) $value : 0;
        } else {
            return false;
        }

        if ($ontime <= 0) {
            return false;
        }

        return (time() - $ontime) < (int) $timeout;
    }
}

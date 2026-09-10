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

define('IN_DOUCO', true);
define('IS_INSTALL', true);

require dirname(__FILE__) . '/init/php_version_gate.php';
douphp_require_min_php('5.6.0');

require dirname(__FILE__) . '/run.php';

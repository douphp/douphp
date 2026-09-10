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
(function (global) {
  "use strict";

  var bag = global.__douLang || {};
  global.__douLang = bag;

  function lang(key, fallback) {
    if (key != null && Object.prototype.hasOwnProperty.call(bag, key)) {
      return bag[key];
    }
    return fallback != null ? fallback : key;
  }

  global.lang = lang;
})(window);

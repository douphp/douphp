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

  function placeholderNames(pattern) {
    var names = [];
    var re = /\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]*)?\}/g;
    var match;
    while ((match = re.exec(pattern)) !== null) {
      if (names.indexOf(match[1]) === -1) {
        names.push(match[1]);
      }
    }
    return names;
  }

  function fillPattern(pattern, values) {
    return pattern.replace(/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]*)?\}/g, function (_full, name) {
      return values[name] !== undefined && values[name] !== null ? String(values[name]) : "";
    }).replace(/\/+/g, "/");
  }

  function appendQuery(url, query) {
    var result = url;
    var key;
    for (key in query) {
      if (!Object.prototype.hasOwnProperty.call(query, key)) {
        continue;
      }
      if (query[key] === null || query[key] === undefined) {
        continue;
      }
      var sep = result.indexOf("?") === -1 ? "?" : "&";
      result += sep + encodeURIComponent(key) + "=" + encodeURIComponent(String(query[key]));
    }
    return result;
  }

  function applyFrontLanguagePrefix(path, config) {
    var base = config.url || "";
    var langConfig = config.lang || {};
    var pack = langConfig.pack || "";

    if (!pack) {
      return base + path;
    }

    if (langConfig.mode === "rewrite_open") {
      var sign = langConfig.sign || "";
      return base + sign + "/" + path;
    }

    var glue = path.indexOf("?") === -1 ? "?" : "&";
    return base + path + glue + "lang=" + encodeURIComponent(pack);
  }

  /**
   * 生成命名路由 URL（对齐 PHP route() / JsRouteBuilder）。
   *
   * @param {string} name
   * @param {Object} [params]
   * @param {Object} [options] query / page
   * @returns {string}
   */
  function route(name, params, options) {
    var config = global.__douRouteConfig;
    var routes = global.__douRouteManifest;
    if (!config || !routes) {
      throw new Error("Route manifest is not initialized");
    }

    params = params || {};
    options = options || {};

    var pattern = routes[name];
    if (!pattern) {
      console.error("Unknown route: " + name);
      throw new Error("Unknown route: " + name);
    }

    var names = placeholderNames(pattern);
    var values = {};
    var i;
    for (i = 0; i < names.length; i++) {
      if (Object.prototype.hasOwnProperty.call(params, names[i])) {
        values[names[i]] = params[names[i]];
      }
    }

    var filled = fillPattern(pattern, values);
    var rewrite = !!config.rewrite;
    var inner = rewrite ? filled : "index.php?route=" + filled;
    var url;

    if (config.shell === "front") {
      url = applyFrontLanguagePrefix(inner, config);
    } else {
      url = (config.url || "") + inner;
    }

    var query = {};
    var key;
    for (key in params) {
      if (!Object.prototype.hasOwnProperty.call(params, key)) {
        continue;
      }
      if (params[key] === null || params[key] === undefined) {
        continue;
      }
      if (names.indexOf(key) !== -1) {
        continue;
      }
      query[key] = params[key];
    }
    if (options.query && typeof options.query === "object") {
      for (key in options.query) {
        if (Object.prototype.hasOwnProperty.call(options.query, key) && options.query[key] !== null) {
          query[key] = options.query[key];
        }
      }
    }
    if (options.page !== undefined && options.page !== null && String(options.page) !== "" && String(options.page) !== "0") {
      query.page = options.page;
    }

    return appendQuery(url, query);
  }

  global.route = route;
})(typeof window !== "undefined" ? window : this);

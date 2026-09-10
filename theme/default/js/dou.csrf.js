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

/**
 * CSRF 全局拦截器
 *
 * 从 <meta name="csrf-token"> 读取 token，对所有非幂等（POST/PUT/PATCH/DELETE）
 * AJAX 请求自动注入 X-CSRF-Token header；jQuery 与 fetch 两套通道同时覆盖。
 *
 * 端到端协议与 Laravel / Rails / Django 一致：模板单点 meta 暴露 token，
 * 客户端业务 AJAX 零感知；服务端 Request::csrfToken() body 优先 / header 回退，
 * 既兼容原生 form 与一次性 token dual-POST，也兜住所有纯 AJAX 调用。
 */
(function () {
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = meta ? meta.getAttribute('content') : '';
    if (!token) {
        return;
    }

    var safe = /^(GET|HEAD|OPTIONS|TRACE)$/i;

    if (window.jQuery) {
        jQuery.ajaxSetup({
            beforeSend: function (xhr, settings) {
                if (settings.crossDomain) {
                    return;
                }
                if (!safe.test(settings.type || 'GET')) {
                    xhr.setRequestHeader('X-CSRF-Token', token);
                }
            }
        });
    }

    if (window.fetch) {
        var orig = window.fetch;
        window.fetch = function (input, init) {
            init = init || {};
            var method = (init.method || (input && input.method) || 'GET').toUpperCase();
            if (!safe.test(method)) {
                var headers = new Headers(init.headers || {});
                if (!headers.has('X-CSRF-Token')) {
                    headers.set('X-CSRF-Token', token);
                }
                init.headers = headers;
            }
            return orig.call(this, input, init);
        };
    }
})();

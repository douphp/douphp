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
 * 后台侧栏「更新角标」静默刷新
 *
 * 暴露 window.DouUpdateBadge：
 *   - refresh(force)：AJAX 拉取 index/update_number，静默更新角标（失败不提示、不阻塞页面）；
 *     force=true 时带 force=1，跳过服务端 10 分钟节流强制刷新（升级完成后即时同步）。
 *   - apply(unum)：按计数直接更新 DOM，供 refresh 之外复用。
 *
 * 首页由 index.htm 在 DOM ready 后自动调用 refresh(false)；
 * cloud/install 页由 cloud.js 在 finalize 后调用 refresh(true)。
 */
(function ($) {
  "use strict";

  // $unum 键 → 侧栏 li[data-id] 映射（与 sidebar.tpl 现有角标位置一致）
  var BADGE_MAP = [
    { key: 'system', li: 'home' },
    { key: 'plugin', li: 'plugin' },
    { key: 'miniprogram', li: 'miniprogram' },
    { key: 'theme', li: 'theme' }
  ];

  function setBadge(liId, count) {
    var $em = $('#dou-sidebar li[data-id="' + liId + '"] > a > em').first();
    if (!$em.length) { return; }

    var n = parseInt(count, 10);
    if (isNaN(n) || n < 0) { n = 0; }

    var $badge = $em.children('.badge').first();
    if (n > 0) {
      if (!$badge.length) {
        $badge = $('<span class="badge"><span></span></span>').appendTo($em);
      }
      $badge.children('span').first().text(n);
      $badge.show();
    } else if ($badge.length) {
      $badge.remove();
    }
  }

  function apply(unum) {
    if (!unum || typeof unum !== 'object') { return; }
    for (var i = 0; i < BADGE_MAP.length; i++) {
      setBadge(BADGE_MAP[i].li, unum[BADGE_MAP[i].key]);
    }
  }

  function refresh(force) {
    var url;
    try {
      url = route('admin.index.update_number');
    } catch (e) {
      return;
    }
    $.ajax({
      url: url,
      type: 'GET',
      data: force ? { force: 1 } : {},
      dataType: 'json'
    }).done(function (resp) {
      var data = resp && resp.data ? resp.data : resp;
      if (!data || data.closed) { return; }
      apply(data.unum);
    }).fail(function () {
      // 静默失败：角标沿用服务端首屏渲染值，不打扰用户
    });
  }

  window.DouUpdateBadge = { refresh: refresh, apply: apply };
})(jQuery);

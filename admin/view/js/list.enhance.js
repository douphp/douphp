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
 * 后台列表增强：表头点击排序 + 单元格快速编辑。
 *
 * 本文件不感知任何具体模块：
 * - 表头：th[data-sort]
 * - 单元格：td.js-quick-edit[data-field][data-value]
 * - 编辑入口：单元格悬停显示的 .js-quick-edit-btn
 * - 提交地址：table[data-quick-edit-url]
 */
(function ($) {
  "use strict";

  function langText(key, fallback) {
    return typeof lang === "function" ? lang(key) : fallback;
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function searchParam(name) {
    if (typeof URLSearchParams !== "undefined") {
      return new URLSearchParams(window.location.search).get(name) || "";
    }
    var match = new RegExp("[?&]" + name + "=([^&]*)").exec(window.location.search);
    return match ? decodeURIComponent(match[1].replace(/\+/g, " ")) : "";
  }

  function sortState() {
    return {
      field: searchParam("sort"),
      order: searchParam("order") === "desc" ? "desc" : "asc",
    };
  }

  function markSortHeaders() {
    var state = sortState();
    if (!state.field) {
      return;
    }
    $("th[data-sort]").each(function () {
      var $th = $(this);
      if ($th.attr("data-sort") !== state.field) {
        return;
      }
      $th.addClass(state.order === "desc" ? "sort-desc" : "sort-asc");
      $th.attr("aria-sort", state.order === "desc" ? "descending" : "ascending");
    });
  }

  function quickEditInputType(field) {
    if (field === "stock" || field === "sort") {
      return ' type="number" min="0" step="1"';
    }
    if (field === "price") {
      return ' type="text" inputmode="decimal"';
    }
    return ' type="text"';
  }

  function buildSortUrl(field, order) {
    var search = window.location.search.replace(/^\?/, "");
    var params = search === "" ? [] : search.split("&");
    var kept = [];
    var i;
    for (i = 0; i < params.length; i++) {
      if (!params[i]) {
        continue;
      }
      var key = params[i].split("=")[0];
      if (key === "sort" || key === "order" || key === "page") {
        continue;
      }
      kept.push(params[i]);
    }
    kept.push("sort=" + encodeURIComponent(field));
    kept.push("order=" + encodeURIComponent(order));
    return window.location.pathname + "?" + kept.join("&") + window.location.hash;
  }

  $(document).on("click", "th[data-sort]", function (e) {
    if ($(e.target).closest("a, button, input, select, textarea").length) {
      return;
    }
    var field = $(this).attr("data-sort");
    if (!field) {
      return;
    }
    var state = sortState();
    var order = state.field === field && state.order === "asc" ? "desc" : "asc";
    window.location.href = buildSortUrl(field, order);
  });

  var quickEditBtnHtml =
    '<a href="javascript:;" class="js-quick-edit-btn" title="' +
    escapeHtml(langText("edit", "编辑")) +
    '"><i class="bi bi-pencil-square"></i></a>';

  function initQuickEditButtons() {
    $("td.js-quick-edit").each(function () {
      var $cell = $(this);
      if ($cell.children(".js-quick-edit-btn").length) {
        return;
      }
      $cell.append(quickEditBtnHtml);
    });
  }

  $(document).on("click", ".js-quick-edit-btn", function (e) {
    e.preventDefault();
    e.stopPropagation();

    var $cell = $(this).closest("td.js-quick-edit");
    var $row = $cell.closest("tr");
    var $table = $cell.closest("table");
    var url = $table.data("quick-edit-url");
    var field = $cell.attr("data-field");
    var id = $row.find('input[name="checkbox[]"]').val();

    if (!url || !field || !id) {
      return;
    }

    var value = $cell.attr("data-value");
    if (typeof value === "undefined") {
      value = $.trim($cell.text());
    }
    var label = $.trim($table.find('th[data-sort="' + field + '"]').first().text()) || field;

    douModal.open({
      title: langText("quick_edit", "快速编辑") + " - " + label,
      width: 460,
      placement: "center",
      bodyHtml:
        '<div class="form"><div class="form-item"><div class="form-label">' +
        escapeHtml(label) +
        '</div><div class="input-block"><input class="input js-quick-edit-input"' +
        quickEditInputType(field) +
        ' value="' +
        escapeHtml(value) +
        '" /></div></div></div>',
      submitText: langText("quick_edit_save", "保存"),
      onSubmit: function ($modal, done, fail) {
        var $input = $modal.find(".js-quick-edit-input").first();
        var nextValue = $.trim($input.val());

        if (nextValue === "") {
          dou.toast.error(langText("quick_edit_title_required", "内容不能为空"));
          return fail();
        }

        $.ajax({
          url: url,
          type: "POST",
          dataType: "json",
          data: { id: id, field: field, value: nextValue },
          success: function (res) {
            if (!res || res.code !== "OK") {
              dou.toast.error((res && res.message) || langText("quick_edit_failed", "保存失败"));
              return fail();
            }

            var data = res.data || {};
            var display = typeof data.display === "undefined" ? nextValue : data.display;
            var $btn = $cell.children(".js-quick-edit-btn").detach();

            if (field === "title" || field === "name") {
              $cell.find(".js-quick-text").text(display);
            } else {
              $cell.text(display);
            }
            $cell.append($btn);
            $cell.attr("data-value", typeof data.value === "undefined" ? nextValue : data.value);
            dou.toast.success(res.message || langText("quick_edit_success", "保存成功"));
            done();
          },
          error: function (xhr) {
            var message = langText("quick_edit_failed", "保存失败");
            if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
              message = xhr.responseJSON.message;
            }
            dou.toast.error(message);
            fail();
          },
        });
      },
    });
  });

  $(markSortHeaders);
  $(initQuickEditButtons);
})(jQuery);

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
 * Excel 导入弹窗（通用组件，基于 douModal）
 *
 * 声明式接入任意后台列表页，无需在页面里预置隐藏表单：
 *   <a href="javascript:;" class="btn-secondary"
 *      data-dou-excel-import
 *      data-url="{url link='admin.product.import_execl'}"
 *      data-title="{$lang.product_import}"
 *      data-message="{$lang.product_import_message}"
 *      data-cue="{$lang.product_import_cue}"
 *      data-hidden='{"product_id":"1"}'>{$lang.product_import}</a>
 *
 * 交互：点击按钮先弹出说明与文件域，点「开始导入」才提交表单；
 * 表单为原生 multipart POST，服务端处理完重定向回列表页闪出结果，
 * 因此不需要任何 AJAX 上传与响应解析逻辑。
 *
 * 说明：data-url / data-title / data-message / data-cue 均可省略。
 * data-message 与 data-cue 均渲染在文件域下方（前者在上），同为 cue 灰字：
 * 前者说明文件格式与总体规则，后者说明本模块的列顺序与不支持项；文案均由
 * 各模块按自身列顺序自行提供，组件不做假定。data-hidden 为可选 JSON，用于
 * 附加业务隐藏字段（如所属商品 ID）。
 */
(function ($, global) {
  "use strict";

  function text(key, fallback) {
    return typeof global.lang === "function" ? global.lang(key, fallback) : fallback;
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  /**
   * 取全局 CSRF token（与 dou.csrf.js 同源，均读 meta），
   * 原生表单提交不带请求头，必须以隐藏字段回传。
   */
  function csrfToken() {
    var meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute("content") || "" : "";
  }

  /**
   * 解析 data-hidden 的 JSON，异常时退化为空对象，避免整块弹窗失效。
   */
  function parseHidden(raw) {
    if (!raw) {
      return {};
    }
    try {
      var parsed = JSON.parse(raw);
      return parsed && typeof parsed === "object" ? parsed : {};
    } catch (e) {
      return {};
    }
  }

  function buildFormHtml($trigger) {
    var hidden = parseHidden($trigger.attr("data-hidden"));
    var html =
      '<form class="form excel-import-form" method="post" enctype="multipart/form-data" action="' +
      escapeHtml($trigger.attr("data-url") || "") +
      '">';

    html += '<input type="hidden" name="token" value="' + escapeHtml(csrfToken()) + '" />';
    for (var name in hidden) {
      if (Object.prototype.hasOwnProperty.call(hidden, name)) {
        html +=
          '<input type="hidden" name="' + escapeHtml(name) + '" value="' + escapeHtml(hidden[name]) + '" />';
      }
    }

    var message = $trigger.attr("data-message") || "";
    var cue = $trigger.attr("data-cue") || "";

    html +=
      '<div class="form-item">' +
      '<div class="form-label">' + escapeHtml(text("excel_import_file", "Excel 文件")) + "</div>" +
      '<div class="input-block">' +
      '<input type="file" name="file" class="input-file excel-import-file" accept=".xlsx,.xls" />' +
      (message ? '<p class="cue">' + escapeHtml(message) + "</p>" : "") +
      (cue ? '<p class="cue">' + escapeHtml(cue) + "</p>" : "") +
      "</div>" +
      "</div>";

    return html + "</form>";
  }

  function open($trigger) {
    if (!global.douModal) {
      return null;
    }

    return global.douModal.open({
      title: $trigger.attr("data-title") || text("excel_import", "从Excel导入"),
      // 与 AI 批量生成弹窗保持一致：右侧抽屉 + lg 宽度，点遮罩即关闭（默认行为）
      placement: "right",
      size: "lg",
      bodyHtml: buildFormHtml($trigger),
      // 文件导入不是「填完表单按回车」的交互，回车不触发「开始导入」
      noEnterSubmit: true,
      buttons: [
        {
          text: text("excel_import_submit", "开始导入"),
          className: "btn",
          onClick: function ($modal, done, fail, $btn) {
            var form = $modal.find("form.excel-import-form").get(0);
            var input = $modal.find(".excel-import-file").get(0);

            if (!input || !input.files || !input.files.length) {
              if (global.dou && global.dou.toast) {
                global.dou.toast.error(text("excel_import_no_file", "请选择要导入的 Excel 文件"));
              }
              return;
            }

            // 提交后页面整体跳转，锁定按钮避免重复提交造成重复导入
            $btn.addClass("disabled").text(text("excel_import_submitting", "正在导入…"));
            form.submit();
          }
        },
        {
          text: text("cancel", "取消"),
          className: "btn-secondary",
          onClick: function ($modal, done) {
            done();
          }
        }
      ]
    });
  }

  $(document).on("click", "[data-dou-excel-import]", function (e) {
    e.preventDefault();
    open($(this));
  });
})(window.jQuery, window);

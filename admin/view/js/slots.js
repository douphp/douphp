/**
 * DouPHP 后台 · 分区文本（slots）表单控件
 *
 * 由 inc/slots_field.tpl 引入使用，data / show 两个模块共用。
 * 「自由文本 / 分区文本」的切换由系统内置 Tab 组件负责，本脚本只管分区面板：
 * - 分区行增删、标识归一（去空白 + 小写 + 空格转下划线）
 * - 提交前校验（空标识 / 非法标识 / 重复标识，与服务端 Slots::fromRows 同规则）
 * - 按当前激活的 Tab 面板写回隐藏域 content_mode（text / slots）
 */
(function ($) {
  "use strict";

  var KEY_PATTERN = /^[a-z][a-z0-9_]{0,59}$/;

  function t(key, fallback) {
    return typeof lang === "function" ? lang(key, fallback) : fallback;
  }

  function escapeAttr(value) {
    return String(value === null || typeof value === "undefined" ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function notify(message) {
    if (window.dou && window.dou.toast && typeof window.dou.toast.error === "function") {
      window.dou.toast.error(message);
      return;
    }
    window.alert(message);
  }

  function rowsBox($pane) {
    return $pane.find("[data-slots-rows]").first();
  }

  function normalizeKey(value) {
    return String(value === null || typeof value === "undefined" ? "" : value)
      .replace(/^\s+|\s+$/g, "")
      .toLowerCase()
      .replace(/\s+/g, "_");
  }

  function rowHtml() {
    return '<div class="slots-row">' +
      '<input type="text" name="slot_key[]" value="" class="input slots-key" placeholder="' + escapeAttr(t("slots_key", "标识")) + '" />' +
      '<input type="text" name="slot_value[]" value="" class="input slots-value" placeholder="' + escapeAttr(t("slots_value", "内容")) + '" />' +
      '<button type="button" class="slots-remove bi-x" title="' + escapeAttr(t("slots_remove", "删除")) + '" tabindex="-1"></button>' +
      "</div>";
  }

  function addRow($pane, key, value) {
    var $row = $(rowHtml());
    $row.find(".slots-key").val(key || "");
    $row.find(".slots-value").val(value || "");
    rowsBox($pane).append($row);
    return $row;
  }

  function initPane($pane) {
    if (!rowsBox($pane).find(".slots-row").length) {
      addRow($pane, "", "");
    }
  }

  function init() {
    $("[data-slots-pane]").each(function () {
      initPane($(this));
    });
  }

  $(document).on("click", "[data-slots-pane] .slots-add", function () {
    var $pane = $(this).closest("[data-slots-pane]");
    if (rowsBox($pane).is("[data-slots-locked]")) {
      return;
    }
    var $row = addRow($pane, "", "");
    $row.find(".slots-key").trigger("focus");
  });

  $(document).on("click", "[data-slots-pane] .slots-remove", function () {
    if ($(this).closest("[data-slots-rows]").is("[data-slots-locked]")) {
      return;
    }
    $(this).closest(".slots-row").remove();
  });

  $(document).on("blur", "[data-slots-pane] .slots-key", function () {
    var $input = $(this);
    var key = normalizeKey($input.val());
    if (key !== String($input.val() || "")) {
      $input.val(key);
    }
  });

  $(document).on("submit", "form", function () {
    var $form = $(this);
    var $pane = $form.find("[data-slots-pane]").first();
    if (!$pane.length) {
      return true;
    }

    // 由当前激活的 Tab 面板决定内容形式，写回隐藏域供服务端排他保存
    var isSlots = $pane.hasClass("active");
    $form.find('input[name="content_mode"]').val(isSlots ? "slots" : "text");
    if (!isSlots) {
      return true;
    }

    var invalid = "";
    var seen = {};
    rowsBox($pane).find(".slots-row").each(function () {
      var $keyInput = $(this).find(".slots-key");
      var key = normalizeKey($keyInput.val());
      var value = String($(this).find(".slots-value").val() || "");
      // 提交值与校验值保持一致：回车提交不触发 blur，需在此写回归一结果
      if (key !== String($keyInput.val() || "")) {
        $keyInput.val(key);
      }
      if (key === "" && $.trim(value) === "") {
        return;
      }
      if (key === "") {
        invalid = t("slots_key_required", "存在未填写标识的分区，请补充标识或删除该行");
        return false;
      }
      if (!KEY_PATTERN.test(key)) {
        invalid = t("slots_key_invalid", "分区标识只能使用小写字母、数字和下划线，且以字母开头") + "：" + key;
        return false;
      }
      if (seen[key]) {
        invalid = t("slots_key_duplicated", "分区标识不可重复") + "：" + key;
        return false;
      }
      seen[key] = true;
    });

    if (invalid) {
      notify(invalid);
      return false;
    }
    return true;
  });

  $(init);

  // AJAX 局部刷新后可重调
  window.douSlots = { init: init };
})(jQuery);

/**
 +----------------------------------------------------------
 * 表单后台：字段面板类型联动 + 键名点击复制 + 键名自动生成
 *
 * 字段属性按类型显隐：可选项仅 select / radio / checkbox，上传限制仅 image / gallery / file，
 * 数量上限仅 gallery / file / checkbox（多选叫「最多可选数」，默认 0 不限）。切换类型时即时联动，
 * 编辑回填后初始化同步一次；被隐藏的属性块会同时 disabled，保证同名 limit 不会双值提交。
 *
 * 复制按钮带 data-copy（字段列表的 slug 等），点击后优先用 navigator.clipboard，旧浏览器回落
 * 隐藏 textarea + execCommand('copy')。
 *
 * 嵌入代码按钮带 data-code（表单列表的整段 HTML 示例），点击弹出模态框展示，
 * 可整段选中或一键复制到任意前台 .dwt 模板。
 *
 * 键名生成按钮带 data-slug-from / data-slug-to（选择器）：中文先转拼音，
 * 再归一为小写字母开头的 _ 分隔串，与后台表单校验规则 ^[a-z][a-z0-9_]{0,59}$ 一致。
 *
 * 校验规则预选项（.js-validate-rule + data-rule）平铺展示，点击后写入 #field-validate，
 * 光标落到规则参数上：带数字的（min:2 / max_value:1000）整段选中，直接键入即替换；
 * 再次点击取消该规则。
 *
 * 切换字段类型时，若 validate 为空或仍是上一类型的默认串，自动替换为新类型的默认串；
 * 用户手改过的内容不会被覆盖。
 +----------------------------------------------------------
 */
(function ($) {
  "use strict";

  // 上一次的字段类型，用于判断切换类型时能否安全替换默认规则
  var lastType = "";

  /**
   * 当前选中的字段类型（面板只有一个 type 控件）
   */
  function currentType() {
    var select = $('select[name="type"]');
    if (select.length) {
      return select.val() || '';
    }
    return $('input[name="type"]').val() || '';
  }

  /**
   * 按当前类型切换各属性块的显隐
   *
   * 隐藏块内的控件一并 disabled：上传类的「最多上传数」与多选的「最多可选数」同名
   * （都提交 limit），不禁用会互相覆盖；其余隐藏属性顺带清空，避免切换类型后残留旧值。
   */
  function sync() {
    var type = currentType();
    $('[data-form-for]').each(function () {
      var allowed = (this.getAttribute('data-form-for') || '').split(/\s+/);
      var visible = $.inArray(type, allowed) !== -1;
      $(this).toggle(visible);
      $(this).find('input, select, textarea').prop('disabled', !visible);
    });
    syncLimitDefault(type);
    syncDefaultRules();
  }

  /**
   * limit 的隐含默认值：多选 0（不限），上传类与其他类型 1
   */
  function limitDefault(type) {
    return type === "checkbox" ? "0" : "1";
  }

  /**
   * 切换类型时联动 limit 默认值
   *
   * 与 validate 默认规则同一策略：只有当前值等于上一类型的隐含默认（或为空）时才替换，
   * 用户手填过的数字保持不变；首次加载（lastType 为空）只记录基线不动输入框。
   */
  function syncLimitDefault(type) {
    if (lastType === "" || type === lastType) {
      return;
    }
    var input = $(".js-field-limit").filter(":visible");
    if (!input.length) {
      input = $(".js-field-limit");
    }
    if (!input.length) {
      return;
    }
    var value = $.trim(input.val() || "");
    if (value === "" || value === limitDefault(lastType)) {
      $(".js-field-limit").val(limitDefault(type));
    }
  }

  /**
   * 切换类型时把该类型的默认规则带入 #field-validate
   *
   * 只在「当前值为空」或「当前值等于上一类型的默认串」时替换，避免覆盖用户手写内容。
   * 首次调用（lastType 为空）只记录基线，不动输入框。
   */
  function syncDefaultRules() {
    var select = $('select[name="type"]');
    var input = $("#field-validate");
    if (!select.length || !input.length) {
      return;
    }
    var current = select.val() || "";
    if (lastType === "") {
      lastType = current;
      return;
    }
    if (current === lastType) {
      return;
    }
    var previous = "";
    var next = "";
    select.find("option").each(function () {
      if (this.value === lastType) {
        previous = this.getAttribute("data-default-rules") || "";
      }
      if (this.value === current) {
        next = this.getAttribute("data-default-rules") || "";
      }
    });
    var value = $.trim(input.val() || "");
    if (value === "" || value === $.trim(previous)) {
      input.val(next);
      syncValidateChips();
    }
    lastType = current;
  }

  /**
   * 名称归一为键名：中文转拼音，非字母数字合并为下划线，缺口补齐字母前缀
   */
  function toSlug(text) {
    var slug = (text || "").toLowerCase();
    if (window.Pinyin && window.Pinyin.isSupported() && /[\u4e00-\u9fa5]/.test(slug)) {
      slug = window.Pinyin.convertToPinyin(slug, "_", true);
    }
    slug = slug
      .replace(/[^a-z0-9]+/g, "_")
      .replace(/_+/g, "_")
      .replace(/^_+|_+$/g, "");
    if (!/^[a-z]/.test(slug)) {
      slug = "form_" + slug;
    }
    return slug.slice(0, 60);
  }

  /**
   * 复制文本：现代浏览器走异步剪贴板，其余走隐藏 textarea 方案
   */
  function copyText(text, done) {
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(text).then(done, function () {
        fallbackCopy(text, done);
      });
      return;
    }
    fallbackCopy(text, done);
  }

  /**
   * 兼容方案：临时 textarea + execCommand
   */
  function fallbackCopy(text, done) {
    var textarea = document.createElement("textarea");
    textarea.value = text;
    textarea.setAttribute("readonly", "readonly");
    textarea.style.position = "fixed";
    textarea.style.top = "-1000px";
    document.body.appendChild(textarea);
    textarea.select();
    try {
      document.execCommand("copy");
      if (typeof done === "function") {
        done();
      }
    } catch (e) {
      if (window.dou && dou.toast) {
        dou.toast.error(lang("operation_failed", "操作失败"));
      }
    }
    document.body.removeChild(textarea);
  }

  /**
   * 转义为可直接拼进 HTML 的文本（嵌入代码含 <textarea> 等标签，不转义会截断外层节点）
   */
  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  /**
   * 拆分校验规则串（半角 / 全角逗号分隔，去空）
   */
  function splitRules(value) {
    var rules = [];
    $.each(String(value || "").split(/[,，]/), function (i, item) {
      var rule = $.trim(item);
      if (rule) {
        rules.push(rule);
      }
    });
    return rules;
  }

  /**
   * 规则名：min:2 → min（同名规则互斥，后写入的覆盖前者）
   */
  function ruleName(rule) {
    return String(rule || "").split(":")[0];
  }

  /**
   * 按当前校验规则值刷新预选项高亮
   */
  function syncValidateChips() {
    var input = $("#field-validate");
    if (!input.length) {
      return;
    }
    var names = [];
    $.each(splitRules(input.val()), function (i, rule) {
      names.push(ruleName(rule));
    });
    $(".js-validate-rule").each(function () {
      var name = ruleName(this.getAttribute("data-rule") || "");
      $(this).toggleClass("active", $.inArray(name, names) !== -1);
    });
  }

  /**
   * 把光标落到刚写入的规则参数上，让用户直接替换预置值
   *
   * 带数字的（min:2 / max_value:1000）整段选中数字，键入即覆盖；
   * 无数字的（regex:// / same:）光标停在冒号后一位（正则的两个斜杠之间）。
   */
  function selectRuleParam(input, preset, joined) {
    var node = input.get(0);
    if (!node || typeof node.setSelectionRange !== "function") {
      return;
    }
    // 同规则再次点击是「取消」，此时 joined 里已无该规则，不做定位
    var start = joined.lastIndexOf(preset);
    if (start === -1) {
      return;
    }
    var paramStart = start + ruleName(preset).length + 1;
    var rest = joined.slice(paramStart);
    var comma = rest.indexOf(",");
    var param = comma === -1 ? rest : rest.slice(0, comma);
    var numStart = param.search(/[0-9]/);

    node.focus();
    if (numStart === -1) {
      node.setSelectionRange(paramStart + 1, paramStart + 1);
      return;
    }
    var numEnd = numStart;
    while (numEnd < param.length && /[0-9.]/.test(param.charAt(numEnd))) {
      numEnd++;
    }
    node.setSelectionRange(paramStart + numStart, paramStart + numEnd);
  }

  $(function () {
    if ($("[data-form-for]").length) {
      $(document).on("change", 'select[name="type"]', sync);
      sync();
    }

    if ($("#field-validate").length) {
      syncValidateChips();
      $(document).on("input change", "#field-validate", syncValidateChips);
    }

    $(document).on("click", ".js-validate-rule", function () {
      var input = $("#field-validate");
      if (!input.length) {
        return false;
      }
      var preset = this.getAttribute("data-rule") || "";
      if (!preset) {
        return false;
      }
      var name = ruleName(preset);
      var matched = false;
      var next = [];
      $.each(splitRules(input.val()), function (i, rule) {
        if (ruleName(rule) !== name) {
          next.push(rule);
          return;
        }
        matched = true;
        if (rule !== preset) {
          next.push(preset);
        }
      });
      if (!matched) {
        next.push(preset);
      }
      var joined = next.join(",");
      input.val(joined);
      syncValidateChips();
      selectRuleParam(input, preset, joined);
      return false;
    });

    $(document).on("click", ".js-slug", function () {
      var source = $($(this).attr("data-slug-from") || "");
      var target = $($(this).attr("data-slug-to") || "");
      if (!source.length || !target.length) {
        return false;
      }
      var value = $.trim(source.val() || "");
      if (!value) {
        return false;
      }
      if (/[\u4e00-\u9fa5]/.test(value) && (!window.Pinyin || !window.Pinyin.isSupported())) {
        alert(lang("pinyin_lib_missing", "拼音库未加载"));
        return false;
      }
      target.val(toSlug(value));
      return false;
    });

    $(document).on("click", ".js-copy", function () {
      var text = $(this).attr("data-copy") || "";
      if (!text) {
        return false;
      }
      copyText(text, function () {
        if (window.dou && dou.toast) {
          dou.toast.success(lang("copy_success", "复制成功！"));
        }
      });
      return false;
    });

    // 嵌入代码：把当前表单的原生 HTML 示例弹出来，供开发者粘到任意前台模板。
    // 代码块（含末尾的 script 调用行）放最上方便于整段选中，说明文字统一排到下方。
    $(document).on("click", ".js-embed", function () {
      var code = $(this).attr("data-code") || "";
      if (!code || !window.douModal) {
        return false;
      }
      var label = lang("form_embed", "嵌入代码");
      var name = $(this).attr("data-name") || "";
      var bodyHtml =
        '<textarea class="textarea embed-code" rows="12" readonly spellcheck="false">' +
        escapeHtml(code) +
        "</textarea>" +
        '<p class="cue">' + escapeHtml(lang("form_embed_cue", "")) + "</p>" +
        '<p class="cue">' + escapeHtml(lang("form_embed_js", "")) + "</p>";

      douModal.open({
        title: name ? label + " - " + name : label,
        bodyHtml: bodyHtml,
        size: "big",
        // 打开即全选，Ctrl+C 也能直接复制
        load: function ($modal) {
          var area = $modal.find(".embed-code").get(0);
          if (area && typeof area.select === "function") {
            area.focus();
            area.select();
          }
        },
        buttons: [
          {
            text: lang("form_embed_copy", "复制代码"),
            className: "btn",
            onClick: function () {
              copyText(code, function () {
                if (window.dou && dou.toast) {
                  dou.toast.success(lang("copy_success", "复制成功！"));
                }
              });
            },
          },
          {
            text: lang("close", "关闭"),
            className: "btn-secondary",
            onClick: function ($modal, done) {
              done();
            },
          },
        ],
      });
      return false;
    });
  });
})(jQuery);

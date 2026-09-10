/**
 * AI 模型表单：内嵌密钥与模型管理（行内可编辑组 + 随表单同步提交）
 *
 * - 创建/编辑时，密钥与模型以表格行式可编辑组内嵌于表单
 * - 点「添加密钥」追加一组空行（别名 / 密钥 / 过期 / 高级折叠）
 * - 点「添加模型」追加一组空行（模型名称 / 模型代码 / 高级折叠）
 * - 点「删除」：已有行禁用并隐藏 + 塞 keys_delete[]/models_delete[]；新增行直接移除
 * - 点「高级」在当前行下方展开折叠行（密钥：过期时间/扩展配置；模型：上下文/最大输出）
 * - 点「高级配置」展开/收起供应商扩展配置
 * - 点「重置」AJAX 即时重置失败计数（独立于表单提交）
 *
 * 密钥/模型字段随表单统一 POST，后端 ModelService 单事务处理增改删。
 *
 * CSRF 由 dou.csrf.js 全局注入 X-CSRF-Token header，无需手动传 token。
 */
(function () {
  "use strict";

  /** 新增行下标自增计数器（避免与已有 key.id / model.id 冲突） */
  var newIndex = 1;

  document.addEventListener("DOMContentLoaded", function () {
    bindAdvancedToggle();

    var addBtn = document.getElementById("ai-add-key-btn");
    if (addBtn) {
      addBtn.addEventListener("click", appendKeyRow);
    }

    // 创建页表格为空时自动追加 1 组空行（密钥必填）
    var table = document.getElementById("provider-key-table");
    if (table && !table.querySelector(".key-row")) {
      appendKeyRow();
    }

    var addModelBtn = document.getElementById("ai-add-model-btn");
    if (addModelBtn) {
      addModelBtn.addEventListener("click", appendModelRow);
    }
  });

  function lang(key, fallback) {
    return window.lang ? window.lang(key, fallback) : fallback;
  }

  /**
   * 切换「高级配置」（特殊配置 / 端点配置）显隐。
   * 初始开合由模板按编辑态是否有值决定，此处只处理点击。
   */
  function bindAdvancedToggle() {
    var toggle = document.getElementById("provider-advanced-toggle");
    var fields = document.getElementById("provider-advanced-fields");
    if (!toggle || !fields) {
      return;
    }
    toggle.addEventListener("click", function () {
      var open = fields.style.display !== "none";
      fields.style.display = open ? "none" : "";
      var icon = toggle.querySelector("i");
      if (icon) {
        icon.className = open ? "bi bi-chevron-right" : "bi bi-chevron-down";
      }
    });
  }

  /**
   * 追加一组空可编辑密钥行 + 高级折叠行。
   * 字段命名 keys[new_N][field]，后端识别无 id → 走 insert。
   */
  function appendKeyRow() {
    var table = document.getElementById("provider-key-table");
    if (!table) {
      return;
    }

    var idx = "new_" + (newIndex++);
    var advancedLabel = lang("ai_key_advanced_options", "高级选项");
    var delLabel = lang("del", "删除");
    var expiresLabel = lang("ai_key_expires_at", "过期时间");
    var expiresCue = lang("ai_key_expires_at_cue", "不填表示永久有效");
    var configLabel = lang("ai_key_config", "扩展配置");
    var configCue = lang("ai_key_config_cue", "JSON格式的扩展配置");

    var aliasPlaceholder = lang("ai_key_alias_cue", "仅用于区分多个KEY，无实际作用，可以随意输入");

    var row = document.createElement("tr");
    row.className = "key-row";
    row.setAttribute("data-key-id", "0");
    row.setAttribute("data-new", "1");
    row.innerHTML =
      '<td><input type="text" name="keys[' + idx + '][alias]" class="input" size="12" placeholder="' + aliasPlaceholder + '" /></td>' +
      '<td><input type="text" name="keys[' + idx + '][api_key]" class="input" size="34" /></td>' +
      '<td class="text-center">' +
        '<a href="javascript:;" class="js-key-advanced"><i class="bi bi-chevron-right"></i> ' + advancedLabel + '</a> | ' +
        '<a href="javascript:;" class="js-key-delete" data-id="0">' + delLabel + '</a>' +
      '</td>';

    var advRow = document.createElement("tr");
    advRow.className = "key-advanced-row";
    advRow.style.display = "none";
    advRow.innerHTML =
      '<td colspan="3">' +
        '<div class="key-advanced-fields">' +
          '<div class="key-adv-field">' +
            '<span class="key-adv-label">' + expiresLabel + '</span>' +
            '<input type="text" name="keys[' + idx + '][expires_at]" class="input" />' +
            '<small class="key-adv-cue">' + expiresCue + '</small>' +
          '</div>' +
          '<div class="key-adv-field">' +
            '<span class="key-adv-label">' + configLabel + '</span>' +
            '<input type="text" name="keys[' + idx + '][config]" class="input" />' +
            '<small class="key-adv-cue">' + configCue + '</small>' +
          '</div>' +
        '</div>' +
      '</td>';

    table.appendChild(row);
    table.appendChild(advRow);
  }

  /**
   * 追加一组空可编辑模型行 + 高级折叠行。
   * 字段命名 models[new_N][field]，后端识别无 id → 走 insert。
   */
  function appendModelRow() {
    var table = document.getElementById("provider-model-table");
    if (!table) {
      return;
    }

    var idx = "new_" + (newIndex++);
    var advancedLabel = lang("ai_key_advanced_options", "高级选项");
    var delLabel = lang("del", "删除");
    var contextLengthLabel = lang("ai_model_context_length", "上下文长度");
    var maxTokensLabel = lang("ai_model_max_tokens", "最大输出 token 数");

    var row = document.createElement("tr");
    row.className = "model-row";
    row.setAttribute("data-model-id", "0");
    row.setAttribute("data-new", "1");
    row.innerHTML =
      '<td><input type="text" name="models[' + idx + '][name]" class="input" size="20" /></td>' +
      '<td><input type="text" name="models[' + idx + '][model_code]" class="input" size="30" /></td>' +
      '<td class="text-center">' +
        '<a href="javascript:;" class="js-model-advanced"><i class="bi bi-chevron-right"></i> ' + advancedLabel + '</a> | ' +
        '<a href="javascript:;" class="js-model-delete" data-id="0">' + delLabel + '</a>' +
      '</td>';

    var advRow = document.createElement("tr");
    advRow.className = "model-advanced-row";
    advRow.style.display = "none";
    advRow.innerHTML =
      '<td colspan="3">' +
        '<div class="key-advanced-fields">' +
          '<div class="key-adv-field">' +
            '<span class="key-adv-label">' + contextLengthLabel + '</span>' +
            '<input type="text" name="models[' + idx + '][context_length]" class="input" />' +
          '</div>' +
          '<div class="key-adv-field">' +
            '<span class="key-adv-label">' + maxTokensLabel + '</span>' +
            '<input type="text" name="models[' + idx + '][max_tokens]" class="input" />' +
          '</div>' +
        '</div>' +
      '</td>';

    table.appendChild(row);
    table.appendChild(advRow);
  }

  /**
   * 事件委托：高级选项切换 / 删除 / 重置。
   */
  document.addEventListener("click", function (e) {
    var target = e.target.closest
      ? e.target.closest(".js-key-advanced, .js-key-delete, .js-key-reset, .js-model-advanced, .js-model-delete")
      : null;
    if (!target) {
      return;
    }

    if (target.classList.contains("js-key-advanced") || target.classList.contains("js-model-advanced")) {
      e.preventDefault();
      toggleAdvanced(target);
      return;
    }

    if (target.classList.contains("js-key-delete")) {
      e.preventDefault();
      deleteKeyRow(target);
      return;
    }

    if (target.classList.contains("js-model-delete")) {
      e.preventDefault();
      deleteModelRow(target);
      return;
    }

    if (target.classList.contains("js-key-reset")) {
      e.preventDefault();
      resetKey(target);
      return;
    }
  });

  /**
   * 切换当前行（密钥/模型）的高级选项折叠行显示/隐藏，并同步箭头方向。
   */
  function toggleAdvanced(link) {
    var tr = link.closest("tr");
    if (!tr) {
      return;
    }
    var advRow = tr.nextElementSibling;
    if (advRow && (advRow.classList.contains("key-advanced-row") || advRow.classList.contains("model-advanced-row"))) {
      var open = advRow.style.display === "none";
      advRow.style.display = open ? "" : "none";
      var icon = link.querySelector("i");
      if (icon) {
        icon.className = open ? "bi bi-chevron-down" : "bi bi-chevron-right";
      }
    }
  }

  /**
   * 删除密钥行：
   * - 已有 key（data-id > 0）：禁用所有输入 + 隐藏行 + 追加 keys_delete[] hidden
   * - 新增行（data-id = 0）：直接移除 DOM
   */
  function deleteKeyRow(link) {
    var tr = link.closest("tr");
    if (!tr) {
      return;
    }
    var keyId = link.getAttribute("data-id");
    var advRow = tr.nextElementSibling;
    var form = document.getElementById("provider-form");

    if (keyId && keyId !== "0" && form) {
      // 已有 key：禁用 + 隐藏 + 标记删除
      var inputs = tr.querySelectorAll("input, select, textarea");
      for (var i = 0; i < inputs.length; i++) {
        inputs[i].disabled = true;
      }
      if (advRow) {
        var advInputs = advRow.querySelectorAll("input, select, textarea");
        for (var j = 0; j < advInputs.length; j++) {
          advInputs[j].disabled = true;
        }
        advRow.style.display = "none";
      }
      tr.style.display = "none";

      var hidden = document.createElement("input");
      hidden.type = "hidden";
      hidden.name = "keys_delete[]";
      hidden.value = keyId;
      form.appendChild(hidden);
    } else {
      // 新增行：直接移除
      if (advRow && advRow.classList.contains("key-advanced-row")) {
        advRow.remove();
      }
      tr.remove();
    }
  }

  /**
   * 删除模型行：
   * - 已有模型（data-id > 0）：禁用所有输入 + 隐藏行 + 追加 models_delete[] hidden
   * - 新增行（data-id = 0）：直接移除 DOM
   */
  function deleteModelRow(link) {
    var tr = link.closest("tr");
    if (!tr) {
      return;
    }
    var modelId = link.getAttribute("data-id");
    var advRow = tr.nextElementSibling;
    var form = document.getElementById("provider-form");

    if (modelId && modelId !== "0" && form) {
      // 已有模型：禁用 + 隐藏 + 标记删除
      var inputs = tr.querySelectorAll("input, select, textarea");
      for (var i = 0; i < inputs.length; i++) {
        inputs[i].disabled = true;
      }
      if (advRow) {
        var advInputs = advRow.querySelectorAll("input, select, textarea");
        for (var j = 0; j < advInputs.length; j++) {
          advInputs[j].disabled = true;
        }
        advRow.style.display = "none";
      }
      tr.style.display = "none";

      var hidden = document.createElement("input");
      hidden.type = "hidden";
      hidden.name = "models_delete[]";
      hidden.value = modelId;
      form.appendChild(hidden);
    } else {
      // 新增行：直接移除
      if (advRow && advRow.classList.contains("model-advanced-row")) {
        advRow.remove();
      }
      tr.remove();
    }
  }

  /**
   * AJAX 即时重置密钥失败计数（独立于表单统一提交）。
   */
  function resetKey(link) {
    var tr = link.closest("tr");
    if (!confirm(lang("ai_key_reset_confirm", "确定重置该密钥的失败计数？"))) {
      return;
    }
    $.ajax({
      url: link.dataset.url,
      type: "POST",
      dataType: "json",
      success: function (resp) {
        if (resp && resp.code === "OK" && resp.data && resp.data.row) {
          refreshRow(tr, resp.data.row);
        } else {
          alert((resp && resp.message) || lang("ai_generate_failed", "操作失败"));
        }
      },
      error: function (xhr) {
        var resp = xhr.responseJSON;
        alert((resp && resp.message) || lang("ai_generate_failed", "操作失败"));
      }
    });
  }

  /**
   * 重置成功后局部刷新该行：操作列移除重置链接（failure_count 已清零）。
   */
  function refreshRow(tr, row) {
    if (!tr || !row) {
      return;
    }

    // 重建操作列（移除重置链接，因 failure_count=0）
    var keyId = tr.getAttribute("data-key-id");
    var cells = tr.children;
    var lastCell = cells[cells.length - 1];
    if (lastCell) {
      var resetLink = Number(row.failure_count) > 0
        ? ' | <a href="javascript:;" class="js-key-reset" data-url="' + route("admin.ai.key.reset", { id: keyId }) + '">' + lang("ai_key_reset", "重置") + '</a>'
        : '';
      lastCell.innerHTML =
        '<a href="javascript:;" class="js-key-advanced"><i class="bi bi-chevron-right"></i> ' + lang("ai_key_advanced_options", "高级选项") + '</a> | ' +
        '<a href="javascript:;" class="js-key-delete" data-id="' + keyId + '">' + lang("del", "删除") + '</a>' +
        resetLink;
    }
  }
})();

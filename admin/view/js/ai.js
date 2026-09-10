/**
 * AI应用管理 JavaScript：placement / module / field 联动
 *
 * - 按应用形态显隐配置行：assist / translate 不挂载模块与字段
 * - 生成字段以 checkbox 标签组呈现（替代 ctrl 多选 select）
 */
(function () {
  "use strict";

  document.addEventListener("DOMContentLoaded", function () {
    bindAdvancedToggle();
    initModelCascade();

    if (document.getElementById("placement-select")) {
      initAiForm();
    }
  });

  /**
   * 模型两级联动：第一级选分组，第二级仅显示该分组下的模型。
   * 未选分组时第二级只有占位项；编辑页按已选模型自动回显所属分组。
   */
  function initModelCascade() {
    var groupSelect = document.getElementById("model-group-select");
    var modelSelect = document.getElementById("model-select");
    if (!groupSelect || !modelSelect) {
      return;
    }

    function applyGroup(groupId, keepSelection) {
      var options = modelSelect.options;
      for (var i = 0; i < options.length; i++) {
        var opt = options[i];
        if (!opt.value) {
          continue; // 占位项始终保留
        }
        opt.hidden = groupId === "" || opt.getAttribute("data-group") !== groupId;
      }

      if (!keepSelection) {
        var current = modelSelect.options[modelSelect.selectedIndex];
        if (current && current.value && current.getAttribute("data-group") !== groupId) {
          modelSelect.selectedIndex = 0;
        }
      }
    }

    groupSelect.addEventListener("change", function () {
      applyGroup(groupSelect.value, false);
    });

    // 编辑页回显：已选模型反查所属分组
    var selected = modelSelect.options[modelSelect.selectedIndex];
    if (selected && selected.value) {
      groupSelect.value = selected.getAttribute("data-group") || "";
      applyGroup(groupSelect.value, true);
    } else {
      applyGroup(groupSelect.value, true);
    }
  }

  /**
   * 切换应用「高级配置」（应用配置 JSON）显隐。
   * 初始开合由模板按编辑态 config 是否为非空 JSON 对象决定，此处只处理点击。
   */
  function bindAdvancedToggle() {
    var toggle = document.getElementById("ai-advanced-toggle");
    var fields = document.getElementById("ai-advanced-fields");
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

  function initAiForm() {
    var placementSelect = document.getElementById("placement-select");
    var moduleSelect = document.getElementById("module-select");
    var fieldGroup = document.getElementById("field-checkbox-group");

    if (!placementSelect || !moduleSelect || !fieldGroup) {
      return;
    }

    placementSelect.addEventListener("change", applyPlacementVisibility);

    moduleSelect.addEventListener("change", function () {
      loadFields();
    });

    applyPlacementVisibility();

    // 编辑页回显：已选模块时拉取字段列表
    if (moduleSelect.value) {
      setTimeout(function () {
        loadFields();
      }, 100);
    }

    /**
     * 形态联动：assist / translate 全局形态，不挂载模块与字段
     */
    function applyPlacementVisibility() {
      var placement = placementSelect.value;
      var isGlobal = placement === "assist" || placement === "translate";
      var moduleRow = document.getElementById("row-module");
      var fieldsRow = document.getElementById("row-fields");
      var fieldCue = document.getElementById("field-cue");
      if (fieldCue) {
        var translateCue = fieldCue.getAttribute("data-cue-translate") || "";
        var defaultCue = fieldCue.getAttribute("data-cue-default") || "";
        fieldCue.textContent = placement === "translate" ? translateCue : defaultCue;
      }
      if (moduleRow) {
        moduleRow.style.display = isGlobal ? "none" : "";
      }
      if (fieldsRow) {
        fieldsRow.style.display = isGlobal ? "none" : "";
      }
    }

    /**
     * 拉取模块字段列表并渲染 checkbox 组
     */
    function loadFields() {
      var moduleName = moduleSelect.value;

      if (!moduleName) {
        fieldGroup.innerHTML = '<span class="muted">' + (fieldGroup.dataset.emptyText || "请先选择模块") + "</span>";
        return;
      }

      fieldGroup.innerHTML = '<span class="muted">...</span>';

      fetchFields(moduleName, "")
        .then(function (data) {
          var fields = data && Array.isArray(data.fields) ? data.fields : [];
          renderFieldCheckboxes(fields);
        })
        .catch(function (error) {
          console.error("获取字段列表失败:", error);
          fieldGroup.innerHTML = '<span class="muted">!</span>';
        });
    }

    /**
     * 渲染字段 checkbox 标签组，并按编辑页已存 field JSON 回显勾选
     */
    function renderFieldCheckboxes(fields) {
      fieldGroup.innerHTML = "";

      if (fields.length === 0) {
        fieldGroup.innerHTML = '<span class="muted">-</span>';
        return;
      }

      var selectedFields = [];
      var selectedInput = document.getElementById("selected-fields-data");
      if (selectedInput && selectedInput.value) {
        try {
          var parsed = JSON.parse(selectedInput.value);
          if (Array.isArray(parsed)) {
            selectedFields = parsed;
          }
        } catch (e) {
          /* 已存数据非 JSON 时按未选处理 */
        }
      }

      fields.forEach(function (field) {
        var label = document.createElement("label");
        label.className = "field-checkbox-item";

        var checkbox = document.createElement("input");
        checkbox.type = "checkbox";
        checkbox.name = "field[]";
        checkbox.value = field.name;
        if (selectedFields.indexOf(field.name) !== -1) {
          checkbox.checked = true;
        }

        label.appendChild(checkbox);
        label.appendChild(document.createTextNode(" " + field.text));
        fieldGroup.appendChild(label);
      });
    }

    function fetchFields(moduleName, fieldsCsv) {
      var url = route("admin.ai.get_fields", {}, {
        query: {
          module: moduleName,
          placement: placementSelect.value,
          fields: fieldsCsv || undefined,
        },
      });

      return fetch(url)
        .then(function (response) {
          if (!response.ok) {
            throw new Error("Network response was not ok");
          }
          return response.json();
        })
        .then(function (resp) {
          return resp && resp.data ? resp.data : null;
        });
    }
  }
})();

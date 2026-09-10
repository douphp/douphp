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
 * 后台 AI 创作助手
 *
 * 配置来自 #ai-config（AiToolbarBuilder::pageConfig：module / context / assist / assist_image / translate / banner）。
 * - batch / fill：page_header 子操作按钮，弹窗提交文案为应用名；
 * - assist / assist_image：按按钮位置分组——文本字段旁一颗「辅助生成」钮、图片字段旁一颗「AI 生成图」钮，
 *   弹窗页脚按应用名并列提交钮（一个位置建多个应用时并列多个按钮）；
 * - translate：多语言弹窗工具条一键译入（全部非图片字段）。
 */
(function () {
  var configEl = document.getElementById("ai-config");
  if (!configEl) {
    return;
  }

  var config;
  try {
    config = JSON.parse(configEl.textContent || configEl.innerHTML);
  } catch (e) {
    return;
  }
  if (!config || !config.context) {
    return;
  }

  // 图片生成字段上下文：提交图片应用时记录目标字段，「使用此图」据此回填 file input
  var imageAssistField = "";
  // 当次生成的成品尺寸（极端比例生成后打开手动裁剪）
  var imageAssistCropTarget = null;
  var lastAssistResult = null;
  // 最近一次图片生成提交参数（路由固定 admin.ai_generate.field.store），供结果弹窗「重新生成」复用
  var lastImageGenRequest = null;
  // 辅助生成弹窗上下文（字段 + 应用），供「显示系统提示词」预览拉取最终提示词
  var assistDialogContext = null;
  // 提示词预览防抖定时器（用户输入变化时自动刷新）
  var promptPreviewTimer = null;

  $(function () {
    bindToolbarButtons();
    bindImageSizeControls();
    bindBannerMaterialControls();
    $(document).on("click", ".ai-copy", function () {
      copyText($(this).attr("data-url") || "");
    });
    $(document).on("click", ".ai-use-image", function () {
      useGeneratedImage($(this));
    });
    $(document).on("click", ".ai-crop-image", function () {
      cropGeneratedImage($(this));
    });
    $(document).on("click", ".ai-regenerate", function () {
      regenerateLastImage();
    });
    if (config.context === "form") {
      renderFieldAssistButtons();
      renderImageFieldAssistButtons();
      bindPromptPreviewControls();
      $(document).on("douLangFormReady", function (e, payload) {
        bindLangTranslateButtons(payload);
      });
    }
  });

  /**
   * 每个带 data-field 的多语言槽插入一颗「辅助生成」触发钮；
   * 图片字段（file-input）跳过，走「AI 生成图」链路。
   */
  function renderFieldAssistButtons() {
    var apps = config.assist || [];
    if (!apps.length) {
      return;
    }
    $(".form .input-block .action[data-field]").each(function () {
      var $slot = $(this);
      if ($slot.closest(".input-block").find(".file-input").length) {
        return;
      }
      var field = $slot.attr("data-field");
      if (field) {
        attachAssistTrigger($slot, field);
      }
    });
  }

  /**
   * @param {JQuery} $slot
   * @param {string} field
   */
  function attachAssistTrigger($slot, field) {
    if ($slot.find('a.ai[data-ai-placement="assist"]').length) {
      return;
    }
    var label = lang("ai_btn_assist", "辅助生成");
    var $btn = $(
      '<a href="javascript:;" class="ai"'
        + ' data-ai-placement="assist"'
        + ' data-field="' + escapeHtml(field) + '"'
        + ' title="' + escapeHtml(label) + '">' + escapeHtml(label) + "</a>"
    );
    $slot.append($btn);
  }

  /**
   * 每个单图字段（.file-input）的 input-block 插入一颗「AI 生成图」触发钮。
   *
   * 仅在存在启用中的图像应用（assist_image）时注入；
   * 「使用此图」按 DataTransfer 注入 file input，走既有上传通道持久化。
   */
  function renderImageFieldAssistButtons() {
    var apps = config.assist_image || [];
    if (!apps.length) {
      return;
    }

    $(".form .input-block .file-input").each(function () {
      var $input = $(this);
      var $block = $input.closest(".input-block");
      if ($block.find('a.ai[data-ai-placement="assist-image"]').length) {
        return;
      }

      var $file = $input.find('input[type="file"]').first();
      var field = $file.attr("name") || "";
      if (!field) {
        return;
      }

      var label = lang("ai_btn_assist", "辅助生成");
      var $btn = $(
        '<a href="javascript:;" class="ai"'
          + ' data-ai-placement="assist-image"'
          + ' data-field="' + escapeHtml(field) + '"'
          + ' title="' + escapeHtml(label) + '">' + escapeHtml(label) + "</a>"
      );
      // 优先挂到既有多语言按钮槽位（与文本字段「辅助生成」位置一致），无槽位时单独成行
      var $slot = $block.find(".action[data-field]").first();
      if ($slot.length) {
        $slot.append($btn);
      } else {
        $('<p class="action"></p>').append($btn).appendTo($block);
      }
    });
  }

  /**
   * 多语言弹窗就绪后：全部启用中的翻译应用都注入（图片字段跳过）。
   *
   * @param {{$modal:JQuery,$trigger:JQuery,field:string,type:string}} ctx
   */
  function bindLangTranslateButtons(ctx) {
    if (!ctx || !ctx.$modal || !ctx.field || ctx.type === "file") {
      return;
    }

    var apps = config.translate || [];
    if (!apps.length) {
      return;
    }

    var $form = ctx.$modal.find(".dou-modal-lang-form");
    if (!$form.length) {
      return;
    }
    var $slot = findLangTranslateSlot(ctx.$modal);
    if (!$slot.length) {
      return;
    }

    var i;
    for (i = 0; i < apps.length; i++) {
      appendLangTranslateButton($slot, apps[i], ctx);
    }
  }

  /**
   * 翻译按钮挂在弹窗正文顶部工具条，input / textarea / 编辑器同一位置。
   *
   * @param {JQuery} $modal
   * @return {JQuery}
   */
  function findLangTranslateSlot($modal) {
    return $modal.find(".dou-modal-lang-toolbar .action.ai-lang-translate").first();
  }

  /**
   * @param {JQuery} $slot
   * @param {{id:number,name:string}} app
   * @param {{$modal:JQuery,$trigger:JQuery,field:string,type:string}} ctx
   */
  function appendLangTranslateButton($slot, app, ctx) {
    if ($slot.find('a.ai[data-ai-app="' + app.id + '"][data-ai-placement="translate"]').length) {
      return;
    }

    var $btn = $(
      '<a href="javascript:;" class="ai"'
        + ' data-ai-app="' + app.id + '"'
        + ' data-ai-placement="translate"'
        + ' data-field="' + escapeHtml(ctx.field) + '"'
        + ' data-ai-name="' + escapeHtml(app.name || "") + '"'
        + ' title="' + escapeHtml(app.name || "") + '">'
        + '<i class="bi bi-globe"></i>'
        + escapeHtml(app.name || "AI")
        + "</a>"
    );
    $btn.on("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      runLangTranslate($btn, app, ctx);
    });
    $slot.append($btn);
  }

  /**
   * 一键翻译：取主表单原文，填入多语言弹窗，不提交 language.value.store。
   *
   * @param {JQuery} $btn
   * @param {{id:number,name:string}} app
   * @param {{$modal:JQuery,$trigger:JQuery,field:string,type:string}} ctx
   */
  function runLangTranslate($btn, app, ctx) {
    if ($btn.hasClass("disabled") || $btn.hasClass("is-generating")) {
      return;
    }

    var source = String(getFieldValue(ctx.field) || "").trim();
    if (!source) {
      alert(lang("ai_translate_empty_source", "请先填写原文"));
      return;
    }

    var targetLang = ctx.$trigger ? String(ctx.$trigger.attr("data-lang") || "") : "";
    var targetLangName = ctx.$trigger ? $.trim(ctx.$trigger.text()) : "";

    setGenerating(ctx.$modal, true, null, $btn);
    $.ajax({
      url: route("admin.ai_generate.translate.store"),
      type: "POST",
      data: {
        app_id: app.id,
        field: ctx.field,
        source_text: source,
        target_lang: targetLang,
        target_lang_name: targetLangName,
      },
      dataType: "json",
      success: function (resp) {
        setGenerating(ctx.$modal, false);
        if (resp && resp.code === "OK") {
          var data = resp.data || {};
          setLangModalValue(ctx.$modal, ctx.type, data.content || "");
          return;
        }
        alert((resp && resp.message) || lang("ai_generate_failed"));
      },
      error: function (xhr) {
        setGenerating(ctx.$modal, false);
        var resp = xhr.responseJSON;
        alert((resp && resp.message) || lang("ai_generate_failed"));
      },
    });
  }

  /**
   * 把译文写入多语言弹窗：text/textarea 改 name=value；content 走弹窗内编辑器。
   *
   * @param {JQuery} $modal
   * @param {string} type
   * @param {string} value
   */
  function setLangModalValue($modal, type, value) {
    value = String(value == null ? "" : value);
    if (type === "content" && window.douEditor) {
      var id;
      for (id in window.douEditor) {
        if (!Object.prototype.hasOwnProperty.call(window.douEditor, id)) {
          continue;
        }
        if (!$modal.find("#" + id).length && !$modal.find("#" + id + "Editor").length) {
          continue;
        }
        var editor = window.douEditor[id];
        if (typeof editor.setValue === "function") {
          editor.setValue(value);
        } else if (typeof editor.setContent === "function") {
          editor.setContent(value);
        }
        var $shadow = $modal.find("#" + id + "Textarea");
        if ($shadow.length) {
          $shadow.val(value);
        }
        return;
      }
    }

    $modal.find('.dou-modal-lang-form [name="value"]').first().val(value).trigger("change");
  }

  /**
   * 统一点击委托：assist 字段触发钮走多应用弹窗，其余按 placement 分流。
   */
  function bindToolbarButtons() {
    $(document).on("click", "a[data-ai-app], a[data-ai-placement='assist'], a[data-ai-placement='assist-image']", function (e) {
      e.preventDefault();
      var $btn = $(this);

      var placement = $btn.attr("data-ai-placement");
      if (placement === "assist") {
        openAssistDialog($btn);
        return;
      }
      if (placement === "assist-image") {
        openImageAssistDialog($btn);
        return;
      }
      if (placement === "translate") {
        return;
      }
      if (placement === "batch") {
        openBatchDialog($btn);
      } else if (placement === "fill") {
        openFormDialog($btn);
      }
    });
  }

  // ------------------------------------------------------------------
  // 三类弹窗
  // ------------------------------------------------------------------

  function openBatchDialog($btn) {
    var appId = parseInt($btn.attr("data-ai-app"), 10);
    var min = parseInt($btn.attr("data-ai-batch-min"), 10) || 1;
    var max = parseInt($btn.attr("data-ai-batch-max"), 10) || 20;

    imageAssistField = "";

    openDialog($btn.attr("data-ai-name"), [
      countRow(min, max),
      promptRow(),
    ], function ($modal, done) {
      var count = parseInt($modal.find(".dou-modal-count").val(), 10) || min;
      post("admin.ai_generate.batch.store", {
        app_id: appId,
        count: count,
        prompt: $modal.find(".dou-modal-prompt").val(),
        category_id: queryParam("category_id") || queryParam("id"),
        parent_id: queryParam("parent_id") || queryParam("id"),
      }, function (data) {
        done();
        var msg = lang("ai_generate_batch") + ": " + data.imported + " OK";
        if (data.failed > 0) {
          msg += ", " + data.failed + " FAIL\n" + (data.errors || []).join("\n");
        }
        alert(msg);
        window.location.reload();
      }, $modal);
    });
  }

  function openFormDialog($btn) {
    var appId = parseInt($btn.attr("data-ai-app"), 10);

    imageAssistField = "";

    openDialog($btn.attr("data-ai-name"), [promptRow()], function ($modal, done) {
      post("admin.ai_generate.form.store", {
        app_id: appId,
        prompt: $modal.find(".dou-modal-prompt").val(),
      }, function (data) {
        done();
        var values = data.values || {};
        for (var name in values) {
          if (Object.prototype.hasOwnProperty.call(values, name)) {
            setFieldValue(name, values[name]);
          }
        }
      }, $modal);
    });
  }

  function openAssistDialog($btn) {
    var field = $btn.attr("data-field");
    var apps = config.assist || [];
    if (!field || !apps.length) {
      return;
    }

    imageAssistField = "";

    var buttons = [];
    var i;
    for (i = 0; i < apps.length; i++) {
      buttons.push(assistSubmitButton(apps[i], field));
    }

    assistDialogContext = { field: field, app: apps[0] };
    openDialog(lang("ai_btn_assist", "辅助生成"), [fieldPromptRow()], null, buttons);
  }

  function assistSubmitButton(app, field) {
    return {
      text: app.name || "AI",
      className: "btn dou-modal-submit",
      onClick: function ($modal, done, fail, $trigger) {
        post("admin.ai_generate.field.store", {
          app_id: app.id,
          field: field,
          prompt: $modal.find(".dou-modal-prompt").val(),
          current_value: getFieldValue(field),
          module: config.module || "",
          form_snapshot: formSnapshot(),
        }, function (data) {
          done();
          setFieldValue(field, data.content || "");
        }, $modal, $trigger);
      },
    };
  }

  /**
   * 图片字段辅助生成弹窗：页脚并列多个图像应用提交钮；
   * 提交后走任务链路（同步已终态直接预览 / 异步轮询），结果弹窗提供「使用此图」回填。
   *
   * banner 弹窗（应用配置了 canvas_size）在尺寸/对齐行之前增加：
   * 图片素材栏与素材用法（模型支持图生图时）+ 主标题 + 副标题 + 风格选择，
   * 提示词 placeholder 换 banner 专属文案。
   *
   * @param {JQuery} $btn
   */
  function openImageAssistDialog($btn) {
    var field = $btn.attr("data-field");
    var apps = config.assist_image || [];
    if (!field || !apps.length) {
      return;
    }

    imageAssistField = field;

    var buttons = [];
    var i;
    for (i = 0; i < apps.length; i++) {
      buttons.push(imageAssistSubmitButton(apps[i], field));
    }

    var rows;
    if (parseCanvasSize(apps[0].canvas_size)) {
      rows = [];
      if (apps[0].image_input) {
        rows.push(bannerMaterialRow());
      }
      rows.push(bannerTitleRow());
      rows.push(bannerSubtitleRow());
      rows.push(bannerStyleRow());
      rows.push(imageSizeRow(apps[0].canvas_size, apps[0].content_align || "center"));
      rows.push(bannerPromptRow());
    } else {
      rows = [imageSizeRow("", ""), fieldPromptRow()];
    }

    assistDialogContext = { field: field, app: apps[0] };
    openDialog(lang("ai_btn_assist", "辅助生成"), rows, null, buttons);
  }

  /**
   * @param {{id:number,name:string}} app
   * @param {string} field
   */
  function imageAssistSubmitButton(app, field) {
    return {
      text: app.name || "AI",
      className: "btn dou-modal-submit",
      onClick: function ($modal, done, fail, $trigger) {
        imageAssistField = field;
        var width = parseInt($modal.find(".dou-modal-width").val(), 10);
        var height = parseInt($modal.find(".dou-modal-height").val(), 10);
        imageAssistCropTarget = width > 0 && height > 0 ? { width: width, height: height } : null;
        var $alignBtn = $modal.find(".ai-align-btn.active");
        var $styleBtn = $modal.find(".ai-style-btn.active");

        var data = {
          app_id: app.id,
          field: field,
          prompt: $modal.find(".dou-modal-prompt").val(),
          size: width > 0 && height > 0 ? width + "x" + height : "",
          content_align: $alignBtn.length ? $alignBtn.attr("data-align") : "",
          module: config.module || "",
          form_snapshot: formSnapshot(),
        };

        // banner 弹窗专属字段（普通图像弹窗无这些节点，取值为空）
        var $bannerTitle = $modal.find(".dou-modal-banner-title");
        if ($bannerTitle.length) {
          data.banner_title = $bannerTitle.val() || "";
          data.banner_subtitle = $modal.find(".dou-modal-banner-subtitle").val() || "";
          data.banner_style = $styleBtn.length ? $styleBtn.attr("data-style") : "";
          var materials = $modal.data("aiMaterials") || [];
          var refs = [];
          var r;
          for (r = 0; r < materials.length; r++) {
            refs.push(materials[r].src);
          }
          data.image_refs = refs;
          var $modeBtn = $modal.find(".ai-material-subject");
          data.banner_material_mode = $modeBtn.prop("checked") ? "subject" : "background";
        }

        lastImageGenRequest = { data: data };
        post("admin.ai_generate.field.store", data, function () {
          done();
        }, $modal, $trigger);
      },
    };
  }

  function openDialog(title, rows, onSubmit, buttons) {
    if (!window.douModal) {
      return;
    }
    var options = {
      title: title,
      placement: "right",
      size: "lg",
      bodyHtml: '<div class="form">' + rows.join("") + "</div>",
    };
    if (buttons && buttons.length) {
      options.buttons = buttons;
    } else {
      options.submitText = title || lang("btn_submit");
      options.onSubmit = function ($modal, done, fail) {
        onSubmit($modal, done, fail);
      };
    }
    window.douModal.open(options);
  }

  function formFieldRow(label, inputHtml) {
    return (
      '<div class="form-item">' +
        '<div class="form-label">' + escapeHtml(label) + "</div>" +
        '<div class="input-block">' + inputHtml + "</div>" +
      "</div>"
    );
  }

  function countRow(min, max) {
    return formFieldRow(
      lang("ai_generate_count"),
      '<input type="number" name="count" class="input dou-modal-count" value="' +
      min +
      '" min="' + min + '" max="' + max + '" size="5" />'
    );
  }

  function promptRow() {
    return (
      '<div class="form-item">' +
        '<div class="input-block">' +
          '<textarea name="prompt" class="textarea dou-modal-prompt" rows="3" placeholder="' +
          escapeHtml(lang("ai_generate_prompt_cue")) +
          '"></textarea>' +
        '</div>' +
      '</div>'
    );
  }

  function fieldPromptRow() {
    return promptRowHtml(lang("ai_generate_prompt_field_cue"));
  }

  /**
   * 提示词行：标题（右侧挂「显示系统提示词」切换钮）+ 完整提示词预览 textarea（默认收起）+ 用户输入框。
   *
   * @param {string} cue 用户输入框 placeholder 文案
   * @return {string}
   */
  function promptRowHtml(cue) {
    return (
      '<div class="form-item">' +
        '<div class="form-label">' + escapeHtml(lang("ai_generate_prompt", "生成要求")) +
          '<a href="javascript:;" class="ai-prompt-preview-toggle">' +
            escapeHtml(lang("ai_prompt_show_system", "显示系统提示词")) +
          "</a>" +
        '</div>' +
        '<div class="input-block">' +
          '<textarea class="textarea ai-system-prompt" rows="6" readonly="readonly" style="display:none"></textarea>' +
          '<textarea name="prompt" class="textarea dou-modal-prompt" rows="3" placeholder="' +
          escapeHtml(cue) +
          '"></textarea>' +
        '</div>' +
      '</div>'
    );
  }

  // ------------------------------------------------------------------
  // 完整提示词预览（「显示系统提示词」）
  // ------------------------------------------------------------------

  /**
   * 预览区交互：切换钮展开/收起；展开时拉取当次最终提示词，
   * 用户输入变化时防抖自动刷新（仅展开状态下）。
   */
  function bindPromptPreviewControls() {
    $(document).on("click", ".ai-prompt-preview-toggle", function (e) {
      e.preventDefault();
      toggleSystemPrompt($(this));
    });
    // 预览展开时，弹窗内任何影响提示词的输入变化都防抖刷新
    $(document).on(
      "input",
      ".dou-modal-prompt, .dou-modal-width, .dou-modal-height, .dou-modal-banner-title, .dou-modal-banner-subtitle",
      function () {
        schedulePromptPreviewRefresh();
      }
    );
    // 比例 / 对齐 / 风格 / 素材用法按钮：选中态变化即影响提示词
    $(document).on("click", ".ai-size-btn", function () {
      schedulePromptPreviewRefresh();
    });
  }

  /**
   * 防抖刷新：仅在预览展开时拉取当次最终提示词；
   * 比例按钮经 .val() 回填宽高在点击处理器内同步完成，防抖窗口内取到的是已更新的值。
   */
  function schedulePromptPreviewRefresh() {
    var $preview = $(".dou-modal.is-open").last().find(".ai-system-prompt");
    if (!$preview.length || !$preview.is(":visible")) {
      return;
    }
    window.clearTimeout(promptPreviewTimer);
    promptPreviewTimer = window.setTimeout(fetchSystemPrompt, 400);
  }

  function toggleSystemPrompt($btn) {
    var $preview = $btn.closest(".dou-modal").find(".ai-system-prompt");
    if (!$preview.length) {
      return;
    }
    if ($preview.is(":visible")) {
      $preview.hide();
      $btn.text(lang("ai_prompt_show_system", "显示系统提示词"));
      return;
    }
    $preview.show();
    $btn.text(lang("ai_prompt_hide_system", "隐藏系统提示词"));
    fetchSystemPrompt();
  }

  /**
   * POST admin.ai_generate.field.preview 取当次最终完整提示词
   * （内置 system + 站点信息/表单快照 + 应用配置提示词 + 用户当次输入）。
   */
  function fetchSystemPrompt() {
    var ctx = assistDialogContext;
    var $modal = $(".dou-modal.is-open").last();
    var $preview = $modal.find(".ai-system-prompt");
    if (!ctx || !$preview.length) {
      return;
    }

    $.ajax({
      url: route("admin.ai_generate.field.preview"),
      type: "POST",
      data: systemPromptPayload($modal, ctx),
      dataType: "json",
      success: function (resp) {
        if (resp && resp.code === "OK") {
          $preview.val((resp.data && resp.data.prompt) || "");
          return;
        }
        $preview.val((resp && resp.message) || lang("ai_prompt_preview_failed", "提示词获取失败"));
      },
      error: function (xhr) {
        var resp = xhr.responseJSON;
        $preview.val((resp && resp.message) || lang("ai_prompt_preview_failed", "提示词获取失败"));
      },
    });
  }

  /**
   * 预览请求参数：与提交一致（image_refs 不传，预览不解析参考图）。
   *
   * @param {JQuery} $modal
   * @param {{field:string,app:{id:number}}} ctx
   * @return {object}
   */
  function systemPromptPayload($modal, ctx) {
    var data = {
      app_id: ctx.app.id,
      field: ctx.field,
      prompt: $modal.find(".dou-modal-prompt").val(),
      current_value: getFieldValue(ctx.field),
      module: config.module || "",
      form_snapshot: formSnapshot(),
    };

    var $width = $modal.find(".dou-modal-width").first();
    if ($width.length) {
      var width = parseInt($width.val(), 10);
      var height = parseInt($modal.find(".dou-modal-height").val(), 10);
      data.size = width > 0 && height > 0 ? width + "x" + height : "";
      var $alignBtn = $modal.find(".ai-align-btn.active");
      data.content_align = $alignBtn.length ? $alignBtn.attr("data-align") : "";
      var $bannerTitle = $modal.find(".dou-modal-banner-title");
      if ($bannerTitle.length) {
        data.banner_title = $bannerTitle.val() || "";
        data.banner_subtitle = $modal.find(".dou-modal-banner-subtitle").val() || "";
        var $styleBtn = $modal.find(".ai-style-btn.active");
        data.banner_style = $styleBtn.length ? $styleBtn.attr("data-style") : "";
      }
    }

    return data;
  }

  // ------------------------------------------------------------------
  // banner 弹窗专属行（素材 / 主副标题 / 风格 / 提示词）
  // ------------------------------------------------------------------

  function bannerMaterialMax() {
    var n = config.banner && parseInt(config.banner.material_max, 10);
    return n > 0 ? n : 4;
  }

  function bannerUploadMaxBytes() {
    var n = config.banner && parseInt(config.banner.upload_max_bytes, 10);
    return n > 0 ? n : 5 * 1024 * 1024;
  }

  function bannerStyleOptions() {
    var styles = config.banner && config.banner.styles;
    return styles && styles.length ? styles : [];
  }

  /**
   * 图片素材栏：已选缩略图 + 「选择产品主图 / 本地上传」入口 + 产品选择面板。
   * 已选素材存 $modal.data("aiMaterials")：[{src: .file号|dataURI, url: 预览地址}]。
   * 「抠出主体」勾选项在标题右侧：勾选=subject，不勾=整张作底图（background）。
   */
  function bannerMaterialRow() {
    return (
      '<div class="form-item">' +
        '<div class="form-label">' + escapeHtml(lang("ai_banner_material", "图片素材")) +
          '<span class="ai-material-cue">' + escapeHtml(lang("ai_banner_material_cue", "可选：选产品主图或上传本地图片")) + "</span>" +
          '<label class="ai-material-mode"><input type="checkbox" class="ai-material-subject" />' +
            escapeHtml(lang("ai_banner_material_mode_subject", "抠出主体")) + "</label></div>" +
        '<div class="input-block">' +
          '<div class="ai-material">' +
            '<div class="ai-material-thumbs"></div>' +
            '<div class="ai-material-actions">' +
              '<a href="javascript:;" class="btn-secondary ai-material-product">' + escapeHtml(lang("ai_banner_material_product", "选择产品主图")) + "</a>" +
              '<a href="javascript:;" class="btn-secondary ai-material-upload">' + escapeHtml(lang("ai_banner_material_upload", "本地上传")) + "</a>" +
              '<input type="file" class="ai-material-file" accept="image/*" multiple="multiple" style="display:none" />' +
            "</div>" +
            '<div class="ai-material-picker" style="display:none">' +
              '<input type="text" class="input ai-material-keyword" placeholder="' +
                escapeHtml(lang("ai_banner_material_search", "输入产品名称，自动搜索")) + '" />' +
              '<div class="ai-material-grid"></div>' +
            "</div>" +
          "</div>" +
        "</div>" +
      "</div>"
    );
  }

  function bannerTitleRow() {
    var name = String(getFieldValue("name") || "").trim();
    return (
      '<div class="form-item">' +
        '<div class="form-label">' + escapeHtml(lang("ai_banner_title", "主标题")) + "</div>" +
        '<div class="input-block">' +
          '<input type="text" class="input dou-modal-banner-title" value="' + escapeHtml(name) + '" placeholder="' +
          escapeHtml(lang("ai_banner_title_cue", "画进 banner 的大字标题，如公司口号")) + '" />' +
        "</div>" +
      "</div>"
    );
  }

  function bannerSubtitleRow() {
    var text = String(getFieldValue("text") || "").replace(/\s+/g, " ").trim();
    return (
      '<div class="form-item">' +
        '<div class="form-label">' + escapeHtml(lang("ai_banner_subtitle", "副标题")) + "</div>" +
        '<div class="input-block">' +
          '<input type="text" class="input dou-modal-banner-subtitle" value="' + escapeHtml(text) + '" placeholder="' +
          escapeHtml(lang("ai_banner_subtitle_cue", "画进 banner 的小字说明，如活动信息")) + '" />' +
        "</div>" +
      "</div>"
    );
  }

  function bannerStyleRow() {
    var html = '<div class="form-item">' +
      '<div class="form-label">' + escapeHtml(lang("ai_banner_style", "风格")) + "</div>" +
      '<div class="input-block ai-size-row">' +
        '<span class="ai-size-ratios ai-style-ratios">';
    var styles = bannerStyleOptions();
    var i;
    for (i = 0; i < styles.length; i++) {
      var key = styles[i];
      html += '<button type="button" class="ai-size-btn ai-style-btn" data-style="' + key + '">' +
        escapeHtml(lang("ai_banner_style_" + key, key)) + "</button>";
    }
    html += '<button type="button" class="ai-size-btn ai-style-btn active" data-style="">' +
      escapeHtml(lang("ai_banner_style_none", "不指定")) + "</button>";
    html += "</span></div></div>";

    return html;
  }

  function bannerPromptRow() {
    return promptRowHtml(lang("ai_banner_prompt_cue", "补充画面细节，如：以车间设备为主体、蓝色科技光效、右侧留白放文字；留空则按标题与风格自动生成"));
  }

  /**
   * 素材栏交互（委托绑定，弹窗动态内容适用）。
   */
  function bindBannerMaterialControls() {
    // 「选择产品主图」：开合产品面板并懒加载
    $(document).on("click", ".ai-material-product", function (e) {
      e.preventDefault();
      var $picker = $(this).closest(".ai-material").find(".ai-material-picker").first();
      var hidden = $picker.is(":hidden");
      $picker.toggle();
      if (hidden) {
        loadMaterialProducts($picker, "");
      }
    });

    // 产品搜索：停止输入后自动搜索（防抖 400ms）；回车仅拦截，防止整页表单被提交
    $(document).on("input", ".ai-material-keyword", function () {
      var $input = $(this);
      var keyword = $.trim($input.val());
      window.clearTimeout($input.data("aiSearchTimer"));
      $input.data("aiSearchTimer", window.setTimeout(function () {
        loadMaterialProducts($input.closest(".ai-material-picker"), keyword);
      }, 400));
    });
    $(document).on("keydown", ".ai-material-keyword", function (e) {
      if (e.keyCode === 13) {
        e.preventDefault();
      }
    });

    // 产品缩略图：点选 / 取消（合计上限见 config.banner.material_max）
    $(document).on("click", ".ai-material-grid .ai-material-option", function () {
      var $opt = $(this);
      var $modal = $opt.closest(".dou-modal");
      var materials = $modal.data("aiMaterials") || [];
      var src = $opt.attr("data-file");
      var url = $opt.attr("data-url");
      var idx = materialIndexOf(materials, src);
      if (idx >= 0) {
        materials.splice(idx, 1);
        $opt.removeClass("active");
      } else {
        if (materials.length >= bannerMaterialMax()) {
          alert(lang("ai_banner_material_max", "最多选择 4 张"));
          return;
        }
        materials.push({ src: src, url: url });
        $opt.addClass("active");
      }
      $modal.data("aiMaterials", materials);
      renderMaterialThumbs($modal);
    });

    // 已选缩略图移除
    $(document).on("click", ".ai-material-remove", function () {
      var $remove = $(this);
      var $modal = $remove.closest(".dou-modal");
      var src = $remove.attr("data-src");
      var materials = $modal.data("aiMaterials") || [];
      var idx = materialIndexOf(materials, src);
      if (idx >= 0) {
        materials.splice(idx, 1);
        $modal.data("aiMaterials", materials);
      }
      $modal.find('.ai-material-option[data-file="' + src + '"]').removeClass("active");
      renderMaterialThumbs($modal);
    });

    // 「本地上传」：file input change → FileReader 转 data URI
    $(document).on("click", ".ai-material-upload", function (e) {
      e.preventDefault();
      $(this).closest(".ai-material-actions").find(".ai-material-file").trigger("click");
    });

    $(document).on("change", ".ai-material-file", function () {
      var $input = $(this);
      var $modal = $input.closest(".dou-modal");
      var files = this.files;
      var materials = $modal.data("aiMaterials") || [];
      var pending = files.length;
      var i;

      var done = function () {
        pending--;
        if (pending <= 0) {
          $modal.data("aiMaterials", materials);
          renderMaterialThumbs($modal);
          $input.val("");
        }
      };

      if (!pending) {
        return;
      }

      for (i = 0; i < files.length; i++) {
        (function (file) {
          if (materials.length >= bannerMaterialMax()) {
            alert(lang("ai_banner_material_max", "最多选择 4 张"));
            done();
            return;
          }
          if (!/^image\//.test(file.type) || file.size > bannerUploadMaxBytes()) {
            alert(lang("ai_banner_material_invalid", "仅支持 5MB 以内的图片文件"));
            done();
            return;
          }
          var reader = new FileReader();
          reader.onload = function (event) {
            materials.push({ src: String(event.target.result), url: String(event.target.result) });
            done();
          };
          reader.onerror = function () {
            done();
          };
          reader.readAsDataURL(file);
        })(files[i]);
      }
    });
  }

  /**
   * @param {JQuery} $picker
   * @param {string} keyword
   */
  function loadMaterialProducts($picker, keyword) {
    var $grid = $picker.find(".ai-material-grid");
    // 记录请求令牌：停止输入自动搜索后，丢弃乱序返回的旧响应
    var token = String((new Date()).getTime()) + Math.random();
    $picker.data("aiSearchToken", token);
    $grid.html('<div class="ai-material-loading">' + escapeHtml(lang("ai_generating", "加载中...")) + "</div>");
    $.ajax({
      url: route("admin.ai_generate.field.products"),
      type: "GET",
      data: { keyword: keyword },
      dataType: "json",
      success: function (resp) {
        if ($picker.data("aiSearchToken") !== token) {
          return;
        }
        $grid.empty();
        if (!resp || resp.code !== "OK" || !resp.data || !resp.data.length) {
          $grid.html('<div class="ai-material-loading">' +
            escapeHtml(lang("ai_banner_material_empty", "没有可用的产品主图")) + "</div>");
          return;
        }
        var $modal = $picker.closest(".dou-modal");
        var materials = $modal.data("aiMaterials") || [];
        var i;
        for (i = 0; i < resp.data.length; i++) {
          var item = resp.data[i];
          var active = materialIndexOf(materials, item.file) >= 0 ? " active" : "";
          $grid.append(
            '<a href="javascript:;" class="ai-material-option' + active + '"' +
            ' data-file="' + escapeHtml(item.file) + '"' +
            ' data-url="' + escapeHtml(item.url) + '"' +
            ' title="' + escapeHtml(item.title) + '">' +
            '<img src="' + escapeHtml(item.url) + '" alt="" loading="lazy" />' +
            "<span>" + escapeHtml(item.title) + "</span>" +
            "</a>"
          );
        }
      },
      error: function () {
        if ($picker.data("aiSearchToken") !== token) {
          return;
        }
        $grid.html('<div class="ai-material-loading">' +
          escapeHtml(lang("ai_generate_failed", "加载失败")) + "</div>");
      },
    });
  }

  /**
   * @param {array} materials
   * @param {string} src
   * @return {number}
   */
  function materialIndexOf(materials, src) {
    var i;
    for (i = 0; i < materials.length; i++) {
      if (materials[i].src === src) {
        return i;
      }
    }

    return -1;
  }

  /**
   * 重绘已选素材缩略图（含移除按钮）。
   *
   * @param {JQuery} $modal
   */
  function renderMaterialThumbs($modal) {
    var $thumbs = $modal.find(".ai-material-thumbs").first();
    if (!$thumbs.length) {
      return;
    }
    var materials = $modal.data("aiMaterials") || [];
    var html = "";
    var i;
    for (i = 0; i < materials.length; i++) {
      html += '<span class="ai-material-thumb">' +
        '<img src="' + escapeHtml(materials[i].url) + '" alt="" />' +
        '<a href="javascript:;" class="ai-material-remove" data-src="' + escapeHtml(materials[i].src) + '">&times;</a>' +
        "</span>";
    }
    $thumbs.html(html);
  }

  /**
   * 图片尺寸行（置于提示词文本框上方），两种模式：
   * - 应用配置了画布尺寸（canvas_size）：最终成品模式，比例按钮替换为核心画面区对齐按钮（左/右/居中），
   *   宽高预填最终尺寸可改；极端比例由底层换基础尺寸出图，生成后手动裁切
   * - 未配置：比例模式 —— 比例按钮（1:1 / 4:3 / 3:2 / 16:9 / 自定义），
   *   选比例按基准宽度 1000 换算高；手动改宽高自动切到「自定义」
   */
  var IMAGE_SIZE_BASE_WIDTH = 1000;
  var IMAGE_SIZE_RATIOS = [["1", "1"], ["4", "3"], ["3", "2"], ["16", "9"]];
  var IMAGE_ALIGN_OPTIONS = ["left", "right", "center"];

  function parseCanvasSize(size) {
    var m = /^(\d{1,5})x(\d{1,5})$/.exec(String(size || "").trim());
    return m ? { width: parseInt(m[1], 10), height: parseInt(m[2], 10) } : null;
  }

  function imageSizeRow(defaultSize, defaultAlign) {
    var custom = parseCanvasSize(defaultSize);

    // 最终成品模式：应用配置了画布尺寸（banner 等），比例按钮替换为核心画面区对齐按钮（左 / 右 / 居中）；
    // 极端比例由底层换基础尺寸出图，生成后手动裁切，弹窗只呈现最终尺寸
    if (custom) {
      var align = IMAGE_ALIGN_OPTIONS.indexOf(defaultAlign) >= 0 ? defaultAlign : "left";
      var alignLabels = {
        left: lang("ai_align_left", "左对齐"),
        right: lang("ai_align_right", "右对齐"),
        center: lang("ai_align_center", "居中对齐"),
      };
      var alignHtml = "";
      var a;
      for (a = 0; a < IMAGE_ALIGN_OPTIONS.length; a++) {
        alignHtml += '<button type="button" class="ai-size-btn ai-align-btn' + (IMAGE_ALIGN_OPTIONS[a] === align ? " active" : "") +
          '" data-align="' + IMAGE_ALIGN_OPTIONS[a] + '">' + escapeHtml(alignLabels[IMAGE_ALIGN_OPTIONS[a]]) + "</button>";
      }

      return (
        '<div class="form-item">' +
          '<div class="input-block ai-size-row ai-size-row-labeled">' +
            '<div class="ai-size-col">' +
              '<div class="form-label">' + escapeHtml(lang("ai_layout_title", "构图与布局")) + "</div>" +
              '<span class="ai-size-ratios">' + alignHtml + "</span>" +
            "</div>" +
            '<div class="ai-size-col ai-size-col-dims">' +
              '<div class="form-label">' + escapeHtml(lang("ai_size_title", "尺寸")) + "</div>" +
              '<span class="ai-size-dims">' +
                '<input type="number" class="input dou-modal-width" value="' + custom.width + '" min="1" size="6" />' +
                '<em class="ai-size-x">×</em>' +
                '<input type="number" class="input dou-modal-height" value="' + custom.height + '" min="1" size="6" />' +
              "</span>" +
            "</div>" +
          "</div>" +
        "</div>"
      );
    }

    var buttonsHtml = "";
    var i;
    for (i = 0; i < IMAGE_SIZE_RATIOS.length; i++) {
      var ratio = IMAGE_SIZE_RATIOS[i][0] + ":" + IMAGE_SIZE_RATIOS[i][1];
      buttonsHtml += '<button type="button" class="ai-size-btn' + (i === 0 ? " active" : "") +
        '" data-ratio="' + ratio + '">' + ratio + "</button>";
    }
    buttonsHtml += '<button type="button" class="ai-size-btn" data-ratio="custom">' +
      escapeHtml(lang("ai_image_ratio_custom", "自定义")) + "</button>";

    return (
      '<div class="form-item">' +
        '<div class="input-block ai-size-row">' +
          '<span class="ai-size-ratios">' + buttonsHtml + "</span>" +
          '<span class="ai-size-dims">' +
            '<input type="number" class="input dou-modal-width" value="' + IMAGE_SIZE_BASE_WIDTH + '" min="1" size="6" />' +
            '<em class="ai-size-x">×</em>' +
            '<input type="number" class="input dou-modal-height" value="' + IMAGE_SIZE_BASE_WIDTH + '" min="1" size="6" />' +
          "</span>" +
        '</div>' +
      '</div>'
    );
  }

  /**
   * 比例按钮/对齐按钮/宽高联动（委托绑定，弹窗动态内容适用）。
   */
  function bindImageSizeControls() {
    $(document).on("click", ".ai-size-btn", function () {
      var $btn = $(this);
      var ratio = $btn.attr("data-ratio");
      $btn.closest(".ai-size-ratios").find(".ai-size-btn").removeClass("active");
      $btn.addClass("active");
      if (ratio == null) {
        // 对齐按钮（左/右/居中）：只切换选中态
        return;
      }
      if (ratio === "custom") {
        return;
      }
      var parts = ratio.split(":");
      var w = parseInt(parts[0], 10) || 1;
      var h = parseInt(parts[1], 10) || 1;
      var $row = $btn.closest(".ai-size-row");
      $row.find(".dou-modal-width").val(IMAGE_SIZE_BASE_WIDTH);
      $row.find(".dou-modal-height").val(Math.round((IMAGE_SIZE_BASE_WIDTH * h) / w));
    });
    $(document).on("input", ".ai-size-row .dou-modal-width, .ai-size-row .dou-modal-height", function () {
      // 手动改宽高 → 比例模式切到「自定义」；对齐模式无比例按钮，不动选中态
      var $custom = $(this).closest(".ai-size-row").find('.ai-size-btn[data-ratio="custom"]');
      if ($custom.length) {
        $custom.addClass("active").siblings(".ai-size-btn").removeClass("active");
      }
    });
  }

  // ------------------------------------------------------------------
  // 工具
  // ------------------------------------------------------------------

  function generatingRing() {
    return '<span class="dou-spin-ring" aria-hidden="true"></span>';
  }

  function generatingMarkup(message) {
    return generatingRing() + escapeHtml(message || "");
  }

  /**
   * 翻译弹窗把状态盖在译文区；带「生成要求」文本框的弹窗不盖遮罩，只改提交钮。
   */
  function generatingHost($modal) {
    if ($modal.find(".dou-modal-prompt").length) {
      return $();
    }
    if ($modal.find(".dou-modal-lang-form").length) {
      var $editor = $modal.find(".editor").first();
      if ($editor.length) {
        return $editor;
      }
      return $modal.find(".dou-modal-lang-form .input-block").first();
    }
    return $();
  }

  function actionButtons($modal) {
    if ($modal.find(".dou-modal-lang-form").length) {
      return $modal.find("a.ai[data-ai-placement='translate']");
    }
    return $modal.find(".dou-modal-submit");
  }

  function resolveActiveTrigger($modal, $trigger) {
    if ($trigger && $trigger.length) {
      return $trigger.first();
    }
    var stored = $modal.data("aiGeneratingTrigger");
    if (stored && stored.length) {
      return stored.first();
    }
    return actionButtons($modal).first();
  }

  function generatingOverlayText($modal, message) {
    if (message) {
      return message;
    }
    if ($modal.find(".dou-modal-lang-form").length) {
      return lang("ai_generating_translate", "正在生成翻译结果...");
    }
    return lang("ai_generating", "正在生成...");
  }

  /**
   * 弹窗生成中：只把被点的提交钮改成「生成中...」，同脚其它钮仅禁用。
   * 带 .dou-modal-prompt 的弹窗不在文本框上盖遮罩。
   *
   * @param {JQuery} $modal
   * @param {boolean} busy
   * @param {string} [message] 遮罩文案；缺省按弹窗类型选择（仅翻译弹窗使用）
   * @param {JQuery} [$trigger] 被点的提交钮
   */
  function setGenerating($modal, busy, message, $trigger) {
    if (!$modal || !$modal.length) {
      $modal = $(".dou-modal.is-open").last();
    }
    if (!$modal.length) {
      return;
    }

    var $active = resolveActiveTrigger($modal, $trigger);
    var $siblings = actionButtons($modal).not($active);
    var $host = generatingHost($modal);
    var $clear = $modal.find(".dou-modal-lang-clear");
    var $body = $modal.find(".dou-modal-body").first();
    var btnText = lang("ai_generating_btn", "生成中...");
    var overlayText = generatingOverlayText($modal, message);

    $body.children(".dou-modal-status").remove();

    if (busy) {
      if ($active.length) {
        $modal.data("aiGeneratingTrigger", $active);
        if ($active.data("originalHtml") == null) {
          $active.data("originalHtml", $active.html());
        }
        $active.addClass("is-generating disabled").attr("aria-busy", "true");
        $active.html(generatingMarkup(btnText));
      }
      $siblings.addClass("disabled");
      if ($host.length) {
        $host.addClass("is-generating-host");
        var $overlay = $host.children(".dou-modal-generating");
        if (!$overlay.length) {
          $overlay = $('<div class="dou-modal-generating" role="status"></div>');
          $host.append($overlay);
        }
        $overlay.html(generatingMarkup(overlayText));
      }
      $clear.addClass("disabled");
      return;
    }

    if ($host.length) {
      $host.removeClass("is-generating-host");
      $host.children(".dou-modal-generating").remove();
    }
    $clear.removeClass("disabled");
    $siblings.removeClass("disabled");
    if ($active.length) {
      var original = $active.data("originalHtml");
      $active.removeClass("is-generating disabled").removeAttr("aria-busy");
      if (original != null) {
        $active.html(original);
        $active.removeData("originalHtml");
      }
    }
    $modal.removeData("aiGeneratingTrigger");
  }

  function post(routeName, data, onSuccess, $modal, $trigger) {
    $modal = $modal && $modal.length ? $modal : $(".dou-modal.is-open").last();
    setGenerating($modal, true, null, $trigger);
    $.ajax({
      url: route(routeName),
      type: "POST",
      data: data,
      dataType: "json",
      success: function (resp) {
        if (resp && resp.code === "OK") {
          var d = resp.data || {};
          // 异步任务（图像/视频生成）：后端返回 task_id，进入轮询模式
          if (d.task_id) {
            if (d.status === "failed" || d.status === "timeout" || d.success === false) {
              fail(d.error || lang("ai_task_submit_failed"));
            } else if (d.status === "succeeded" && d.result) {
              // 同步生成已落终态任务：跳过轮询直接预览
              setGenerating($modal, false);
              showAsyncResult(d.result, d.expires_at, d.task_id);
            } else {
              pollAsyncTask(d.task_id, $modal);
            }
            return;
          }
          // 异步提交失败（未拿到 task_id）：给出可读错误而非走同步成功分支
          if (d.mode === "async" && d.success === false) {
            fail(d.error || lang("ai_task_submit_failed"));
            return;
          }
          setGenerating($modal, false);
          onSuccess(d);
        } else {
          fail(resp && resp.message);
        }
      },
      error: function (xhr) {
        var resp = xhr.responseJSON;
        fail(resp && resp.message);
      },
    });

    function fail(message) {
      setGenerating($modal, false);
      alert(message || lang("ai_generate_failed"));
      restoreAfterRegenFail($modal);
    }
  }

  /**
   * 异步任务轮询：每 5 秒查一次任务状态，成功后弹窗预览结果。
   * 网络抖动容忍连续 3 次失败（任务本身仍在后台执行，可到「异步任务」列表找回）。
   *
   * @param {number} taskId
   * @param {JQuery} [$modal]
   */
  function pollAsyncTask(taskId, $modal) {
    $modal = $modal && $modal.length ? $modal : $(".dou-modal.is-open").last();
    setGenerating($modal, true, lang("ai_task_polling"));

    var MAX_NET_ERRORS = 3;
    var netErrors = 0;

    var timer = window.setInterval(function () {
      $.ajax({
        url: route("admin.ai.task.show", { id: taskId }),
        type: "GET",
        dataType: "json",
        success: function (resp) {
          netErrors = 0;
          if (!resp || resp.code !== "OK") {
            window.clearInterval(timer);
            failPoll(resp && resp.message);
            return;
          }
          var d = resp.data || {};
          if (d.status === "succeeded") {
            window.clearInterval(timer);
            setGenerating($modal, false);
            showAsyncResult(d.result, d.expires_at, taskId);
          } else if (d.status === "failed" || d.status === "timeout") {
            window.clearInterval(timer);
            failPoll(resp.message || d.error || lang("ai_task_submit_failed"));
          }
        },
        error: function (xhr) {
          // 422 等业务错误：响应体携带真实失败原因（如上游任务失败），直接展示而非当网络抖动
          var resp = xhr.responseJSON;
          if (resp && resp.message) {
            window.clearInterval(timer);
            failPoll(resp.message);
            return;
          }
          // 网络抖动：连续多次失败才终止轮询，任务在后端与上游仍在执行
          if (++netErrors >= MAX_NET_ERRORS) {
            window.clearInterval(timer);
            failPoll(lang("ai_task_poll_network"));
          }
        },
      });
    }, 5000);

    function failPoll(message) {
      setGenerating($modal, false);
      alert(message || lang("ai_generate_failed"));
      restoreAfterRegenFail($modal);
    }
  }

  /**
   * 极端比例（与后端 GenerateService 阈值一致）需要生成后手动裁切。
   *
   * @param {{width:number,height:number}|null} target
   * @return {boolean}
   */
  function needsManualCrop(target) {
    if (!target || !target.width || !target.height) {
      return false;
    }
    var ratio = target.width / target.height;
    return ratio >= 1.9 || ratio <= 0.55;
  }

  /**
   * 异步任务结果展示：图片/视频预览 + 复制链接；过期则给出提示。
   * 图片字段生成链路附带「使用此图」：按 task_id + index 取回字节注入目标字段。
   * 极端比例成品：生成后直接打开裁剪（默认垂直居中），取消/关闭不弹结果预览。
   *
   * @param {object|null} result {urls: string[]} 或 null
   * @param {string|null} expiresAt 结果过期时间（Y-m-d H:i:s）
   * @param {number|string} [taskId] 任务 ID（「使用此图」需要）
   * @param {object} [opts]
   */
  function showAsyncResult(result, expiresAt, taskId, opts) {
    opts = opts || {};
    lastAssistResult = { result: result, expiresAt: expiresAt, taskId: taskId };

    var urls = (result && result.urls) || [];
    var useTaskId = taskId ? parseInt(taskId, 10) || 0 : 0;
    var useField = imageAssistField;
    var firstIsVideo = urls.length ? /\.(mp4|mov|webm|avi|mkv)(\?|#|$)/i.test(urls[0]) : true;

    if (!opts.skipCrop && needsManualCrop(imageAssistCropTarget) && useTaskId && useField && urls.length && !firstIsVideo) {
      // 生成后直接进入裁剪；取消/关闭即结束，不再弹结果预览
      // （结果预览里仍保留「裁剪」按钮，可随时手动进入裁剪，取消后回到预览）
      openGeneratedImageCrop(useTaskId, 0, useField, imageAssistCropTarget);
      return;
    }

    if (window.douModal && typeof window.douModal.close === "function") {
      window.douModal.close();
    }

    var expired = false;
    if (expiresAt) {
      var exp = new Date(String(expiresAt).replace(/-/g, "/"));
      expired = !isNaN(exp.getTime()) && exp.getTime() < Date.now();
    }

    var html = '<div class="ai-result">';
    var i;
    if (!urls.length) {
      html += '<pre class="ai-result-raw">' + escapeHtml(JSON.stringify(result || {}, null, 2)) + "</pre>";
    } else {
      if (expired) {
        html += '<div class="ai-result-expired">' + escapeHtml(lang("ai_task_result_expired")) + "</div>";
      }
      for (i = 0; i < urls.length; i++) {
        var url = urls[i];
        var isVideo = /\.(mp4|mov|webm|avi|mkv)(\?|#|$)/i.test(url);
        var media = isVideo
          ? '<video class="ai-result-media" src="' + escapeHtml(url) + '" controls preload="metadata"></video>'
          : '<img class="ai-result-media" src="' + escapeHtml(url) + '" alt="" loading="lazy" />';
        var useBtn = "";
        var cropBtn = "";
        if (useField && useTaskId && !isVideo) {
          useBtn =
            '<a href="javascript:;" class="ai-use-image"'
            + ' data-task-id="' + useTaskId + '"'
            + ' data-index="' + i + '"'
            + ' data-field="' + escapeHtml(useField) + '">'
            + '<i class="bi-check2"></i>'
            + escapeHtml(lang("ai_image_use", "使用此图"))
            + "</a>";
          if (needsManualCrop(imageAssistCropTarget)) {
            cropBtn =
              '<a href="javascript:;" class="ai-crop-image"'
              + ' data-task-id="' + useTaskId + '"'
              + ' data-index="' + i + '"'
              + ' data-field="' + escapeHtml(useField) + '"'
              + ' data-crop-width="' + imageAssistCropTarget.width + '"'
              + ' data-crop-height="' + imageAssistCropTarget.height + '">'
              + '<i class="bi-crop"></i>'
              + escapeHtml(lang("ai_image_crop", "裁剪"))
              + "</a>";
          }
        }
        var regenBtn = lastImageGenRequest
          ? '<a href="javascript:;" class="ai-regenerate">'
            + '<i class="bi-arrow-clockwise"></i>'
            + escapeHtml(lang("ai_image_regenerate", "重新生成"))
            + "</a>"
          : "";
        html +=
          '<div class="ai-result-item">' +
          media +
          '<div class="ai-result-actions">' +
          useBtn +
          cropBtn +
          '<a href="javascript:;" class="ai-copy" data-url="' + escapeHtml(url) + '">' +
          '<i class="bi-clipboard"></i>' +
          escapeHtml(lang("ai_task_copy")) +
          "</a>" +
          '<a href="' + escapeHtml(url) + '" class="ai-result-view" target="_blank" rel="noopener">' +
          '<i class="bi-box-arrow-up-right"></i>' +
          escapeHtml(lang("ai_task_view")) +
          "</a>" +
          regenBtn +
          "</div>" +
          "</div>";
      }
    }
    html += "</div>";

    if (window.douModal) {
      window.douModal.open({
        title: lang("ai_task_result_title"),
        placement: "right",
        size: "lg",
        bodyHtml: html,
      });
    } else {
      alert(urls.join("\n"));
    }
  }

  /**
   * 结果弹窗「裁剪」：取回产物后打开裁剪窗，确认后回填字段。
   *
   * @param {JQuery} $btn
   */
  function cropGeneratedImage($btn) {
    var taskId = parseInt($btn.attr("data-task-id"), 10) || 0;
    var index = parseInt($btn.attr("data-index"), 10) || 0;
    var field = String($btn.attr("data-field") || "");
    var width = parseInt($btn.attr("data-crop-width"), 10) || 0;
    var height = parseInt($btn.attr("data-crop-height"), 10) || 0;
    if (!taskId || !field || width <= 0 || height <= 0 || $btn.hasClass("disabled")) {
      return;
    }
    var originalText = $btn.text();
    $btn.addClass("disabled").text(lang("ai_image_loading", "获取中..."));
    openGeneratedImageCrop(taskId, index, field, { width: width, height: height }, function () {
      $btn.removeClass("disabled").text(originalText);
      if (lastAssistResult) {
        showAsyncResult(lastAssistResult.result, lastAssistResult.expiresAt, lastAssistResult.taskId, { skipCrop: true });
      }
    });
  }

  /**
   * 结果弹窗「重新生成」：按最近一次图片生成的提交参数原样重提，
   * 复用任务链路（同步终态直接预览 / 异步轮询），失败后回到上次结果预览。
   */
  function regenerateLastImage() {
    if (!lastImageGenRequest) {
      return;
    }
    if (window.douModal && typeof window.douModal.close === "function") {
      window.douModal.close();
    }
    var $modal = null;
    if (window.douModal) {
      $modal = window.douModal.open({
        title: lang("ai_task_result_title"),
        placement: "right",
        size: "lg",
        bodyHtml: '<div class="ai-result"><div class="ai-regenerating">' +
          generatingMarkup(lang("ai_generating", "正在生成...")) +
          "</div></div>",
        buttons: [],
      });
      $modal.addClass("ai-regen-modal");
    }
    post("admin.ai_generate.field.store", lastImageGenRequest.data, function () {
      // 任务链路成功时由 showAsyncResult 关闭本加载窗并弹结果
    }, $modal);
  }

  /**
   * 重新生成失败（提交失败 / 轮询失败）后，回到上次结果预览。
   *
   * @param {JQuery} [$modal] 当前加载弹窗
   */
  function restoreAfterRegenFail($modal) {
    if (!$modal || !$modal.length || !$modal.hasClass("ai-regen-modal") || !lastAssistResult) {
      return;
    }
    showAsyncResult(lastAssistResult.result, lastAssistResult.expiresAt, lastAssistResult.taskId, { skipCrop: true });
  }

  /**
   * 取回任务图并打开裁剪：比例锁定成品宽高，裁剪框默认垂直居中。
   *
   * @param {number} taskId
   * @param {number} index
   * @param {string} field
   * @param {{width:number,height:number}} cropTarget
   * @param {function} onCancel
   */
  function openGeneratedImageCrop(taskId, index, field, cropTarget, onCancel) {
    if (window.douModal && typeof window.douModal.close === "function") {
      window.douModal.close();
    }
    if (window.douModal) {
      window.douModal.open({
        title: lang("ai_image_crop", "裁剪"),
        bodyHtml: "<p>" + escapeHtml(lang("ai_image_crop_loading", "正在打开裁剪…")) + "</p>",
        buttons: [],
      });
    }

    fetchTaskImageBase64(taskId, index, function (base64) {
      if (window.douModal && typeof window.douModal.close === "function") {
        window.douModal.close();
      }
      var file = base64ToFile(base64);
      if (!file || !window.douCrop || typeof window.douCrop.editFile !== "function") {
        if (typeof onCancel === "function") {
          onCancel();
        }
        return;
      }
      var ratio = cropTarget.width + "/" + cropTarget.height;
      var ext = (file.name.split(".").pop() || "jpg").toLowerCase();
      window.douCrop.editFile(
        file,
        ratio,
        function (blob) {
          if (!blob) {
            if (typeof onCancel === "function") {
              onCancel();
            }
            return;
          }
          var cropped = new File([blob], file.name, { type: blob.type || file.type });
          applyFileToFileField(field, cropped, { skipCropOnUpload: true });
        },
        ext,
        {
          centerVertically: true,
          outputWidth: cropTarget.width,
          maxEdge: Math.max(cropTarget.width, cropTarget.height),
          onRegenerate: function () {
            regenerateLastImage();
          },
        }
      );
    }, function (message) {
      alert(message || lang("ai_image_fetch_failed", "获取图片失败"));
      if (typeof onCancel === "function") {
        onCancel();
      }
    });
  }

  /**
   * POST admin.ai.task.image 取回任务产物 base64。
   *
   * @param {number} taskId
   * @param {number} index
   * @param {function} onOk
   * @param {function} onFail
   */
  function fetchTaskImageBase64(taskId, index, onOk, onFail) {
    $.ajax({
      url: route("admin.ai.task.image"),
      type: "POST",
      data: { task_id: taskId, index: index },
      dataType: "json",
      success: function (resp) {
        if (resp && resp.code === "OK" && resp.data && resp.data.data) {
          onOk(String(resp.data.data));
          return;
        }
        onFail((resp && resp.message) || lang("ai_image_fetch_failed", "获取图片失败"));
      },
      error: function (xhr) {
        var resp = xhr.responseJSON;
        onFail((resp && resp.message) || lang("ai_image_fetch_failed", "获取图片失败"));
      },
    });
  }

  /**
   * 「使用此图」：POST admin.ai.task.image 取回任务产物字节（服务端代取，规避 SSRF），
   * 还原为 File 注入目标图片字段的 file input，触发 change 走既有上传/预览通道。
   *
   * @param {JQuery} $btn 结果弹窗中的 .ai-use-image
   */
  function useGeneratedImage($btn) {
    var taskId = parseInt($btn.attr("data-task-id"), 10) || 0;
    var index = parseInt($btn.attr("data-index"), 10) || 0;
    var field = String($btn.attr("data-field") || "");
    if (!taskId || !field || $btn.hasClass("disabled")) {
      return;
    }
    var originalText = $btn.text();
    $btn.addClass("disabled").text(lang("ai_image_loading", "获取中..."));

    fetchTaskImageBase64(taskId, index, function (base64) {
      applyImageToFileField(field, base64);
    }, function (message) {
      $btn.removeClass("disabled").text(originalText);
      alert(message);
    });
  }

  /**
   * base64 → File。
   *
   * @param {string} base64
   * @return {File|null}
   */
  function base64ToFile(base64) {
    var mime = sniffImageMime(base64);
    var ext = (mime.split("/")[1] || "png").replace("jpeg", "jpg");
    var bytes;
    try {
      var binary = atob(base64);
      bytes = new Uint8Array(binary.length);
      for (var i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
      }
    } catch (e) {
      return null;
    }
    return new File([bytes], "ai-" + Date.now() + "." + ext, { type: mime });
  }

  /**
   * base64 → File → DataTransfer 注入 file input 并触发 change
   * （file-input 组件的预览/裁剪逻辑挂在 change 上，自动接管）。
   *
   * @param {string} field 目标图片字段名
   * @param {string} base64 图片字节（base64，不带 data: 前缀）
   */
  function applyImageToFileField(field, base64) {
    var file = base64ToFile(base64);
    if (!file) {
      alert(lang("ai_image_fetch_failed", "获取图片失败"));
      return;
    }
    applyFileToFileField(field, file, {});
  }

  /**
   * File 注入目标图片字段。
   *
   * @param {string} field
   * @param {File} file
   * @param {object} opts skipCropOnUpload 为真时暂关「上传时裁剪」，避免二次裁剪弹窗
   */
  function applyFileToFileField(field, file, opts) {
    opts = opts || {};
    var $input = $('.form .file-input input[type="file"][name="' + field + '"]').first();
    if (!$input.length || !window.DataTransfer || typeof File !== "function") {
      alert(lang("ai_image_field_missing", "未找到目标图片字段"));
      return;
    }

    imageAssistField = "";
    if (window.douModal && typeof window.douModal.close === "function") {
      window.douModal.close();
    }

    var $pref = $input.closest(".file-input").find(".file-input-crop-pref");
    var wasChecked = $pref.prop("checked");
    if (opts.skipCropOnUpload) {
      $pref.prop("checked", false);
    }

    var transfer = new DataTransfer();
    transfer.items.add(file);
    $input[0].files = transfer.files;
    $input.trigger("change");

    if (opts.skipCropOnUpload) {
      $pref.prop("checked", wasChecked);
    }
  }

  /**
   * 按 base64 魔数嗅探真实 MIME（生成图可能为 png / jpeg / webp / gif）。
   *
   * @param {string} base64
   * @return {string}
   */
  function sniffImageMime(base64) {
    if (base64.indexOf("iVBORw0KGgo") === 0) {
      return "image/png";
    }
    if (base64.indexOf("/9j/") === 0) {
      return "image/jpeg";
    }
    if (base64.indexOf("UklGR") === 0) {
      return "image/webp";
    }
    if (base64.indexOf("R0lGOD") === 0) {
      return "image/gif";
    }
    return "image/png";
  }

  /**
   * 复制文本到剪贴板（Clipboard API 优先，降级 execCommand）。
   *
   * @param {string} text
   */
  function copyText(text) {
    if (!text) {
      return;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(
        function () {
          alert(lang("ai_task_copy_done"));
        },
        function () {
          fallbackCopy(text);
        }
      );
      return;
    }
    fallbackCopy(text);
  }

  function fallbackCopy(text) {
    var $tmp = $('<textarea style="position:absolute;left:-9999px;"></textarea>')
      .val(text)
      .appendTo("body");
    $tmp[0].select();
    document.execCommand("copy");
    $tmp.remove();
    alert(lang("ai_task_copy_done"));
  }

  /**
   * 字段读取：编辑器（vditor getValue / ueditor getContent）优先，普通控件按 name 取值。
   *
   * @param {string} name
   * @return {string}
   */
  function getFieldValue(name) {
    if (!name) {
      return "";
    }

    if (window.douEditor && window.douEditor[name]) {
      var editor = window.douEditor[name];
      if (typeof editor.getValue === "function") {
        return String(editor.getValue() || "");
      }
      if (typeof editor.getContent === "function") {
        if (typeof editor.sync === "function") {
          editor.sync();
        }
        return String(editor.getContent() || "");
      }
    }

    var $shadow = $("#" + name + "Textarea");
    if ($shadow.length) {
      return String($shadow.val() || "");
    }

    var $el = $('[name="' + name + '"]');
    if (!$el.length) {
      return "";
    }

    return String($el.first().val() || "");
  }

  /**
   * 字段回填：编辑器（vditor setValue / ueditor setContent）优先，普通控件按 name 赋值。
   */
  function setFieldValue(name, value) {
    if (value === null || value === undefined) {
      return;
    }
    value = String(value);

    if (window.douEditor && window.douEditor[name]) {
      var editor = window.douEditor[name];
      if (typeof editor.setValue === "function") {
        editor.setValue(value);
      } else if (typeof editor.setContent === "function") {
        editor.setContent(textToHtml(value));
      }
      var $shadow = $("#" + name + "Textarea");
      if ($shadow.length) {
        $shadow.val(value);
      }
      return;
    }

    var $el = $('[name="' + name + '"]');
    if (!$el.length) {
      return;
    }
    var el = $el.first();
    if (el.is(":radio") || el.is(":checkbox")) {
      $el.filter('[value="' + value + '"]').prop("checked", true);
    } else {
      el.val(value).trigger("change");
    }
  }

  /**
   * assist 表单快照：标题 / 分类名 / 正文 / 关键词 / 描述。
   *
   * @return {{title:string,category:string,content:string,keywords:string,description:string}}
   */
  function formSnapshot() {
    var title = String(getFieldValue("title") || getFieldValue("name") || "").trim();
    return {
      title: title,
      category: selectedOptionText("category_id") || selectedOptionText("parent_id"),
      content: String(getFieldValue("content") || "").trim(),
      keywords: String(getFieldValue("keywords") || "").trim(),
      description: String(getFieldValue("description") || "").trim(),
    };
  }

  /**
   * @param {string} name
   * @return {string}
   */
  function selectedOptionText(name) {
    var $el = $('[name="' + name + '"]').first();
    if (!$el.length || !$el.is("select")) {
      return "";
    }
    var val = String($el.val() || "");
    if (val === "" || val === "0") {
      return "";
    }
    return $.trim($el.find("option:selected").text() || "");
  }

  function textToHtml(value) {
    return escapeHtml(value).replace(/\n/g, "<br/>");
  }

  function queryParam(name) {
    var match = new RegExp("[?&]" + name + "=([^&]*)").exec(window.location.search);
    return match ? decodeURIComponent(match[1].replace(/\+/g, " ")) : "";
  }

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }
})();

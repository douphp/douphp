/**
 +----------------------------------------------------------
 * 页面加载时运行
 +----------------------------------------------------------
 */
$(function () {
  // 删除按钮事件委托（替代内联 onclick="douDelete(...)"，避免单引号嵌套冲突）
  // 配合 data-url 提供删除地址，data-confirm 可选提供确认文案
  $(document).on("click", ".js-delete", function (e) {
    e.preventDefault();
    var url = $(this).data("url");
    var confirmMsg = $(this).data("confirm");
    return douDelete(url, confirmMsg);
  });

  // 通用 POST 动作事件委托（替代内联 onclick="douPost(...)"，避免单引号嵌套冲突）
  // 优先读 data-url，否则回退到 <a> 的 href；data-confirm 可选提供确认文案
  $(document).on("click", ".js-post", function (e) {
    e.preventDefault();
    var url = $(this).data("url") || this.href;
    var confirmMsg = $(this).data("confirm");
    return douPost(url, confirmMsg);
  });

  // 行内布尔切换事件委托（js-toggle 协议）——AJAX 无刷新切换，
  // 取代 js-post 整页式启停与 Alpine changeField。协议与接入方法：
  //
  //   <a href="javascript:;" class="js-toggle"
  //      data-url="/admin/..."                       切换端点（POST，成功须返回 {code:'OK', message, data:{value:1|0}}）
  //      data-post='{"module":"product","item_id":5,"field":"status"}'
  //                                                  随请求提交的业务参数（JSON，可省略；参数走 URL 时无需）
  //      data-state="on|off"                         当前状态（on=1 显示/启用，off=0 隐藏/停用）
  //      data-text-on / data-text-off                状态为 on/off 时本元素显示的文字
  //      data-on-class / data-off-class              状态为 on/off 时本元素的 class（纯文字链接可省略）
  //      data-confirm-on / data-confirm-off          转为 on/off 前的确认文案（可省略，缺省不弹窗）
  //      ></a>
  //
  //   同一行内可放多个纯展示徽章 class="js-toggle-view"（text/class 属性同上，随状态一起翻转）；
  //   「状态徽章即切换按钮」形态：单一元素同时带 js-toggle 与视图属性（如 product.htm 状态列、
  //   ai_model.htm 供应商状态），可再配 title 提示可点击；
  //   所在 <tr> 可声明 data-off-row-class="row-disabled"（值为 off 时加类，整行灰显）。
  //   服务端：$request->wantsJson() 时返回 ApiResponse::success(['value' => 新值, ...], 文案)，
  //   可复用 BaseController::respondToggle()。参考：ai_model.htm 供应商启停、product.htm 状态列。
  $(document).on("click", ".js-toggle", function (e) {
    e.preventDefault();
    return douToggle($(this));
  });

  // 下拉菜单
  var $dropMenu = $(".dropdown");
  if ($dropMenu.length) {
    $dropMenu.hover(
      function () {
        $(this).addClass("active");
      },
      function () {
        $(this).removeClass("active");
      },
    );
  }

  // 侧栏栏目子菜单：同一份 ul.sub-menu 两种形态——当前模块（li.cur）内联展开由 CSS 控制，
  // 非当前模块悬停弹出。.menu-scroll 有 overflow 裁剪，弹层用 fixed 定位逃出，JS 仅补视口坐标。
  $("#dou-sidebar").on("mouseenter", ".menu-scroll li.has-sub:not(.cur)", function () {
    var $sub = $(this).children("ul.sub-menu");
    if (!$sub.length) {
      return;
    }
    var sidebar = $("#dou-sidebar");
    var popTop = this.getBoundingClientRect().top;
    var popHeight = $sub.outerHeight();
    if (popTop + popHeight > $(window).height()) {
      popTop = $(window).height() - popHeight;
    }
    $sub.css({ top: popTop, left: sidebar.offset().left + sidebar.outerWidth() - 3 });
  });

  // 点击菜单项时保存滚动位置
  $(".menu-scroll").on("click", "a", function () {
    var $menu = $(this).closest(".menu-scroll");
    if ($menu.length) {
      localStorage.setItem("menuScrollPosition", $menu.scrollTop());
    }
  });

  if ($("#index").length) {
    localStorage.removeItem("menuScrollPosition");
  }

  // 页面加载时恢复滚动位置
  var $menu = $(".menu-scroll");
  if ($menu.length) {
    var savedPosition = parseInt(localStorage.getItem("menuScrollPosition")) || 0;

    if (savedPosition) {
      // 有位置缓存时
      $menu.stop().scrollTop(savedPosition);
    } else {
      // 无位置缓存时
      const $element = $('.menu-scroll [data-id="' + cur + '"]');

      // 滚动到该元素（尽量居中）
      if ($element.length) {
        const container = $(".menu-scroll");
        const containerHeight = container.height();
        const elementHeight = $element.outerHeight();
        const elementOffset = $element.offset().top - container.offset().top;
        const scrollPosition = elementOffset - containerHeight / 2 + elementHeight / 2;

        container.scrollTop(scrollPosition);
      }
    }
  }

  // 页面之间操作回到滚动位置
  var $pageScroll = $(".page-scroll-position");
  if ($pageScroll.length) {
    $pageScroll.on("click", "a", function (e) {
      localStorage.setItem("pageScrollPosition", $(window).scrollTop());
    });

    var savePagePosition = parseInt(localStorage.getItem("pageScrollPosition")) || 0;
    if (savePagePosition) {
      setTimeout(function () {
        $(window).scrollTop(savePagePosition);
        localStorage.removeItem("pageScrollPosition");
      }, 100);
    }
  }

  // 单图 file input：本地预览、回显、清除，随表单提交
  $(document).on("change", ".file-input-crop-pref", function () {
    var $el = $(this);
    var on = $el.prop("checked") ? 1 : 0;
    var scope = $el.attr("data-crop-scope") || ($el.closest(".file-box").length ? "gallery" : "thumb");
    var token = $("#dou-post-form input[name=token]").val() || "";
    $.ajax({
      type: "POST",
      url: route("admin.file.cropPref"),
      data: { crop: on, scope: scope, token: token },
      dataType: "json",
    });
  });

  $(".file-input").each(function () {
    var box = $(this);
    var fileInput = box.find('input[type="file"]');
    var preview = box.find(".file-input-preview");
    var clearBtn = box.find(".file-input-remove");
    var cropBtn = box.find(".file-input-action-crop");
    var initialSrc = box.data("initial-src") || "";
    var initialNumber = box.data("initial-number") || "";
    var hasLocalFile = false;

    // 已保存图才显示蒙板「裁剪」；本地未保存文件无可替换的附件号
    function refreshCropAction() {
      var src = String(initialSrc || "");
      var show = !!initialNumber && !hasLocalFile && window.douCrop && window.douCrop.editableExt(src);
      box.toggleClass("file-input-croppable", show);
    }

    // 高度固定 88px，只按图片宽高比改 stage 宽度（jQuery 1.10 不能写 CSS 变量）
    function setStageWidth(px) {
      var width = Math.max(100, parseInt(px, 10) || 100) + "px";
      var stageEl = box.find(".file-input-stage")[0];
      if (stageEl) {
        stageEl.style.width = width;
      }
    }

    function syncStageWidth() {
      var img = preview[0];
      var height = 88;

      if (!box.hasClass("file-input-filled") || !img || !preview.attr("src")) {
        setStageWidth(100);
        return;
      }

      var w = img.naturalWidth;
      var h = img.naturalHeight;
      if (w > 0 && h > 0) {
        setStageWidth(Math.round((height * w) / h));
      } else {
        setStageWidth(100);
      }
    }

    function syncStageWidthWhenReady() {
      var img = preview[0];
      if (!img || !preview.attr("src")) {
        syncStageWidth();
        return;
      }

      if (img.decode) {
        img.decode().then(function () {
          syncStageWidth();
        }).catch(function () {
          syncStageWidth();
        });
        return;
      }

      if (img.complete) {
        window.setTimeout(function () {
          syncStageWidth();
        }, 0);
        return;
      }

      preview.one("load.fileInputStage", function () {
        syncStageWidth();
      }).one("error.fileInputStage", function () {
        setStageWidth(100);
      });
    }

    function showPreview(src) {
      preview.off("load.fileInputStage error.fileInputStage");
      box.addClass("file-input-filled").removeClass("file-input-no-reader");
      preview.attr("src", src).show();
      syncStageWidthWhenReady();
    }

    function showEmpty() {
      preview.off("load.fileInputStage");
      preview.attr("src", "").hide();
      box.removeClass("file-input-filled file-input-no-reader file-input-croppable");
      setStageWidth(100);
    }

    function restoreInitial() {
      hasLocalFile = false;
      if (initialSrc && initialNumber) {
        showPreview(initialSrc);
      } else {
        showEmpty();
      }
      refreshCropAction();
    }

    function previewFile(file) {
      if (window.FileReader) {
        var reader = new FileReader();
        reader.onload = function (event) {
          showPreview(event.target.result);
        };
        reader.readAsDataURL(file);
      } else {
        box.addClass("file-input-filled file-input-no-reader");
      }
    }

    function applyLocalFile(file) {
      hasLocalFile = true;
      refreshCropAction();
      previewFile(file);
    }

    fileInput.on("change", function () {
      var file = fileInput[0].files[0];
      if (!file) {
        return;
      }

      var cropOn = box.find(".file-input-crop-pref").prop("checked");
      var canCropFile = cropOn && window.douCrop && window.douCrop.editableFile && window.douCrop.editableFile(file);
      if (canCropFile) {
        window.douCrop.editFile(file, box.data("crop-ratio"), function (blob) {
          if (!blob) {
            fileInput.val("");
            restoreInitial();
            return;
          }
          if (!replaceInputFile(fileInput[0], blob, file.name)) {
            fileInput.val("");
            restoreInitial();
            return;
          }
          applyLocalFile(fileInput[0].files[0] || file);
          flashUpdated(box);
        });
        return;
      }

      applyLocalFile(file);
      flashUpdated(box);
    });

    box.find(".file-input-action-upload, .file-input-action-replace").on("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      fileInput.trigger("click");
    });

    cropBtn.on("click", function (e) {
      e.preventDefault();
      e.stopPropagation();
      fileCrop(initialNumber, initialSrc, box.data("crop-ratio"), function (url) {
        if (!url) {
          return;
        }
        initialSrc = url;
        box.data("initial-src", url);
        showPreview(url);
        flashUpdated(box);
      });
    });

    clearBtn.on("click", function (e) {
      e.preventDefault();
      e.stopPropagation();

      fileInput.val("");

      if (hasLocalFile) {
        restoreInitial();
        return;
      }

      if (initialNumber) {
        fileDel(initialNumber);
        initialSrc = "";
        initialNumber = "";
        box.data("initial-src", "");
        box.data("initial-number", "");
      }

      showEmpty();
      refreshCropAction();
    });

    if (box.hasClass("file-input-filled")) {
      syncStageWidthWhenReady();
    }

    refreshCropAction();
  });

  $(document).on("click", ".file-box .file-list .file-input-action-crop", function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $li = $(this).closest(".file-item");
    var img = $li.find("img")[0];
    var number = $li.attr("data-number") || "";
    var ratio = $li.closest(".file-box").attr("data-crop-ratio") || "";
    if (!number || !img) {
      return;
    }
    fileCrop(number, img.src, ratio, function (url) {
      if (url) {
        img.src = url;
        flashUpdated($(img).closest(".file-item"));
      }
    });
  });

  $(document).on("click", ".file-box .file-list .file-input-action-replace", function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $li = $(this).closest(".file-item");
    var img = $li.find("img")[0];
    var number = $li.attr("data-number") || "";
    var ratio = $li.closest(".file-box").attr("data-crop-ratio") || "";
    if (!number || !img) {
      return;
    }
    fileReplace(number, img, ratio);
  });

  $(document).on("click", ".file-box .file-list .file-input-remove", function (e) {
    e.preventDefault();
    e.stopPropagation();
    var $li = $(this).closest(".file-item");
    var number = $li.attr("data-number") || "";
    var listId = $li.closest(".file-list").attr("id") || "";
    if (!number) {
      return;
    }
    fileDel(number, listId);
  });

  // 弹出菜单
  $("#switch-menu").click(function () {
    if ($("#dou-sidebar").hasClass("open")) {
      $("#dou-sidebar").removeClass("open");
    } else {
      $("#dou-sidebar").addClass("open");
    }
  });

  // 文本复制
  $(".copytext").on("click", function () {
    const $el = $(this);
    // 优先使用 data-text，如果没有则使用元素的文本内容
    let text = $el.data("text") || $el.text().trim();
    if (!text) return;

    // 复制文本
    const $temp = $("<textarea>");
    $("body").append($temp);
    $temp.val(text).select();
    document.execCommand("copy");
    $temp.remove();

    // 移除已有的提示（防重复）
    $el.find(".hint").remove();

    // 创建新提示
    const $hint = $('<i class="hint">' + lang('copy_success', '复制成功！') + '</i>');
    $el.append($hint);

    // 触发淡入（下一帧加 .show）
    setTimeout(() => {
      $hint.addClass("show");
    }, 10); // 微小延迟确保 transition 生效

    // 1秒后开始淡出，再过0.2秒彻底移除
    setTimeout(() => {
      $hint.removeClass("show");
      setTimeout(() => {
        $hint.remove();
      }, 200); // 等待淡出动画完成
    }, 1000);
  });

  // textarea 自动高度
  $.fn.autoTextarea = function () {
    this.each(function () {
      var $ta = $(this);
      var minRows = parseInt($ta.data("min"), 10);
      var maxRows = parseInt($ta.data("max"), 10);
      // 计算行高
      var lineHeight = parseFloat($ta.css("line-height"));
      if (isNaN(lineHeight)) {
        var fontSize = parseFloat($ta.css("font-size"));
        lineHeight = fontSize * 1.2;
      }
      // 边框和内边距补偿（针对 border-box）
      var extra = 0;
      if ($ta.css("box-sizing") === "border-box") {
        extra = parseFloat($ta.css("border-top-width")) + parseFloat($ta.css("border-bottom-width")) + parseFloat($ta.css("padding-top")) + parseFloat($ta.css("padding-bottom"));
      }
      var minHeight = isNaN(minRows) ? null : minRows * lineHeight + extra;
      var maxHeight = isNaN(maxRows) ? null : maxRows * lineHeight + extra;
      // 设置样式限制
      if (minHeight !== null) $ta.css("min-height", minHeight + "px");
      if (maxHeight !== null) $ta.css("max-height", maxHeight + "px");
      // 高度自适应函数
      function adjust() {
        $ta.css("height", "auto");
        var height = $ta[0].scrollHeight;
        if (maxHeight !== null && height > maxHeight) height = maxHeight;
        if (minHeight !== null && height < minHeight) height = minHeight;
        $ta.css("height", height + "px");
      }
      $ta.on("input", adjust);
      adjust();
    });
    return this;
  };

  $(function () {
    if ($(".auto-textarea").length > 0) {
      $(".auto-textarea").autoTextarea();
    }
  });

  // jQuery
});

/**
 +----------------------------------------------------------
 * alpine
 +----------------------------------------------------------
 */
document.addEventListener("alpine:init", () => {
  // 拼音生成组件（已有）
  Alpine.data("slugApp", () => ({
    autoSlug() {
      // 1. 获取输入
      const nameInput = document.querySelector('input[name="name"], input[name="title"]');
      if (!nameInput || !nameInput.value) return;

      const rawText = nameInput.value.trim();
      if (!rawText) return;

      // 2. 检查拼音库
      if (!window.Pinyin || !window.Pinyin.isSupported()) {
        alert(lang('pinyin_lib_missing', '拼音库未加载'));
        return;
      }

      // 3. 判断是否包含中文
      const hasChinese = /[\u4e00-\u9fa5]/.test(rawText);

      // 4. 生成基础字符串
      let base = rawText;
      if (hasChinese) {
        // 中文部分使用拼音库转换（已小写、连字符分隔）
        base = window.Pinyin.convertToPinyin(rawText, "-", true);
      }

      // 5. 统一转换为 slug 格式
      const slug = this.toSlug(base);

      // 6. 填充
      const slugInput = document.querySelector('input[name="slug"]');
      if (slugInput) {
        slugInput.value = slug;
      }
    },

    // 将任意字符串转换为 slug 格式
    toSlug(str) {
      return str
        .toLowerCase() // 转小写
        .replace(/[^\w\u4e00-\u9fa5-]+/g, "-") // 非字母数字中文连字符替换为 -
        .replace(/-+/g, "-") // 多个连续 - 合并为一个
        .replace(/^-|-$/g, ""); // 去掉首尾 -
    },
  }));
});


/**
 +----------------------------------------------------------
 * 刷新验证码
 +----------------------------------------------------------
 */
function refreshimage() {
  var cap = document.getElementById("vcode");
  // 如果 URL 已有 ?，则替换现有参数；否则添加 ?
  if (cap.src.indexOf("?") > -1) {
    cap.src = cap.src.replace(/\?.*$/, "?route=captcha&t=" + Date.now());
  } else {
    cap.src = cap.src + "?route=captcha&t=" + Date.now();
  }
}

/**
 +----------------------------------------------------------
 * 无组件刷新局部内容
 *
 * 默认 POST（含 static_admin token，CsrfMiddleware 校验）；method 可选 'GET' / 'DELETE' /
 * 'PUT' / 'PATCH'：GET 把 name=value 追加到 URL 不带 token；其它写动作仍走 POST 但通过
 * data 内 _method 字段做 HTTP 动词伪装（与 Request::method() spoofing 链路一致），保留
 * token 由 CsrfMiddleware 在 POST 阶段校验。
 +----------------------------------------------------------
 */
function dou_callback(page, name, value, target, method) {
  method = (method || "POST").toString().toUpperCase();
  var token = $("#dou-post-form input[name=token]").val() || "";

  if (method === "GET") {
    var sep = page.indexOf("?") >= 0 ? "&" : "?";
    page = page + sep + encodeURIComponent(name) + "=" + encodeURIComponent(value);
    $.ajax({
      type: "GET",
      url: page,
      dataType: "html",
      success: function (html) {
        $("#" + target).html(html);
      },
    });
    return;
  }

  var data = encodeURIComponent(name) + "=" + encodeURIComponent(value) + "&token=" + encodeURIComponent(token);
  if (method !== "POST") {
    data += "&_method=" + encodeURIComponent(method);
  }
  $.ajax({
    type: "POST",
    url: page,
    data: data,
    dataType: "html",
    success: function (html) {
      $("#" + target).html(html);
    },
  });
}

/**
 +----------------------------------------------------------
 * 表单全选
 +----------------------------------------------------------
 */
function selectcheckbox(form) {
  for (var i = 0; i < form.elements.length; i++) {
    var e = form.elements[i];
    if (e.name != "chkall" && e.disabled != true) e.checked = form.chkall.checked;
  }
}

/**
 +----------------------------------------------------------
 * 弹出窗口
 +----------------------------------------------------------
 */
function douFrame(name, frame, url) {
  $.ajax({
    type: "POST",
    url: url,
    data: { name: name, frame: frame },
    dataType: "html",
    success: function (html) {
      $(document.body).append(html);
    },
  });
}

/**
 * 点击切换显示和隐藏
 */
function showHide(target) {
  $("#" + target).toggle();
}

/**
 +----------------------------------------------------------
 * 添加文本
 +----------------------------------------------------------
 */
function douInput(target, text) {
  document.getElementById(target).value = text;
}

/**
 +----------------------------------------------------------
 * 清空对象内HTML
 +----------------------------------------------------------
 */
function douRemove(target) {
  var obj = document.getElementById(target);
  obj.parentNode.removeChild(obj);
}

/**
 +----------------------------------------------------------
 * 无刷新自定义导航名称
 +----------------------------------------------------------
 */
function change(id, choose) {
  document.getElementById(id).value = choose.options[choose.selectedIndex].title;
}

/**
 +----------------------------------------------------------
 * 给指定表单赋值
 +----------------------------------------------------------
 */
function changeInput(targer, value) {
  $("#" + targer).attr("value", value);
}

/**
 +----------------------------------------------------------
 * RESTful 删除：经共享隐藏表单以 POST + _method=DELETE 提交到资源成员 URL
 *
 * 浏览器原生 <a>/表单只能发 GET/POST；资源 destroy 动作为 DELETE 方法，故删除入口统一走
 * #dou-delete-form（footer.tpl 单点渲染，已带 static_admin token）：设置 action 后 submit，
 * 由 Request::method() 的方法伪装识别为 DELETE 命中 destroy 路由。confirmMsg 非空时先确认。
 +----------------------------------------------------------
 */
function douDelete(url, confirmMsg) {
  if (confirmMsg && !window.confirm(confirmMsg)) {
    return false;
  }
  var form = document.getElementById("dou-delete-form");
  if (!form) {
    return false;
  }
  form.setAttribute("action", url);
  form.submit();
  return false;
}

/**
 +----------------------------------------------------------
 * 状态变更：经共享隐藏表单以 POST 提交到目标 URL
 *
 * 浏览器原生 <a> 只能发 GET；启用 / 禁用 / 标记已读 / 推送等非删除型写动作统一走
 * #dou-post-form（footer.tpl 单点渲染，已带 static_admin token）：设置 action 后 submit，
 * 由 CsrfMiddleware 校验 token 后命中目标 POST 路由。confirmMsg 非空时先确认。
 +----------------------------------------------------------
 */
function douPost(url, confirmMsg) {
  if (confirmMsg && !window.confirm(confirmMsg)) {
    return false;
  }
  var form = document.getElementById("dou-post-form");
  if (!form) {
    return false;
  }
  form.setAttribute("action", url);
  form.submit();
  return false;
}

/**
 +----------------------------------------------------------
 * 行内布尔切换（js-toggle 协议，协议声明见文件头部 $(function) 内注释）
 *
 * AJAX POST 提交 data-url（携带 data-post 业务参数），成功后以响应中的
 * data.value 为准翻转：本元素文字/类、同一行内 .js-toggle-view 徽章、
 * 行弱化类（tr 的 data-off-row-class）。CSRF 由 dou.csrf.js 注入请求头。
 +----------------------------------------------------------
 */
function douToggle($el) {
  var url = $el.data("url") || $el.attr("href");
  if (!url) {
    return false;
  }
  var state = $el.data("state") === "off" ? "off" : "on";
  var target = state === "on" ? "off" : "on";
  var confirmMsg = $el.data("confirm-" + target);
  if (confirmMsg && !window.confirm(confirmMsg)) {
    return false;
  }

  $.ajax({
    url: url,
    type: "POST",
    data: $el.data("post") || {},
    dataType: "json",
    success: function (resp) {
      if (resp && resp.code === "OK" && resp.data && typeof resp.data.value !== "undefined") {
        douToggleApply($el, resp.data.value ? "on" : "off");
        dou.toast.success(resp.message);
      } else {
        dou.toast.error((resp && resp.message) || lang("ai_toggle_failed", "操作失败"));
      }
    },
    error: function (xhr) {
      var resp = xhr.responseJSON;
      dou.toast.error((resp && resp.message) || lang("ai_toggle_failed", "操作失败"));
    }
  });
  return false;
}

/**
 * 把 on/off 状态应用到切换元素、同行的展示徽章与行弱化类。
 */
function douToggleApply($el, state) {
  var $scope = $el.closest("tr");
  if (!$scope.length) {
    var scopeSelector = $el.data("scope");
    $scope = scopeSelector ? $el.closest(scopeSelector) : $el.parent();
  }

  applyToggleState($el, state);

  $scope.find(".js-toggle-view").each(function () {
    applyToggleState($(this), state);
  });

  var offRowClass = $scope.data("off-row-class");
  if (offRowClass) {
    $scope.toggleClass(offRowClass, state === "off");
  }
}

/**
 * 单元素按状态翻转文字与类。
 *
 * 双内层形态（<span class="js-toggle-state">当前态</span><span class="js-toggle-action">动作</span>，
 * CSS 控制 hover 显隐）时更新两者文字；单文字形态保持整体 text()。
 * remove 仅作用于已声明的两侧类，避免误清其它 class。
 */
function applyToggleState($el, state) {
  $el.data("state", state);

  var text = $el.data("text-" + state);
  var opposite = $el.data(state === "on" ? "text-off" : "text-on");
  var $stateText = $el.find(".js-toggle-state");
  var $actionText = $el.find(".js-toggle-action");
  if ($stateText.length) {
    if (text) {
      $stateText.text(text);
    }
    if (opposite) {
      $actionText.text(opposite);
    }
  } else if (text) {
    $el.text(text);
  }

  var onClass = $el.data("on-class");
  var offClass = $el.data("off-class");
  if (onClass || offClass) {
    $el.removeClass(onClass).removeClass(offClass);
    var nextClass = state === "on" ? onClass : offClass;
    if (nextClass) {
      $el.addClass(nextClass);
    }
  }
}

/**
 +----------------------------------------------------------
 * 移动分类至
 +----------------------------------------------------------
 */
function douAction() {
  var frm = document.forms["action"];
  frm.elements["new_cat_id"].style.display = frm.elements["action"].value == "category_move" ? "" : "none";
}

/**
 +----------------------------------------------------------
 * 文件盒子.上传失败的可读错误文本
 +----------------------------------------------------------
 * JSON envelope（{code, message}）取 message；HTML 错误页（如 413 / 500）
 * 剥掉标签后截取前 100 字符；均无法解析时回退通用上传失败文案。
 */
function fileBoxErrorText(xhr) {
  if (xhr && xhr.responseText) {
    try {
      var json = $.parseJSON(xhr.responseText);
      if (json && json.message) {
        return String(json.message);
      }
    } catch (e) {}
    var plain = String(xhr.responseText)
      .replace(/<[^>]*>/g, " ")
      .replace(/\s+/g, " ")
      .trim();
    if (plain) {
      return plain.slice(0, 100);
    }
  }
  return lang("upload_failed");
}

/**
 +----------------------------------------------------------
 * 文件盒子.文件上传
 +----------------------------------------------------------
 */
function fileBox(type, target, module, item_id, draft_token, img_width, editor, boxTarget) {
  img_width = img_width || "";
  editor = editor || "";
  boxTarget = boxTarget || (target + "File");

  var status = $("#" + boxTarget + " .file-status");
  var btn = $("#" + boxTarget + " .btn-file");
  var form = $("#" + target + "Form");
  var field = $("#" + target + "Field");
  var existingCount = 0;

  if (form.length === 0) {
    var field_name = target + "_file[]"; // 带 [] 才能让 PHP 接收 multiple 多文件数组
    var csrf_token = $("#dou-post-form input[name=token]").val() || "";

    var formHtml = '<form action="' + route("admin.file.box", {}, { query: { module: module, target: target } }) + '" id="' + target + 'Form" enctype="multipart/form-data" style="display:none">' + '<input id="' + target + 'Field" type="file" name="' + field_name + '" multiple>' + '<input type="hidden" name="item_id" value="' + item_id + '">' + '<input type="hidden" name="draft_token" value="' + draft_token + '">' + '<input type="hidden" name="token" value="' + csrf_token + '">' + "</form>";
    $("body").append(formHtml);
    form = $("#" + target + "Form");
    field = $("#" + target + "Field");

    form.ajaxForm({
      type: "POST",
      data: { type: type, img_width: img_width },
      beforeSubmit: function () {
        existingCount = $("#" + target + " .file-item").length;
        status.show();
        btn.hide();
      },
      success: function (html) {
        // 入口对 AJAX 的 DomainException 返回 200 + dou_msg.htm 整页提示（如上传超限），
        // 不能塞进上传列表容器：提取 <h2> 提示文本弹窗
        if (/<html[\s>]|<!DOCTYPE/i.test(html)) {
          var msgMatch = String(html).match(/<h2[^>]*>([\s\S]*?)<\/h2>/i);
          alert(msgMatch ? msgMatch[1].replace(/<[^>]*>/g, "").trim() : fileBoxErrorText(null));
          return;
        }
        if (type == "content") {
          if (html.indexOf("<img") >= 0) {
            if (editor == "vditor") {
              douEditor[target].insertValue(html);
            } else {
              douEditor[target].execCommand("insertHtml", html);
            }
          } else {
            alert(html);
          }
        } else {
          $("#" + target).html(html);
          // 新上传的相册项闪「已更新」反馈（服务器回传整表，旧项在前、新项在后）
          var items = $("#" + target + " .file-item");
          for (var i = existingCount; i < items.length; i++) {
            flashUpdated($(items[i]));
          }
        }
      },
      error: function (xhr) {
        alert(fileBoxErrorText(xhr));
      },
      complete: function () {
        status.hide();
        btn.show();
        field.val("");
      },
      clearForm: true,
    });
  } else {
    form.find('input[name="item_id"]').val(item_id);
    form.find('input[name="draft_token"]').val(draft_token);
  }

  field.click();

  field.off("change").on("change", function () {
    if ($(this).val() == "") {
      return;
    }
    var cropOn = type != "content" && $("#" + boxTarget + " .file-input-crop-pref").prop("checked");
    var nativeFiles = field[0].files;
    if (!cropOn || !nativeFiles || !nativeFiles.length || !window.douCrop) {
      form.submit();
      return;
    }
    var files = [];
    for (var i = 0; i < nativeFiles.length; i++) {
      files.push(nativeFiles[i]);
    }
    var ratio = $("#" + boxTarget).attr("data-crop-ratio") || "";
    cropGalleryFiles(field[0], files, ratio, function (ok) {
      if (ok) {
        form.submit();
      } else {
        field.val("");
      }
    });
  });
}

/**
 +----------------------------------------------------------
 * 相册多图：勾选「上传时裁剪」后逐张弹窗，确认的写回 file input
 +----------------------------------------------------------
 */
function blobToNamedFile(blob, origName) {
  if (!blob || typeof File === "undefined") {
    return null;
  }
  var type = blob.type ? blob.type : "image/jpeg";
  var ext = ".jpg";
  if (type === "image/png") {
    ext = ".png";
  } else if (type === "image/webp") {
    ext = ".webp";
  }
  var base = String(origName || "image").replace(/\.[^.]+$/, "");
  try {
    return new File([blob], base + ext, { type: type, lastModified: Date.now() });
  } catch (e) {
    return null;
  }
}

function cropGalleryFiles(input, files, ratio, done) {
  var kept = [];
  var index = 0;

  function finish() {
    if (!kept.length || typeof DataTransfer === "undefined") {
      done(false);
      return;
    }
    try {
      var dt = new DataTransfer();
      for (var n = 0; n < kept.length; n++) {
        dt.items.add(kept[n]);
      }
      input.files = dt.files;
      done(!!(input.files && input.files.length));
    } catch (e) {
      done(false);
    }
  }

  function next() {
    if (index >= files.length) {
      finish();
      return;
    }
    var file = files[index++];
    var canCrop = window.douCrop.editableFile && window.douCrop.editableFile(file);
    if (!canCrop) {
      kept.push(file);
      next();
      return;
    }
    window.douCrop.editFile(file, ratio || "", function (blob) {
      if (blob) {
        kept.push(blobToNamedFile(blob, file.name) || file);
      }
      next();
    });
  }

  next();
}

/**
 +----------------------------------------------------------
 * 文件盒子.文件删除
 +----------------------------------------------------------
 */
function fileDel(number, target = "") {
  var target = target ? $("#" + target) : "";

  $.ajax({
    type: "POST",
    url: route("admin.file.destroy"),
    data: { number: number, token: $("#dou-post-form input[name=token]").val() || "" },
    dataType: "html",
    success: function (html) {
      if (target) {
        target.html(html);
      }
    },
  });
}

/**
 +----------------------------------------------------------
 * 把裁剪 blob 写回 file input（随表单提交裁剪后的图）
 +----------------------------------------------------------
 */
function replaceInputFile(input, blob, origName) {
  if (typeof DataTransfer === "undefined" || typeof File === "undefined" || !input) {
    return false;
  }
  var type = blob && blob.type ? blob.type : "image/jpeg";
  var ext = ".jpg";
  if (type === "image/png") {
    ext = ".png";
  } else if (type === "image/webp") {
    ext = ".webp";
  }
  var base = String(origName || "image").replace(/\.[^.]+$/, "");
  try {
    var file = new File([blob], base + ext, { type: type, lastModified: Date.now() });
    var dt = new DataTransfer();
    dt.items.add(file);
    input.files = dt.files;
    return !!(input.files && input.files.length);
  } catch (e) {
    return false;
  }
}

/**
 +----------------------------------------------------------
 * 相册项：按附件号覆盖原图（裁剪 / 换图共用）
 +----------------------------------------------------------
 */
function fileSaveCrop(number, blobOrFile, done) {
  var form = new FormData();
  var fname = blobOrFile && blobOrFile.name ? blobOrFile.name : "";
  if (!fname) {
    var mime = blobOrFile && blobOrFile.type ? blobOrFile.type : "";
    var ext = "jpg";
    if (mime === "image/png") {
      ext = "png";
    } else if (mime === "image/webp") {
      ext = "webp";
    }
    fname = "crop." + ext;
  }
  form.append("number", number);
  form.append("file", blobOrFile, fname);
  form.append("token", $("#dou-post-form input[name=token]").val() || "");
  $.ajax({
    type: "POST",
    url: route("admin.file.crop"),
    data: form,
    processData: false,
    contentType: false,
    dataType: "json",
    success: function (res) {
      if (res && res.url) {
        done && done(res.url);
      } else {
        alert((res && res.error) || lang("crop_save_failed", "保存失败"));
        done && done("");
      }
    },
    error: function () {
      alert(lang("crop_save_failed", "保存失败"));
      done && done("");
    },
  });
}

/**
 +----------------------------------------------------------
 * URL / 文件名里的图片扩展名
 +----------------------------------------------------------
 */
function srcFileExt(src) {
  var m = String(src || "").split("?")[0].match(/\.([a-z0-9]+)$/i);
  return m ? m[1].toLowerCase() : "";
}

function extMime(ext) {
  ext = String(ext || "").toLowerCase();
  if (ext === "png") {
    return "image/png";
  }
  if (ext === "webp") {
    return "image/webp";
  }
  if (ext === "jpg" || ext === "jpeg") {
    return "image/jpeg";
  }
  return "";
}

function sameImageExt(a, b) {
  a = String(a || "").toLowerCase();
  b = String(b || "").toLowerCase();
  if (a === "jpeg") {
    a = "jpg";
  }
  if (b === "jpeg") {
    b = "jpg";
  }
  return a !== "" && a === b;
}

/**
 +----------------------------------------------------------
 * 把本地 File 转成原附件扩展名对应的 blob（类型相同则原样返回）
 +----------------------------------------------------------
 */
function fileToBlobAsExt(file, ext, done) {
  var mime = extMime(ext);
  if (!file || !mime) {
    done(null);
    return;
  }
  var fileExtName = srcFileExt(file.name || "");
  if (sameImageExt(fileExtName, ext) || (file.type && file.type === mime)) {
    done(file);
    return;
  }
  if (typeof URL === "undefined" || !URL.createObjectURL) {
    done(null);
    return;
  }
  var url = URL.createObjectURL(file);
  var img = new Image();
  img.onload = function () {
    var canvas = document.createElement("canvas");
    canvas.width = img.naturalWidth || img.width;
    canvas.height = img.naturalHeight || img.height;
    var ctx = canvas.getContext("2d");
    if (mime === "image/jpeg") {
      ctx.fillStyle = "#fff";
      ctx.fillRect(0, 0, canvas.width, canvas.height);
    }
    ctx.drawImage(img, 0, 0);
    URL.revokeObjectURL(url);
    if (!canvas.toBlob) {
      done(null);
      return;
    }
    canvas.toBlob(
      function (blob) {
        done(blob || null);
      },
      mime,
      mime === "image/jpeg" ? 0.85 : undefined
    );
  };
  img.onerror = function () {
    URL.revokeObjectURL(url);
    done(null);
  };
  img.src = url;
}

/**
 +----------------------------------------------------------
 * 上传/替换/裁剪成功后：短暂释放悬停蒙板，闪「已更新」徽标
 * 解决新图被悬停蒙板挡住、用户无法确认是否替换成功的问题。
 * $host：单图 .file-input 或相册 .file-item 的 jQuery 对象
 +----------------------------------------------------------
 */
function flashUpdated($host) {
  if (!$host || !$host.length) {
    return;
  }
  var isItem = $host.hasClass("file-item");
  var flashCls = isItem ? "file-item-flashing" : "file-input-flashing";
  var flashSel = isItem ? ".file-item-flash" : ".file-input-flash";
  var badgeSel = isItem ? ".file-item-flash-badge" : ".file-input-flash-badge";

  if (!$host.hasClass(flashCls)) {
    if (!$host.find(flashSel).length) {
      $host.append(
        '<div class="' + (isItem ? "file-item-flash" : "file-input-flash") + '">' +
        '<span class="' + (isItem ? "file-item-flash-badge" : "file-input-flash-badge") + '">' +
        '<i class="bi-check-lg"></i>' + lang("image_updated", "Updated") +
        "</span></div>"
      );
    }
    $host.addClass(flashCls);
  }

  // 重启动画（连续操作时也重新闪一次）
  var badge = $host.find(badgeSel);
  if (badge.length) {
    badge[0].style.animation = "none";
    void badge[0].offsetWidth; // 强制回流
    badge[0].style.animation = "";
  }

  clearTimeout($host.data("flashTimer"));
  $host.data(
    "flashTimer",
    setTimeout(function () {
      $host.removeClass(flashCls);
    }, 1200)
  );
}

/**
 +----------------------------------------------------------
 * 相册项换图：选本地文件 → 可选裁剪 → admin.file.crop 原路径覆盖
 +----------------------------------------------------------
 */
function fileReplace(number, imgEl, ratio) {
  var input = document.getElementById("fileReplaceField");
  if (!input) {
    input = document.createElement("input");
    input.type = "file";
    input.id = "fileReplaceField";
    input.accept = "image/*";
    input.style.display = "none";
    document.body.appendChild(input);
  }
  input.value = "";
  $(input)
    .off("change.fileReplace")
    .on("change.fileReplace", function () {
      var file = input.files && input.files[0];
      input.value = "";
      if (!file || !imgEl) {
        return;
      }
      var origExt = srcFileExt(imgEl.src);
      var cropOn = $(imgEl).closest(".file-box").find(".file-input-crop-pref").prop("checked");
      var canCropFile = cropOn && window.douCrop && window.douCrop.editableFile && window.douCrop.editableFile(file);
      if (canCropFile) {
        window.douCrop.editFile(
          file,
          ratio || "",
          function (blob) {
            if (!blob) {
              return;
            }
            fileSaveCrop(number, blob, function (url) {
              if (url) {
                imgEl.src = url;
                flashUpdated($(imgEl).closest(".file-item"));
              }
            });
          },
          srcFileExt(file.name) || origExt
        );
        return;
      }
      fileSaveCrop(number, file, function (url) {
        if (url) {
          imgEl.src = url;
          flashUpdated($(imgEl).closest(".file-item"));
        }
      });
    });
  input.click();
}

/**
 +----------------------------------------------------------
 * 文件.图片编辑（上传后裁剪）：弹窗裁剪已上传图片 → admin.file.crop 原路径替换
 * number  dou_file 附件号
 * src     原图 URL（弹窗加载源，扩展名决定导出格式）
 * ratio   初始裁剪比例（可空 = 自由）
 * done    回调 done(url)：成功回传带版本参数的新 URL，取消/失败回传空
 +----------------------------------------------------------
 */
function fileCrop(number, src, ratio, done) {
  if (!window.douCrop) {
    done && done("");
    return;
  }
  window.douCrop.edit(src, ratio || "", function (blob) {
    if (!blob) {
      done && done("");
      return;
    }
    fileSaveCrop(number, blob, done);
  });
}

/**
 +----------------------------------------------------------
 * 查询表单过滤空值
 +----------------------------------------------------------
 */
function hideEmpty(form) {
  // 收集非空参数
  const params = [];

  form.querySelectorAll("input[name], select[name]").forEach((el) => {
    const elementName = el.name;
    const value = el.value;
    let isEmpty = false;

    if (el.type === "text") {
      isEmpty = !value || value.trim() === "";
    } else if (el.name === "status") {
      isEmpty = !value || value === "";
    } else if (el.tagName === "SELECT") {
      isEmpty = !value || value === "" || value == 0;
    } else {
      isEmpty = !value;
    }

    if (!isEmpty) {
      // 对参数进行编码（route 为 module/action，保留路径中的 /，避免 user%2Fcontact）
      const encodedName = encodeURIComponent(el.name);
      let encodedValue;
      if (el.name === "route") {
        encodedValue = el.value
          .split("/")
          .map(function (segment) {
            return encodeURIComponent(segment);
          })
          .join("/");
      } else {
        encodedValue = encodeURIComponent(el.value);
      }
      params.push(`${encodedName}=${encodedValue}`);
    }
  });

  // 构建URL
  // 注意：当表单内存在 name="action" 的子控件（如本页 manager/log 的「操作类型」筛选），
  // form.action 会被命名控件遮蔽返回 HTMLSelectElement，拼出 [object HTMLSelectElement]。
  // 用 getAttribute("action") 取真实的 action 属性字符串，避开命名遮蔽。
  let url = form.getAttribute("action") || form.action;
  if (params.length > 0) {
    // action 可能是伪静态基址（无 ?）或 admin_rewrite 关闭时的 index.php?route= 形态（已含 ?）。
    url += (url.indexOf("?") === -1 ? "?" : "&") + params.join("&");
  }

  // 手动跳转
  window.location.href = url;

  // 阻止表单默认提交行为
  return false;
}

/**
 +----------------------------------------------------------
 * 编辑器插入文本
 +----------------------------------------------------------
 */
function editorInsert(target, html) {
  UM.getEditor(target).execCommand("insertHtml", html);
}

/**
 +----------------------------------------------------------
 * 产品型号
 +----------------------------------------------------------
 */
function modelBox(mode, id, action_id = "") {
  var target = $("#model-list");
  var action_id = action_id ? action_id : $("#modelId").val();

  if (!id) {
    alert(lang('product_model_add_first', '请先添加好商品，重新编辑时才可以添加款式'));
  }

  $.ajax({
    type: "POST",
    url: route("admin.product.model"),
    data: { mode: mode, id: id, action_id: action_id },
    dataType: "html",
    success: function (html) {
      target.html(html);
    },
    error: function () {
      alert(lang('ajax_error', '错误'));
    },
  });
}

/**
 +----------------------------------------------------------
 * 添加文本
 +----------------------------------------------------------
 */
function boxClass(target_a, text_a, target_b, text_b) {
  document.getElementById(target_a).value = text_a;
  document.getElementById(target_b).value = text_b;
}

/**
 +----------------------------------------------------------
 * 大文件上传
 +----------------------------------------------------------
 */
function fileBig(type, target, module, item_id, editor = "ueditor", draft_token = "") {
  // 初始化
  var itemId = "#" + target + "BigFile";

  var fileId = document.querySelector(itemId + " .field");
  var percentId = $(itemId + " .percent");
  var progressId = $(itemId + " .progress");
  var linkId = $(itemId + " .link");
  var sqlLinkUrl = $(itemId + " .link").val();

  //---------------------------
  var file = null;
  var fileMd5Value = "";
  // 分片总数
  var total_blob_num;
  // 进程队列
  var courseQueue = [];
  // 分片队列
  var burstQueue = [];
  // 进度队列
  var progressQueue = [];

  var courseTimer = 0;
  var progressTimer = 0;

  //如果文件大，md5值生成较慢  md5值生成后才能上传处理，自己优化下吧
  browserMD5File(fileId.files[0].slice(0, 1000), function (err, md5) {
    fileMd5Value = md5; //如果需要刷新后也能断点，可利用cookie记录，自行完善
  });

  // 验证文件格式
  var check_ext = "";
  $.ajax({
    type: "POST",
    url: route("admin.file.bigfile", {}, { query: { act: "ext" } }),
    data: { check_filename: fileId.files[0]["name"], token: $("#dou-post-form input[name=token]").val() || "" },
    dataType: "html",
    async: false,
    success: function (html) {
      if (html) {
        alert(html);
      } else {
        check_ext = true;
      }
    },
  });

  if (check_ext) {
    // 执行上传操作
    restartUpload();

    var step = 1024 * 1024 * 1.8; // 分卷大小1.8M
    file = fileId.files[0];
    total_blob_num = Math.ceil(file.size / step);

    var start = 0;
    var end = start + step;
    for (var blob_num = 1; blob_num <= total_blob_num; blob_num++) {
      burstQueue.push({
        id: blob_num,
        start: start,
        end: end,
      });
      start = end;
      end = start + step;
    }

    // 启动上传队列
    courseTimer = setInterval(sendFile, 10);
    // 启动进程监控器
    progressTimer = setInterval(progressChecker, 1000);

    function restartUpload() {
      clearInterval(courseTimer);
      clearInterval(progressTimer);
      burstQueue = [];
      courseQueue = [];
      progressQueue = [];

      var progress = 0 + "%";
      progressId.css("width", progress);
      percentId.html(progress);
    }

    function progressChecker() {
      var finishNum = progressQueue.length;
      var progress = Math.min(100, (finishNum / total_blob_num) * 100).toFixed(0) + "%";
      console.log("progress-----" + progress);
      progressId.css("width", progress);
      percentId.html(progress);
      if (progressQueue.length === total_blob_num) {
        clearInterval(progressTimer);
        clearInterval(courseTimer);
      }
    }

    // 发送文件
    function sendFile() {
      // 如果队列满负荷运载，则不再起队列
      if (courseQueue.length >= 20) {
        return;
      }

      // 如果分片已经被分配完了，终止定时器
      if (burstQueue.length === 0) {
        return;
      }

      // 否则加入进程列表
      var burst = burstQueue.shift();
      courseQueue.push(1);

      var file_blob = file.slice(burst.start, burst.end);
      var form_data = new FormData();
      form_data.append("file", file_blob); // 创建name为file的文件域
      form_data.append("blob_num", burst.id);
      form_data.append("total_blob_num", total_blob_num);
      form_data.append("file_md5_value", fileMd5Value);
      form_data.append("file_name", file.name);
      form_data.append("sql_link_url", sqlLinkUrl);
      form_data.append("token", $("#dou-post-form input[name=token]").val() || "");

      var xhr = new XMLHttpRequest();
      xhr.open("POST", route("admin.file.bigfile", {}, { query: { module: module, type: type, item_id: item_id, target: target, draft_token: draft_token } }), false);

      xhr.onreadystatechange = function () {
        if (xhr.readyState === 4 && xhr.status === 200) {
          courseQueue.shift();
          progressQueue.push(1);
        }
      };

      xhr.send(form_data);

      // 回调
      var data = JSON.parse(xhr.responseText);

      if (data.wrong) {
        alert(data.wrong);
      }

      // 如果是添加详情图片则插入到编辑器
      if (type == "content") {
        if (editor == "vditor") {
          douEditor[target].insertValue(data.html);
        } else {
          douEditor[target].execCommand("insertHtml", data.html);
        }
      } else {
        linkId.val(data.file_path);
      }
    }
  }

  // 每次执行后要清空文件域
  fileId.value = "";
}

/**
 +----------------------------------------------------------
 * Tab 组件（Bootstrap data-api 风格）
 * 触发器标记 data-toggle="tab"，目标用 href="#id" 或 data-target="#id" 显式配对，
 * 加载即生效、零使用处初始化；同页多实例以 .tab 容器隔离。
 +----------------------------------------------------------
 */
function resolveTabTarget($trigger) {
  var href = $trigger.attr("href");
  if (href && href.charAt(0) === "#" && href.length > 1) return href;
  var target = $trigger.attr("data-target");
  if (target && target.charAt(0) === "#") return target;
  return null;
}

// 归属当前 .tab 容器（排除嵌套 .tab）的面板
function ownTabPanes($root) {
  var root = $root.get(0);
  return $root.find(".tab-pane").filter(function () {
    return $(this).closest(".tab").get(0) === root;
  });
}

// 归属当前 .tab 容器的切换触发器
function ownTabTriggers($root) {
  var root = $root.get(0);
  return $root.find(".tab-title [data-toggle='tab']").filter(function () {
    return $(this).closest(".tab").get(0) === root;
  });
}

// 在指定 .tab 容器内按 id 激活面板（容器内定位，绝不全局查找）
function activateTab($root, selector) {
  var $panes = ownTabPanes($root);
  var $panel = $panes.filter(selector);
  if (!$panel.length) return;
  var $triggers = ownTabTriggers($root);
  $triggers.closest("li").removeClass("active");
  $triggers
    .filter(function () {
      return resolveTabTarget($(this)) === selector;
    })
    .closest("li")
    .addClass("active");
  $panes.removeClass("active");
  $panel.addClass("active");
}

// 默认激活：markup 未给 active 时落到 data-tab-default 或第一个触发器
function applyTabDefaults(context) {
  $(".tab", context).each(function () {
    var $root = $(this);
    var $panes = ownTabPanes($root);
    if (!$panes.length) return; // 纯导航条：仅 CSS + 服务端高亮
    var $triggers = ownTabTriggers($root);
    if ($triggers.closest("li").filter(".active").length && $panes.filter(".active").length) return;
    var selector = $root.attr("data-tab-default");
    if (!selector) {
      var $first = $triggers.first();
      selector = $first.length ? resolveTabTarget($first) : null;
    }
    if (selector) activateTab($root, selector);
  });
}

// data-api：document 级委托，加载即生效，动态注入的 Tab 也能点
$(document).on("click", ".tab .tab-title [data-toggle='tab']", function (e) {
  var $trigger = $(this);
  var $root = $trigger.closest(".tab");
  var selector = resolveTabTarget($trigger);
  if (!selector) return; // 无 #目标：当普通链接放行
  if (!ownTabPanes($root).filter(selector).length) return; // 无对应面板：放行原生跳转
  e.preventDefault();
  activateTab($root, selector);
});

$(function () {
  applyTabDefaults();
});

// 对外 API：脚本切换 / 给动态注入内容补默认态
window.douTab = {
  show: function (groupId, target) {
    var $root = $("#" + groupId);
    if ($root.length) activateTab($root, target.charAt(0) === "#" ? target : "#" + target);
  },
  refresh: applyTabDefaults,
};

/**
 * 后台通用模态框：编程式 douModal.open/show/hide/close + 声明式 data-dou-toggle 委托。
 */
(function (global, $) {
  "use strict";

  var modalSel = ".dou-modal";
  var activeModal = null;
  var animationSpeed = 300;

  var placements = [
    "top-right",
    "top",
    "top-left",
    "center",
    "right",
    "bottom-right",
    "bottom",
    "bottom-left",
    "left",
  ];

  function escapeHtml(value) {
    return String(value == null ? "" : value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function isPlacementName(name) {
    return name === "center" || $.inArray(name, placements) !== -1;
  }

  function placementClass(placement, size) {
    var name = placement || "";
    if (!name && size === "center") {
      name = "center";
    }
    if ($.inArray(name, placements) === -1) {
      name = "right";
    }
    return "pos-" + name;
  }

  function normalizeSize(size) {
    if (!size || size === "default" || size === "center") {
      return "";
    }
    if (size === "big") {
      return "lg";
    }
    if (size === "sm" || size === "lg" || size === "full") {
      return size;
    }
    return "";
  }

  function sizeModifier(size) {
    var token = normalizeSize(size);
    return token ? " size-" + token : "";
  }

  function resolveModal(target) {
    if (!target) {
      return null;
    }
    if (target.jquery) {
      return target.closest(modalSel);
    }
    var sel = String(target).charAt(0) === "#" ? target : "#" + target;
    return $(sel).filter(modalSel).first();
  }

  function applyModalWidth($dialog, options) {
    var width = options.width;
    if (!width) {
      return;
    }
    var px = parseInt(width, 10);
    if (px > 0) {
      $dialog.css("--dou-modal-width", px + "px");
    }
  }

  function buildModalShell(options) {
    options = options || {};
    var posClass = placementClass(options.placement, options.size || options.align);
    var sizeClass = sizeModifier(options.size);
    var className = options.className ? " " + options.className : "";
    var closeLabel = escapeHtml(typeof lang === "function" ? lang("close") : "Close");

    var $modal = $(
      '<div class="dou-modal" data-dou-modal-dynamic="1" tabindex="-1" aria-hidden="true"></div>'
    );
    var $dialog = $('<div class="dou-modal-dialog ' + posClass + sizeClass + className + '"></div>');
    var $content = $('<div class="dou-modal-content"></div>');
    var $header = $(
      '<div class="dou-modal-header">' +
        '<h5 class="dou-modal-title">' +
        escapeHtml(options.title || "") +
        "</h5>" +
        '<a href="javascript:;" class="dou-modal-close" data-dou-dismiss="modal" aria-label="' +
        closeLabel +
        '">&times;</a>' +
        "</div>"
    );
    var $body = $('<div class="dou-modal-body"></div>');
    var $footer = $('<div class="dou-modal-footer"></div>');

    $content.append($header, $body, $footer);
    $dialog.append($content);
    $modal.append($dialog);

    if (options.bodyHtml) {
      $body.html(options.bodyHtml);
    }

    applyModalWidth($dialog, options);

    return {
      $modal: $modal,
      $dialog: $dialog,
      $content: $content,
      $body: $body,
      $footer: $footer,
    };
  }

  function bindModalActions($modal, shell, options) {
    var $footer = shell.$footer;

    if (options.buttons && options.buttons.length) {
      renderButtons($footer, options.buttons, $modal);
    } else if (typeof options.onSubmit === "function") {
      var $submit = $(
        '<div class="dou-modal-actions"><a href="javascript:;" class="btn dou-modal-submit">' +
          escapeHtml(options.submitText || (typeof lang === "function" ? lang("btn_submit") : "OK")) +
          "</a></div>"
      );
      $footer.append($submit);
      var $submitBtn = $submit.find(".dou-modal-submit");
      $submitBtn.on("click", function () {
        if ($submitBtn.hasClass("dou-modal-loading")) {
          return;
        }
        $submitBtn.addClass("dou-modal-loading");
        options.onSubmit($modal, function () {
          $submitBtn.removeClass("dou-modal-loading");
          hide($modal);
        }, function () {
          $submitBtn.removeClass("dou-modal-loading");
        });
      });
    }

    if (typeof options.load === "function") {
      options.load($modal, shell.$body, $footer);
    }
  }

  function revealShow($modal) {
    var $content = $modal.find(".dou-modal-content").first();
    $content.css({ opacity: 0 });
    $modal.css({ display: "block", opacity: 0 });
    $modal.attr("aria-hidden", "false").addClass("is-open");
    $modal.fadeTo(animationSpeed / 2, 1);
    $content.delay(animationSpeed / 2).animate({ opacity: 1 }, animationSpeed);
  }

  function onEscKey(event) {
    if (event.key === "Escape" || event.keyCode === 27) {
      hide();
    }
  }

  function show(target) {
    var $modal = resolveModal(target);
    if (!$modal || !$modal.length) {
      return null;
    }

    if (activeModal && activeModal.length && activeModal[0] !== $modal[0]) {
      hide(activeModal);
    }

    activeModal = $modal;
    $(document).off("keydown.douModal", onEscKey);
    $(document).on("keydown.douModal", onEscKey);

    $modal.off("click.douModalBackdrop").on("click.douModalBackdrop", function (e) {
      if (e.target === $modal[0]) {
        hide($modal);
      }
    });

    revealShow($modal);
    return $modal;
  }

  function hide(target) {
    var $modal = target ? resolveModal(target) : activeModal;
    if (!$modal || !$modal.length) {
      return;
    }

    var isDynamic = $modal.attr("data-dou-modal-dynamic") === "1";
    var $content = $modal.find(".dou-modal-content").first();

    $(document).off("keydown.douModal", onEscKey);

    if ($modal.hasClass("has-editor-fullscreen") || $modal.find(".editor.fullscreen").length) {
      $modal.removeClass("has-editor-fullscreen");
      $modal.find(".editor").removeClass("fullscreen");
      if (!$(".editor.fullscreen").length) {
        $("body").css("overflow", "");
      }
    }

    $modal.stop(true, true).fadeTo(animationSpeed / 2, 0);
    $content.stop(true, true).animate({ opacity: 0 }, animationSpeed, function () {
      $modal.removeClass("is-open").attr("aria-hidden", "true").css("display", "none");
      if (isDynamic) {
        $modal.remove();
      }
      if (activeModal && activeModal[0] === $modal[0]) {
        activeModal = null;
      }
    });
  }

  function close() {
    hide(activeModal);
  }

  function open(options) {
    if (activeModal) {
      hide(activeModal);
    }
    options = options || {};
    var shell = buildModalShell(options);
    var $modal = shell.$modal;

    $modal.appendTo("body");
    bindModalActions($modal, shell, options);

    if (options.closeOnOverlay !== false) {
      $modal.on("click.douModalBackdrop", function (e) {
        if (e.target === $modal[0]) {
          hide($modal);
        }
      });
    }

    show($modal);

    if (typeof options.onOpen === "function") {
      options.onOpen($modal);
    }

    return $modal;
  }

  function renderButtons($footer, buttons, $modal) {
    var i;
    var html = '<div class="dou-modal-actions">';
    for (i = 0; i < buttons.length; i++) {
      var btn = buttons[i];
      var cls = btn.className || btn.style || "btn";
      html +=
        '<a href="javascript:;" class="' +
        escapeHtml(cls) +
        '" data-modal-btn="' +
        i +
        '">' +
        escapeHtml(btn.text || "") +
        "</a>";
    }
    html += "</div>";
    $footer.html(html);

    $footer.find("[data-modal-btn]").each(function () {
      var idx = parseInt($(this).attr("data-modal-btn"), 10);
      var btn = buttons[idx];
      if (!btn) {
        return;
      }
      $(this).on("click", function () {
        var $el = $(this);
        if ($el.hasClass("dou-modal-loading") || $el.hasClass("is-generating") || $el.hasClass("disabled")) {
          return;
        }
        if (typeof btn.onClick === "function") {
          var host = $modal && $modal.length ? $modal : activeModal;
          btn.onClick(host, function () {
            hide(host);
          }, function () {
            /* 失败时保持弹窗打开，由调用方还原按钮状态 */
          }, $el);
          return;
        }
        if (btn.href) {
          if (btn.method === "post") {
            douPost(btn.href);
          } else {
            window.location.href = btn.href;
          }
          close();
        }
      });
    });
  }

  function openMessage($trigger) {
    var title = $trigger.attr("data-title") || "";
    var html = $trigger.attr("data-html") || $trigger.attr("data-text") || "";
    var btnName = $trigger.attr("data-btn-name") || "";
    var btnLink = $trigger.attr("data-btn-link") || "";
    var btnMethod = $trigger.attr("data-btn-method") || "";
    var rawSize = $trigger.attr("data-size") || "";
    var placement = $trigger.attr("data-placement") || $trigger.attr("data-align") || "";
    if (!placement && isPlacementName(rawSize)) {
      placement = rawSize;
    } else if (!placement) {
      placement = "center";
    }
    var size = !rawSize || isPlacementName(rawSize) ? "default" : rawSize;
    var buttons = [];

    if (btnName && btnLink) {
      buttons.push({
        text: btnName,
        className: "btn",
        href: btnLink,
        method: btnMethod,
      });
    }

    open({
      title: title,
      placement: placement,
      size: size,
      width: $trigger.attr("data-width"),
      bodyHtml: html ? html : "",
      buttons: buttons.length ? buttons : undefined,
    });
  }

  function openRestore($trigger) {
    var filename = $trigger.attr("data-filename") || "";
    open({
      title: (typeof lang === "function" ? lang("backup_sql_pre") : "Restore") + " " + filename,
      placement: "right",
      bodyHtml:
        '<div class="dou-modal-message">如果选择 "安全恢复"，系统将会先进行一次自动备份（防止导入后想恢复原来的，备份文件以 "AUTO" 开头），然后再进行导入操作，当然您也可以 "直接恢复"。</div>',
      buttons: [
        {
          text: "安全导入",
          className: "btn btn-primary",
          href: route("admin.backup.backup", {}, { query: { act: "all", restore_filename: filename } }),
          method: "post",
        },
        {
          text: "直接导入",
          className: "btn gray",
          href: route("admin.backup.import", {}, { query: { sql_filename: filename } }),
          method: "post",
        },
      ],
    });
  }

  function openLangBox($trigger) {
    var languagePack = $trigger.attr("data-lang");
    var module = $trigger.attr("data-module");
    var itemId = $trigger.attr("data-item-id");
    var field = $trigger.attr("data-field");
    var token = $trigger.attr("data-token");
    var type = $trigger.attr("data-type");
    var title = $trigger.attr("data-title") || "";

    $trigger.attr("id", languagePack + "_" + field);

    open({
      title: title,
      placement: "right",
      size: "lg",
      className: "is-lang",
      load: function ($modal, $body, $footer) {
        $body.html('<p class="dou-modal-loading-text">...</p>');
        $.ajax({
          type: "POST",
          url: route("admin.language.value.value"),
          data: {
            language_pack: languagePack,
            module: module,
            item_id: itemId,
            field: field,
            type: type,
          },
          dataType: "json",
          success: function (data) {
            $body.html(buildLangForm(data, {
              languagePack: languagePack,
              module: module,
              itemId: itemId,
              field: field,
              token: token,
              type: type,
            }));
            $footer.html(
              '<div class="dou-modal-actions">' +
              '<a href="javascript:;" class="btn dou-modal-lang-submit">' +
              escapeHtml(typeof lang === "function" ? lang("btn_submit") : "提交") +
              "</a></div>"
            );
            bindLangFormSubmit($modal, $trigger);
            bindLangFormClear($modal, $trigger);
            $(document).trigger("douLangFormReady", {
              $modal: $modal,
              $trigger: $trigger,
              field: field,
              type: type,
            });
          },
          error: function () {
            alert("系统错误，请联系管理员！");
            close();
          },
        });
      },
    });
  }

  function buildLangForm(data, ctx) {
    var option = "";
    var popupEditorId;

    if (ctx.type === "content") {
      popupEditorId = ctx.module + "ContentID" + Date.parse(new Date());

      if (typeof cur_editor !== "undefined" && cur_editor === "vditor") {
        option =
          '<div class="editor-content low"><div id="' +
          popupEditorId +
          '" class="editor-class"></div></div><textarea id="' +
          popupEditorId +
          'Textarea" name="value" style="display: none;">' +
          escapeHtml(data.value) +
          '</textarea><script type="text/javascript">initEditor(\'' +
          popupEditorId +
          "')</script>";
      } else {
        option =
          '<script id="' +
          popupEditorId +
          '" name="value" type="text/plain" class="editor-class">' +
          data.value +
          '</script><script type="text/javascript">initEditor(\'' +
          popupEditorId +
          "', '200')</script>";
      }

      option =
        '<div id="' +
        popupEditorId +
        'Editor" class="editor">' +
        '<div class="editor-bar">' +
        '<div class="editor-bar-left">' +
        '<div class="file-box">' +
        (data.paid_use
          ? '<div class="editor-btn" onClick="editorInsert(\'' +
            popupEditorId +
            "', '<hr/>');\"><span class=\"btn-file\">" +
            lang("insert_paid_use_line") +
            "</span></div>"
          : "") +
        '<div id="' +
        popupEditorId +
        'File" class="editor-btn" onclick="fileBox(\'content\', \'' +
        popupEditorId +
        "', '" +
        ctx.module +
        "', '" +
        ctx.itemId +
        "', '', '', '" +
        cur_editor +
        '\');"><span class="btn-file">' +
        lang("file_insert_image") +
        '</span><span class="file-status" style="display:none"><img src="images/loader.gif" alt="uploading"/></span></div>' +
        '<div id="' +
        popupEditorId +
        'BigFile" class="editor-btn" onChange="fileBig(\'content\', \'' +
        popupEditorId +
        "', '" +
        ctx.module +
        "', '" +
        ctx.itemId +
        "', '" +
        cur_editor +
        '\');"><input type="file" class="field"><span class="btn-file">' +
        lang("file_insert_media") +
        '<em class="percent"></em></span></div>' +
        "</div></div>" +
        '<div class="editor-bar-right">' +
        '<span class="editor-text"><label><input name="content_remote_image_local" type="checkbox"> ' +
        lang("file_remote_image_local") +
        "</label></span>" +
        '<span class="editor-text btn-fullscreen" onclick="btnFullscreen(\'' +
        popupEditorId +
        "', '200')\"><em class=\"yes\">" +
        lang("fullscreen") +
        '</em><em class="no">' +
        lang("fullscreen_exit") +
        "</em></span></div></div>" +
        option +
        "</div>";
    } else if (ctx.type === "file") {
      option = '<input type="file" name="value" size="38" class="input-file" />';
      if (data.value) {
        option +=
          '<a href="' +
          escapeHtml(data.value) +
          '" target="_blank"><img class="icon" src="images/icon_yes.png"></a>';
      } else {
        option += '<img class="icon" src="images/icon_no.png">';
      }
    } else if (ctx.type === "textarea") {
      option =
        '<textarea name="value" cols="115" rows="3" class="textarea">' +
        escapeHtml(data.value) +
        "</textarea>";
    } else {
      option =
        '<input type="text" name="value" class="input" value="' +
        escapeHtml(data.value) +
        '" size="20" />';
    }

    // 多语言工具条（一键翻译槽 + 清空）仅在存在启用中的 translate 应用时输出
    var hasLangTranslate = false;
    var aiConfigEl = document.getElementById("ai-config");
    if (aiConfigEl) {
      try {
        var aiConfig = JSON.parse(aiConfigEl.textContent || aiConfigEl.innerHTML);
        hasLangTranslate = !!(aiConfig && aiConfig.translate && aiConfig.translate.length);
      } catch (e) {
        hasLangTranslate = false;
      }
    }

    var langToolbar = hasLangTranslate
      ? '<div class="dou-modal-lang-toolbar">' +
        '<span class="action ai-lang-translate"></span>' +
        '<a href="javascript:;" class="dou-modal-lang-clear">' +
        escapeHtml(typeof lang === "function" ? lang("ai_lang_clear", "清空") : "清空") +
        "</a></div>"
      : "";

    return (
      '<form class="dou-modal-lang-form" action="' +
      route("admin.language.value.store") +
      '" method="post" enctype="multipart/form-data">' +
      langToolbar +
      '<div class="form">' +
      '<div class="form-item">' +
      '<div class="input-block">' +
      option +
      "</div></div></div>" +
      '<input type="hidden" name="language_pack" value="' +
      escapeHtml(ctx.languagePack) +
      '" />' +
      '<input type="hidden" name="module" value="' +
      escapeHtml(ctx.module) +
      '" />' +
      '<input type="hidden" name="item_id" value="' +
      escapeHtml(ctx.itemId) +
      '" />' +
      '<input type="hidden" name="field" value="' +
      escapeHtml(ctx.field) +
      '" />' +
      '<input type="hidden" name="token" value="' +
      escapeHtml(ctx.token) +
      '" />' +
      '<input type="hidden" name="type" value="' +
      escapeHtml(ctx.type) +
      '" />' +
      "</form>"
    );
  }

  function bindLangFormClear($modal, $trigger) {
    $modal.find(".dou-modal-lang-clear").off("click.douModal").on("click.douModal", function (e) {
      e.preventDefault();
      clearLangModalValue($modal, $trigger.attr("data-type"));
    });
  }

  function clearLangModalValue($modal, type) {
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
          editor.setValue("");
        } else if (typeof editor.setContent === "function") {
          editor.setContent("");
        }
        var $shadow = $modal.find("#" + id + "Textarea");
        if ($shadow.length) {
          $shadow.val("");
        }
        return;
      }
    }

    $modal.find('.dou-modal-lang-form [name="value"]').first().val("").trigger("change");
  }

  function bindLangFormSubmit($modal, $trigger) {
    $modal.find(".dou-modal-lang-submit").off("click.douModal").on("click.douModal", function () {
      $modal.find(".dou-modal-lang-form").ajaxForm({
        type: "POST",
        data: {},
        dataType: "json",
        success: function (resp) {
          if (resp && resp.id) {
            $("#" + resp.id).addClass("cur");
          }
          close();
        },
        error: function (xhr) {
          var resp = xhr.responseJSON;
          alert(resp && resp.message ? resp.message : "操作失败");
        },
        clearForm: true,
      }).submit();
    });
  }

  function handleToggleClick(e) {
    var $trigger = $(this);
    var kind = $trigger.attr("data-dou-modal");
    var target = $trigger.attr("data-dou-target");

    if (kind === "lang") {
      e.preventDefault();
      openLangBox($trigger);
      return;
    }
    if (kind === "restore") {
      e.preventDefault();
      openRestore($trigger);
      return;
    }
    if (kind === "message") {
      e.preventDefault();
      openMessage($trigger);
      return;
    }
    if (target) {
      e.preventDefault();
      show(target);
    }
  }

  $(document).on("click", '[data-dou-toggle="modal"]', handleToggleClick);

  $(document).on("click", '[data-dou-dismiss="modal"]', function (e) {
    e.preventDefault();
    var $modal = $(this).closest(modalSel);
    if ($modal.length) {
      hide($modal);
    } else {
      close();
    }
  });

  $(document).on("keydown", function (event) {
    if (activeModal && activeModal.find(".dou-modal-lang-form").length && event.key === "Enter") {
      if (!$(event.target).is("textarea")) {
        event.preventDefault();
        activeModal.find(".dou-modal-lang-submit").trigger("click");
      }
    }
  });

  global.douModal = {
    open: open,
    show: show,
    hide: hide,
    close: close,
  };
})(window, jQuery);

/**
 * 图片编辑模块（上传后裁剪）：对已上传的图片（相册列表项 / 单图字段已保存图）弹窗裁剪，
 * 导出结果提交 admin.file.crop 替换原文件（路径不变，前台引用零影响）。
 *
 * 依赖 cropperjs v1：js/cropper.min.js 与 css/cropper.min.css 由使用图片字段的页面
 * 模板直接引用（参考 ai.htm 对 ai.css / ai.js 的引用方式，AdminPrefilter 编译期重写路径）；
 * 未引入插件的页面编辑入口不生效。EXIF 方向由 cropperjs 内置 checkOrientation 矫正。
 *
 * 输出策略：扩展名跟随原图（.png → PNG，.jpg/.jpeg → JPEG），
 * 路径与文件名保持不变；最长边不超过后台设置的 site.image_width（未配置取 1000）。
 * 弹窗工具栏宽、高输入框同步选区像素，确认时按框内尺寸导出（同上封顶）。
 *
 * 对外接口：
 *   douCrop.editableExt(src)  URL 是否为可编辑图片扩展（jpg/jpeg/png/webp）
 *   douCrop.editableFile(file)  本地 File 是否为可编辑图片
 *   douCrop.edit(src, ratioValue, callback)
 *     - src        已上传图片的 URL
 *     - ratioValue 初始宽高比（"1920/400"、"1.3333"；整数对且非 1:1/4:3/16:9 时作为末项精确比例）
 *     - callback(blob|null)  裁剪产物（扩展名与原图一致）；取消/失败回传 null
 *   douCrop.editFile(file, ratioValue, callback, exportExt, options)
 *     - file       本地 File（选图后、提交前裁剪）
 *     - exportExt  可选，覆盖导出扩展名（换图时与原附件扩展名对齐）
 *     - options    可选 {centerVertically, outputWidth, maxEdge}
 *     - 其余同 edit；未传 exportExt 时导出 MIME 跟文件名/类型
 */
(function (global, $) {
  "use strict";

  var JPEG_QUALITY = 0.85;

  // 输出最长边上限：来自后台设置 site.image_width（javascript.tpl 输出），未配置取 1000
  function maxEdge() {
    var v = parseInt(global.dou_crop_max_edge, 10);
    return isFinite(v) && v > 0 ? v : 1000;
  }

  // 预设比例：自由 / 1:1 / 4:3 / 16:9（data-ratio 空串 = 自由）
  var RATIO_PRESETS = [
    { key: "", labelKey: "crop_ratio_free", fallback: "自由" },
    { key: "1", label: "1:1" },
    { key: "1.3333", label: "4:3" },
    { key: "1.7778", label: "16:9" },
  ];

  function tr(key, fallback) {
    return typeof lang === "function" ? lang(key, fallback) : fallback;
  }

  // 裁剪弹窗自身样式（内联注入；cropper.min.css / cropper.min.js 由页面模板引入）
  function injectStyle() {
    if (!$("#dou-crop-style").length) {
      $(
        '<style id="dou-crop-style">'
          + ".dou-crop{width:100%}"
          + ".dou-crop-toolbar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:10px}"
          + ".dou-crop-ratios,.dou-crop-ops{display:flex;align-items:center;flex-wrap:wrap;gap:4px}"
          + ".dou-crop-ratios .dou-crop-label{font-size:12px;color:#889;margin-right:2px}"
          + ".dou-crop-size{display:flex;align-items:center;flex-wrap:wrap;gap:6px}"
          + ".dou-crop-size-x{font-size:12px;color:#889}"
          + ".dou-crop-outw{font-size:12px;color:#889;display:inline-flex;align-items:center;gap:4px}"
          + ".dou-crop-outw input{width:64px;height:26px;padding:0 6px;border:1px solid #d9dce3;border-radius:3px;box-sizing:border-box;font-size:12px;line-height:24px;text-align:center;color:#334;background:#f3f4f6;vertical-align:middle}"
          + ".dou-crop-outw input[type=number]::-webkit-inner-spin-button,.dou-crop-outw input[type=number]::-webkit-outer-spin-button{-webkit-appearance:none;margin:0}"
          + ".dou-crop-outw input[type=number]{-moz-appearance:textfield;appearance:textfield}"
          + ".dou-crop-toolbar a{padding:3px 10px;border:1px solid #d9dce3;border-radius:3px;font-size:12px;color:#556;text-decoration:none;cursor:pointer;display:inline-block;line-height:1.6}"
          + ".dou-crop-toolbar a:hover{border-color:#2d6cdf;color:#2d6cdf}"
          + ".dou-crop-toolbar a.active{background:#2d6cdf;border-color:#2d6cdf;color:#fff}"
          + ".dou-crop-stage{height:420px;max-height:60vh;background:#f3f4f6;overflow:hidden}"
          + ".dou-crop-stage img{display:block;max-width:100%}"
          + "</style>"
      ).appendTo("head");
    }
  }

  /**
   * URL 是否为可编辑图片：按扩展名判断（jpg/jpeg/png/webp）。
   * gif（动图裁剪丢帧）、bmp、svg 不提供编辑。
   */
  function editableExt(src) {
    var m = String(src || "").split("?")[0].match(/\.([a-z0-9]+)$/i);
    return !!m && /^(jpe?g|png|webp)$/i.test(m[1]);
  }

  /**
   * 本地 File 的可编辑扩展：优先文件名，其次 MIME。
   */
  function fileExt(file) {
    var name = file && file.name ? String(file.name) : "";
    var m = name.split("?")[0].match(/\.([a-z0-9]+)$/i);
    if (m && /^(jpe?g|png|webp)$/i.test(m[1])) {
      return m[1].toLowerCase();
    }
    var type = file && file.type ? String(file.type).toLowerCase() : "";
    if (type === "image/png") {
      return "png";
    }
    if (type === "image/webp") {
      return "webp";
    }
    if (type === "image/jpeg") {
      return "jpg";
    }
    return "";
  }

  function editableFile(file) {
    return fileExt(file) !== "";
  }

  /**
   * 解析比例值："200/150"、"1.3333" → 数值；非法或非正值 → NaN（自由比例）。
   */
  function parseRatio(value) {
    if (value == null) {
      return NaN;
    }
    var parts = String(value).split("/");
    var w = parseFloat(parts[0]);
    var h = parts.length > 1 ? parseFloat(parts[1]) : NaN;
    var ratio = parts.length > 1 ? w / h : w;
    return isFinite(ratio) && ratio > 0 ? ratio : NaN;
  }

  /**
   * 比例落在 1:1 / 4:3 / 16:9 时返回对应 data-ratio，否则返回空串。
   *
   * @param {number} ratio
   * @return {string}
   */
  function matchPresetKey(ratio) {
    if (!(isFinite(ratio) && ratio > 0)) {
      return "";
    }
    var i;
    for (i = 0; i < RATIO_PRESETS.length; i++) {
      var preset = RATIO_PRESETS[i];
      if (preset.key === "") {
        continue;
      }
      var presetRatio = parseRatio(preset.key);
      if (isFinite(presetRatio) && Math.abs(presetRatio - ratio) < 0.01) {
        return preset.key;
      }
    }
    return "";
  }

  /**
   * 整数对 "1920/400"。
   *
   * @param {string} ratioValue
   * @return {{key:string,label:string,width:number,height:number}|null}
   */
  function integerPairFromRatioValue(ratioValue) {
    var parts = String(ratioValue == null ? "" : ratioValue).split("/");
    if (parts.length !== 2) {
      return null;
    }
    var rawW = String(parts[0]).trim();
    var rawH = String(parts[1]).trim();
    if (!/^\d+$/.test(rawW) || !/^\d+$/.test(rawH)) {
      return null;
    }
    var w = parseInt(rawW, 10);
    var h = parseInt(rawH, 10);
    if (w <= 0 || h <= 0) {
      return null;
    }
    return { key: w + "/" + h, label: w + ":" + h, width: w, height: h };
  }

  /**
   * 整数对且对不上 1:1 / 4:3 / 16:9 时，作为工具栏末项精确比例。
   *
   * @param {string} ratioValue
   * @return {{key:string,label:string,width:number,height:number}|null}
   */
  function extraPresetFromRatioValue(ratioValue) {
    var pair = integerPairFromRatioValue(ratioValue);
    if (!pair || matchPresetKey(pair.width / pair.height) !== "") {
      return null;
    }
    return pair;
  }

  function ratioPresetsFor(ratioValue) {
    var presets = RATIO_PRESETS.slice();
    var extra = extraPresetFromRatioValue(ratioValue);
    if (extra) {
      presets.push({ key: extra.key, label: extra.label });
    }
    return presets;
  }

  function activateRatioButton($m, ratio) {
    var key = "";
    $m.find(".dou-crop-ratios a").each(function () {
      var k = $(this).attr("data-ratio");
      if (k === "") {
        return;
      }
      var r = parseRatio(k);
      if (isFinite(r) && isFinite(ratio) && Math.abs(r - ratio) < 0.01) {
        key = k;
      }
    });
    $m.find(".dou-crop-ratios a").removeClass("active");
    $m.find('.dou-crop-ratios a[data-ratio="' + key + '"]').addClass("active");
  }

  /**
   * 把目标宽高收到原图与最长边上限之内，保持比例。
   *
   * @param {number} w
   * @param {number} h
   * @param {number} edgeCap
   * @param {{naturalWidth:number,naturalHeight:number}|null} imageData
   * @return {{width:number,height:number}|null}
   */
  function clampExportSize(w, h, edgeCap, imageData) {
    if (!(isFinite(w) && w > 0 && isFinite(h) && h > 0)) {
      return null;
    }
    var maxW = edgeCap;
    var maxH = edgeCap;
    if (imageData && imageData.naturalWidth > 0 && imageData.naturalHeight > 0) {
      maxW = Math.min(maxW, imageData.naturalWidth);
      maxH = Math.min(maxH, imageData.naturalHeight);
    }
    var scale = 1;
    if (w > maxW) {
      scale = Math.min(scale, maxW / w);
    }
    if (h > maxH) {
      scale = Math.min(scale, maxH / h);
    }
    return {
      width: Math.max(1, Math.round(w * scale)),
      height: Math.max(1, Math.round(h * scale)),
    };
  }

  function buildToolbarHtml(ratioValue, edgeCap) {
    var html = '<div class="dou-crop-toolbar"><div class="dou-crop-ratios">';
    html += '<span class="dou-crop-label">' + tr("crop_ratio_label", "比例") + "</span>";
    var presets = ratioPresetsFor(ratioValue);
    var extra = extraPresetFromRatioValue(ratioValue);
    var activeKey = extra ? extra.key : matchPresetKey(parseRatio(ratioValue));
    for (var i = 0; i < presets.length; i++) {
      var preset = presets[i];
      var label = preset.labelKey ? tr(preset.labelKey, preset.fallback) : preset.label;
      var active = preset.key === activeKey ? " active" : "";
      html += '<a href="javascript:;" data-ratio="' + preset.key + '" class="' + active.replace(" ", "") + '">' + label + "</a>";
    }
    html += '</div><div class="dou-crop-size">';
    html += '<label class="dou-crop-outw" title="' + tr("crop_size_width", "宽度") + '"><input type="number" class="dou-crop-width" min="1" max="' + edgeCap + '" placeholder="' + tr("crop_size_width", "宽度") + '"></label>';
    html += '<span class="dou-crop-size-x">×</span>';
    html += '<label class="dou-crop-outw" title="' + tr("crop_size_height", "高度") + '"><input type="number" class="dou-crop-height" min="1" max="' + edgeCap + '" placeholder="' + tr("crop_size_height", "高度") + '"></label>';
    html += '<span class="dou-crop-size-x">px</span>';
    html += '</div><div class="dou-crop-ops">';
    html += '<a href="javascript:;" data-op="left">' + tr("crop_rotate_left", "向左旋转") + "</a>";
    html += '<a href="javascript:;" data-op="flip">' + tr("crop_flip", "左右翻转") + "</a>";
    html += '<a href="javascript:;" data-op="reset">' + tr("crop_reset", "重置") + "</a>";
    html += "</div></div>";
    html += '<div class="dou-crop-stage"><img alt=""></div>';
    return html;
  }

  function edit(src, ratioValue, callback) {
    injectStyle();
    if (!global.Cropper || !global.douModal || !editableExt(src)) {
      callback(null);
      return;
    }
    openEditModal(src, ratioValue, callback, "", "", {});
  }

  function editFile(file, ratioValue, callback, exportExt, options) {
    injectStyle();
    if (!global.Cropper || !global.douModal || !editableFile(file)) {
      callback(null);
      return;
    }
    var objectUrl = URL.createObjectURL(file);
    var ext = exportExt || fileExt(file) || "jpg";
    openEditModal(objectUrl, ratioValue, callback, ext, objectUrl, options || {});
  }

  /**
   * 把裁剪框沿原图垂直方向居中（横幅从 16:9 裁到 1920x400 时默认切中部条带）。
   *
   * @param {Cropper} cropper
   */
  function centerCropVertically(cropper) {
    if (!cropper) {
      return;
    }
    try {
      var data = cropper.getData(true);
      var imageData = cropper.getImageData();
      if (!data || !imageData || imageData.naturalHeight <= 0 || data.height <= 0) {
        return;
      }
      var y = Math.round((imageData.naturalHeight - data.height) / 2);
      if (y < 0) {
        y = 0;
      }
      cropper.setData({
        x: data.x,
        y: y,
        width: data.width,
        height: data.height,
      });
    } catch (e) {
      /* noop */
    }
  }

  /**
   * 把画布中心对齐到选区中心，使旋转绕画面中心进行。
   *
   * @param {Cropper} cropper
   */
  function alignCanvasToCropCenter(cropper) {
    if (!cropper) {
      return;
    }
    try {
      var crop = cropper.getCropBoxData();
      var canvas = cropper.getCanvasData();
      if (!crop || !canvas || crop.width <= 0 || canvas.width <= 0) {
        return;
      }
      cropper.setCanvasData({
        left: canvas.left + (crop.left + crop.width / 2) - (canvas.left + canvas.width / 2),
        top: canvas.top + (crop.top + crop.height / 2) - (canvas.top + canvas.height / 2),
      });
    } catch (e) {
      /* noop */
    }
  }

  function openEditModal(src, ratioValue, callback, exportExt, objectUrl, options) {
    var settled = false;
    var processing = false;
    var cropper = null;
    var $modal = null;
    options = options || {};
    var initialRatio = parseRatio(ratioValue);
    var pixelPair = integerPairFromRatioValue(ratioValue);
    var outputWidth = parseInt(options.outputWidth, 10) || 0;
    var edgeCap = parseInt(options.maxEdge, 10);
    if (!isFinite(edgeCap) || edgeCap <= 0) {
      edgeCap = maxEdge();
    }
    if (outputWidth > edgeCap) {
      edgeCap = outputWidth;
    }
    if (pixelPair) {
      edgeCap = Math.max(edgeCap, pixelPair.width, pixelPair.height);
    }
    var currentRatio = initialRatio;
    var editingSize = false;
    // 滚轮/双指缩放期间标记为 true：缩放只改变留在选区里的图像，不回写选区尺寸
    var zooming = false;

    // 兜底：X / ESC 关闭弹窗不经按钮回调，轮询 DOM 移除后按取消结算
    var timer = setInterval(function () {
      if ($modal && $modal.length && !document.contains($modal[0])) {
        settle(null);
      }
    }, 400);

    function settle(result) {
      if (settled) {
        return;
      }
      settled = true;
      clearInterval(timer);
      if (cropper) {
        try {
          cropper.destroy();
        } catch (e) {
          /* noop */
        }
        cropper = null;
      }
      if (objectUrl) {
        try {
          URL.revokeObjectURL(objectUrl);
        } catch (e) {
          /* noop */
        }
      }
      callback(result);
    }

    function exportCanvas(done) {
      if (processing || !cropper) {
        return;
      }
      processing = true;

      // 导出：宽高输入框有值则精确导出（最长边 edgeCap 封顶）；否则按选区尺寸封顶
      var canvasOpts = {
        imageSmoothingEnabled: true,
        imageSmoothingQuality: "high",
        maxWidth: edgeCap,
        maxHeight: edgeCap,
      };
      var outW = parseInt($modal.find(".dou-crop-width").val(), 10);
      var outH = parseInt($modal.find(".dou-crop-height").val(), 10);
      var sized = clampExportSize(outW, outH, edgeCap, null);
      if (sized) {
        canvasOpts = {
          imageSmoothingEnabled: true,
          imageSmoothingQuality: "high",
          width: sized.width,
          height: sized.height,
        };
      }

      var canvas = null;
      try {
        canvas = cropper.getCroppedCanvas(canvasOpts);
      } catch (e) {
        canvas = null;
      }
      if (!canvas || !canvas.toBlob) {
        settle(null);
        done();
        return;
      }

      // 输出类型跟随原图扩展（路径与文件名保持不变），PNG 保留透明
      var srcExt = exportExt || (String(src).split("?")[0].match(/\.([a-z0-9]+)$/i) || [])[1] || "jpg";
      srcExt = String(srcExt).toLowerCase();
      var type = "image/jpeg";
      if (srcExt === "png") {
        type = "image/png";
      } else if (srcExt === "webp") {
        type = "image/webp";
      }
      canvas.toBlob(
        function (blob) {
          settle(blob || null);
          done();
        },
        type,
        type === "image/jpeg" ? JPEG_QUALITY : undefined
      );
    }

    $modal = global.douModal.open({
      title: tr("crop_title", "图片裁剪"),
      size: "lg",
      width: 720,
      closeOnOverlay: false,
      bodyHtml: buildToolbarHtml(ratioValue, edgeCap),
      onOpen: function ($m) {
        var $img = $m.find(".dou-crop-stage img");
        $img.attr("src", src);
        $img.on("error", function () {
          settle(null);
          global.douModal.hide($m);
        });
        cropper = new global.Cropper($img[0], {
          // 画布可超出舞台：宽幅图 90° 旋转时保持原缩放，才能绕选区中心转并转回
          viewMode: 0,
          dragMode: "move",
          autoCropArea: 1,
          checkOrientation: true,
          rotatable: true,
          scalable: true,
          aspectRatio: initialRatio,
          zoom: function () {
            zooming = true;
            setTimeout(function () {
              zooming = false;
            }, 0);
          },
          ready: function () {
            if (options.centerVertically) {
              centerCropVertically(this);
            }
          },
          crop: function (event) {
            if (editingSize || zooming) {
              return;
            }
            var w = Math.round(event.detail.width);
            var h = Math.round(event.detail.height);
            if (w > 0 && h > 0) {
              $m.find(".dou-crop-width").val(w);
              $m.find(".dou-crop-height").val(h);
            }
          },
        });

        function applySizeFromInputs(changedEl) {
          if (!cropper) {
            return;
          }
          var ratioKey = $m.find(".dou-crop-ratios a.active").attr("data-ratio");
          var lockedRatio = parseRatio(ratioKey);
          var w = parseInt($m.find(".dou-crop-width").val(), 10);
          var h = parseInt($m.find(".dou-crop-height").val(), 10);
          var changedIsWidth = changedEl && $(changedEl).hasClass("dou-crop-width");
          var parts;
          var rw;
          var rh;
          if (isFinite(lockedRatio) && lockedRatio > 0) {
            parts = String(ratioKey || "").split("/");
            rw = parts.length === 2 ? parseFloat(parts[0]) : NaN;
            rh = parts.length === 2 ? parseFloat(parts[1]) : NaN;
            if (changedIsWidth && isFinite(w) && w > 0) {
              if (isFinite(rw) && isFinite(rh) && rw > 0) {
                h = Math.max(1, Math.round((w * rh) / rw));
              } else {
                h = Math.max(1, Math.round(w / lockedRatio));
              }
            } else if (isFinite(h) && h > 0) {
              if (isFinite(rw) && isFinite(rh) && rh > 0) {
                w = Math.max(1, Math.round((h * rw) / rh));
              } else {
                w = Math.max(1, Math.round(h * lockedRatio));
              }
            }
          }
          var imageData = null;
          try {
            imageData = cropper.getImageData();
          } catch (e) {
            imageData = null;
          }
          var sized = clampExportSize(w, h, edgeCap, imageData);
          if (!sized) {
            return;
          }
          $m.find(".dou-crop-width").val(sized.width);
          $m.find(".dou-crop-height").val(sized.height);
          try {
            var data = cropper.getData();
            cropper.setData({
              x: data.x,
              y: data.y,
              width: sized.width,
              height: sized.height,
            });
          } catch (e2) {
            /* noop */
          }
        }

        $m.on("focus", ".dou-crop-width, .dou-crop-height", function () {
          editingSize = true;
        });
        $m.on("blur", ".dou-crop-width, .dou-crop-height", function () {
          applySizeFromInputs(this);
          editingSize = false;
        });
        $m.on("change", ".dou-crop-width, .dou-crop-height", function () {
          applySizeFromInputs(this);
        });
        $m.on("click", ".dou-crop-ratios a", function () {
          var $a = $(this);
          $a.closest(".dou-crop-ratios").find("a").removeClass("active");
          $a.addClass("active");
          var v = parseRatio($a.attr("data-ratio"));
          currentRatio = isFinite(v) && v > 0 ? v : NaN;
          if (!cropper) {
            return;
          }
          var prev = null;
          try {
            prev = cropper.getData();
          } catch (e) {
            prev = null;
          }
          cropper.setAspectRatio(currentRatio);
          if (prev && prev.width > 0 && prev.height > 0) {
            try {
              cropper.setData({
                x: prev.x,
                y: prev.y,
                width: prev.width,
                height: prev.height,
              });
            } catch (e2) {
              /* noop */
            }
          }
        });
        $m.on("click", ".dou-crop-ops a", function () {
          if (!cropper) {
            return;
          }
          var op = $(this).attr("data-op");
          if (op === "left") {
            cropper.rotate(-90);
            alignCanvasToCropCenter(cropper);
          } else if (op === "flip") {
            var data = cropper.getData();
            var scaleX = data && isFinite(data.scaleX) ? data.scaleX : 1;
            cropper.scaleX(scaleX > 0 ? -1 : 1);
          } else if (op === "reset") {
            currentRatio = initialRatio;
            activateRatioButton($m, initialRatio);
            cropper.reset();
            if (cropper.setAspectRatio) {
              cropper.setAspectRatio(currentRatio);
            }
            if (options.centerVertically) {
              setTimeout(function () {
                centerCropVertically(cropper);
              }, 0);
            }
          }
        });
      },
      buttons: [
        {
          text: tr("crop_cancel", "取消"),
          className: "btn",
          onClick: function (host, done) {
            settle(null);
            done();
          },
        },
      ]
        .concat(
          typeof options.onRegenerate === "function"
            ? [
                {
                  text: tr("ai_image_regenerate", "重新生成"),
                  className: "btn",
                  onClick: function (host, done) {
                    settle(null);
                    done();
                    options.onRegenerate();
                  },
                },
              ]
            : []
        )
        .concat([
          {
            text: tr("crop_confirm", "确认裁剪"),
            className: "btn btn-primary",
            onClick: function (host, done) {
              exportCanvas(done);
            },
          },
        ]),
    });
  }

  global.douCrop = {
    editableExt: editableExt,
    editableFile: editableFile,
    edit: edit,
    editFile: editFile,
  };
})(window, jQuery);

/**
 +----------------------------------------------------------
 * 穿梭框（.transfer）
 +----------------------------------------------------------
 * 左右双栏勾选互移的通用组件，布局参考 layui transfer。
 * 结构：容器内两个 .transfer-box（各含 .transfer-header 标题与 .transfer-data 列表），
 * 中间的 .transfer-active 按钮列由组件自动生成；列表项 .transfer-item 内含 checkbox
 * （value 为提交值），可穿插 .transfer-group 分组标题（组内项全部移至右栏时自动隐藏）。
 * 初始已选：左栏项标记 data-selected，初始化时自动移至右栏。
 * 提交值：容器设置 data-name（如 model_ids[]）时，右栏内容实时同步为同名隐藏域。
 */
function initTransfer(elem) {
  var $box = $(elem);
  if ($box.data("inited")) {
    return;
  }
  $box.data("inited", true);

  var $boxes = $box.children(".transfer-box");
  if ($boxes.length < 2) {
    return;
  }
  var $leftData = $boxes.first().find(".transfer-data");
  var $rightData = $boxes.last().find(".transfer-data");

  // 中间互移按钮列（容器已有 .transfer-active 时不再生成）
  if (!$box.children(".transfer-active").length) {
    $boxes.first().after(
      '<div class="transfer-active">' +
        '<button type="button" class="transfer-btn" data-to="right">&gt;</button>' +
        '<button type="button" class="transfer-btn" data-to="left">&lt;</button>' +
        "</div>"
    );
  }

  // 记录每个选项所属分组（其前面最近的 .transfer-group，存 DOM 引用）
  var group = null;
  $leftData.children("li").each(function () {
    if ($(this).hasClass("transfer-group")) {
      group = this;
    } else if (group) {
      $(this).data("group", group);
    }
  });

  // 分组标题显隐：组内选项全部移至右栏时隐藏
  function updateGroups() {
    $leftData.children(".transfer-group").each(function () {
      var title = this;
      var hasItem = false;
      $leftData.children(".transfer-item").each(function () {
        if ($(this).data("group") === title && !$(this).hasClass("transfer-hide")) {
          hasItem = true;
          return false;
        }
      });
      $(title).toggleClass("transfer-hide", !hasItem);
    });
  }

  // 右栏内容同步为隐藏域（容器 data-name 存在时）
  function syncValues() {
    var name = $box.data("name");
    var $holder = $box.children(".transfer-values");
    if (!name) {
      $holder.remove();
      return;
    }
    if (!$holder.length) {
      $holder = $("<span></span>").addClass("transfer-values").appendTo($box);
    }
    $holder.empty();
    $rightData.children(".transfer-item").each(function () {
      $holder.append($("<input>").attr("type", "hidden").attr("name", name).val($(this).find("input").val()));
    });
  }

  // 左栏项移至右栏：隐藏左栏原项（保留分组位置），右栏追加副本
  function toRight($li) {
    $li.addClass("transfer-hide").find("input").prop("checked", false);
    $li.clone().removeClass("transfer-hide").appendTo($rightData);
  }

  // 初始已选项移至右栏
  $leftData.children(".transfer-item[data-selected]").each(function () {
    toRight($(this));
  });
  updateGroups();
  syncValues();

  // 互移按钮：把当前栏勾选项移动到另一栏
  $box.on("click", ".transfer-btn", function () {
    var rightward = $(this).attr("data-to") === "right";
    var $from = rightward ? $leftData : $rightData;
    $from.children(".transfer-item").each(function () {
      var $li = $(this);
      if ($li.hasClass("transfer-hide") || !$li.find("input").prop("checked")) {
        return;
      }
      if (rightward) {
        toRight($li);
      } else {
        // 右栏项移回左栏：按 checkbox value 恢复左栏对应项
        var value = $li.find("input").val();
        $leftData.children(".transfer-item").each(function () {
          var $item = $(this);
          if ($item.hasClass("transfer-hide") && $item.find("input").val() === value) {
            $item.removeClass("transfer-hide");
          }
        });
        $li.remove();
      }
    });
    updateGroups();
    syncValues();
  });
}

$(function () {
  $(".transfer").each(function () {
    initTransfer(this);
  });
});

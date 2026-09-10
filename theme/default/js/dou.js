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

$(function () {
  /* 顶部导航 */
  $("ul.top-nav li.parent").hover(
    function () {
      $(this).addClass("hover");
      $("ul:first", this).css("display", "block");
    },
    function () {
      $(this).removeClass("hover");
      $("ul:first", this).css("display", "none");
    },
  );

  // 恢复父级菜单可点击
  if ($(window).width() > 768) {
    $(document).off("click.bs.dropdown.data-api");
  }

  // 增加三级菜单支持
  $(".dropdown-menu a.dropdown-toggle").on("click", function (e) {
    if (!$(this).next().hasClass("show")) {
      $(this).parents(".dropdown-menu").first().find(".show").removeClass("show");
    }
    var $subMenu = $(this).next(".dropdown-menu");
    $subMenu.toggleClass("show");

    $(this)
      .parents("li.nav-item.dropdown.show")
      .on("hidden.bs.dropdown", function (e) {
        $(".dropdown-submenu .show").removeClass("show");
      });

    return false;
  });

  // navbar一定高度后置顶固定
  if ($(".navbar").hasClass("scroll")) {
    navbarFix();
    $(window).on("scroll", navbarFix);
  }

  // 同级元素点击显示/隐藏
  $(".d-show").click(function () {
    var target = $(this).siblings().not(this);
    target.toggle();
  });

  // 收藏按钮
  $(".btn-favorites").click(function () {
    favorites($(this).data("module"), $(this).data("item_id"), $(this));
  });

  // 优惠卷领取按钮
  $(".get-coupon").click(function () {
    coupon($(this).data("id"), $(this));
  });

  // 语言选择按钮
  $(".lang-select").click(function () {
    $(this).toggleClass("active");
  });

  // 加入购物车
  $(".btn-addtocart").click(function () {
    var item_id = $(this).data("item_id");
    var module = $(this).data("module") ? $(this).data("module") : "product";

    addToCart(item_id, module);
  });

  // 邮件订阅（如模板存在对应表单）
  $(".email-subscribe-form").on("submit", function (e) {
    e.preventDefault();
    subscribeEmail($(this));
  });
});

/**
 +----------------------------------------------------------
 * 固定导航菜单
 +----------------------------------------------------------
 */
function navbarFix() {
  if (!$(".navbar").hasClass("scroll")) return;

  var scrollTop = $(window).scrollTop();
  var navbarHeight = $(".navbar").outerHeight();

  if (scrollTop > navbarHeight) {
    $(".navbar.scroll").addClass("fix");
  } else {
    $(".navbar.scroll").removeClass("fix");
  }
}

/**
 +----------------------------------------------------------
 * 统一解析后端 JSON 响应（API错误返回规范.md）
 +----------------------------------------------------------
 * 严格模式：只读 code/message/data/errors/request_id 五字段，
 * 不再兼容 msg / wrong / 根级业务字段。
 *
 * 调用方约定：
 *   - 业务成功判定：result.ok（即 code === "OK"）
 *   - 文案：result.message
 *   - 业务字段：仅从 result.data 读取
 *   - 字段级错误：result.errors
 * 网络层 4xx/5xx 也会被解析（从 jqXHR.responseJSON / responseText 兜底）。
 */
function douApi(input) {
  var raw = input;
  if (input && typeof input === "object" && typeof input.responseJSON !== "undefined") {
    raw = input.responseJSON;
    if (!raw && typeof input.responseText === "string") {
      try {
        raw = JSON.parse(input.responseText);
      } catch (e) {
        raw = null;
      }
    }
  }

  var code = raw && typeof raw.code === "string" ? raw.code : "";
  var message = raw && typeof raw.message === "string" ? raw.message : "";
  var errors = raw && raw.errors && typeof raw.errors === "object" ? raw.errors : {};
  var data = raw && raw.data && typeof raw.data === "object" ? raw.data : {};
  var requestId = raw && typeof raw.request_id === "string" ? raw.request_id : "";

  return {
    ok: code === "OK",
    code: code,
    message: message,
    data: data,
    errors: errors,
    requestId: requestId,
  };
}

/**
 +----------------------------------------------------------
 * 刷新验证码
 +----------------------------------------------------------
 */
function refreshimage() {
  var cap = document.getElementById("vcode");
  var src = cap.src;

  // 判断是伪静态格式还是路由格式
  if (src.indexOf("/captcha") !== -1 && src.indexOf("?") === -1) {
    // 伪静态格式：/captcha 或 /captcha?t=xxx
    cap.src = src.replace(/\?.*$/, "") + "?t=" + Date.now();
  } else {
    // 路由格式：index.php?route=captcha
    cap.src = src.replace(/\?.*$/, "") + "?route=captcha&t=" + Date.now();
  }
}

/**
 +----------------------------------------------------------
 * 加入购物车
 +----------------------------------------------------------
 */
function addToCart(item_id = "", module = "product") {
  function handle(result) {
    if (result.ok) {
      if (result.data && result.data.jump_url) {
        window.location.href = result.data.jump_url;
      }
      return;
    }
    if (result.code === "UNAUTHORIZED" && result.data && result.data.jump_url) {
      window.location.href = result.data.jump_url;
      return;
    }
    alert(result.message || "error");
  }

  $.ajax({
    type: "POST",
    url: route("order.cart.store"),
    data: { module: module, item_id: item_id, from: "js" },
    dataType: "json",
    success: function (data) {
      handle(douApi(data));
    },
    error: function (xhr) {
      handle(douApi(xhr));
    },
  });
}

/**
 +----------------------------------------------------------
 * 项目收藏
 +----------------------------------------------------------
 */
function favorites(module = "product", item_id = "", target = "") {
  if (target.hasClass("ed")) {
    return;
  }

  function handle(result) {
    if (!result.ok) {
      if (result.code === "UNAUTHORIZED" && result.data && result.data.jump_url) {
        window.location.href = result.data.jump_url;
        return;
      }
      if (result.message) {
        alert(result.message);
      }
      return;
    }

    if (result.data && result.data.jump_url) {
      window.location.href = result.data.jump_url;
      return;
    }

    target.addClass("ed");
    var text = result.data && result.data.text ? result.data.text : "";
    target.find("em").html(text);
  }

  $.ajax({
    type: "POST",
    url: url_favorites,
    data: { module: module, item_id: item_id, from: "js" },
    dataType: "json",
    success: function (data) {
      handle(douApi(data));
    },
    error: function (xhr) {
      handle(douApi(xhr));
    },
  });
}

/**
 +----------------------------------------------------------
 * 优惠券
 +----------------------------------------------------------
 */
function coupon(id = "", target = "") {
  if (target.hasClass("got")) {
    return;
  }

  function handle(result) {
    if (!result.ok) {
      if (result.message) {
        alert(result.message);
      }
      return;
    }

    target.addClass("got");
    var text = "";
    if (result.data && result.data.text) {
      text = result.data.text;
    } else if (result.message) {
      text = result.message;
    }
    target.find(".t").html(text);
  }

  $.ajax({
    type: "POST",
    url: url_coupon,
    data: { id: id, from: "js" },
    dataType: "json",
    success: function (data) {
      handle(douApi(data));
    },
    error: function (xhr) {
      handle(douApi(xhr));
    },
  });
}

/**
 +----------------------------------------------------------
 * 邮件订阅
 +----------------------------------------------------------
 */
function subscribeEmail($form) {
  if (!$form || !$form.length) {
    return;
  }

  var email = $.trim($form.find('input[name="email"]').val() || "");
  function setMessage(text) {
    var $msg = $form.find(".email-subscribe-message");
    if ($msg.length) {
      $msg.text(text || "");
      return;
    }
    if (text) {
      alert(text);
    }
  }

  $.ajax({
    type: "POST",
    url: route("email.store"),
    data: { email: email },
    dataType: "json",
    success: function (data) {
      var result = douApi(data);
      if (result.ok) {
        setMessage(result.message || "");
        $form[0].reset();
        return;
      }
      if (result.errors && result.errors.email) {
        setMessage(result.errors.email);
        return;
      }
      setMessage(result.message || "error");
    },
    error: function (xhr) {
      var result = douApi(xhr);
      setMessage(result.message || "error");
    },
  });
}

/**
 +----------------------------------------------------------
 * 弹出确认提示
 +----------------------------------------------------------
 */
function douConfirm(url, msg) {
  if (confirm(msg)) {
    window.location.href = url;
  }
}

/**
 +----------------------------------------------------------
 * RESTful 删除：经共享隐藏表单以 POST + _method=DELETE 提交到资源成员 URL
 *
 * 浏览器原生 <a>/表单只能发 GET/POST；资源 destroy 动作为 DELETE 方法，故删除入口统一走
 * #dou-delete-form（inc/common_footer.tpl 单点渲染，已带 static_user token）：设置 action 后
 * submit，由 Request::method() 的方法伪装识别为 DELETE 命中 destroy 路由。confirmMsg 非空时先确认。
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
 * 清空对象内HTML
 +----------------------------------------------------------
 */
function douRemove(target) {
  var obj = document.getElementById(target);
  obj.parentNode.removeChild(obj);
}

/**
 +----------------------------------------------------------
 * 同意用户协议
 +----------------------------------------------------------
 */
function agree() {
  var submit = document.getElementById("submitBtn");
  if (document.getElementById("agreement").checked) {
    submit.disabled = false;
    submit.className = "btn";
  } else {
    submit.disabled = "disabled";
    submit.className = "btn-secondary";
  }
}

/**
 * 点击切换显示和隐藏
 */
function showHide(target) {
  $("#" + target).toggle();
}

/**
 +----------------------------------------------------------
 * 收藏本站
 +----------------------------------------------------------
 */
function AddFavorite(url, title) {
  try {
    window.external.addFavorite(url, title);
  } catch (e) {
    try {
      window.sidebar.addPanel(title, url, "");
    } catch (e) {
      alert("加入收藏失败，请使用Ctrl+D进行添加");
    }
  }
}

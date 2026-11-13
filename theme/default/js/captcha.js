/**
 +----------------------------------------------------------
 * 短信发送
 +----------------------------------------------------------
 */
// 语言项取词（lang.js 由 code_footer.tpl 先于本文件加载，缺失时回退中文）
var captchaText = typeof window.lang === "function" ? window.lang : function (key, fallback) { return fallback; };

function sendCaptcha(type, account, captcha_token, check = "no_allow_phone_exist") {
  var account = $("#" + account).val();
  $.ajax({
    url: route("captcha.verification"),
    type: "POST",
    dataType: "json",
    data: {
      type: type,
      account: account,
      captcha_token: captcha_token,
      check: check,
    },
    success: function (data) {
      var result = typeof douApi === "function" ? douApi(data) : null;
      if (result && result.ok) {
        time();
      } else {
        alert((result && result.message) ? result.message : captchaText("operation_failed", "操作失败，请重试"));
      }
    },
    error: function (xhr, status, error) {
      var result = typeof douApi === "function" ? douApi(xhr) : null;
      alert((result && result.message) ? result.message : captchaText("network_error_retry", "网络错误，请稍后重试"));
    },
  });
}

/**
 +----------------------------------------------------------
 * 倒计时
 +----------------------------------------------------------
 */
var wait = 60;
function time() {
  var btn = $("#btnCaptcha");
  if (wait == 0) {
    btn.removeAttr("disabled");
    btn.val(captchaText("verification_get", "获取验证码"));
    wait = 60;
  } else {
    btn.attr("disabled", true);
    btn.val(captchaText("verification_resend", "重新发送(%s)").replace("%s", wait));
    wait--;
    setTimeout(function () {
      time(btn);
    }, 1000);
  }
}

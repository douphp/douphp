/**
 +----------------------------------------------------------
 * 短信发送
 +----------------------------------------------------------
 */
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
        alert((result && result.message) ? result.message : "request_failed");
      }
    },
    error: function (xhr, status, error) {
      var result = typeof douApi === "function" ? douApi(xhr) : null;
      alert((result && result.message) ? result.message : "网络错误，请稍后重试");
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
    btn.val("获取验证码");
    wait = 60;
  } else {
    btn.attr("disabled", true);
    btn.val("重新发送(" + wait + ")");
    wait--;
    setTimeout(function () {
      time(btn);
    }, 1000);
  }
}

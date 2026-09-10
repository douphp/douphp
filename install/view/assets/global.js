/**
 * 表单提交（AJAX 预校验）
 *
 * 将 action URL 中的 install/post 替换为 install/callback 发起预校验请求；
 * 服务端返回空字符串时再真正提交表单。
 */
function douSubmit(form_id) {
    var formAction = $("#" + form_id).attr("action");
    var formParam = $("#" + form_id).serialize();
    var callbackUrl = formAction.replace(/install\/post/, "install/callback");

    $.ajax({
        type: "POST",
        url: callbackUrl,
        data: formParam,
        dataType: "html",
        success: function (html) {
            if (!html) {
                $("#" + form_id).submit();
            } else {
                $("#cue").html(html);
            }
        }
    });
}

/**
 * 切换主机：localhost <-> 127.0.0.1
 */
function changeHost(target) {
    target = target || "dbhost";
    var value = document.getElementById(target).value;
    if (value == "localhost") {
        document.getElementById(target).value = "127.0.0.1";
    } else {
        document.getElementById(target).value = "localhost";
    }
}

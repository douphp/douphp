/**
 * dou.toast — 后台通用轻提示（右上角浮层，2.5 秒自动消失）
 *
 * 用法：
 *   dou.toast.success('供应商已停用');
 *   dou.toast.error('操作失败');
 * 依赖 jQuery；样式见 common.css 的「Toast」区块。
 */
(function () {
  "use strict";

  var CONTAINER_ID = "dou-toast-box";
  var DURATION = 2500;
  var TRANSITION = 300;

  function container() {
    var box = document.getElementById(CONTAINER_ID);
    if (!box) {
      box = document.createElement("div");
      box.id = CONTAINER_ID;
      document.body.appendChild(box);
    }
    return box;
  }

  function show(type, message) {
    var box = container();
    var item = document.createElement("div");
    item.className = "dou-toast dou-toast-" + type;
    item.textContent = message;
    box.appendChild(item);

    // 下一帧再加 in 类，触发入场过渡
    window.setTimeout(function () {
      item.classList.add("dou-toast-in");
    }, 10);

    window.setTimeout(function () {
      item.classList.remove("dou-toast-in");
      window.setTimeout(function () {
        if (item.parentNode) {
          item.parentNode.removeChild(item);
        }
      }, TRANSITION);
    }, DURATION);
  }

  window.dou = window.dou || {};
  window.dou.toast = {
    success: function (message) {
      show("success", message || "操作成功");
    },
    error: function (message) {
      show("error", message || "操作失败");
    }
  };
})();

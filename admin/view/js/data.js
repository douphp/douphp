/**
 +----------------------------------------------------------
 * 内容片段表单：调用标签随「唯一标记」实时联动
 *
 * 提示由服务端渲染（PC 主题为 {$data.code.field}，小程序模板为 {{data.code.field}}），
 * 打开页面即固定。这里监听 code 输入框，用同一套规则即时重写所有 .copytext 里的
 * data.<code> 段，避免提示与实际唯一标记不一致。
 *
 * 兼容 {$data.xxx.field} 与 {{data.xxx.field}} 两种写法，.name / .image / .text /
 * .link / .code / .child 一并生效；被锁定的记录 code 不可改，只在初始化时同步一次。
 +----------------------------------------------------------
 */
(function ($) {
  "use strict";

  var $code = $('form input[name="code"]');
  if (!$code.length) {
    return;
  }

  /**
   * 归一化唯一标记：仅保留小写字母、数字、下划线（与后台校验规则一致）
   */
  function normalize(value) {
    return String(value == null ? "" : value)
      .toLowerCase()
      .replace(/[^a-z0-9_]/g, "");
  }

  /**
   * 重写提示中的 code 段，覆盖三种写法：
   * - PC 字段标签：{$data.old.field}
   * - PC 分组标签：<!-- {foreach from=$data.old.child item=item} -->
   * - 小程序调用代码：{{data.old.field}}
   */
  function replaceCode(text, code) {
    return String(text).replace(/(from=\$|\{\{?|\{\$)data\.[A-Za-z0-9_]+(?=\.)/g, function (match, prefix) {
      return prefix + "data." + code;
    });
  }

  /**
   * 同步所有提示节点；带 data-text 的节点同步复制文本，保证复制内容与显示一致
   */
  function refresh() {
    var code = normalize($code.val());
    if (!code) {
      return;
    }

    $(".dou-page .copytext").each(function () {
      var $el = $(this);
      var html = $el.html();
      var next = replaceCode(html, code);
      if (next !== html) {
        $el.html(next);
      }

      var dataText = $el.attr("data-text");
      if (dataText) {
        $el.attr("data-text", replaceCode(dataText, code));
      }
    });
  }

  $code.on("input change", refresh);

  // 初始化：保证服务端替换后的提示与输入框当前值一致
  refresh();
})(jQuery);

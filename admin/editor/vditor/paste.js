/**
 +----------------------------------------------------------
 * Vditor 粘贴拦截：Markdown 源码按 Markdown 渲染，避免被整段包成代码块
 * 后台编辑器（editor/vditor/init.js）与前台知识库（theme/default/js/geo.js）共用
 +----------------------------------------------------------
 */

/**
 +----------------------------------------------------------
 * 绑定 Markdown 粘贴拦截（捕获阶段，早于 Vditor 自身的 paste 处理）
 * editors：Vditor 实例集合，数组或 id → 实例 的对象（如后台 douEditor）
 +----------------------------------------------------------
 */
function douVditorBindMarkdownPaste(editors) {
  document.addEventListener('paste', function(event) {
    if (!event.clipboardData) {
      return;
    }
    var editor = douVditorPasteTarget(editors, event.target);
    if (!editor) {
      return;
    }
    var host = editor.vditor.wysiwyg.element;
    // 编辑器禁用、或粘贴进已有代码块 / 表格单元格时保持 Vditor 原逻辑
    if (host.getAttribute('contenteditable') !== 'true' || douVditorInCodeOrCell(event.target, host)) {
      return;
    }
    var plain = event.clipboardData.getData('text/plain');
    var html = event.clipboardData.getData('text/html');
    if (!plain || !html || !douVditorLooksLikeMarkdown(plain) || !douVditorPlainTextShell(html, plain)) {
      return;
    }
    event.preventDefault();
    event.stopPropagation();
    editor.insertMD(plain);
  }, true);
}

/**
 +----------------------------------------------------------
 * 按粘贴目标查找对应的 Vditor 实例（仅 wysiwyg 模式）
 +----------------------------------------------------------
 */
function douVditorPasteTarget(editors, target) {
  var list = [];
  if (Object.prototype.toString.call(editors) === '[object Array]') {
    list = editors;
  } else {
    for (var key in editors) {
      if (editors.hasOwnProperty(key)) {
        list.push(editors[key]);
      }
    }
  }

  for (var i = 0; i < list.length; i++) {
    var vditor = list[i] && list[i].vditor;
    if (vditor && vditor.currentMode === 'wysiwyg' && vditor.wysiwyg &&
        vditor.wysiwyg.element.contains(target)) {
      return list[i];
    }
  }

  return null;
}

/**
 +----------------------------------------------------------
 * 光标是否落在代码块 / 行内代码 / 表格单元格内
 * 向上查找到编辑区宿主即停止：wysiwyg 编辑区自身就是 <pre class="vditor-reset">，不能算代码块
 +----------------------------------------------------------
 */
function douVditorInCodeOrCell(node, host) {
  while (node && node !== host) {
    var tag = node.nodeName;
    if (tag === 'CODE' || tag === 'TD' || tag === 'TH' ||
        (tag === 'PRE' && !(node.classList && node.classList.contains('vditor-reset')))) {
      return true;
    }
    node = node.parentNode;
  }

  return false;
}

/**
 +----------------------------------------------------------
 * 检测纯文本是否含 Markdown 块级结构（标题 / 列表 / 引用 / 围栏代码 / 表格）
 * 只有这类内容被包成代码块才会明显丢格式；行内记号（加粗、链接）不在此列，
 * 避免把从 IDE 复制的普通代码误判成 Markdown
 +----------------------------------------------------------
 */
function douVditorLooksLikeMarkdown(text) {
  return /^[ \t]{0,3}(```|~~~)/m.test(text)
    || /^[ \t]{0,3}#{1,6}[ \t]+\S/m.test(text)
    || /^[ \t]{0,3}(?:[-*+]|\d+[.)])[ \t]+\S/m.test(text)
    || /^[ \t]{0,3}>[ \t]+\S/m.test(text)
    || /^[ \t]{0,3}\|.*\|[ \t]*$/m.test(text);
}

/**
 +----------------------------------------------------------
 * 检测剪贴板 HTML 是否只是纯文本外壳：不含图片、表格、列表、引用、标题、链接等排版结构，
 * 且文本与剪贴板纯文本一致（仅忽略空白差异）。IDE 复制出来的是
 * <meta charset> + 单个 monospace 容器（或单个 <pre>），正属于该形态
 +----------------------------------------------------------
 */
function douVditorPlainTextShell(html, plain) {
  var box = document.createElement('div');
  box.innerHTML = html;
  var junk = box.querySelectorAll('meta, style, script, link');
  for (var i = 0; i < junk.length; i++) {
    junk[i].parentNode.removeChild(junk[i]);
  }
  if (box.querySelector('img, table, ul, ol, blockquote, h1, h2, h3, h4, h5, h6, a[href]')) {
    return false;
  }

  return box.textContent.replace(/[\s\u00a0]+/g, '') === plain.replace(/[\s\u00a0]+/g, '');
}

/**
 +----------------------------------------------------------
 * 编辑器配置文件
 +----------------------------------------------------------
 */
var douEditor = {};

$(function() {
  $(document).on('keydown', 'input', function(e) {
      if (e.keyCode === 13) {
          e.preventDefault();
          e.stopPropagation(); // 阻止事件冒泡，防止 Vditor 捕获

          const $form = $(this).closest('form');
          if ($form.length) {
              $form.submit();
          }
      }
  });
  
  $('.editor-class').each(function() {
      var $this = $(this);
      var editorId = $this.attr('id');

      // 初始化编辑器
      initEditor(editorId)
  });

  // 提交前同步（兜底）：把各 vditor 实例的当前值（Markdown 源码）写回对应 textarea
  $(document).on('submit', 'form', function() {
      syncAllEditors();
  });
  
  // 可选：按ESC键退出全屏
  $(document).keyup(function(e) {
      if (e.keyCode === 27) { // ESC键
          $('.editor').removeClass('fullscreen');
          $('.dou-modal').removeClass('has-editor-fullscreen');
          $('body').css('overflow', '');
      }
  });
});

/**
 +----------------------------------------------------------
 * 初始化
 +----------------------------------------------------------
 */
function initEditor(editorId) {
    var editorValue = $('#' + editorId + 'Textarea').val();

    // 仅当存量内容是 HTML 时才需要转成 Markdown 回填。
    // 不可用「是否含 Markdown 语法」反推是否 HTML：HTML→Markdown 转换后的纯文本正文
    // （如 <p>a<br/><br/>b</p> → "a\n\nb"）不含 # / 列表等标记，会被误判为「非 Markdown」
    // 而再次走 html2md；html2md 视入参为 HTML，会把换行折叠成空格、并转义 ** 等记号，破坏正文。
    var isHtml = looksLikeHtml(editorValue);
    
    douEditor[editorId] = new Vditor(editorId, {
        toolbar: ["emoji", "headings", "bold", "italic", "strike", "link", "|", "list", "ordered-list", "check", "outdent", "indent", "|", "quote", "line", "code", "inline-code", "insert-before", "insert-after", "|", "table", "|", "undo", "redo", "|", "outline", "preview", "edit-mode", { name: "more", toolbar: ["both", "code-theme", "content-theme", "export", "devtools", "help"] }],
        mode: 'wysiwyg',
        height: '100%', // 填满父容器
        cdn: (typeof admin_url !== 'undefined' ? admin_url : '') + 'editor/vditor', 
        value: editorValue,
        after: () => {
            if (isHtml) {
                const markdown = douEditor[editorId].html2md(editorValue);
                douEditor[editorId].setValue(markdown);
                // setValue 属编程式赋值，不会触发 input 回调，需手动同步到提交用的 textarea
                $('#' + editorId + 'Textarea').val(markdown);
            }
        },
        input: (content) => {
            $('#' + editorId + 'Textarea').val(content);
        },
        cache: {
            enable: false, // 禁用缓存, 
            id: editorId // 设置缓存ID
        }
    });

    // 所在表单提交前同步：此绑定早于 jquery.form 的 ajaxForm（多语言弹窗），
    // 保证 ajaxForm 序列化表单数据时 textarea 已是 Markdown 源码
    var $form = $('#' + editorId + 'Textarea').closest('form');
    if ($form.length) {
        $form.on('submit', function() {
            syncEditorValue(editorId);
        });
    }
}

/**
 +----------------------------------------------------------
 * 同步单个编辑器当前值（Markdown 源码）到提交用 textarea
 * Vditor 的 setValue / insertValue 等编程式赋值不会触发 input 回调，
 * 若只靠 input 同步，打开表单后未编辑直接提交会把 textarea 里的旧值（如存量 HTML）提交上去
 +----------------------------------------------------------
 */
function syncEditorValue(editorId) {
    if (douEditor[editorId] && typeof douEditor[editorId].getValue === 'function') {
        $('#' + editorId + 'Textarea').val(douEditor[editorId].getValue());
    }
}

/**
 +----------------------------------------------------------
 * 同步页面上全部编辑器（兜底，覆盖 textarea 初始化时不在表单内的场景）
 +----------------------------------------------------------
 */
function syncAllEditors() {
    for (var editorId in douEditor) {
        if (douEditor.hasOwnProperty(editorId)) {
            syncEditorValue(editorId);
        }
    }
}

/**
 +----------------------------------------------------------
 * 检测内容是否像 HTML 片段（与 PHP MarkdownRenderer::looksLikeHtml 规则一致）
 * 命中 HTML 即视为 HTML 内容，交由 html2md 转 Markdown 回填
 +----------------------------------------------------------
 */
function looksLikeHtml(content) {
  if (!content) {
    return false;
  }

  // 先剔除代码块 / 行内代码，避免 Markdown 正文里的 HTML 示例被误判
  var probe = content.replace(/```[\s\S]*?```|~~~[\s\S]*?~~~|`[^`]*`/g, '');

  return /<\/?[a-z][a-z0-9]*(?:\s[^>]*)?>/i.test(probe);
}

/**
 +----------------------------------------------------------
 * 全屏
 +----------------------------------------------------------
 */
function btnFullscreen(editorId, height = '') {
    var $editor = $("#" + editorId + 'Editor');
    $editor.toggleClass('fullscreen');

    var isFullscreen = $editor.hasClass('fullscreen');
    $editor.closest('.dou-modal').toggleClass('has-editor-fullscreen', isFullscreen);
    $('body').css('overflow', isFullscreen ? 'hidden' : '');
}


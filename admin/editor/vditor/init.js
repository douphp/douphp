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

    // 判断是否是 Markdown（与 PHP 保持一致的逻辑）
    var isMarkdown = hasMarkdownSyntax(editorValue);
    
    douEditor[editorId] = new Vditor(editorId, {
        toolbar: ["emoji", "headings", "bold", "italic", "strike", "link", "|", "list", "ordered-list", "check", "outdent", "indent", "|", "quote", "line", "code", "inline-code", "insert-before", "insert-after", "|", "table", "|", "undo", "redo", "|", "outline", "preview", "edit-mode", { name: "more", toolbar: ["both", "code-theme", "content-theme", "export", "devtools", "help"] }],
        mode: 'wysiwyg',
        height: '100%', // 填满父容器
        cdn: (typeof admin_url !== 'undefined' ? admin_url : '') + 'editor/vditor', 
        value: editorValue,
        after: () => {
            if (!isMarkdown) {
                const markdown = douEditor[editorId].html2md(editorValue);
                douEditor[editorId].setValue(markdown);
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
}

/**
 +----------------------------------------------------------
 * 快速检测 Markdown 语法
 +----------------------------------------------------------
 */
function hasMarkdownSyntax(content) {
  // 空内容不算 Markdown
  if (!content || content.trim() === "") {
    return false;
  }

  // 使用多行模式，使 ^ 匹配行首
  const patterns = [
    // 标题 (行首 1-6 个 # 后跟空格)
    /^#{1,6}\s+/m,

    // 代码块 (行首三个反引号或三个波浪号)
    /^```|^~~~/m,

    // 引用块 (行首 > 后跟空格)
    /^>\s+/m,

    // 分隔线 (行首至少三个 * - _，可选空格)
    /^(\*{3,}|-{3,}|_{3,})\s*$/m,

    // 无序列表 (行首 * + - 后跟空格)
    /^[\*\+\-]\s+/m,

    // 有序列表 (行首数字加点加空格)
    /^\d+\.\s+/m,

    // 任务列表 (行首 - 后跟 [空格] 或 [x])
    /^-\s*\[[ x]\]\s+/im,

    // 表格 (行中包含 | 且至少有一个分隔行 --- )
    // 简单检测：行中有 | 且下一行有 --- 或 :--- 等，但这里简化：检测包含 | 的行
    /\|.*\|/,

    // 链接 [text](url) (允许 url 中包含括号，非贪婪；允许 text 为空)
    /\[[^\]]*\]\([^)]+\)/,

    // 图片 ![alt](url)（允许 alt 为空：编辑器期上传的图返回 <img> 不带 alt，
    // 经 vditor insertValue 转 markdown 时落成 ![](url)，必须能被识别为 markdown）
    /!\[[^\]]*\]\([^)]+\)/,

    // 粗体 (双星号或双下划线包围，中间无空白)
    /\*\*[^*]+\*\*|__[^_]+__/,

    // 斜体 (单星号或单下划线包围，注意区分粗体)
    /(?<!\*)\*[^*]+\*(?!\*)|(?<!_)_[^_]+_(?!_)/,

    // 删除线 (双波浪号包围)
    /~~[^~]+~~/,

    // 行内代码 (单个反引号包围)
    /`[^`]+`/,

    // 脚注 [^1]
    /\[\^[^\]]+\]/,
  ];

  for (const pattern of patterns) {
    if (pattern.test(content)) {
      return true;
    }
  }

  return false;
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


/**
 +----------------------------------------------------------
 * 编辑器配置文件
 +----------------------------------------------------------
 */
var douEditor = {};
var editorDefaultHeight = 400;

$(function() {
  $('.editor-class').each(function() {
      var $this = $(this);
      var editorId = $this.attr('id');

      // 初始化编辑器
      initEditor(editorId, editorDefaultHeight)
  });
 
  $(window).on('resize', function() {
      if ($('.editor').hasClass('fullscreen')) {
          resizeEditor(window.editorId);
      }
  });
  
  // 可选：按ESC键退出全屏
  $(document).keyup(function(e) {
      if (e.keyCode === 27) { // ESC键
          $('.editor').removeClass('fullscreen');
          $('.dou-modal').removeClass('has-editor-fullscreen');
          $('body').css('overflow', '');

          if (window.editorId && douEditor[window.editorId]) {
              douEditor[window.editorId].setHeight(window.editorHeight ? window.editorHeight : editorDefaultHeight);
          }
      }
  });
});

/**
 +----------------------------------------------------------
 * 初始化
 +----------------------------------------------------------
 */
function initEditor(editorId, height = 200) {
  douEditor[editorId] = UE.getEditor(editorId, {
    autoHeightEnabled: false,
    initialFrameWidth: '100%',
    initialFrameHeight: height
  });
  
  // 监听就绪事件
  douEditor[editorId].addListener('ready', function() {
      this.setHeight(height);
  });
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

    if (isFullscreen) {
        $('body').css('overflow', 'hidden');
        window.editorId = editorId;
        window.editorHeight = height;
        resizeEditor(editorId);
    } else {
        $('body').css('overflow', '');
        douEditor[editorId].setHeight(height ? height : editorDefaultHeight);
        window.editorId = null;
    }
}

/**
 +----------------------------------------------------------
 * 全屏
 +----------------------------------------------------------
 */
function resizeEditor(editorId) {
    var editorBarHeight = $("#" + editorId + 'Editor .editor-bar').outerHeight(true);
    var windowHeight = $(window).height();
    var toolbarboxHeight = $("#" + editorId + 'Editor .edui-editor-toolbarbox').outerHeight(true);
    var bottomContainerHeight = $("#" + editorId + 'Editor .edui-editor-bottomContainer').outerHeight(true);
    
    douEditor[editorId].setHeight(windowHeight - editorBarHeight - toolbarboxHeight - bottomContainerHeight);
}

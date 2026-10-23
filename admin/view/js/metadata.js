/**
 +----------------------------------------------------------
 * 扩展字段定义表单：按字段类型显隐相关配置项
 *
 * 选项仅 select / radio / checkbox 使用；允许扩展名与大小上限仅 file 使用；
 * 数量上限仅 gallery / checkbox 使用。新建页切换类型时即时联动；
 * 编辑页类型已锁定，脚本仅做一次初始同步。
 +----------------------------------------------------------
 */
(function ($) {
  /**
   * 当前选中的字段类型（新建页为下拉，编辑页为隐藏域）
   */
  function currentType() {
    var select = $('select[name="type"]');
    if (select.length) {
      return select.val() || '';
    }
    return $('input[name="type"]').val() || '';
  }

  /**
   * 按当前类型切换各配置项的显隐
   */
  function sync() {
    var type = currentType();
    $('[data-metadata-for]').each(function () {
      var allowed = (this.getAttribute('data-metadata-for') || '').split(/\s+/);
      $(this).toggle($.inArray(type, allowed) !== -1);
    });
  }

  $(function () {
    if (!$('[data-metadata-for]').length) {
      return;
    }
    $(document).on('change', 'select[name="type"]', sync);
    sync();
  });
})(jQuery);

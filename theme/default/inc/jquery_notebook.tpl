<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<script type="text/javascript" src="js/jquery.notebook.min.js"></script>
<script type="text/javascript">
{literal}
$(document).ready(function(){
  $('.visualize-box').notebook({
    autoFocus: true,
    placeholder: 'Your text here...',
    mode: 'multiline', //多行或内嵌
    modifiers: ['bold', 'italic', 'underline']
  });

  $("#btnVisualize").click(function(){
      var content = $('.visualize-box').html();
      var id = $(this).data("id");

      $.ajax({
        type: "POST",
        url: '{/literal}{$site.admin_url}{literal}index.php?route=page/visualize',
        data: {"id":id, "content":content},
        dataType: "json",
        success: function(resp) {
          if (resp && resp.code === 'OK') {
            alert('{/literal}{$lang.edit}{$lang.success}{literal}');
            location.reload();
          }
        },
        error: function(xhr) {
          var resp = xhr.responseJSON;
          alert(resp && resp.message ? resp.message : 'Save failed');
        }
    });
  });
});
{/literal}
</script>
<style>
{literal}
.btn-visualize {
 display: inline-block;
 position: fixed;
 left: 20px;
 bottom: 20px;
 background-color: #55B66E;
 color: #FFF;
 padding: 8px 30px;
 font-size: 18px;
 z-index: 999999;
}
.btn-visualize:hover {
 background-color: #0072C6;
 color: #FFF;
}
{/literal}
</style>
<a id="btnVisualize" class="btn-visualize" data-id="{$page.id}" href="javascript:;">{$lang.btn_visualize_submit}</a>
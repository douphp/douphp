<div class="clear"></div>
<div id="dou-footer"{if $width eq 'full'} class="is-full"{/if}>
 <ul><!-- {if !$pure_mode} -->{$lang.footer_copyright}<!-- {else} -->{$site.site_name}<!-- {/if} --></ul>
</div><!-- dou-footer 结束 -->
<div class="clear"></div>
<form id="dou-delete-form" method="post" action="" style="display:none">
 <input type="hidden" name="_method" value="DELETE">
 <input type="hidden" name="token" value="{$token}">
</form>
<form id="dou-post-form" method="post" action="" style="display:none">
 <input type="hidden" name="token" value="{$token}">
</form>

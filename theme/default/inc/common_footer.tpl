<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<footer id="footer">
 <div class="copy-right">
  <div class="container">{$lang.copyright} {$lang.powered_by nofilter} <!-- {if $site.icp} --><a href="https://beian.miit.gov.cn/" target="_blank">{$site.icp}</a><!-- {/if} --><!-- {if $site.net_safe_record} --><a href="http://www.beian.gov.cn/portal/registerSystemInfo?recordcode={$site.net_safe_record_number}" class="net-safe-record" target="_blank"><img src="../images/icon_net_safe_record.png" />{$site.net_safe_record}</a><!-- {/if} --></div>
 </div>
</footer>
<!-- {if $site.code} -->
<div style="display:none">{$site.code nofilter}</div>
<!-- {/if} -->
<form id="dou-delete-form" method="post" action="" style="display:none">
 <input type="hidden" name="_method" value="DELETE">
 <input type="hidden" name="token" value="{$token}">
</form>
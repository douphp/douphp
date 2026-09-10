<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<footer id="footer">
 <div class="container">
  <div class="row">
   <!-- {foreach from=$nav_bottom_list name=nav_bottom_list item=nav} -->
   <!-- {if $nav@iteration le 4} -->
   <div class="col-md-2">
    <div class="foot-nav">
     <div class="nav-parent">
      <a href="{$nav.url}">{$nav.name}</a>
     </div>
     <div class="nav-child">
      <!-- {foreach from=$nav.child item=child} -->
      <a href="{$child.url}">{$child.name}</a>
      <!-- {/foreach} -->
     </div>
    </div>
   </div>
   <!-- {/if} -->
   <!-- {/foreach} -->
   <div class="col-md-2">
    <!-- {if $site.weixin_img} -->
    <div class="weixin"><img src="{$site.weixin_img}" /><p>{$site.weixin_name}</p></div>
    <!-- {/if} -->
   </div>
   <div class="col-md-2">
    <div class="contact">
     <div class="tel">{$site.tel}</div>
     <!-- {if $site.qq.0.number} --> 
     <div class="online-qq"><a href="http://wpa.qq.com/msgrd?v=3&amp;uin={$site.qq.0.number}&amp;site=qq&amp;menu=yes" target="_blank"><i class="bi bi-qq"></i>{$lang.online_qq}</a></div>
     <!-- {/if} --> 
    </div>
   </div>
  </div>
  <div class="copy-right">{$lang.copyright} {$lang.powered_by nofilter} <!-- {if $site.icp} --><a href="https://beian.miit.gov.cn/" target="_blank">{$site.icp}</a><!-- {/if} --><!-- {if $site.net_safe_record} --><a href="http://www.beian.gov.cn/portal/registerSystemInfo?recordcode={$site.net_safe_record_number}" class="net-safe-record" target="_blank"><img src="../images/icon_net_safe_record.png" />{$site.net_safe_record}</a><!-- {/if} --></div>
  </div>
</footer>
<!-- {if $site.code} -->
<div style="display:none">{$site.code nofilter}</div>
<!-- {/if} -->
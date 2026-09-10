<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<!-- {if $site.show_customer} -->
<link href="css/online_service.css" rel="stylesheet" type="text/css" />
<div class="online-service">
 <!-- {if $site.qq} -->
 <div class="item">
  <i class="item-icon bi bi-tencent-qq"></i>
  <div class="pop-box">
   <div class="item-box">
    <div class="qq-box">
     <!-- {foreach from=$site.qq item=qq} -->
     <a class="qq" href="http://wpa.qq.com/msgrd?v=3&uin={$qq.number}&site=qq&menu=yes" target="_blank"><i class="bi bi-tencent-qq"></i><!-- {if $qq.nickname} -->{$qq.nickname}<!-- {else} -->{$lang.online_qq}<!-- {/if} --></a>
     <!-- {/foreach} -->
    </div>
   </div>
  </div>
 </div>
 <!-- {/if} -->
 <!-- {if $site.weixin_img} -->
 <div class="item">
  <i class="item-icon bi bi-wechat"></i>
  <div class="pop-box">
   <div class="item-box">
    <div class="weixin-box"><img src="{$site.weixin_img}" /><p>{$site.weixin_name}</p></div>
   </div>
  </div>
 </div>
 <!-- {/if} -->
 <!-- {if $site.skype} -->
 <div class="item">
  <a class="item-icon bi bi-skype" href="{$site.skype}" target="_blank"></a>
 </div>
 <!-- {/if} -->
 <!-- {if $site.whatsapp} -->
 <div class="item">
  <a class="item-icon bi bi-whatsapp" href="{$site.whatsapp}" target="_blank"></a>
 </div>
 <!-- {/if} -->
 <div class="item">
  <i class="item-icon bi bi-telephone"></i>
  <div class="pop-box">
   <div class="item-box">
    <div class="tel-box">{$site.tel}</div>
   </div>
  </div>
 </div>
 <!-- {if $site.chat_link} -->
 <div class="item">
  <a class="item-icon bi bi-headset" href="{$site.chat_link}" target="_blank"></a>
 </div>
 <!-- {/if} -->
 <p class="go-top"><a href="javascript:;;" onfocus="this.blur();" class="go-btn bi bi-chevron-up"></a></p>
</div>
<!-- {/if} -->
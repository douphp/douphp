<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<!-- {if $site.show_customer} -->
<link href="css/online_service.css" rel="stylesheet" type="text/css" />
<div class="online-service">
 <!-- {if $cur_lang eq 'en_us'} -->
 <!-- {if $site.facebook} -->
 <div class="item">
  <a class="item-icon bi bi-facebook" href="{$site.facebook}" target="_blank" title="Facebook"></a>
 </div>
 <!-- {/if} -->
 <!-- {if $site.twitter} -->
 <div class="item">
  <a class="item-icon bi bi-twitter" href="{$site.twitter}" target="_blank" title="Twitter"></a>
 </div>
 <!-- {/if} -->
 <!-- {if $site.pinterest} -->
 <div class="item">
  <a class="item-icon bi bi-pinterest" href="{$site.pinterest}" target="_blank" title="Pinterest"></a>
 </div>
 <!-- {/if} -->
 <!-- {if $site.youtube} -->
 <div class="item">
  <a class="item-icon bi bi-youtube" href="{$site.youtube}" target="_blank" title="YouTube"></a>
 </div>
 <!-- {/if} -->
 <!-- {if $site.vk} -->
 <div class="item">
  <a class="item-icon item-icon-text" href="{$site.vk}" target="_blank" title="VK">VK</a>
 </div>
 <!-- {/if} -->
 <!-- {if $site.linkedin} -->
 <div class="item">
  <a class="item-icon bi bi-linkedin" href="{$site.linkedin}" target="_blank" title="LinkedIn"></a>
 </div>
 <!-- {/if} -->
 <!-- {if $site.instagram} -->
 <div class="item">
  <a class="item-icon bi bi-instagram" href="{$site.instagram}" target="_blank" title="Instagram"></a>
 </div>
 <!-- {/if} -->
 <!-- {if $site.tiktok} -->
 <div class="item">
  <a class="item-icon bi bi-tiktok" href="{$site.tiktok}" target="_blank" title="TikTok"></a>
 </div>
 <!-- {/if} -->
 <!-- {if $site.skype} -->
 <div class="item">
  <a class="item-icon bi bi-skype" href="{$site.skype}" target="_blank" title="Skype"></a>
 </div>
 <!-- {/if} -->
 <!-- {if $site.whatsapp} -->
 <div class="item">
  <a class="item-icon bi bi-whatsapp" href="{$site.whatsapp}" target="_blank" title="WhatsApp"></a>
 </div>
 <!-- {/if} -->
 <!-- {else} -->
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
 <!-- {if $site.weibo} -->
 <div class="item">
  <a class="item-icon bi bi-sina-weibo" href="{$site.weibo}" target="_blank" title="微博"></a>
 </div>
 <!-- {/if} -->
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
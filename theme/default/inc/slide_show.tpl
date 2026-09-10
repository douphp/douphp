<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<!-- {if $show_list} -->
<div class="slide-show">
 <div class="swiper">
  <div class="swiper-wrapper">
   <!-- {foreach from=$show_list name=show_list item=show} -->
   <div class="swiper-slide"><a href="{$show.link}" target="_blank" style="background-image:url({$show.image})"></a></div>
   <!-- {/foreach} -->
  </div>
  <!-- 如果需要分页器 -->
  <div class="swiper-pagination"></div>

  <!-- 如果需要导航按钮 -->
  <div class="swiper-button-prev"></div>
  <div class="swiper-button-next"></div>
 </div>
</div>
<!-- {/if} -->
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<div class="tree-box">
 <h3>{$lang.product_tree}</h3>
 <ul>
  <!-- {foreach from=$product_category item=cate} 一级分类 -->
  <li{if $cate.cur} class="cur"{/if}><a href="{$cate.url}">{$cate.name}</a></li>
  <!-- {if $cate.child} -->
  <ul>
   <!-- {foreach from=$cate.child item=child} 二级分类 -->
   <li{if $child.cur} class="cur"{/if}><i>-</i><a href="{$child.url}">{$child.name}</a></li>
   <!-- {/foreach} -->
  </ul>
  <!-- {/if} -->
  <!--{/foreach}-->
 </ul>
</div>
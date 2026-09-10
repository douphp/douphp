<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<div class="tree-box">
 <h3>{$lang.about_tree}</h3>
 <ul>
  <!-- {if $top.name} -->
  <li{if $top_cur} class="cur"{/if}><a href="{$top.url}">{$top.name}</a></li>
  <!-- {/if} -->
  <!-- {foreach from=$page_list item=list} -->
  <li{if $list.cur} class="cur"{/if}><a href="{$list.url}">{$list.name}</a></li>
  <!-- {if $list.child} -->
  <ul>
   <!-- {foreach from=$list.child item=child} -->
   <li{if $child.cur} class="cur"{/if}><i>-</i><a href="{$child.url}">{$child.name}</a></li>
   <!-- {/foreach} -->
  </ul>
  <!-- {/if} -->
  <!--{/foreach}-->
 </ul>
</div>
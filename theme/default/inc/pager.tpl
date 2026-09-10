<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<div class="pager">
 <ul>
  <!-- {if $pager.page neq '1'} -->
  <li><a href="{$pager.first}"><i class="bi bi-chevron-double-left"></i></a></li>
  <li><a href="{$pager.previous}"><i class="bi bi-chevron-left"></i></a></li>
  <!-- {/if} -->
  <!-- {foreach from=$pager.box item=box} -->
  <li{if $box.cur} class="active"{/if}><a href="{$box.url}">{$box.page}</a></li>
  <!-- {/foreach} -->
  <!-- {if $pager.page neq $pager.page_count} -->
  <li><a href="{$pager.next}"><i class="bi bi-chevron-right"></i></a></li>
  <li><a href="{$pager.last}"><i class="bi bi-chevron-double-right"></i></a></li>
  <!-- {/if} -->
 </ul>
</div>
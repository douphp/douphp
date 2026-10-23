<!-- 统一页面 Tab：渲染 $tabs 中央解析结果（项文案 / URL / 激活态均来自 AdminTabResolver） -->
<!-- {if $tabs && $tabs.items} -->
<ul class="tab-nav">
 <!-- {foreach from=$tabs.items item=item} -->
 <li class="nav-item"><a class="nav-link{if $item.is_active} active{/if}" href="{$item.url}"<!-- {if $item.is_anchor} --> data-toggle="tab"<!-- {/if} -->>{$item.name}</a></li>
 <!-- {/foreach} -->
</ul>
<!-- {/if} -->

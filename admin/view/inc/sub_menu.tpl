<!-- 统一子菜单：渲染 $nav.sub_menu 中央解析结果（族标题/图标/项状态均来自 AdminNavResolver） -->
<!-- {if $nav.sub_menu} -->
<aside class="subnav">
 <h3><i class="{$nav.sub_menu.icon}"></i>{$nav.sub_menu.title}</h3>
 <ul>
  <!-- {foreach from=$nav.sub_menu.items item=item} -->
  <li><a href="{$item.url}"{if $item.is_active} class="cur"{/if}>{$item.name}</a></li>
  <!-- {/foreach} -->
 </ul>
</aside>
<!-- {/if} -->

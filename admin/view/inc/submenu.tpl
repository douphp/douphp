<!-- 统一子菜单：渲染 $nav.submenu 中央解析结果（族标题/图标/项状态均来自 AdminNavResolver） -->
<!-- {if $nav.submenu} -->
<aside class="subnav">
 <h3><i class="{$nav.submenu.icon}"></i>{$nav.submenu.title}</h3>
 <ul>
  <!-- {foreach from=$nav.submenu.items item=item} -->
  <li><a href="{$item.url}"{if $item.is_active} class="cur"{/if}>{$item.name}</a></li>
  <!-- {/foreach} -->
 </ul>
</aside>
<!-- {/if} -->
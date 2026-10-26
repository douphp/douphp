<!-- 表单子菜单：结构写死，不走 AdminNavResolver；各项即表单，末尾依次为「新建（仅加号）」与「表单管理」入口 -->
<aside class="subnav">
 <h3><i class="bi bi-file-medical"></i>{$lang.form}</h3>
 <ul>
  <!-- {if $form_nav_list} -->
  <!-- {foreach from=$form_nav_list item=form_item} -->
  <li>
   <a href="{$form_item.url}"{if $form_item.cur} class="cur"{/if} title="{$form_item.name}">
    <span class="subnav-name">{$form_item.name}</span><!-- {if $form_item.unread} --><em class="subnav-count">{$form_item.unread}</em><!-- {/if} -->
   </a>
  </li>
  <!-- {/foreach} -->
  <!-- {else} -->
  <li class="subnav-empty">{$lang.form_nav_empty}</li>
  <!-- {/if} -->
  <li class="subnav-add"><a href="{url link='admin.form.template'}" title="{$lang.form_create}" aria-label="{$lang.form_create}"><i class="bi bi-plus"></i></a></li>
  <li class="subnav-manage"><a href="{url link='admin.form.form_list'}"{if $form_manage_cur} class="cur"{/if} title="{$lang.form_list}"><span class="subnav-name">{$lang.form_list}</span></a></li>
 </ul>
</aside>

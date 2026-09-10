<div class="data-list page-scroll-position">
 <!-- {foreach from=$data_list item=data} -->
 <div class="data-list-box {if $data.is_class}block{else}inline{/if}">
  <!-- {if $data.is_class} -->
  <div class="data-list-parent">
   <div class="data-list-name"><em>{$data.name}</em><a href="{url link='admin.data.edit' id=$data.id}">{$lang.edit}</a><a href="{url link='admin.data.create' parent_code=$data.code}">{$lang.add}</a></div>
   <div class="data-list-text">{$data.text nofilter}</div>
  </div>
  <div class="data-list-child">
   <!-- {foreach from=$data.child item=child} -->
   <div class="data-list-item">
    <div class="data-list-name">{$child.name}</div>
    <div class="data-list-content">{$child.content nofilter}</div>
    <div class="data-list-edit"><a href="{url link='admin.data.edit' id=$child.id}">{$lang.edit}</a></div>
   </div>
   <!-- {/foreach} -->
  </div>
  <!-- {else} -->
  <div class="data-list-item">
   <div class="data-list-name">{$data.name}</div>
   <div class="data-list-content">{$data.content nofilter}</div>
   <div class="data-list-edit"><a href="{url link='admin.data.edit' id=$data.id}">{$lang.edit}</a></div>
  </div>
  <!-- {/if} -->
 </div>
 <!-- {/foreach} -->
</div>

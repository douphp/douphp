<div class="page-header">
 <div class="page-header-bar">
  <div class="page-header-title">
   <!-- {if $page_breadcrumb} -->{$page_breadcrumb} &gt; <!-- {/if} -->{$ur_here}
   <!-- {if $page_sub_actions} -->
   <div class="page-header-action-sub">
    <!-- {foreach from=$page_sub_actions item=sub} -->
    <a href="{$sub.href}"<!-- {if $sub.style || $sub.cur} --> class="<!-- {if $sub.style} -->{$sub.style}<!-- {/if} --><!-- {if $sub.cur} --> cur<!-- {/if} -->"<!-- {/if} --> <!-- {if $sub.attrs} --><!-- {foreach from=$sub.attrs key=k item=v} -->{$k}="{$v|escape}" <!-- {/foreach} --><!-- {/if} -->>{$sub.text}</a>
    <!-- {/foreach} -->
   </div>
   <!-- {/if} -->
   <!-- {if $page_cue_inline} -->
   <div class="page-header-cue-inline">{$page_cue_inline nofilter}</div>
   <!-- {/if} -->
  </div>
  <!-- {if $page_actions} -->
  <div class="page-header-action-btns">
   <!-- {foreach from=$page_actions item=btn} -->
   <!-- {if $btn.delete} -->
   <a href="javascript:;" class="page-header-action-btn js-delete{if $btn.style} {$btn.style}{/if}" data-url="{$btn.href}"<!-- {if $btn.confirm} --> data-confirm="{$btn.confirm}"<!-- {/if} -->>{$btn.text}</a>
   <!-- {elseif $btn.post} -->
   <a href="javascript:;" class="page-header-action-btn js-post{if $btn.style} {$btn.style}{/if}" data-url="{$btn.href}"<!-- {if $btn.confirm} --> data-confirm="{$btn.confirm}"<!-- {/if} -->>{$btn.text}</a>
   <!-- {else} -->
   <a href="{$btn.href}" class="page-header-action-btn{if $btn.style} {$btn.style}{/if}" <!-- {if $btn.attrs} --><!-- {foreach from=$btn.attrs key=k item=v} --> {$k}="{$v|escape}"<!-- {/foreach} --><!-- {/if} -->>{$btn.text}</a>
   <!-- {/if} -->
   <!-- {/foreach} -->
  </div>
  <!-- {/if} -->
 </div>
 <!-- {if $page_cue} -->
 <div class="cue">{$page_cue nofilter}</div>
 <!-- {/if} -->
 <!-- {foreach from=$flashes key=type item=f} -->
 <div class="notice notice-{$type}">
  <p><strong>{$f.message}</strong></p>
  <!-- {if $f.back_url} -->
  <p><a href="{$f.back_url}">&larr; {$f.back_text}</a></p>
  <!-- {/if} -->
  <button type="button" class="notice-dismiss" aria-label="{$lang.close}">&times;</button>
 </div>
 <!-- {/foreach} -->
</div>

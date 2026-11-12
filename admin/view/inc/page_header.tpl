<div class="page-header">
 <div class="page-header-bar">
  <div class="page-header-title">
   <!-- {if $page_breadcrumb} -->{$page_breadcrumb} &gt; <!-- {/if} -->{$ur_here}
   <!-- {if $page_sub_actions} -->
   <div class="page-header-action-sub">
    <!-- {foreach from=$page_sub_actions item=sub} -->
    <!-- {if $sub.delete} -->
    <a href="javascript:;" class="js-delete{if $sub.style} {$sub.style}{/if}{if $sub.icon} is-icon{/if}" data-url="{$sub.href}"<!-- {if $sub.confirm} --> data-confirm="{$sub.confirm}"<!-- {/if} --><!-- {if $sub.icon} --> title="{$sub.text}" aria-label="{$sub.text}"<!-- {/if} -->><!-- {if $sub.icon} --><i class="{$sub.icon}"></i><!-- {else} -->{$sub.text}<!-- {/if} --></a>
    <!-- {elseif $sub.post} -->
    <a href="javascript:;" class="js-post{if $sub.style} {$sub.style}{/if}{if $sub.icon} is-icon{/if}" data-url="{$sub.href}"<!-- {if $sub.modal_confirm} --> data-modal-confirm="{$sub.modal_confirm}"<!-- {if $sub.modal_title} --> data-modal-title="{$sub.modal_title}"<!-- {/if} --><!-- {elseif $sub.confirm} --> data-confirm="{$sub.confirm}"<!-- {/if} --><!-- {if $sub.icon} --> title="{$sub.text}" aria-label="{$sub.text}"<!-- {/if} -->><!-- {if $sub.icon} --><i class="{$sub.icon}"></i><!-- {else} -->{$sub.text}<!-- {/if} --></a>
    <!-- {else} -->
    <a href="{$sub.href}"<!-- {if $sub.style || $sub.cur || $sub.icon} --> class="<!-- {if $sub.style} -->{$sub.style}<!-- {/if} --><!-- {if $sub.cur} --> cur<!-- {/if} --><!-- {if $sub.icon} --> is-icon<!-- {/if} -->"<!-- {/if} --> <!-- {if $sub.attrs} --><!-- {foreach from=$sub.attrs key=k item=v} -->{$k}="{$v|escape}" <!-- {/foreach} --><!-- {/if} --><!-- {if $sub.icon} --> title="{$sub.text}" aria-label="{$sub.text}"<!-- {/if} -->><!-- {if $sub.icon} --><i class="{$sub.icon}"></i><!-- {else} -->{$sub.text}<!-- {/if} --></a>
    <!-- {/if} -->
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
   <a href="javascript:;" class="page-header-action-btn js-post{if $btn.style} {$btn.style}{/if}" data-url="{$btn.href}"<!-- {if $btn.modal_confirm} --> data-modal-confirm="{$btn.modal_confirm}"<!-- {if $btn.modal_title} --> data-modal-title="{$btn.modal_title}"<!-- {/if} --><!-- {elseif $btn.confirm} --> data-confirm="{$btn.confirm}"<!-- {/if} -->>{$btn.text}</a>
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

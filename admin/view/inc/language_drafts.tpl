<!-- {if $lang_drafts} -->
<!-- {foreach from=$lang_drafts item=pack} -->
<!-- {foreach from=$pack.fields item=item} -->
<input type="hidden" name="language_draft[{$pack.language_pack|escape}][{$item.field|escape}]" value="{$item.value|escape}" />
<!-- {/foreach} -->
<!-- {/foreach} -->
<!-- {/if} -->

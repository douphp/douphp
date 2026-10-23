<!-- {if $definition_lang_drafts} -->
<!-- {foreach from=$definition_lang_drafts item=pack} -->
<!-- {foreach from=$pack.fields item=item} -->
<input type="hidden" name="definition_language[{$pack.language_pack|escape}][{$item.field|escape}]" value="{$item.value|escape}" />
<!-- {/foreach} -->
<!-- {/foreach} -->
<!-- {/if} -->

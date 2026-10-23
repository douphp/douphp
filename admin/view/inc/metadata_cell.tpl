<!-- {if $field.type eq 'image'} -->
<!-- {if $field.url} --><img src="{$field.url|escape}" height="24" alt="{$field.label|escape}" /><!-- {else} -->-<!-- {/if} -->
<!-- {elseif $field.type eq 'gallery'} -->
<!-- {if $field.urls} --><!-- {foreach from=$field.urls item=u} --><img src="{$u|escape}" height="24" alt="{$field.label|escape}" /><!-- {/foreach} --><!-- {else} -->-<!-- {/if} -->
<!-- {elseif $field.type eq 'file'} -->
<!-- {if $field.url} --><a href="{$field.url|escape}" target="_blank" rel="noopener">{$lang.metadata_download}</a><!-- {else} -->-<!-- {/if} -->
<!-- {else} -->
<!-- {if $field.has_value} -->{$field.summary|escape}<!-- {else} -->-<!-- {/if} -->
<!-- {/if} -->

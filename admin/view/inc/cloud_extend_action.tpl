<!-- {if $action.kind eq 'installed'} -->
<em>{$action.label}</em>
<!-- {elseif $action.popup} -->
<!-- {if $action.popup.align} -->
<a href="javascript:;" data-dou-toggle="modal" data-dou-modal="message" data-title="{$action.popup.title}" data-html="{$action.popup.text}" data-btn-name="{$action.popup.btn_name}" data-placement="{$action.popup.align}" data-btn-link="{$action.popup.btn_link}">{$action.label}</a>
<!-- {else} -->
<a href="javascript:;" data-dou-toggle="modal" data-dou-modal="message" data-title="{$action.popup.title}" data-html="{$action.popup.text}" data-btn-name="{$action.popup.btn_name}" data-placement="center" data-btn-link="{$action.popup.btn_link}">{$action.label}</a>
<!-- {/if} -->
<!-- {elseif $action.kind} -->
<!-- {if $action.target eq '_blank'} -->
<a href="{$action.url}" target="_blank">{$action.label}</a>
<!-- {else} -->
<a href="{$action.url}">{$action.label}</a>
<!-- {/if} -->
<!-- {/if} -->

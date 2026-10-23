<!-- {if $metadata_form.present} -->
<div class="metadata-fields">
 <input type="hidden" name="metadata_present" value="1" />
 <!-- {foreach from=$metadata_form.panes item=pane} -->
 <div class="metadata-lang-pane" id="metadata-pane-{$pane.language_pack}"{if !$pane.is_default} style="display:none"{/if}>
  <!-- {foreach from=$pane.fields item=metadata_field} -->
  <div class="form-item metadata-field" data-slug="{$metadata_field.slug}">
   <div class="form-label">{$metadata_field.label|escape}{if $metadata_field.required_display} <i>*</i>{/if}</div>
   <div class="input-block">
    <!-- {if !$pane.is_default} -->
    <label class="checkbox metadata-inherit-label"><input type="checkbox" class="metadata-inherit" name="{$metadata_field.inherit_name}" value="1"{if $metadata_field.inherit} checked="checked"{/if} /> {$lang.metadata_inherit_default}</label>
    <!-- {/if} -->
    <div class="metadata-control">
     <!-- {if $metadata_field.type eq 'textarea'} -->
     <textarea name="{$metadata_field.input_name}" cols="115" rows="3" class="textarea"{if $metadata_field.inherit} disabled="disabled"{/if}>{$metadata_field.value|escape}</textarea>
     <!-- {elseif $metadata_field.type eq 'select'} -->
     <select name="{$metadata_field.input_name}" class="select"{if $metadata_field.inherit} disabled="disabled"{/if}>
      <option value=""></option>
      <!-- {foreach from=$metadata_field.options_data item=option} -->
      <option value="{$option.value|escape}"{if $option.checked} selected="selected"{/if}>{$option.label|escape}</option>
      <!-- {/foreach} -->
     </select>
     <!-- {elseif $metadata_field.type eq 'radio'} -->
     <label class="radio"><input type="radio" name="{$metadata_field.input_name}" value=""{if $metadata_field.value eq ''} checked="checked"{/if}{if $metadata_field.inherit} disabled="disabled"{/if} /> {$lang.metadata_clear}</label>
     <!-- {foreach from=$metadata_field.options_data item=option} -->
     <label class="radio"><input type="radio" name="{$metadata_field.input_name}" value="{$option.value|escape}"{if $option.checked} checked="checked"{/if}{if $metadata_field.inherit} disabled="disabled"{/if} /> {$option.label|escape}</label>
     <!-- {/foreach} -->
     <!-- {elseif $metadata_field.type eq 'checkbox'} -->
     <!-- {foreach from=$metadata_field.options_data item=option} -->
     <label class="checkbox"><input type="checkbox" name="{$metadata_field.input_name}[]" value="{$option.value|escape}"{if $option.checked} checked="checked"{/if}{if $metadata_field.inherit} disabled="disabled"{/if} /> {$option.label|escape}</label>
     <!-- {/foreach} -->
     <!-- {elseif $metadata_field.type eq 'switch'} -->
     <input type="hidden" name="{$metadata_field.input_name}" value="0"{if $metadata_field.inherit} disabled="disabled"{/if} />
     <label class="checkbox"><input type="checkbox" name="{$metadata_field.input_name}" value="1"{if $metadata_field.checked} checked="checked"{/if}{if $metadata_field.inherit} disabled="disabled"{/if} /> {$metadata_field.label|escape}</label>
     <!-- {elseif $metadata_field.type eq 'image'} -->
     <div class="metadata-file-input">
      {include file="inc/file_image.tpl" name=$metadata_field.file_name value=$metadata_field.file_url file_number=$metadata_field.file_number hidden_name=$metadata_field.input_name defer_delete=1}
     </div>
     <!-- {elseif $metadata_field.type eq 'gallery'} -->
     <div class="metadata-file-input">
      <!-- {foreach from=$metadata_field.slots item=slot} -->
      {include file="inc/file_image.tpl" name=$slot.file_name value=$slot.url file_number=$slot.number hidden_name=$slot.input_name defer_delete=1}
      <!-- {/foreach} -->
     </div>
     <!-- {elseif $metadata_field.type eq 'file'} -->
     <div class="metadata-file">
      <input type="hidden" name="{$metadata_field.input_name}" value="{$metadata_field.file_number}" class="metadata-file-number"{if $metadata_field.inherit} disabled="disabled"{/if} />
      <!-- {if $metadata_field.file_url} --><a href="{$metadata_field.file_url|escape}" target="_blank" rel="noopener">{$metadata_field.file_url|escape}</a><!-- {/if} -->
      <input type="file" name="{$metadata_field.file_name}" accept="{$metadata_field.accept|escape}"{if $metadata_field.inherit} disabled="disabled"{/if} />
      <label class="checkbox"><input type="checkbox" name="{$metadata_field.clear_name}" value="1"{if $metadata_field.inherit} disabled="disabled"{/if} /> {$lang.metadata_clear}</label>
     </div>
     <!-- {elseif $metadata_field.type eq 'editor'} -->
     {include file="inc/editor.tpl" name=$metadata_field.editor_id input_name=$metadata_field.input_name value=$metadata_field.value js="no" hide_upload=1}
     <!-- {elseif $metadata_field.type eq 'number'} -->
     <input type="number" step="any" name="{$metadata_field.input_name}" value="{$metadata_field.value|escape}" class="input input-number"{if $metadata_field.inherit} disabled="disabled"{/if} />
     <!-- {elseif $metadata_field.type eq 'date'} -->
     <input type="date" name="{$metadata_field.input_name}" value="{$metadata_field.value|escape}" class="input"{if $metadata_field.inherit} disabled="disabled"{/if} />
     <!-- {else} -->
     <input type="text" name="{$metadata_field.input_name}" value="{$metadata_field.value|escape}" class="input"{if $metadata_field.inherit} disabled="disabled"{/if} />
     <!-- {/if} -->
     <!-- {if $metadata_field.action_inline && $pane.is_default && $metadata_field.lang_buttons} --><span class="action metadata-action-inline" data-field="{$metadata_field.slug|escape}">{$metadata_field.lang_buttons nofilter}</span><!-- {/if} -->
    </div>
    <!-- {if $metadata_field.tip} --><p class="cue">{$metadata_field.tip|escape}</p><!-- {/if} -->
    <!-- {if !$metadata_field.action_inline && $pane.is_default && $metadata_field.lang_buttons} --><p class="action" data-field="{$metadata_field.slug|escape}">{$metadata_field.lang_buttons nofilter}</p><!-- {/if} -->
   </div>
  </div>
  <!-- {/foreach} -->
 </div>
 <!-- {/foreach} -->
</div>
<!-- {/if} -->

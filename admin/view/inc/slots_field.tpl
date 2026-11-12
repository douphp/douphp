<!-- Admin Slots Field
 +----------------------------------------------------------
 * data / show 共用：仅渲染「分区文本」面板内容（标识 -> 内容 逐行 input）
 * 由 data.htm / show.htm 的内置 Tab（.tab-pane[data-slots-pane]）内 include，
 * 面板标签行（文字内容 / 分区文本 触发器）在父级 form-label 中，本片段不含标签
 *
 * 入参：
 *   rows   分区行 array(array('key' => '..', 'value' => '..'))
 *   cue    面板底部提示（已替换 code 的调用示例，可空）
 *   locked 片段锁定标记（data.is_locked）：key 只读灰度、禁止增删行
 *
 * 其余变量（$lang.slots_* / $btn_lang.slots）直接取自父级作用域
 * 依赖：js/slots.js（增删行、标识归一、提交前校验、写回 content_mode）
 +----------------------------------------------------------
-->
<div class="input-block">
 <div class="slots-box">
  <div class="slots-rows" data-slots-rows{if $locked} data-slots-locked="1"{/if}>
   <!-- {foreach from=$rows item=row} -->
   <div class="slots-row">
    <input type="text" name="slot_key[]" value="{$row.key}" class="input slots-key" placeholder="{$lang.slots_key}"{if $locked} readonly{/if} />
    <input type="text" name="slot_value[]" value="{$row.value}" class="input slots-value" placeholder="{$lang.slots_value}" />
    <!-- {if !$locked} --><button type="button" class="slots-remove bi-x" title="{$lang.slots_remove}" tabindex="-1"></button><!-- {/if} -->
   </div>
   <!-- {/foreach} -->
  </div>
  <!-- {if !$locked} -->
  <div class="slots-actions">
   <a href="javascript:;" class="slots-add" title="{$lang.slots_add}"><i class="bi-plus"></i></a>
  </div>
  <!-- {/if} -->
 </div>
 <!-- {if $btn_lang.slots} --><p class="action" data-field="slots">{$btn_lang.slots nofilter}</p><!-- {/if} -->
 <!-- {if $cue} --><p class="cue">{$cue nofilter}</p><!-- {/if} -->
</div>

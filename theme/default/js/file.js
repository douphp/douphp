/**
 +----------------------------------------------------------
 * 文件盒子.文件上传
 +----------------------------------------------------------
 */
function fileBox(type, target, module, item_id, folder = 'no', img_width = '', draft_token = '', token = '') {
    if (!token) {
        token = $('input[name="token"]').first().val() || '';
    }

    if ($('#' + target + 'Form').length == 0) {
        var field_name = target + (type == 'content' ? '_file[]' : '_file');
        
        var form = 
           '<form action="' + route('user.filebox', {}, { query: { module: module, target: target } }) + '" id="' + target + 'Form" enctype="multipart/form-data" style="display:none">'
           + '<input id="' + target + 'Field" type="file" name="' + field_name + '" multiple>'
           + '<input type="hidden" name="folder" value="' + folder + '">'
           + '<input type="hidden" name="item_id" value="' + item_id + '">'
           + '<input type="hidden" name="draft_token" value="' + draft_token + '">'
           + '<input type="hidden" name="token" value="' + token + '">'
         + '</form>';
        $("body").append(form);
    }
   
    var status = $('#' + target + 'File .fileStatus');
    var btn = $('#' + target + 'File .btnFile');
    var field = $('#' + target + 'Field');
    
    // 点击文件域
    field.click();
    
    // 选择文件后文件域状态改变，开始提交表单
    field.off("change").on("change",function() {
        // 还要判断文件域是否为空
        if($(this).val() != '') { 
            $('#' + target + 'Form').ajaxForm({
                type: 'POST',
                data: {'type':type, 'img_width':img_width},
                beforeSubmit: function() {
                    status.show();
                    btn.hide();
                },
                success: function(html) {
                    // 如果是添加详情图片则插入到编辑器
                    if (type == 'content') {
                        if (html.indexOf('<img') >= 0 ) { 
                            UM.getEditor(target).execCommand('insertHtml', html);
                        } else {
                            alert(html);
                        }
                    } else {
                        $('#' + target).html(html);
                    }
                    status.hide();
                    btn.show();
                },
                error: function() {
                    status.hide();
                    btn.show();
                },
                clearForm: true
            }).submit();
         
            // 每次执行后要清空文件域
            field.val('');
        } 
   })
}

/**
 +----------------------------------------------------------
 * 文件盒子.文件删除
 +----------------------------------------------------------
 */
function fileDel(number, target, module = '') {
    var target = $('#' + target);
    var token = $('input[name="token"]').first().val() || '';
    
    $.ajax({
        type: "POST",
        url: route('user.filedel'),
        data: {"number": number, "token": token},
        dataType: "html",
        success: function(html) {
            target.html(html);
        }
    });
}
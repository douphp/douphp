/**
 +----------------------------------------------------------
 * 监听
 +----------------------------------------------------------
 */
$(function() {
    $(".input-file .input-file-btn").click(function() {
        var target = $(this).data("target");
        var fileField = $("#" + target + " input[type=file]");
        fileField.click();
        fileField.change(function() {
            fileImgShow(this, target)
        });
    });
});

/**
 +----------------------------------------------------------
 * 实时显示FILE图片
 +----------------------------------------------------------
 */
function fileImgShow(node, target) {
    var imgURL = "";
    
    try {
        var file = null;
        if (node.files && node.files[0] ) {
            file = node.files[0];
        } else if (node.files && node.files.item(0)) {
            file = node.files.item(0);
        }
        //Firefox 因安全性问题已无法直接通过input[file].value 获取完整的文件路径
        try {
            imgURL = file.getAsDataURL();
        } catch(e) {
            imgRUL = window.URL.createObjectURL(file);
        }
    } catch(e) {
        if (node.files && node.files[0]) {
            var reader = new FileReader();
            reader.onload = function(e) {
                imgURL = e.target.result;
            };
            reader.readAsDataURL(node.files[0]);
        }
    }
    
    var textHtml = "<img src='" + imgRUL + "'/>";
    $("#" + target + " .input-file-show").html(textHtml);
    $("#" + target + " input[type=hidden]").val(imgRUL);
    
    return imgURL;
}
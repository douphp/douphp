/**
 +----------------------------------------------------------
 * excel导入
 +----------------------------------------------------------
 */
function importExecl() {
    var excelFile = $('#excelFile');
    var excelForm = $('#excelForm');
    
    // 点击文件域
    excelFile.click();
    
    // 选择文件后文件域状态改变，开始提交表单
    excelFile.change(function() {
        excelForm.submit();
    })
}
<?php /* DouView 1.0 | rev 11 | compiled 2026-10-16 12:58:06 */ ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<title><?php echo $ctx->vars['title']; ?>
</title>
<link href="view/assets/style.css" rel="stylesheet" type="text/css" />
</head>
<body>
<div id="wrapper">
 <div class="logo"><a href="http://www.douphp.com" target="_blank"><img src="view/assets/logo.gif" alt="DouPHP" title="DouPHP" /></a></div>
 <div class="check">
  <h3><?php echo $ctx->vars['lang']['check_system']; ?>
</h3>
  <ul>
   <li><?php echo $ctx->vars['lang']['os']; ?>
 ............................................................................... <?php echo $ctx->vars['sys_info']['os']; ?>
</li>
   <li><?php echo $ctx->vars['lang']['web_server']; ?>
 ............................................................................... <?php echo $ctx->vars['sys_info']['web_server']; ?>
</li>
   <?php if ($ctx->vars['php_ok']): ?>
   <li><?php echo $ctx->vars['lang']['php_version']; ?>
 ............................................................................... <?php echo $ctx->vars['sys_info']['php_ver']; ?>
</li>
   <?php else: ?>
   <li><?php echo $ctx->vars['lang']['php_version']; ?>
 ............................................................................... <b class="noWrite"><?php echo $ctx->vars['sys_info']['php_ver']; ?>
</b>（<?php echo $ctx->vars['lang']['php_version_too_low']; ?>
）</li>
   <?php endif; ?>
   <li><?php echo $ctx->vars['lang']['mysql_version']; ?>
 ............................................................................... <?php echo $ctx->vars['sys_info']['mysql_ver']; ?>
</li>
   <li><?php echo $ctx->vars['lang']['socket']; ?>
 ............................................................................... <?php echo $ctx->vars['sys_info']['socket']; ?>
</li>
   <li><?php echo $ctx->vars['lang']['timezone']; ?>
 ............................................................................... <?php echo $ctx->vars['sys_info']['timezone']; ?>
</li>
   <li><?php echo $ctx->vars['lang']['gd_version']; ?>
 ............................................................................... <?php echo $ctx->vars['sys_info']['gd']; ?>
</li>
   <li><?php echo $ctx->vars['lang']['zlib']; ?>
 ............................................................................... <?php echo $ctx->vars['sys_info']['zlib']; ?>
</li>
  </ul>
  <h3><?php echo $ctx->vars['lang']['check_dir']; ?>
</h3>
  <ul>
   <?php $_from = $ctx->vars['writeable']; if (!is_array($_from) && !is_object($_from)) { settype($_from, 'array'); }$ctx->loops['_auto_0_writeable'] = array('total' => count($_from), 'iteration' => 0);
$ctx->loopVarmap['writeable'] = '_auto_0_writeable';
if ($ctx->loops['_auto_0_writeable']['total'] > 0):
    foreach ($_from as $ctx->vars['writeable']):
        $ctx->loops['_auto_0_writeable']['iteration']++;
?>
   <li><?php echo $ctx->vars['writeable']['dir']; ?>
 ............................................................................... <?php echo $ctx->vars['writeable']['if_write']; ?>
</li>
   <?php endforeach; endif; unset($_from); ?>
  </ul>
  <p class="action">
  <input type="button" class="btnGray" value="<?php echo $ctx->vars['lang']['back']; ?>
" onclick="location.href='index.php'"/>
  <?php if ($ctx->vars['block_next']): ?>
  <input type="submit" class="btnGray" value="<?php echo $ctx->vars['lang']['next']; ?>
" onclick="location.href='index.php?route=setting'" disabled="true"/>
  <?php else: ?>
  <input type="submit" class="btn" value="<?php echo $ctx->vars['lang']['next']; ?>
" onclick="location.href='index.php?route=setting'"/>
  <?php endif; ?>
  </p>
 </div>
</div>
</body>
</html>
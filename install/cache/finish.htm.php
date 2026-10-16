<?php /* DouView 1.0 | rev 11 | compiled 2026-10-16 12:58:22 */ ?>
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
 <div class="finish">
  <h3><?php echo $ctx->vars['lang']['finish_title']; ?>
</h3>
  <p class="success"><?php echo $ctx->vars['lang']['finish']; ?>
</p>
  <ul>
   <table width="100%" border="0" cellspacing="0" cellpadding="0">
    <tr>
     <td width="120"><strong><?php echo $ctx->vars['lang']['setting_username']; ?>
：</strong></td>
     <td>
      <?php echo $ctx->vars['username']; ?>

     </td>
    </tr>
    <tr>
     <td><strong><?php echo $ctx->vars['lang']['setting_password']; ?>
：</strong></td>
     <td>
      <?php echo $ctx->vars['lang']['finish_password']; ?>

     </td>
    </tr>
   </table>
  </ul>
  <p class="action">
   <a href="../admin" class="btn"><?php echo $ctx->vars['lang']['load']; ?>
</a>
  </p>
 </div>
</div>
</body>
</html>
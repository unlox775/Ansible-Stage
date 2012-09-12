<?php require(dirname(dirname($_SERVER['SCRIPT_FILENAME'])) .'/ansible-controller.inc.php'); ?>
<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/header.inc.php' ); ?>

<h2><?php echo $view->command_name ?> log of <?php echo $view->file ?></h2>
<p><a href="javascript:history.back()">Go Back</a></p>
<hr>

<xmp><?php echo "\n". $view->clog ."\n" ?></xmp>

<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/footer.inc.php' ); ?>

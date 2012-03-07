<?php require(dirname(dirname($_SERVER['SCRIPT_FILENAME'])) .'/ansible-controller.inc.php'); ?>
<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/minimal_header.inc.php' ); ?>

<h2><?php echo $view->command_name ?> log entries of <?php echo $view->file ?> from -r <?php echo $view->from_rev ?> to -r <?php echo $view->to_rev ?></h2>
<p><a href="javascript:history.back()">Go Back</a></p>
<hr>

<xmp><?php echo "\n". $view->clog ."\n" ?></xmp>

<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/footer.inc.php' ); ?>

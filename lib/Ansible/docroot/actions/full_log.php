<?php require(dirname(dirname($_SERVER['SCRIPT_FILENAME'])) .'/ansible-controller.inc.php'); ?>
<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/header.inc.php' ); ?>

<h2><?php echo $view->command_name ?> log of <?php echo $view->file ?></h2>
<p><a href="../project.php?<?php echo $view->project_url_params ?>">Go Back</a></p>
<hr>

<div style="position: relative; left: -40px">
	<a href="set_file_tag.php?file=<?php echo urlencode($view->file) ?>&rev=&<?php echo $view->project_url_params ?>&redir=<?php echo urlencode( $_SERVER['REQUEST_URI'] ) ?>"
	style="color: black"
	>[ No Target ]</a>
</div>

<xmp><?php echo "\n". $view->clog ."\n" ?></xmp>

<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/footer.inc.php' ); ?>

<?php require(dirname(dirname($_SERVER['SCRIPT_FILENAME'])) .'/ansible-controller.inc.php'); ?>
<?php
$modal_mode = isset($_REQUEST['m']);
?>
<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/'. ( $modal_mode ? 'modal-' : '' ) .'header.inc.php' ); ?>

<h2><?php echo $view->command_name ?> log entries of <?php echo $view->file ?> from -r <?php echo $view->from_rev ?> to -r <?php echo $view->to_rev ?></h2>
<p>
	<?php if ( !$modal_mode ) { ?><a href="../project.php?<?php echo $view->project_url_params ?>">Go Back</a><?php } ?>
	<div style="float: right; width: 180px; text-align: right">
		<a href="<?php echo $_SERVER['REQUEST_URI'] ?>&refresh=1">Refresh Revisions</a>
	</div>
	<?php if ( true ) { ?>
		<a href="javascript:void(null)" onclick="activateLogProposeMerge()">Propose Merge</a>
		<div id="propose-merge"
			 style="display: none"
			 data-file="<?php echo $view->file ?>"
			 >
			<textarea></textarea>
		</div>
	<?php } ?>
</p>
<hr>

<div style="padding-left: 50px">
	<div style="position: relative; left: -40px">
		<a href="set_file_tag.php?file=<?php echo urlencode($view->file) ?>&rev=&<?php echo $view->project_url_params ?>&redir=<?php echo urlencode( $_SERVER['REQUEST_URI'] ) ?>"
		style="color: black"
		>[ No Target ]</a>
	</div>

	<xmp><?php echo "\n". $view->clog ."\n" ?></xmp>
</div>

<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/'. ( isset($_REQUEST['m']) ? 'modal-' : '' ) .'footer.inc.php' ); ?>

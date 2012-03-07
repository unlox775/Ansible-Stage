<?php require(dirname($_SERVER['SCRIPT_FILENAME']) .'/ansible-controller.inc.php') ?>
<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/header.inc.php' ); ?>

<!-- /////  Command output ///// -->
<?php if ( ! empty($view->previous_command ) ) { ?>
	<font color=red><h3>Command Output</h3>
	<xmp>> <?php echo $view->previous_command['cmd'] ?>
	<?php echo "\n".$view->previous_command['output'] ?></xmp>
	</font>
	<br><br><a href="admin.php" style="font-size:70%">&lt;&lt;&lt; Click here to hide Command output &gt;&gt;&gt;</a><br>
<?php } ?>

<h2>Repository Administration and Maintenance</h2>

<!-- /////  Actions  ///// -->
<?php $view->scoped_include( './admin_actions.inc.php', array() ); ?>

<h3>Repository Status for Root Directory</h3>
<table width=100%>
	<tr><td>&nbsp;</td><td colspan=5 align=center style="border: solid black; border-width: 1px 1px 0px 1px"><b>Revisions</b></td><td>&nbsp;</td><td>&nbsp;</td></tr>
	<tr>
		<td width=30%><b>File Name</b></td>
		<td align=center><b>Current Status</b></td>
		<td align=center><b>PROD_TEST</b></td>
		<td align=center><b>PROD_SAFE</b></td>
	</tr>
	<?php foreach ($view->files as $file) { ?>
		<tr>
	    	<td><a href="actions/entire_repo_full_log.php?file=<?php echo urlencode($file) ?>"><?php echo $file['file'] ?></a></td>
	    	<td align=center><?php echo $file['cur_vers'] ?></td>
	    	<td align=center><?php echo $file['prod_test_vers'] ?></td>
	    	<td align=center><?php echo $file['prod_safe_vers'] ?></td>
	    </tr>
	<?php } ?>
</table>

<!-- /////  If there were any locally modified files, then  ///// -->
<!-- /////    DISABLE Updating until they are fixed         ///// -->
<?php if ( ! empty( $view->locally_modified ) ) { ?>
	<script type="text/javascript">
	disable_actions = 1;
	</script>
<?php } ?>

<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/footer.inc.php' ); ?>

<?php require(dirname($_SERVER['SCRIPT_FILENAME']) .'/ansible-controller.inc.php') ?>
<?php require( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/header.inc.php' ); ?>

<!-- /////  Command output ///// -->
<?php if ( ! empty($view->previous_command ) ) { ?>
	<?php require($stage->extend->run_hook('command_output', 0)) ?>
	<font color=red>
        <h3>Command Output</h3>
        <xmp>> <?php echo $view->previous_command['cmd'] ?>
        <?php echo "\n".$view->previous_command['output'] ?></xmp>
	</font>
	<br><br><a href="project.php?pname=<?php echo $view->project->project_name ?>" style="font-size:70%">&lt;&lt;&lt; Click here to hide Command output &gt;&gt;&gt;</a><br>
	<?php require($stage->extend->run_hook('command_output', 10)) ?>
<?php } ?>

<h2>Project: <?php echo $view->project->project_name ?></h2>

<!-- /////  Actions  ///// -->
<?php $view->scoped_include( './project_actions.inc.php', array('project') ) ?>

<!-- /////  Affected Files  ///// -->
<h3>Affected Files</h3>
<table width="100%">
	<tr><td>&nbsp;</td><td colspan=5 align=center style="border: solid black; border-width: 1px 1px 0px 1px"><b>Revisions</b></td><td>&nbsp;</td><td>&nbsp;</td></tr>
	<tr>
		<td width=30%><b>File Name</b></td>
		<td align=center><b>Current Status</b></td>
		<td align=center><b>Target</b></td>
		<td align=center><b>HEAD</b></td>
		<td align=center><b>PROD_TEST</b></td>
		<td align=center><b>PROD_SAFE</b></td>
		<td align=center><b>Changes By</b></td>
		<td align=left><b>Action</b></td>
	</tr>

    <?php foreach ( $view->files as $file ) { ?>
		<tr>
			<td><a href="actions/full_log.php?file=<?php echo urlencode($file['file']) ?>"><?php echo $file['file'] ?></a></td>
			<td align=center><?php echo $file['cur_vers'] ?></td>
			<td align=center><?php echo $file['target_vers'] ?></td>
			<td align=center><?php echo $file['head_vers'] ?></td>
			<td align=center><?php echo $file['prod_test_vers'] ?></td>
			<td align=center><?php echo $file['prod_safe_vers'] ?></td>
			<td align=center><?php echo $file['changes_by'] ?></td>
			<td align=left  ><?php echo $file['actions'] ?></td>
		</tr>
    <?php } ?>
</table>

<!-- /////  If there were any locally modified files, then  ///// -->
<!-- /////  DISABLE Updating until they are fixed  ///// -->
<?php if ( $view->locally_modified ) { ?>
	<script type="text/javascript">
	disable_actions = 1;
	</script>
<?php } ?>

<?php if ( ! empty( $view->other_projects ) ) { ?>
<label class="other_projects" style="padding: 15px 10px 0 0; font-weight: bold; display: inline-block">Projects Sharing Files: </label>
	<?php
	  $content = array();
	  foreach ( $view->other_projects as $pname => $their_files ) {
		  $content[] = '<a href="project.php?pname='. urlencode($pname) .'" title="Sharing '. count($their_files) .' Files:'. "\n". join("\n", $their_files) .'">'. $pname .'</a>';
	  }
	  echo join(', ', $content);
	?>
<?php } ?>

<!-- /////  Summary File  ///// -->
<h3>Summary</h3>
<pre>
	<?php if ( $view->project->file_exists("summary.txt") ) { ?>
	    <?php echo $view->project->get_file("summary.txt"); ?>
	<?php } else { ?>
	    -- No project summary entered --
	<?php } ?>
</pre>

<?php require( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/footer.inc.php' ); ?>

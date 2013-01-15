<!-- /////	Actions ///// -->
<table width="100%" border=0 cellspacing=0 cellpadding=0>
	<tr>
		<td align="left" valign="top">
			<?php if ( $stage->read_only_mode() ) { ?>
				<h4>Actions</h4>
				<i>You must log in as a privileged user to perform $repo->display_name actions.	 Sorry.</i>
			<?php } else { ?>
				<h4>Actions</h4>
				Update to: <a href="javascript: confirmAction('UPDATE','actions/update.php?<?php echo $view->project_url_params ?>&tag=Target')"	>Target</a>
							 | <a href="javascript: confirmAction('UPDATE','actions/update.php?<?php echo $view->project_url_params ?>&tag=HEAD')"		>HEAD</a>
							 | <a href="javascript: confirmAction('UPDATE','actions/update.php?<?php echo $view->project_url_params ?>&tag=PROD_TEST')">Rollout Tag</a>
							 | <a href="javascript: confirmAction('UPDATE','actions/update.php?<?php echo $view->project_url_params ?>&tag=PROD_SAFE')">Rollback Tag</a>
				<br>Tag as:	   <a href="javascript: confirmAction('TAG',   'actions/set_all_project_targets.php?<?php echo $view->project_url_params ?>&redir=<?= urlencode( $stage->safe_self_url()) ?>')"	  >Target</a>
							 | <a href="javascript: confirmAction('TAG',   'actions/tag.php?<?php echo $view->project_url_params ?>&tag=PROD_TEST')"	  >Rollout Tag</a>
							 | <a href="javascript: confirmAction('TAG',   'actions/tag.php?<?php echo $view->project_url_params ?>&tag=PROD_SAFE')"	  >Rollback Tag</a>
			<?php } ?>
		</td>

		<td align="left" valign="top">
			<!-- /////	Rollout process for different phases  ///// -->
			<?php if ( $stage->onAlpha() ) { ?>
				<h4>Rollout Process</h4>
				When you are ready, review the below file list to make sure:
				<ol>
					<li>All needed code and display logic files are here</li>
					<li>Any needed database patch scripts are listed (if any)</li>
					<li>In the "Current Status" column everything is "Up-to-date"</li>
					<li>In the "Changes by" column, they are all ychanges</li>
				</ol>
				Then, tell QA and they will continue in the <a href="<?php echo $stage->get_area_url('beta','project.php') ?>">QA Staging Area</a>
			<?php } else if ( $stage->onBeta() ) { ?>
				<?php if ( $stage->read_only_mode() ) { ?>
					<h4>Rollout Process - QA STAGING PHASE</h4>
					<b>Step 1</b>: Once developer is ready, Update to Target<br>
					<b>Step 2</b>: <i> -- Perform QA testing -- </i><br>
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2a</b>: For minor updates, Update to Target again<br>
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2b</b>: If major problems, Roll back to Previous Rollout Tag (Production)<br>
					<b>Step 3</b>: When everything checks out, Tag as Rollout Tag<br>
					<br>
					Then, <a href="<?php echo $stage->get_area_url('live','project.php') ?>">Switch to Live Production Area</a>
				<?php } else { ?>
					<h4>Rollout Process - QA STAGING PHASE</h4>
					<b>Step 1</b>: Once developer is ready, <a href="javascript: confirmAction('UPDATE','actions/update.php?<?php echo $view->project_url_params ?>&tag=Target&set_group=01_staging')"	  >Update to Target</a><br>
					<b>Step 2</b>: <i> -- Perform QA testing -- </i><br>
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2a</b>: For minor updates, <a		 href="javascript: confirmAction('UPDATE','actions/update.php?<?php echo $view->project_url_params ?>&tag=Target')"   >Update to Target again</a><br>
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2b</b>: If major problems, <a		 href="javascript: confirmAction('UPDATE','actions/update.php?<?php echo $view->project_url_params ?>&tag=PROD_TEST&set_group=00_none')">Roll back to Previous Rollout Tag (Production)</a><br>
					<b>Step 3</b>: When everything checks out, <a href="javascript: confirmAction('TAG',   'actions/tag.php?<?php echo $view->project_url_params ?>&tag=PROD_TEST&set_group=03_testing_done')"		>Tag as Rollout Tag</a><br>
					<br>
					Then, <a href="<?php echo $stage->get_area_url('live','project.php') ?>">Switch to Live Production Area</a>
				<?php } ?>
			<?php } else if ( $stage->onLive() ) { ?>
				<?php if ( $stage->read_only_mode() ) { ?>
					<h4>Rollout Process - PRODUCTION PHASE</h4>
					Check that in the "Current Status" column there are <b><u>no <b>"Locally Modified"</b> or <b>"Needs Merge"</b> statuses</u></b>!!
					<br>
					<b>Step 4</b>: Set set a safe rollback point, Tag as Rollback Tag<br>
					<b>Step 5</b>: Then to roll it all out, Update to Rollout Tag<br>
					<b>Step 6</b>: <i> -- Perform QA testing -- </i><br>
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6a</b>: If any problems, Roll back to Rollback Tag<br>
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6b</b>: While fixes are made, Re-set Previous Rollout Tag<br>
					Then, go back to the <a href="<?php echo $stage->get_area_url('beta','project.php') ?>">QA Staging Area</a> and continue with <b>Step 1</b> or <b>Step 2</b>.
				<?php } else { ?>
					<h4>Rollout Process - PRODUCTION PHASE</h4>
					Check that in the "Current Status" column there are <b><u>no <b>"Locally Modified"</b> or <b>"Needs Merge"</b> statuses</u></b>!!
					<br>
					<b>Step 4</b>: Set set a safe rollback point, <a href="javascript: confirmAction('TAG',	  'actions/tag.php?<?php echo $view->project_url_params ?>&tag=PROD_SAFE&set_group=04_prod_rollout_prep')"		>Tag as Rollback Tag</a><br>
					<b>Step 5</b>: Then to roll it all out, <a		href="javascript: confirmAction('UPDATE','actions/update.php?<?php echo $view->project_url_params ?>&tag=PROD_TEST&set_group=05_rolled_out')">Update to Rollout Tag</a><br>
					<b>Step 6</b>: <i> -- Perform QA testing -- </i><br>
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6a</b>: If any problems, <a	   href="javascript: confirmAction('UPDATE','actions/update.php?<?php echo $view->project_url_params ?>&tag=PROD_SAFE&set_group=03_testing_done')">Roll back to Rollback Tag</a><br>
					&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6b</b>: While fixes are made, <a href="javascript: confirmAction('TAG','actions/tag.php?<?php echo $view->project_url_params ?>&tag=PROD_TEST&set_group=01_staging')">Re-tag to Rollout Tag</a><br>
					Then, go back to the <a href="<?php echo $stage->get_area_url('beta','project.php') ?>">QA Staging Area</a> and continue with <b>Step 1</b> or <b>Step 2</b>.
				<?php } ?>
			<?php } ?>
		</td>
	</tr>
</table>

<?php require(dirname($_SERVER['SCRIPT_FILENAME']) .'/ansible-controller.inc.php') ?>
<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/header.inc.php' ); ?>

<!-- /////  List of projects  ///// -->
<h3>List of <?= ( $view->category == 'archived' ? 'Archived' : '' ) ?> Projects</h3>

<form action="project.php" method="GET">
<?php foreach ( array_keys( $view->projects ) as $group ) { ?>
	<h2><?= ( isset( $view->groups[$group] ) ? $view->groups[$group] : $group ) ?></h2>

	<div>
		<input type="checkbox" onchange="var this_checked = $(this).attr('checked'); $(this).parent().find('input').each(function(i,elm){$(elm).attr('checked', this_checked ? true : false ); })" value="1"/> Check All
		<table class="ansible_one">
			<thead>
				<tr>
					<td width="1%"  align="left" class="first">&nbsp;</td>
					<td width="30%" align="left" class="first project-name-column">Name</td>
					<td align="center" class="hide-on-phones">Created&nbsp;by</td>
					<td align="center" class="hide-on-phones">Modified</td>
					<td align="center"># Files</td>
					<!-- 
					<td align="center" class="hide-on-phones">Summary File</td>
					-->
					<td align="left">Actions</td>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $view->projects[ $group ] as $project ) { ?>
					<tr>
						<td>
							<input type="checkbox" name="p[]" value="<?php echo htmlentities( $project['project_name'] ) ?>"/>
						</td>
						<td class="project-name-column" title="<?php echo $project['name'] ?>">
							<?php echo ( $view->category == 'archived'
							  			 ? $project['name']
							  			 : "<a href=\"project.php?p=". urlencode($project['project_name']) ."\">". $project['name'] ."</a>"
							) ?>
						</td>
						<td align="center" class="hide-on-phones"><?= $project['project']->get_creator() ?></td>
						<td align="center" class="hide-on-phones"><?= $project['mod_time_display'] ?></td>
						<td align="center"><?= $project['aff_file_count'] ?></td>
						<!-- 
						<td align="center" class="hide-on-phones"><?= $project['has_summary'] ?></td>
						-->
						<td>
							<?= ( $view->category == 'archived'
							      ? "<a href=\"actions/unarchive_project.php?p=". urlencode($project['project_name']) ."\">Un-Archive</a>"
								  : "<a href=\"actions/archive_project.php?p=". urlencode($project['project_name']) ."\">Archive</a>"
								 )
							?>
						</td>
					</tr>
					<?php if ( $project['project']->proxy_mode == 'group' ) { ?>
						<?php foreach ( $project['project']->proxy_obj->projects as $sub_project ) { ?>
							<tr>
								<td style="padding-left: 66px" colspan=5 class="project-name-column" title="<?php echo $sub_project->proxy()->project_name ?>">
									<?php echo ( $view->category == 'archived'
									  ? $sub_project->proxy()->project_name
									  			 : "<a href=\"project.php?p=". urlencode($sub_project->proxy()->project_name) ."\">". $sub_project->proxy()->project_name ."</a>"
									) ?>
								</td>
								<td>
									<a style="white-space: nowrap" href="actions/remove_from_group.php?p=<?php echo urlencode($sub_project->proxy()->project_name) ?>">Un-Group</a>
								</td>
							</tr>
						<?php } ?>
					<?php } ?>
				<?php } ?>
			<tbody>
		</table>
		<div class="button-bar right">
			<input type="text" name="group_name[]" value="" placeholder="Group Name"/>
			<input type="submit" name="create_roll_group" value="Create Roll Group"/>
		</div>
		<div class="button-bar left">
			<input type="submit" name="view_checked" value="View Checked Projects"/>
		</div>
		<div class="clear"></div>
	</div>
<?php } ?>
</form>

<?php if ( $view->category != 'archived' ) { ?>
	<p>See list of <a href="?cat=archived">Archived projects</p>
<?php } else  { ?>
	<p>Back to <a href="?">Active projects list</p>
<?php } ?>

<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/footer.inc.php' ); ?>

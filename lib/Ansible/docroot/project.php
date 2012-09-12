<?php require(dirname($_SERVER['SCRIPT_FILENAME']) .'/ansible-controller.inc.php') ?>
<?php require( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/header.inc.php' ); ?>

<!-- /////  Command output ///// -->
<?php if ( ! empty($view->previous_command ) ) { ?>
	<?php require($stage->extend->run_hook('command_output', 0)) ?>
	<font color=red>
        <h4>Command Output</h4>
        <xmp>> <?php echo $view->previous_command['cmd'] ?>
        <?php echo "\n".$view->previous_command['output'] ?></xmp>
	</font>
	<br><br><a href="project.php?<?php echo $view->project_url_params ?>" style="font-size:70%">&lt;&lt;&lt; Click here to hide Command output &gt;&gt;&gt;</a><br>
	<?php require($stage->extend->run_hook('command_output', 10)) ?>
<?php } ?>


<?php
/*---- NEW Rollout Table --------------------------------
<!-- /////  Rollout Process  ///// -->
<h2>Rollout Process</h2>
<div id="rollout_pane">
	<table class="ansible_one">
		<thead>
			<tr>
				<td>Phase</td>
				<td>Current Stage</td>
				<td>Last Roll</td>
				<td>&nbsp;</td>
			</tr>
		</thead>
	
		<tbody>
			<?php
			  ///  Odd even for visible row count
			  $count = 1;

			  ///  Get the (possibly branching) Rollout Tree
			  $rollout_tree = $stage->get_rollout_tree();

			  /// In advance, determine what stage had the most recent rollout
			  $latest_roll_time  = null;
			  $latest_roll_stage = null;
			  foreach ( $rollout_tree as $env => $area ) {
				  $rolls = Ansible__RollPoint::get_by_env_and_project($env, $view->projects);
				  if ( ! empty( $rolls )
					   && ( is_null( $latest_roll_time ) 
							|| strtotime($rolls[0]->creation_date) > $latest_roll_time // sorted DESC
							)
					   ) {
					  $latest_roll_stage = $env;
					  $latest_roll_time  = ! empty( $rolls ) ? strtotime($rolls[0]->creation_date) : null;
				  }
			  }
			  ///  As we loop through stages:
			  ///     1) Gray if no rolls
			  ///     2) Green if rolls and before $latest_roll_stage
			  ///     3) Orange if rolls and after $latest_roll_stage
			  $past_last_action_stage = false;

			  $prev_links = array();
			?>
			<?php foreach ( $rollout_tree as $env => $area ) { ?>
			    <?php
				  ///  Get the current rollout sitch for this stage
				  $rolls = Ansible__RollPoint::get_by_env_and_project($env, $view->projects);

				  ///  Indicator class
				  $indicator_class = 'stage_'. ( empty( $rolls )
												 ? 'incomplete'
												 : ( ( ! empty( $rolls ) && !$past_last_action_stage )
													 ? 'complete'
													 : 'needs_update' // If an earlier stage has rolled
													 )
												 );

				  $classes = array( 'stage',
									$area->stage_class,
									$indicator_class,
									(($count++ % 2) == 0 ? 'even' : 'odd' ),
									);
				  if ( ! empty( $rolls ) )         $classes[] = 'has_rolls';
				  if ($env == $latest_roll_stage ) $classes[] = 'latest_roll';
				  
				  ///  Make this link to a previous child (pick one)
				  $prev_stage = null;
				  if ( isset( $prev_links[ $env ] ) && count( $prev_links[ $env ] ) == 1 ) {
					  $prev_stage = $prev_links[ $env ][0];
				  } else if ( isset( $prev_links[ $env ] ) ) {
					  $prev_latest_roll_time  = null;
					  $prev_latest_roll_stage = null;
					  foreach ( $prev_links[ $env ] as $prev_env ) {
						  $prev_rolls = Ansible__RollPoint::get_by_env_and_project($env, $view->projects);
						  if ( is_null( $prev_latest_roll_stage ) // we want to make sure we have a prev
							   || ( ! empty( $prev_rolls )
									&& ( is_null( $prev_latest_roll_time ) 
										 || strtotime($prev_rolls[0]->creation_date) > $prev_latest_roll_time // sorted DESC
										 ) 
									)
							   ) {
							  $prev_latest_roll_stage = $env;
							  $prev_latest_roll_time  = ! empty( $prev_rolls ) ? strtotime($prev_rolls[0]->creation_date) : null;
						  }
					  }
					  $prev_stage = $prev_latest_roll_stage;
				  }

				  ///  Populate the next links so we can determine previous links
				  if ( $area->next_stage ) { $prev_links[ $area->next_stage ][] = $env; }
				?>
				<tr id="stage_<?php echo $env ?>"
				    class="<?php echo join(' ',$classes) ?>"
					<?php if ( $area->next_stage ) { ?> next_stage="<?php echo $area->next_stage ?>" <?php } ?>
					<?php if ( $prev_stage       ) { ?> prev_stage="<?php echo $prev_stage       ?>" <?php } ?>
					>
					<td class="name"><?php echo $area->name ?> Stage</td>
					<td class="indicator">&bull;</td>
					<td class="last_roll">
						<a class="static" <?php echo (empty($rolls) ? '' : 'rlpt_id="'. $rolls[0]->rlpt_id .'"' ) ?>
						   onclick="if ( $(this).attr('rlpt_id') ) $(this).toggle().next().toggle()"
						   >
							<?php echo (empty($rolls) ? 'never' : get_relative_time( $rolls[0]->creation_date ) ) ?>
						</a>
						<span class="choose" style="display: none">
							<select id="stage_roll_choose_<?php echo $env ?>">
							    <option value=""> -- Choose Roll Point -- </option>
								<?php foreach( $rolls as $roll ) { ?>
									<option value="<?php echo $roll->rlpt_id ?>">
										<?php if ( $roll->includes_same_projects($view->projects) ) { ?>
											<?php echo count($view->projects) ?> projects on <?php echo get_relative_time( $roll->creation_date ) ?>
										<?php } else { ?>
											<?php $diff = $roll->projects_list_diff($view->projects) ?>
											<?php
											  echo ( count($diff[0]) .' of these projects'
													 . ( ( count($diff[2]) > 0 ) ? ' / '. count($diff[2]) .' missing' : '' )
													 . ( ( count($diff[1]) > 0 ) ? ' / '. count($diff[1]) .' others'  : '' )
													 . ' on '. get_relative_time( $roll->creation_date ) 
													 )
											?>
										<?php } ?>
									</option>
								<?php } ?>
							</select>
						</span>
					</td>
					<td class="actions">
						<ul>
 							<li class="roll"    ><a href="javascript:void(null)" onclick="ansible_roll('<?php echo $env ?>','rollout' );">Roll</a></li>
 							<li class="reupdate"><a href="javascript:void(null)" onclick="ansible_roll('<?php echo $env ?>','reupdate');">Re-Update</a></li>
 							<li class="reroll"  ><a href="javascript:void(null)" onclick="ansible_roll('<?php echo $env ?>','reroll'  );">Re-Roll</a></li>
							<li class="log"     ><a href="javascript:void(null)">Log</a></li>
						</ul>
					</td>
				</tr>
				<!-- /////  Sub-Phases  ///// -->
				<?php foreach (array('rollout_stages','rollback_stages','reroll_stages') as $set) { ?>
					<?php foreach ( $area->$set as $set_i => $sub_area ) { ?>
					    <?php
						  $classes = array( 'sub_stage',
											'incomplete',
											$sub_area->stage_class,
											'stage_'. $env .'_sub',
											rtrim($set,'s')
											);
						  
						  ///  Set vars to execute these steps
						  $stage_vars = array( 'env_name' => $area->name,
											   );
#						  if ( in_array($sub_area->stage_class, array('create_rollpoint','update_to_that_rollpoint') ) ) {
							  if ( $sub_area->target_env == 'this' ) {
								  $stage_vars['target_env'] = $env;
								  $stage_vars['target_env_name'] = $area->name;
							  } else if ( $sub_area->target_env == 'prev' && $prev_stage ) {
								  $stage_vars['target_env'] = $prev_stage;
								  $stage_vars['target_env_name'] = $rollout_tree[ $prev_stage ]->name;
							  } else if ( isset( $area->next_stage ) ) {
								  $stage_vars['target_env'] = $area->next_stage;
								  $stage_vars['target_env_name'] = $rollout_tree[ $area->next_stage ]->name;
							  }
#						  }
						  $attrs = array(); foreach(array_keys($stage_vars) as $key) { $attrs[] = $key .'="'. htmlentities($stage_vars[$key],ENT_COMPAT,$stage->config('encoding')) .'"'; }
						  
						?>
						<tr id="stage_<?php echo $env .'-'. $set_i ?>"
						    class="<?php echo join(' ', $classes ) ?>"
							sub_stage_class="<?php echo $sub_area->stage_class ?>"
							<?php echo join(' ', $attrs) ?>
							>
							<td class="name"><span class="count"><?php echo $set_i + 1 ?>.</span> <span class="main"><?php echo $stage->get_sub_stage_name_by_class($sub_area->stage_class, $stage_vars) ?></span></td>
							<td class="indicator">&bull;</td>
							<td class="last_roll">&nbsp;</td>
							<td class="actions">&nbsp;</td>
						</tr>
					<?php } ?>
				<?php } ?>
				<?php if ($env == $latest_roll_stage ) $past_last_action_stage = true; ?>
			<? } ?>
		<tbody>
	</table>
	<?php $view->scoped_include('rollout_drawer.inc.php', array('id' => 'drawer_0')) ?>
	<?php $view->scoped_include('rollout_drawer.inc.php', array('id' => 'drawer_1')) ?>
</div>
*/ ?>

<!-- /////  Actions  ///// -->
<?php $view->scoped_include( './project_actions.inc.php', array('project','project_url_params') ) ?>

<?php foreach ($view->project_data as $pdata ) { ?>
	
	<h2>
		Project: <?php echo $pdata['project']->project_name ?> [<?= substr($pdata['project']->get_group(), 0, 2) ?>]
		<a href="project.php?<?php echo $pdata['remove_project_url'] ?>">[X]</a>
	</h2>

	<!-- /////  Affected Files  ///// -->
	<table width="100%" class="ansible_one">
		<thead>
			<tr class="pre-header"><td>&nbsp;</td><td colspan=5 align=center style="border: solid black; border-width: 1px 1px 0px 1px"><b>Revisions</b></td><td>&nbsp;</td><td>&nbsp;</td></tr>
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
		</thead>
	
		<tbody>
	    	<?php foreach ( $pdata['files'] as $file ) { ?>
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
		</tbody>
	</table>
	
	<?php if ( ! empty( $pdata['other_projects'] ) ) { ?>
		<label class="other_projects" style="padding: 15px 10px 0 0; font-weight: bold; display: inline-block">Projects Sharing Files: </label>
		<?php
		  $content = array();
		  foreach ( $pdata['other_projects'] as $pname => $their_files ) {
	          $data = $their_files['data'];  unset( $their_files['data'] );
			  $content[] = ( '<a href="project.php?p='. urlencode($pname)
							 . '" title="Sharing '. count($their_files) .' Files:'. "\n". join("\n", $their_files) .'">'
							 . $pname
							 . ' ['. substr($data['project']->get_group(), 0, 2) .']'
							 . '</a>'
							 . ( $data['included']
								 ? ' <a href="project.php?'. $data['remove_project_url'] .'" style="color: green">[X]</a>'
								 : ( ' <a href="project.php?'. $view->project_url_params .'&p[]='. urlencode($pname) .'"'
									 . ' style="color: '. ($pdata['project']->get_group() == $data['project']->get_group() ? 'red' : 'darkgray') .'"'
									 . '>[&harr;]</a>'
									 )
								 )
	                         );
		  }
		  echo join(', ', $content);
		?>
	<?php } ?>

	<?php if ( $pdata['project']->file_exists("summary.txt") ) { ?>
		<!-- /////  Summary File  ///// -->
		<h4>Summary</h4>
		<pre>
			<?php echo $pdata['project']->get_file("summary.txt"); ?>
		</pre>
	<?php } ?>
<?php } ?>
<!-- /////  If there were any locally modified files, then  ///// -->
<!-- /////  DISABLE Updating until they are fixed  ///// -->
<?php if ( $view->locally_modified ) { ?>
	<script type="text/javascript">
	  disable_actions = 1;

	</script>
<?php } ?>

<script type="text/javascript">

	  ///  Start this param out, we may modify it as porjects are added / removed with AJAX
	  var projects_param = <?php echo json_encode($view->project_url_params) ?>;
</script>


<?php require( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/footer.inc.php' ); ?>

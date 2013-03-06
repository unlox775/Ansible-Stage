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
<br/><br/>
			<p>
				<a href="javascript:void(null)" onclick="hiliteFilesWithNewRevs()">[Hilight Files with New Revisions]</a>
			</p>
			<form method="GET" action="actions/add_projects_to_group.php?<?php echo $view->project_url_params ?>">
			<input type="hidden" name="redir" value="<?php echo $stage->safe_self_url() ?>"/>
			<?php $proj_params = array(); parse_str($view->project_url_params, $proj_params); ?>
			<?php foreach ( (array) $proj_params['p'] as $proj_name ) { ?>
				<input type="hidden" name="p[]" value="<?php echo $proj_name ?>"/>
			<?php } ?>
			<?php 
			  $groups = array();
			  foreach ( $stage->get_projects() as $project_item ) {
				  $test_project = new Ansible__ProjectProxy($project_item, $stage);
				  if ( $test_project->proxy_mode == 'group' ) {
					  $groups[] = $test_project;
				  }
			  }
			  $this_group = null;
			  foreach ( $view->projects as $project ) {
				  $group = $project->get_roll_group();
				  if ( ! is_null( $group ) ) {
					  if ( $this_group === null ) {
						  $this_group = $group;
					  }
					  else if ( $this_group->project_name != $group->project_name ) {
						  /// Too bad...
						  $this_group = null;
						  break;
					  }
				  }
			  }
			?>
			<?php /* if ( $this_group && ! $this_group->is_roll_group() ) { */ ?>
				<p>
					Add Project(s) to Group:
					<select name="group_name">
						<option value="">-- Choose Group --</option>
						<?php foreach ( $groups as $g_proj ) { ?>
							<option value="<?php echo $g_proj->project_name ?>" <?php if ( $this_group && $g_proj->project_name == $this_group->project_name ) echo 'selected="selected"' ?>><?php echo $g_proj->get_display_name() ?></option>
						<?php } ?>
					</select>
					<input type="submit" value="Go"/>
				</p>
			<?php /* } */ ?>
			</form>
		</td>

		<td align="left" valign="top">
			<?php
			  $GLOBALS['pactions_view'] = $view;
			  $GLOBALS['pactions_stage'] = $stage;

			  ///  Swap Functions
			  function pactions_link_action_update($x,$set_group) { return "javascript: confirmAction('UPDATE','actions/update.php?". $GLOBALS['pactions_view']->project_url_params ."&tag=". $x . ( $set_group ? '&set_group='. $set_group : '' ) ."')"; }
			  function pactions_link_action_tag($x,$set_group)    { return "javascript: confirmAction('TAG', 'actions/tag.php?". $GLOBALS['pactions_view']->project_url_params ."&tag=". $x . ( $set_group ? '&set_group='. $set_group : '' ) ."')"; }
			  function pactions_link_role($x)                     { return $GLOBALS['pactions_stage']->get_area_url($x, 'project.php'); }
			  
			  function pactions_swap($str) {
				  return preg_replace_callback('/{(\w+)(?:\:([^\}]+))}/','pactions_swap_replace',$str);
			  }
			  function pactions_swap_replace($m) {
				  if ( function_exists('pactions_'.$m[1]) ) {
					  $params = array(); if ( ! empty( $m[2] ) ) { foreach(explode('|',$m[2]) as $x) { $params[] = urldecode($x); } }
					  return call_user_func_array('pactions_'.$m[1], $params);
				  }
			  }


			  function pactions_li_nest($ary) {
				  $return_str = '';
				  foreach ( (array) $ary as $i => $val ) {
					  if ( is_int($i) ) {
						  $return_str .= '<li>'. pactions_swap( $val ) .'</li>';
					  }
					  else {
						  $return_str .= '<li>'. pactions_swap( $i ) .'<ol>'. pactions_li_nest($val) .'</ol></li>';
					  }
				  }
				  return $return_str;
			  }

			  ///  Which script section to pull
			  if ( isset( $stage->env()->role ) && isset( $stage->rollout_script[ $stage->env()->role ] ) ) {
				  $roll_script = $stage->rollout_script[ $stage->env()->role ];
			  } else if ( isset( $stage->rollout_script['alpha'] ) ) {
				  $roll_script = $stage->rollout_script['alpha'];
			  }
			?>

			<?php if ( ! $roll_script ) { ?>
				<i>Config error: No Rollout script.  You must at least define a script for 'alpha' as a catchall script.</i>
			<?php } else { ?>
				<h4><?php echo pactions_swap($roll_script['title']) ?></h4>
				<?php if ( ! empty( $roll_script['pre_content'] ) ) { ?><p><?php echo pactions_swap($roll_script['pre_content']) ?></p><?php } ?>
				<ol>
					<?php echo pactions_li_nest($roll_script['steps']) ?>
				</ol>
				<?php if ( ! empty( $roll_script['post_content'] ) ) { ?><p><?php echo pactions_swap($roll_script['post_content']) ?></p><?php } ?>
			<?php }  ?>
		</td>
	</tr>
</table>

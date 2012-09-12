<?php

class Ansible__root extends Stark__Controller__Base {

	public function directory_handler($ctl, $path) {
		/* HOOK */$__x = $ctl->stage->extend->x('root_directory_handler', 0); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh();if($__x->hr()) return $__x->get_return();
		ini_set("session.cookie_lifetime",86400 * 60);
		ini_set("session.gc_maxlifetime", 86400 * 60);
		session_name('ansble_stage_sess_id');
		session_set_cookie_params(86400*365, '/');
		session_start();

		///  Read the env
		if ( ! empty( $_SESSION['env'] ) ) $ctl->stage->env = $_SESSION['env'];
		///  Of if it's not set, bounce them to where they can choose an ENV
		else if ( $path    != $ctl->stage->url_prefix.'/index.php'
				  && $path != $ctl->stage->url_prefix.'/change_env.php' 
				  ) {
			$ctl->redirect($ctl->stage->url_prefix.'/index.php');
			exit;
		}

		/* HOOK */$__x = $ctl->stage->extend->x('root_directory_handler', 10); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh();if($__x->hr()) return $__x->get_return();

		///  All dir handlers must return true or it will give a 403: Forbidden
		return true;
	}

	public function index_page($ctl) {
		return( array( 'stage_areas' => $ctl->stage->staging_areas
					   ) );
	}
	
	public function change_env_page($ctl) {
		if ( ! empty( $_REQUEST['env'] ) ) {
			$_SESSION['env'] = $_REQUEST['env'];
			$ctl->stage->env = $_SESSION['env']; # probably unneccesary
		}
		
		///  Return vars
		$return = array( 'status' => 'ok', 'env' => $_SESSION['env'] );

		///  Redirect if they have asked for it
		if ( ! empty( $_REQUEST['redirect'] ) ) {
			/// TODO: implement host switching here
			$return['redirect'] = $_REQUEST['redirect'];
		}
		
		///  If it is an AJAX call, this will handle and exit()
		$ctl->view->handle_ajax($return);

		///  Otherwise we'd better redirect...
		if ( ! empty( $return['redirect'] ) ) {
			header('Location: '. $ctl->stage->url_prefix. $return['redirect']);
			exit;
		}
		else return trigger_error('change_env.php must be called in AJAX mode, or passed a redirect parameter', E_USER_ERROR);
	}

	public function list_page($ctl) {
		$category = ( ! empty( $_REQUEST['cat'] ) && $_REQUEST['cat'] == 'archived' ) ? 'archived' : 'active';

		list($projects, $groups) = $ctl->stage->get_projects_by_group($category);

		return( array( 'category' => $category,
					   'projects' => $projects,
					   'groups'   => $groups,
					   )
				);
	}

	public function project_page($ctl) {
		if ( empty( $_REQUEST['p'] ) )
			$ctl->redirect('list.php');
		$projects = $ctl->stage->get_projects_from_param($_REQUEST['p']);
		$projects_lookup = array_flip((array) $_REQUEST['p']);
		
		###  Read Command output
		$previous_command = $this->read_previous_command( $ctl );

		###  Load for each project
		$locally_modified = false;
		$project_data = array();
		foreach ( $projects as $project ) {
	
			###  Load and parse the file list
			$file_tags = $project->get_file_tags();
			$files = $project->get_affected_files();
			$project_data[$project->project_name]['project'] = $project;
			$project_data[$project->project_name]['file_lines'] = array();
			foreach ( $files as $file ) {
				$file_line = array( 'file' => $file, 
									);
	
		        ###  Get Current Version
		        $file_line['cur_vers'] = delayed_load_span(array($file), create_function('$file',now_doc('DELAY')/*
		            global $stage;
		            if ( ! file_exists($stage->env()->repo_base ."/$file") ) {
		                $cur_vers = '<i>-- n/a --</i>';
		            } else if ( is_dir($stage->env()->repo_base ."/$file") ) {
		                $cur_vers = '<i>Directory</i>';
		            } else {
		                list($cur_rev, $error, $status, $state_code, $is_modified)
		                    = $stage->repo()->get_current_rev( $file );
		                if ( empty( $error ) ) {
		            
		                    ###  Add a diff link if Locally Modified
		                    if ( $is_modified ) {
		                        $cur_vers = "<a href=\"actions/diff.php?from_rev=$cur_rev&to_rev=local&file=". urlencode($file) ."\">$status</a>, $cur_rev";
		                        $locally_modified = true;
		                    }
		                    else { $cur_vers = "$status, $cur_rev"; }
		                } else {
		                    $cur_vers = "<div title=\"". htmlentities( $stage->repo()->get_status($file)) ."\"><i>". $error ."</i></div>";
		                }
		            }
		
		            return $cur_vers;
DELAY
*/
));

		        ###  Get PROD_SAFE Version
		        $file_line['prod_safe_vers'] = delayed_load_span(array($file), create_function('$file',now_doc('DELAY')/*
		            global $stage;
		
					list($cur_rev) = $stage->repo()->get_current_rev( $file );
		
					///  So it will show the "Loading..."
					list( $all_revs ) = $stage->repo()->get_all_log_revs($file);
		
		            $prod_safe_rev = $stage->repo()->get_tag_rev($file, 'PROD_SAFE');
		            if ( $prod_safe_rev ) {
		                if ( $prod_safe_rev != $cur_rev ) {
		                    $prod_safe_vers = "<b><font color=red>$prod_safe_rev</font></b>";
		                }
		                else { $prod_safe_vers = $prod_safe_rev; }
		            }
		            else { $prod_safe_vers = '<i>-- n/a --</i>'; }
		
		            return $prod_safe_vers;
DELAY
*/
));

		        ###  Get PROD_TEST Version
		        $file_line['prod_test_vers'] = delayed_load_span(array($file), create_function('$file',now_doc('DELAY')/*
		            global $stage;
		
					list($cur_rev) = $stage->repo()->get_current_rev( $file );
		
					///  So it will show the "Loading..."
					list( $all_revs ) = $stage->repo()->get_all_log_revs($file);
		
		            $prod_test_rev = $stage->repo()->get_tag_rev($file, 'PROD_TEST');
		            if ( ! empty( $prod_test_rev ) ) {
		                if ( $prod_test_rev != $cur_rev ) {
		                    $prod_test_vers = "<b><font color=red>$prod_test_rev</font></b>";
		                }
		                else { $prod_test_vers = $prod_test_rev; }
		            }
		            else { $prod_test_vers = '<i>-- n/a --</i>'; }
		
		            return $prod_test_vers;
DELAY
*/
));

		        ###  Get HEAD Version
		        $file_line['head_vers'] = delayed_load_span(array($file,$project,$file_tags), create_function('$file,$project,$file_tags',now_doc('DELAY')/*
		            global $stage;
		
					list($cur_rev) = $stage->repo()->get_current_rev( $file );
		
					///  So it will show the "Loading..."
					list( $all_revs ) = $stage->repo()->get_all_log_revs($file);
		
		            list($head_rev, $error, $error_code) = $stage->repo()->get_head_rev($file);
		            if ( empty($error) ) {
		                if ( $head_rev != $cur_rev
		                     && ( empty( $file_tags[$file] )
		                          || $file_tags[$file] == $cur_rev
		                          )
		                     ) {
		                    $head_vers = "<b><font color=red>$head_rev</font></b>";
		                }
		                else { $head_vers = $head_rev; }
		            } else if ( $error_code == 'not_exists' ) {
		                $head_vers = "<i>". $error ."</i>";
		            } else {
		                $head_vers = "<div title=\"". htmlentities( $stage->repo()->get_log($file) ) ."\"><i>". $error ."</i></div>";
		            }
		
		            return $head_vers;
DELAY
*/
));

		        ###  Do Target
		        $file_line['target_vers'] = delayed_load_span(array($file,$project), create_function('$file,$project',now_doc('DELAY')/*
		            global $stage;
		
					list($cur_rev) = $stage->repo()->get_current_rev( $file );
		
					///  So it will show the "Loading..."
					list( $all_revs ) = $stage->repo()->get_all_log_revs($file);
		
		            list($head_rev, $error, $error_code) = $stage->repo()->get_head_rev($file);
		            if ( empty($error) ) {
		                ###  Set Target version if it's there
		                list($target_rev, $used_file_tags) = $project->determine_target_rev($file, $head_rev);
		                if ( $used_file_tags ) {
		                    if ( $target_rev != $cur_rev ) { $target_vers = "<b><font color=red>". $target_rev ."</font></b>"; }
		                    else {                           $target_vers = "<b>".                 $target_rev        ."</b>"; }
		                }
		                else { $target_vers = '-&gt;'; }
		            }
		
		            return $target_vers;
DELAY
*/
));

		        ###  Changes by
		        $file_line['changes_by'] = delayed_load_span(array($file,$project), create_function('$file,$project',now_doc('DELAY')/*
		            global $stage;
		
					list($cur_rev) = $stage->repo()->get_current_rev( $file );
		
					///  So it will show the "Loading..."
					list( $all_revs ) = $stage->repo()->get_all_log_revs($file);
		
		            $prod_test_rev = $stage->repo()->get_tag_rev($file, 'PROD_TEST');
		            list($head_rev, $error, $error_code) = $stage->repo()->get_head_rev($file);
		            list($target_rev, $used_file_tags) = $project->determine_target_rev($file, $head_rev);
		            $c_by_rev = $stage->onLive() ? $cur_rev : $prod_test_rev;
		            if ( $c_by_rev && $target_rev ) {
		                $diff_revs = $stage->repo()->get_revs_in_diff($file, $c_by_rev, $target_rev);
		                $names = array();  foreach ( array_reverse( $diff_revs ) as $_ ) { $names[] = $stage->repo()->get_rev_committer( $file, $_ ); }
		                $names = array_unique($names);
		    
		                ###  Find regressions!
		                $changes_by = null;
		                if ( count($diff_revs) == 0 && $c_by_rev != $target_rev ) {
		                    $reverse_revs = $stage->repo()->get_revs_in_diff($file, $target_rev, $c_by_rev);
		                    if ( count($reverse_revs) > 0 ) {
		                        $changes_by = '<font color=red><b><i>-'. count( $reverse_revs ) .' rev'. (count($reverse_revs) == 1 ? '' : 's'). '!!!</i></b></font>';
		                    }
		                }
		                if ( empty($changes_by) ) $changes_by = count( $diff_revs ) .' rev'. (count($diff_revs) == 1 ? '' : 's') . ($names ? (', '. join(', ',$names)) : '');
		            }
		
		            return $changes_by;
DELAY
*/
));

		        ###  Actions
		        $file_line['actions'] = delayed_load_span(array($file,$project), create_function('$file,$project',now_doc('DELAY')/*
		            global $stage;
		
					list($cur_rev) = $stage->repo()->get_current_rev( $file );
		
					///  So it will show the "Loading..."
					list( $all_revs ) = $stage->repo()->get_all_log_revs($file);
		
		            $prod_test_rev = $stage->repo()->get_tag_rev($file, 'PROD_TEST');
		            list($head_rev, $error, $error_code) = $stage->repo()->get_head_rev($file);
		            list($target_rev, $used_file_tags) = $project->determine_target_rev($file, $head_rev);
		            $c_by_rev = $stage->onLive() ? $cur_rev : $prod_test_rev;
		
		            $actions = '<i>n/a</i>';
		            if ( $c_by_rev && $target_rev ) {
		                $actions = ( "<a         href=\"actions/part_log.php?from_rev=$c_by_rev&to_rev=$target_rev&file=". urlencode($file) ."\">Log</a>"
		                             . "&nbsp;<a     href=\"actions/diff.php?from_rev=$c_by_rev&to_rev=$target_rev&file=". urlencode($file) ."\">Diff</a>"
		                             );
		            }
		
		            return $actions;
DELAY
*/
));


				###  Other Projects Sharing files
				$other_projects = array();
				foreach ( $ctl->stage->get_projects() as $pname ) {
					if ( empty( $pname ) || $pname == $project->project_name ) continue;

					$other_project = new Ansible__Project( $pname, $ctl->stage, false );
					if ( ! in_array( $other_project->get_group(), array( '00_none','01_staging','03_testing_done','04_prod_rollout_prep' ) ) )
						continue;

					foreach ( $files as $our_file ) {
						foreach ( $other_project->get_affected_files() as $their_file ) {
							if ( $our_file == $their_file ) {
								if ( ! isset( $other_projects[ $pname ] ) )
									$other_projects[ $pname ] = array( 'data' =>
																	   array( 'project' => $other_project,
																			  'included' => isset( $projects_lookup[ $other_project->project_name ] ),
																			  'remove_project_url' => $ctl->stage->get_projects_url($projects, $other_project->project_name),
																			  )
																	   );
								$other_projects[ $pname ][] = $their_file;
							}
						}
					}
				}
				$project_data[$project->project_name]['other_projects'] = $other_projects;
				$project_data[$project->project_name]['remove_project_url'] = $ctl->stage->get_projects_url($projects, $project->project_name);
				$project_data[$project->project_name]['files'][] = $file_line;
			}
		}
			
		return array( 'projects'           => $projects,
					  'project_data'       => $project_data,
					  'previous_command'   => $previous_command,
					  'locally_modified'   => $locally_modified,
					  'project_url_params' => $ctl->stage->get_projects_url($projects),
					 );
	}

	public function read_previous_command( $ctl ) {
		###  Command output (Sometimes the length is SO long that PHP refuses to parse it into $_REQUEST, but we can still manually get it out of $_SERVER['QUERY_STRING'])
        require($ctl->stage->extend->run_hook('command_output', -10));
		$previous_command = array();
		if ( preg_match(   '/&cmd=([^&]+)/',            $_SERVER['QUERY_STRING'], $cmd_m )
			 && preg_match('/&command_output=([^&]+)/', $_SERVER['QUERY_STRING'], $command_output_m )
			 ) {
			$previous_command['cmd'] = gzinflate( base64_decode(urldecode($cmd_m[1])) );
			$previous_command['output'] = gzinflate( base64_decode(urldecode($command_output_m[1])) );
		}
        require($ctl->stage->extend->run_hook('command_output', -5));
		return $previous_command;
	}

	public function admin_page($ctl) {

		###  Read Command output
		$previous_command = $this->read_previous_command( $ctl );

#   	 $repo->cache_logs( $files );
#   	 $repo->cache_statuses( $files );
    	$locally_modified = false;
		$file_lines = array();
    	foreach ( $ctl->stage->repo()->get_ls() as $file ) {

			$file_line = array( 'file' => $file, 
								);
		
    	    ###  Get Current Version
    	    $file_line['cur_vers'] = delayed_load_span(array($file), create_function('$file',now_doc('DELAY')/*
    	        global $stage;
		
    	        $dir_status = $stage->repo()->analyze_dir_status($file);
    	        $status_items = array();
    	        if ( ! empty( $dir_status['has_modified'] ) ) $status_items[] = $dir_status['has_modified'] .' Locally Modified';
    	        if ( ! empty( $dir_status['has_conflicts'] ) ) $status_items[] = '<font color="red">'. $dir_status['has_conflicts'] .' Has Conflicts</font>';
    	        if ( empty( $status_items ) ) $status_items[] = '<font color="green">No Local Changes</font>';
		
    	        $cur_vers = "<div>". join(', ', $status_items) ."</div>";
		
    	        return $cur_vers;
DELAY
*/
));

	        ###  Get PROD_TEST Version
	        $file_line['prod_test_vers'] = delayed_load_span(array($file,$cur_rev), create_function('$file,$cur_rev',now_doc('DELAY')/*
	            global $stage;
	
	            $dir_diff = $stage->repo()->diff_dir_from_tag('PROD_TEST', $file);
	            $diff_items = array();
	            if ( ! empty( $dir_diff['files_ahead_of_tag'] ) ) $diff_items[] = $dir_diff['files_ahead_of_tag'] .' ahead of tag';
	            if ( ! empty( $dir_diff['files_behind_tag']   ) ) $diff_items[] = $dir_diff['files_behind_tag']   .' behind tag';
	            if ( ! empty( $dir_diff['files_no_tag']       ) ) $diff_items[] = $dir_diff['files_no_tag']       .' NO tag';
	            if ( ! empty( $dir_diff['files_unknown']      ) ) $diff_items[] = $dir_diff['files_unknown']      .' unknown';
	            if ( ! empty( $dir_diff['files_on_tag']       ) ) $diff_items[] = '<font color="green">'. $dir_diff['files_on_tag']       .' on tag</font>';
	
	            $prod_test_vers = "<div>". join(', ', $diff_items) ."</div>";
	
	            return $prod_test_vers;
DELAY
*/
));

	        ###  Get PROD_SAFE Version
	        $file_line['prod_safe_vers'] = delayed_load_span(array($file,$cur_rev), create_function('$file,$cur_rev',now_doc('DELAY')/*
	            global $stage;
	
	            $dir_diff = $stage->repo()->diff_dir_from_tag('PROD_SAFE', $file);
	            $diff_items = array();
	            if ( ! empty( $dir_diff['files_ahead_of_tag'] ) ) $diff_items[] = $dir_diff['files_ahead_of_tag'] .' ahead of tag';
	            if ( ! empty( $dir_diff['files_behind_tag']   ) ) $diff_items[] = $dir_diff['files_behind_tag']   .' behind tag';
	            if ( ! empty( $dir_diff['files_no_tag']       ) ) $diff_items[] = $dir_diff['files_no_tag']       .' NO tag';
	            if ( ! empty( $dir_diff['files_unknown']      ) ) $diff_items[] = $dir_diff['files_unknown']      .' unknown';
	            if ( ! empty( $dir_diff['files_on_tag']       ) ) $diff_items[] = '<font color="green">'. $dir_diff['files_on_tag']       .' on tag</font>';
	
	            $prod_safe_vers = "<div>". join(', ', $diff_items) ."</div>";
	
	            return $prod_safe_vers;
DELAY
*/
));

			$file_lines[] = $file_line;
		}

		return array( 'previous_command' => $previous_command,
					  'files'            => $file_lines,
					  'locally_modified' => $locally_modified,
					 );
	}
}

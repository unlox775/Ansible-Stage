<?php

/**
 * SVN Spefic hooks for Ansible Stage
 */
class Ansible__Repo__SVN extends Ansible__Repo {
	public $stage = null;
    public $display_name = 'SVN';
    public $command_name = 'svn';
#    public $depth_empty = '--depth empty';
    public $depth_empty = '';

	///  Check if we have done the once-per-session check
	public $checked_revision_cache_expire_token = false;
	public $revision_cache_expire_token = false;
	public $checked_repository_root = false;
	public $repository_root = false;
 
    #########################
    ###  Action Methods

    public function updateAction($projects, $tag, $user, $echo = false, $file_source = 'project') {

		$updates = array();

		$this->stage->updateStatus($echo, 'reading_project');
		foreach ( $this->group_projects_by_file($projects, $tag, $file_source) as $item ) {
			list( $file, $file_projects ) = $item;
			$rev = $this->get_update_revision($file, $tag, $file_projects);

			if ( $rev !== null && $this->parent_doesnt_exist($file) )
				@$updates['00_update_dirs'] = $this->add_parent_dirs($file, $file_projects, $updates['00_update_dirs'], $rev);

			if ( $rev == 'HEAD' ) 
				$updates['01_head_updates'][] = $item;
			else if ( $rev === null ) 
				$updates['02_indiv_updates'][] = array( $file, $file_projects, $this->get_removal_rev($file) );
			else 
				$updates['02_indiv_updates'][] = array( $file, $file_projects, $rev );
		}
		
		///  When done, sort the "00_update_dirs" so that parent dirs get added before their children
		if ( isset( $updates['00_update_dirs'] ) )
			ksort($updates['00_update_dirs']);

		$complete_cmd = array();
		$command_output = '';

		///  Create rollback point optionally
		$this->stage->updateStatus($echo, 'creating_rollback_point');
		$point_roll = $this->record_roll_point($projects, $tag, $user, $file_source);


		$total_items_in_queue = 0;  foreach ( $updates as $group => $items ) $total_items_in_queue += count($items);
		$this->stage->updateStatus($echo, 'creating_rollback_point');

		///  Run the updates
		ksort( $updates );
		$cmd_prefix = $this->stage->config('repo_cmd_prefix');
        foreach ( $updates as $group => $items ) {
			$cmds = array();
			if ( $group == '00_update_dirs' ) {
				foreach ($items as $item) $cmds[] = array('svn up '. $this->depth_empty .' -r'. $item[2] .' "'. addslashes($item[0]) .'"',array($item[1]));
			}
			else if ( $group == '01_head_updates' ) {
				$files = array();  $cmd_projects = array();
				foreach ($items as $item) { $files[] = addslashes($item[0]); $cmd_projects[] = $item[1]; }
				$cmds[] = array('svn update '. $this->depth_empty .' -rHEAD "'. join('" "',$files) .'"', $cmd_projects);
			}
			else if ( $group == '02_indiv_updates' ) {
				foreach ($items as $item) {
					if ( is_null( $item[2] ) ) continue; // bad format files get this as get_removal_rev() can't find anything
					if ( isset( $cmds[ $item[2] ] ) ) $cmds[ $item[2] ][0] .=    ' "'. addslashes($item[0]) .'"';
					else $cmds[ $item[2] ] = array('svn update '. $this->depth_empty .' -r'. $item[2] .' "'. addslashes($item[0]) .'"',array($item[1]));
				}
			}
			else { return trigger_error('Internal error: Invalid update group', E_USER_ERROR); }

			///  Run the commands
			foreach ($cmds as $cmd_item) {
				list($cmd,$project_sets) = $cmd_item;

                $complete_cmd[] = $cmd;
				$this->stage->updateCommand( $echo, join(' ; ',$complete_cmd) );

                START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
				START_TIMER('REPO_CMD('. $group .')', PROJECT_PROJECT_TIMERS);
                $this->stage->appendOutputFromShellExec($echo, $command_output, "$cmd_prefix$cmd 2>&1 | cat -");
				END_TIMER('REPO_CMD('. $group .')', PROJECT_PROJECT_TIMERS);
                END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);

				///  Log
				$this->log_projects_action($cmd, $project_sets, $user);
            }
        }

		///  After a rollout on Production, retag PROD_TEST for other people's reference
		if ( $this->stage->onLive() ) {
			list($tag_cmd, $tag_command_output) =$this->tagAction($projects, 'PROD_TEST', $user, $echo);
			bug('TAGGED PROD_TEST!!', $tag_cmd, $tag_command_output);
		}

		///  If this is a Roll to Point then save the command output
		if ( isset( $point_roll	) )
			$point_roll->set_and_save(array('cmd' => $cmd,'cmd_output' => $command_output));

        if ( empty( $command_output ) ) $command_output = '</xmp><i>No output</i>';
#		bug(join(' ; ',$complete_cmd), $command_output);
#		exit;

        return( array(join(' ; ',$complete_cmd), $command_output) );
    }

	public function get_removal_rev($file) {
		list($first_rev, $error) = $this->get_first_rev( $file );
		if ( empty( $error ) ) {
			$rev_before_first = $first_rev - 1;
			return $rev_before_first;
		}
		return null;
	}

	public function rev_greater_than($rev1, $rev2, $file) {
		return( $rev1 > $rev2 );
	}

    public function tagAction($projects, $tag, $user, $echo = false, $file_source = 'project', $source_tag = null) {
		$point = null;

        $cmd = "TAG all files: $tag";
		$command_output = '';
		$this->stage->updateCommand( $echo, $cmd );
		$this->stage->updateStatus(  $echo, 'reading_project');

		if ( in_array($tag, array('rollback', 'rollout')) ) {
			require_once(dirname(__FILE__) .'/../model/RollPoint.class.php');

			///  Create new RollPoint
			$point = new Ansible__RollPoint();
			$point->create(array( 'point_type' => $tag,
								  'created_by' => $user,
								  ));

			###  Look and update tags
			$seen_projects = array();
			$seen_files = array();
			$files_by_proj = $this->group_projects_by_file($projects, ($source_tag ? $source_tag : $tag), $file_source);
			$this->stage->updateStatus($echo, 'tagging_files', count($files_by_proj));
			foreach ( $files_by_proj as $item ) {
				list( $file, $file_projects ) = $item;
				
				foreach ( $file_projects as $project ) {
					if ( ! isset( $seen_projects[ $project->project_name ] ) )
						$seen_projects[ $project->project_name ] = $point->add_project($project->project_name);

        			###  Make sure this file exists
        			if ( file_exists($this->stage->env()->repo_base ."/$file") ) {
        				list( $cur_rev ) = $this->get_current_rev($file);
						
						///  Roll Point System (New Regime)
						if ( ! isset( $seen_files[ $project->project_name ][ $file ] ) )
							$seen_projects[ $project->project_name ]->add_file($file, $cur_rev);
						$seen_files[ $project->project_name ][ $file ] = true;

						$this->stage->appendOutput($echo, $command_output, "Set roll point on $file at $cur_rev\n");
        			}
        			###  If it doesn't exist, we need to remove the tag...
        			else {
						///  Roll Point System (New Regime)
						if ( ! isset( $seen_files[ $project->project_name ][ $file ] ) )
							$seen_projects[ $project->project_name ]->add_file($file, null);
						$seen_files[ $project->project_name ][ $file ] = true;

						$this->stage->appendOutput($echo, $command_output, "Set roll point on $file to DELETE\n");
        			}
        		}
			}
		}
		///  For the old style tags...
		else {
			###  Look and update tags
			foreach ( $projects as $project ) {
        		foreach ( $project->get_affected_files() as $file ) {
        			###  Make sure this file exists
        			if ( file_exists($this->stage->env()->repo_base ."/$file") ) {
        				list( $cur_rev ) = $this->get_current_rev($file);

						/// Old Tag System (Keep around and maintain, until we are always doing live status)
        				###  See what the tag was before...
        				$sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
        				$old_rev = $sth->fetch(PDO::FETCH_NUM);
        				$sth->closeCursor();
        	    		
        				###  Update the Tag DB for this file...
        				$rv = dbh_do_bind("DELETE FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
        				$rv = dbh_do_bind("INSERT INTO file_tag ( file,tag,revision ) VALUES (?,?,?)", $file, $tag, $cur_rev);
						
        				###  Add to Command output whether we really changed the tag or not
        				if ( ! empty( $old_rev ) && $old_rev[0] != $cur_rev ) {
							$this->stage->appendOutput($echo, $command_output, "Moved $tag on $file from ". $old_rev[0] . " to $cur_rev\n");
        					$this->log_repo_action("TAG: Moved $tag on $file from ". $old_rev[0] . " to $cur_rev", $project, $user);
        				}
        				else if ( empty( $old_rev ) ) {
							$this->stage->appendOutput($echo, $command_output, "Set $tag on $file to ". $cur_rev ."\n");
        					$this->log_repo_action("TAG: Set $tag on $file to ". $cur_rev, $project, $user);
        				}
        				else {
        					$this->stage->appendOutput($echo, $command_output, "Preserved $tag on $file at ". $old_rev[0] ."\n");
        					$this->log_repo_action("TAG: Preserved $tag on $file at ". $old_rev[0], $project, $user);
        				}
        			}
        			###  If it doesn't exist, we need to remove the tag...
        			else {

        				###  See what the tag was before...
        				$sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
        				$old_rev = $sth->fetch(PDO::FETCH_NUM);
        				$sth->closeCursor();
        				
        				###  Update the Tag DB for this file...
        				$rv = dbh_do_bind("DELETE FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
						
        				###  Add to Command output whether we really changed the tag or not
        				if ( ! empty( $old_rev ) ) {
        					$this->stage->appendOutput($echo, $command_output, "Removed $tag on $file\n");
        					$this->log_repo_action("TAG: Removed $tag on $file", $project, $user);
        				}
        			}
        		}
			}
		}
		$this->stage->updateStatus(  $echo, 'done_tagging');

        if ( empty( $command_output ) ) $this->stage->appendOutput($echo, $command_output, '</xmp><i>No output</i>');

        return( array($cmd, $command_output) );
    }


    #########################
    ###  SVN file Log and Status caching (for speed)
    
    public function get_log( $file, $limit = null ) {

        ###  If not cached, get it and cache
        if ( ! $this->repo_cache['log'][$file] ) {
            $parent_dir = dirname($file);
            if ( is_dir($this->stage->env()->repo_base ."/$parent_dir") ) {
                START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
                START_TIMER('REPO_CMD(log all sub-timers)', PROJECT_PROJECT_TIMERS);
                START_TIMER('REPO_CMD(log)', PROJECT_PROJECT_TIMERS);
    
    
                
    //            #########################
    //            #########################
    //            ###  DIRTY hack until we can get access...
    //            $cstat = get_status($file);
    //            if ( preg_match('/^(\w?)\s*\d+\s+(\d+)\s/', $cstat, $m) ) {
    //                $last_rev = $m[2];
    //                $this->repo_cache['log'][$file] = <<<HACK_LOG
    //------------------------------------------------------------------------
    //r$last_rev | nobody | 2012-12-21 12:21:12 -0100 (Not, 21 Dec 2012) | 2 lines
    //
    //Ugh! I cringe!
    //HACK_LOG;
    //            } else $this->repo_cache['log'][$file] = '';
    //            #########################
    //            #########################
    
    #            bug(`${REPO_CMD_PREFIX}svn log -r HEAD:1 "$file" 2>&1 | cat`); exit;
                ###  Use HEAD:1 so we aren't limited to don't just get the revs up to the file's current rev
                $limit_arg = ! empty( $limit ) ? ' --limit '. $limit : '';
				$cmd_prefix = $this->stage->config('repo_cmd_prefix');
				$svn_path = $this->get_repository_root();
#				bug("${cmd_prefix}svn log $limit_arg -r HEAD:1 \"$svn_path/$file\" 2>&1 | cat"); exit;
                $this->repo_cache['log'][$file] = `${cmd_prefix}svn log $limit_arg -r HEAD:1 "$svn_path/$file" 2>&1 | cat`;
#                $this->repo_cache['log'][$file] = `${cmd_prefix}svn log $limit_arg -r HEAD:1 "$file" 2>&1 | cat`;
                END_TIMER('REPO_CMD(log)', PROJECT_PROJECT_TIMERS);
                END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);

				###  If we get "file not found" but the file does exist, then try again without the HEAD:1
				###    NOTE: this MAY be misleading if there are revs after ours but before the file
				###         was deleted.  We won't see those one...  SVN Sux!
#                bug('Retrying?',$this->repo_cache['log'][$file], preg_match('/^svn: .*?(path not found)\s*$/s', $this->repo_cache['log'][$file]));
				if ( preg_match('/^svn: .*?(is not under version control|path not found)\s*$/s', $this->repo_cache['log'][$file])
					 && file_exists($this->stage->env()->repo_base ."/$file")
					 ) {
#                    bug('Retrying',$this->repo_cache['log'][$file] );
					START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
					START_TIMER('REPO_CMD(log retry)', PROJECT_PROJECT_TIMERS);
					$this->repo_cache['log'][$file] = `${cmd_prefix}svn log $limit_arg "$file" 2>&1 | cat`;
					END_TIMER('REPO_CMD(log retry)', PROJECT_PROJECT_TIMERS);
					END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
#                    bug('Retried',$this->repo_cache['log'][$file]);
				}
				///  Dig into the log to get Deletion rev
				else if ( preg_match('/^svn: .*?(is not under version control)\s*$/s', $this->repo_cache['log'][$file])
						  && ! file_exists($this->stage->env()->repo_base ."/$file")
						  ///  Only do this if the repo base exists...
						  && is_dir( $this->stage->env()->repo_base )
						  ) {
					///  Find a parent dir that does exist...
					$parent_dir = null;  $deleted_parents = array();
					while( is_null( $parent_dir ) || ! is_dir( $this->stage->env()->repo_base ."/$parent_dir" ) ) {
						if ( ! is_null( $parent_dir ) && $parent_dir != '.' ) $deleted_parents[] = $parent_dir;
						$parent_dir = ( dirname( $parent_dir ) == DIRECTORY_SEPARATOR ? '.' : dirname( is_null( $parent_dir ) ? $file : $parent_dir ) );
					}
					
#                    bug('Retrying',$this->repo_cache['log'][$file] );
					START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
					START_TIMER('REPO_CMD(log for del rev)', PROJECT_PROJECT_TIMERS);
					$parent_verbose = `${cmd_prefix}svn log --verbose -r HEAD:1 --limit 50 "$parent_dir" 2>&1 | cat`;
					END_TIMER('REPO_CMD(log for del rev)', PROJECT_PROJECT_TIMERS);
					END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
#                    bug('Parent Log', substr($parent_verbose,0,1000 ));
					###  New Parsing Method (more flexible and forgiving)
					foreach ( $this->parse_log($clog) as $rev => $data ) {
						$m[] = array(null,$rev, $data['committer']);
					}
					if ( ! empty( $m ) ) {
#                        bug('REVS:', count($m), $m[0]);
						$deleted_parents_pattern = empty( $deleted_parents ) ? '' : '|\Q'. join('\E|\Q', $deleted_parents) .'\E';
						$pre_deletion_rev = null;  $deletion_entry = null;
						foreach( $m as $entry ) {
#                            bug( $entry[0], '@^\s+D\s(/trunk/\Q'. $file .'\E'. $deleted_parents_pattern .')\s*@m',
#                                 preg_match('@^\s+D\s(/trunk/\Q'. $file .'\E'. $deleted_parents_pattern .')\s*@m', $entry[0]) );
							if ( preg_match('@^\s+D\s(/trunk/\Q'. $file .'\E'. $deleted_parents_pattern .')\s*@m', $entry[0]) ) {
								$pre_deletion_rev = $entry[1] - 1;
								$deletion_entry = $entry[0];
								break;
							}
						}
						if ( ! empty( $pre_deletion_rev ) ) {

							$repo_cmd_minimum_prefix = $this->stage->config('repo_cmd_minimum_prefix');
							$repo_cmd_path    = $this->stage->config('repo_cmd_path');

							///  First check out a copy of it's parent directory
							if ( ! is_dir( $this->stage->config('operation_tmp_base'). '/ansible' ) ) mkdir($this->stage->config('operation_tmp_base'). '/ansible', 0777, true);
							$svn_path = $this->get_repository_root();
							if ( is_dir( $this->stage->config('operation_tmp_base'). '/ansible') && ! empty( $svn_path ) ) {
								///  Checkout an empty copy of it's parent
								$cd_path = $this->stage->config('operation_tmp_base') .'/ansible/'. ( dirname($file) == DIRECTORY_SEPARATOR ? 'trunk' : basename( dirname($file) ) );
								`rm -Rf "$cd_path"`;
								$parent_checkout = $svn_path .( dirname($file) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR. dirname($file) );
								$tmp_path = $this->stage->config('operation_tmp_base');
								START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
								START_TIMER('REPO_CMD(co for logcheck)', PROJECT_PROJECT_TIMERS);
								$depth_empty = $this->depth_empty;
#                                bug('CHEKOUT PARENT', "cd $tmp_path/ansible; ${repo_cmd_minimum_prefix}${repo_cmd_path}svn co $depth_empty -r $pre_deletion_rev \"$parent_checkout\" 2>&1 | cat");
								$par_checkout = `cd "$tmp_path/ansible"; ${repo_cmd_minimum_prefix}${repo_cmd_path}svn co $depth_empty -r $pre_deletion_rev "$parent_checkout" 2>&1 | cat`;
#                                bug($par_checkout);
								END_TIMER('REPO_CMD(co for logcheck)', PROJECT_PROJECT_TIMERS);
								END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);

								if ( is_dir( $cd_path ) ) {
									///  Now, checkout an pre-deletion copy of this file
									$file_basename = basename( $file );
									START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
									START_TIMER('REPO_CMD(update for logcheck)', PROJECT_PROJECT_TIMERS);
#                                    bug('GETTING UNDELETED FILE', "cd \"$cd_path\"; ${repo_cmd_minimum_prefix}${repo_cmd_path}svn update -r $pre_deletion_rev \"$file_basename\" 2>&1 | cat");
									$up_justthisfile = `cd "$cd_path"; ${repo_cmd_minimum_prefix}${repo_cmd_path}svn update -r $pre_deletion_rev "$file_basename" 2>&1 | cat`;
#                                    bug($up_justthisfile);
									END_TIMER('REPO_CMD(update for logcheck)', PROJECT_PROJECT_TIMERS);
									END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);

									///  Now, get the log, up to just before the deletion
									START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
									START_TIMER('REPO_CMD(2nd log for logcheck)', PROJECT_PROJECT_TIMERS);
#                                    bug('Retrying',$this->repo_cache['log'][$file], "cd \"$cd_path\"; ${repo_cmd_minimum_prefix}${repo_cmd_path}svn log $limit_arg \"$file_basename\" 2>&1 | cat" );
									$this->repo_cache['log'][$file] = `cd "$cd_path"; ${repo_cmd_minimum_prefix}${repo_cmd_path}svn log $limit_arg "$file_basename" 2>&1 | cat`;
#                                    bug('Retried',$this->repo_cache['log'][$file]);
									END_TIMER('REPO_CMD(2nd log for logcheck)', PROJECT_PROJECT_TIMERS);
									END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
									
									///  Remove the temp dir
									`rm -Rf "$cd_path"`;
									///  If dir still exists, then do our best to minimize the file space creep
									if ( is_dir( $cd_path ) ) {
										START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
										START_TIMER('REPO_CMD(update to remove for logcheck)', PROJECT_PROJECT_TIMERS);
										$up_to_remove = `cd "$cd_path"; ${repo_cmd_minimum_prefix}${repo_cmd_path}svn update -r HEAD "$file_basename" 2>&1 | cat`;
#                                        bug("UP TO REMOVE", $up_to_remove);
										END_TIMER('REPO_CMD(update to remove for logcheck)', PROJECT_PROJECT_TIMERS);
										END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
										
										///  Move the checkout dir out of the way (since we can't delete)
										$rand_filename = null;  while( is_null( $rand_filename ) || is_dir( $this->stage->config('operation_tmp_base') .'/ansible/'. $rand_filename ) ) $rand_filename = 'DELME__'. md5( rand(1, 1000000). $cd_path ); 
										$mv_outoftheway = `mv "$cd_path" "$tmp_path/ansible/$rand_filename"`;
#                                        bug("Move Out of the Way", $rand_filename, $mv_outoftheway);
									}

									///  Prepend the deletion entry, because SVN SuX and it doesn't EVER divulge this kind of log that we need...
									$this->repo_cache['log'][$file] = $deletion_entry."\n".$this->repo_cache['log'][$file];
#                                    bug("Adding deleted rev", $this->repo_cache['log'][$file]);
								}
							}
						}
					}
				}
                END_TIMER('REPO_CMD(log all sub-timers)', PROJECT_PROJECT_TIMERS);
            }
            else {
                $this->repo_cache['log'][$file] = "svn [status aborted]: no such directory `$parent_dir'";
            }
        }
    
        return $this->repo_cache['log'][$file];
    }
    
    public function cache_logs( $files ) {
        $cache_key = 'log';
    
        ###  Batch and run the command
        while ( count($files) > 0 ) {
            $round = array();
            $round_str = '';
            while ( $files && $round < $this->stage->config('max_batch_size') && strlen($round_str) < $this->stage->config('max_batch_string_size') ) {
                $file = array_shift( $files );
    
                ###  Skip ones whos parent dir ! exists
                $parent_dir = dirname($file);
                if ( ! is_dir($this->stage->env()->repo_base ."/$parent_dir") ) continue;
    
                array_push( $round, $file );
                $round_str .= " \"$file\"";
            }
    
            $round_checkoff = array_flip($round);
            START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
            START_TIMER('REPO_CMD(cache logs)', PROJECT_PROJECT_TIMERS);
			$cmd_prefix = $this->stage->config('repo_cmd_prefix');
			$all_entries = `${cmd_prefix}svn log $round_str 2>&1 | cat`;
    #        bug substr($all_entries, -200);
            END_TIMER('REPO_CMD(cache logs)', PROJECT_PROJECT_TIMERS);
            END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
            foreach ( preg_split('@===================================================================+\n@', $all_entries) as $entry ) {
                if ( preg_match('/^\s*$/s', $entry, $m) ) continue;
    
                ###  Get the filename
                $file;
                if ( preg_match('@^\s*RCS file: /sandbox/svnroot/(?:project/)?(.+?),v\n@', $entry, $m ) ) {
                    $file = $m[1];
                }
                ###  Other than "normal" output
                else {
                    # silently skip
                    continue;
                }
    
                ###  Cache
                if ( ! array_key_exists( $round_checkoff[$file] ) ) {
                    continue;
    #                BUG [$file,$round_checkoff];
    #                return trigger_error("file not in round", E_USER_ERROR);
                }
                unset( $round_checkoff[$file] );
                $this->repo_cache[$cache_key][$file] = $entry;
            }
        }
    }
    
    public function get_status( $file ) {
    
        ###  If not cached, get it and cache
        if ( ! isset( $this->repo_cache['status'] ) || ! array_key_exists( $file, $this->repo_cache['status'] ) ) {
            $parent_dir = dirname($file);
            if ( is_dir($this->stage->env()->repo_base ."/$parent_dir") ) {
                START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
                START_TIMER('REPO_CMD(status)', PROJECT_PROJECT_TIMERS);
                START_TIMER('REPO_CMD(status->'. $file .')', PROJECT_PROJECT_TIMERS);
				$cmd_prefix = $this->stage->config('repo_cmd_prefix');
				$depth_empty = $this->depth_empty;
#				bug(                                 "${cmd_prefix}svn -v status $depth_empty \"$file\" 2>&1 | cat");
                $this->repo_cache['status'][$file] = `${cmd_prefix}svn -v status $depth_empty "$file" 2>&1 | cat`;
                END_TIMER('REPO_CMD(status->'. $file .')', PROJECT_PROJECT_TIMERS);
                END_TIMER('REPO_CMD(status)', PROJECT_PROJECT_TIMERS);
                END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
            }
            else {
                $this->repo_cache['status'][$file] = "svn [status aborted]: no such directory `$parent_dir'";;
            }
        }
    
        return $this->repo_cache['status'][$file];
    }
    
    public function cache_statuses( $files ) {
        $cache_key = 'status';
    
        ###  Batch and run the command
        while ( count($files) > 0 ) {
            $round = array();
            $round_str = '';
            while ( $files && $round < $this->stage->config('max_batch_size') && strlen($round_str) < $this->stage->config('max_batch_string_size') ) {
                $file = array_shift( $files );
    
                ###  Skip ones whos parent dir ! exists
                $parent_dir = dirname($file);
                if ( ! is_dir($this->stage->env()->repo_base ."/$parent_dir") ) continue;
    
                array_push( $round, $file );
                $round_str .= " \"$file\"";
            }
    
            $round_checkoff = array_flip( $round );
            START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
            START_TIMER('REPO_CMD(cache statuses)', PROJECT_PROJECT_TIMERS);
			$cmd_prefix = $this->stage->config('repo_cmd_prefix');
			$all_entries = `${cmd_prefix}svn status --depth empty $round_str 2>&1 | cat`;
    #        bug substr($all_entries, -200);
            END_TIMER('REPO_CMD(cache statuses)', PROJECT_PROJECT_TIMERS);
            END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
            foreach ( preg_split('@===================================================================+\n@', $all_entries) as $entry ) {
                if ( preg_match('/^\s*$/s', $entry, $m) ) continue;
    
                ###  Get the filename
                if ( preg_match('@Repository revision:\s*[\d\.]+\s*/sandbox/svnroot/(?:project/)?(.+?),v\n@', $entry, $m) ) {
                    $file = $m[1];
                    array_shift( $round );
                }
                else if ( preg_match('@^File: (?:no file )?(.+?)\s+Status@', $entry, $m) ) {
                    $file = $m[1];
    
                    if ( preg_match('@/\Q$file\E$@', $round[0], $m) ) {
                        $file = array_shift( $round );
                    }
                    else {
    #                    bug [$entry, $file];
                    }
                }
                ###  Other than "normal" output
                else {
     #               bug [$entry];
                    # silently skip
                    continue;
                }
    
                ###  Cache
                if ( ! array_key_exists( $round_checkoff[$file] ) ) { 
                    continue;
                    # BUG [$entry, $round, $file,$round_checkoff];
                    # return trigger_error("file not in round", E_USER_ERROR); 
                }
                unset( $round_checkoff[$file] );
                $this->repo_cache[$cache_key][$file] = $entry;
            }
        }
    }


    #########################
    ###  Utility functions

    public function get_revs_in_diff( $file, $from, $to ) {
        if ( $from == $to ) return array();

        $revs = array();

        if ( $from >= $to ) return array();
        list( $all_revs ) = $this->get_all_log_revs($file);
        $revs = array();  foreach ( range( ($from+1), $to ) as $_ ) { if ( in_array($_, $all_revs) ) $revs[] = $_; }

        return $revs;
    }

    public function get_head_rev( $file ) {
        list( $all_revs ) = $this->get_all_log_revs($file);

        $head_rev = null;  $error = '';
		if ( empty( $all_revs ) ) {
            $error = "Not in $this->display_name";
		}
		else {
			$head_rev = array_shift( $all_revs );
		}
        return( array( $head_rev, $error) );
    }

    public function get_current_rev( $file ) {
        $cstat = $this->get_status($file);

        $cur_rev = null;  $error = '';  $status = '';  $state_code = '';  $is_modified = false;
        if ( preg_match('/^(\w?).\s*?(\d+)\s+\d+\s/', $cstat, $m) ) {
            $letter = $m[1];
            if ( empty($letter) ) $letter = '';
            $letter_trans = array( '' => 'Up-to-date', 'M' => 'Locally Modified', 'A' => 'To-be-added' );
            $status = ( isset( $letter_trans[ $letter ] ) ? $letter_trans[ $letter ] : 'Other: "'. $letter .'"' );
			///  Determine the revision by taking the first revision number and working backwards in the revision list
			///    until we find an actual revision.  We used to trust the second number, but that didn't take into
			///    account SVN move operations, maybe because of and SVN bug ???  Either way, this is the only safe way.
            if ( preg_match('/^\w?.\s*?(\d+)\s+\d+\s/', $cstat, $m) ) {
                $actual_rev = $m[1];

				///  Loop thru the revs from high to low until we pass the rev we just saw in the status output
				list( $all_revs ) = $this->get_all_log_revs($file);
				foreach ( $all_revs as $rev ) {
					if ( $rev <= $actual_rev ) {
						$cur_rev = $rev;
						break;
					}
				}
            } else {
                $error = "malformed $this->command_name status";
                $error_code = 'malformed';
            }
            //  States (Should be ding this by letter.... TODO)
            if      ( $status == 'Locally Modified' )          { $state_code = 'locally_modified'; $is_modified = true; }
            else if ( $status == 'Needs Merge' )                 $state_code = 'needs_merge';
            else if ( $status == 'File had conflicts on merge' ) $state_code = 'conflict';
        } else {
            $error = "malformed $this->command_name status";
            $error_code = 'malformed';
            $is_modified = true;
        }
        
        return( array( $cur_rev, $error, $status, $state_code, $is_modified ) );
    }

    public function get_rev_committer( $file, $rev ) {
        list( $all_revs, $all_committers ) = $this->get_all_log_revs($file);
		
		foreach ( $all_revs as $i => $i_rev ) {
			if ( $rev == $i_rev ) {
				return $all_committers[ $i ];
			}
		}
        return null;
    }

    public function get_prev_rev( $file, $rev ) {

        ///  Loop through (Low to high)
        list( $all_revs ) = $this->get_all_log_revs($file);
        $prev_rev = null;
        foreach ( array_reverse( $all_revs ) as $e ) {
            if ( $e >= $rev ) return $prev_rev;
            $prev_rev = $e;
        }
        return $prev_rev;
    }

    public function get_first_rev( $file ) {
        list( $all_revs ) = $this->get_all_log_revs($file);

        $head_rev = null;  $error = '';
		if ( empty( $all_revs ) ) {
            $error = "Not in $this->display_name";
		}
		else {
			$head_rev = array_pop( $all_revs );
		}
        return( array( $head_rev, $error) );
    }

    public function get_all_log_revs( $file ) {

        $cache_key = 'all_log_revs';

		if ( empty( $file ) && ! is_numeric( $file ) )
			return( array( array(), array() ) );

		if ( ! isset( $this->repo_cache[$cache_key][$file] ) ) {

			///  Check if this is cached already
			$sth = dbh_query_bind("SELECT * FROM revision_cache WHERE file = ?", $file);
			$cache = $sth->fetch(PDO::FETCH_ASSOC);
			$sth->closeCursor();

			///  If we got a record and have an expire token...
			if ( ! empty( $cache )
				 && $this->expire_token() !== false
				 ) {
				///  If the Expire Token matched, then use the DB cache...
				if ( $this->expire_token() == $cache['expire_token'] ) {
					$this->repo_cache[$cache_key][$file]['revisions']  = empty($cache['revisions'])  ? array() : explode(',', $cache['revisions']);
					$this->repo_cache[$cache_key][$file]['committers'] = empty($cache['committers']) ? array() : explode(',', $cache['committers']);

					///  Good enough,  return now...
					return( array( $this->repo_cache[$cache_key][$file]['revisions'], $this->repo_cache[$cache_key][$file]['committers'] ) );
				}
				else {
					//  Then we Truncate the entire table...  (Or later we can add params to do this partially if people are using Ansible diffeerenly)
					$rv = dbh_query_bind("TRUNCATE TABLE revision_cache");
#					if ( $_SERVER['REMOTE_ADDR'] == '10.1.2.116' ) bug('[ TRUNCATING REVISION CACHE ]');
					///  Then continue, so we can store a new value...
				}
			}

			///  If we got here then it means we need to get and store a new value
			
			///  Start out by getting an SVN Log...
			$clog = $this->get_log($file);

			///  Match
######  Old All-regex parsing mechanism.  It threw up (non-match) when it hit a 600K commit message...
###			$matches = preg_match_all('/---------+\nr(\d+)\s*\|\s*([^\s\|]+)\s*\|.+?(?=---------+|$)/s', $clog, $m, PREG_PATTERN_ORDER);
			###  New Parsing Method (more flexible and forgiving)
			foreach ( $this->parse_log($clog) as $rev => $data ) {
				$m[1][] = $rev;
				$m[2][] = $data['committer'];
			}
			if ( ! empty( $m[1] ) ) {

				///  Set it from the regex
				$this->repo_cache[$cache_key][$file]['revisions']  = empty( $m ) ? array() : $m[1];
				$this->repo_cache[$cache_key][$file]['committers'] = empty( $m ) ? array() : $m[2];

				/// Store it in the database if we can.  (the only reason we couldn't would be an uninitialize Repo or an error)
				if ( $this->expire_token() !== false ) {
					if ( strpos($this->stage->config('db_dsn'), 'mysql') !== false ) {
						$rv = dbh_do_bind("REPLACE INTO revision_cache ( file,expire_token,revisions,committers ) VALUES (?,?,?,?)",
										  $file,
										  $this->expire_token(), 
										  join(',', $this->repo_cache[$cache_key][$file]['revisions']  ),
										  join(',', $this->repo_cache[$cache_key][$file]['committers'] )
										  );
					}
					else {
						$rv = dbh_do_bind("DELETE FROM revision_cache WHERE file = ?", $file);
						$rv = dbh_do_bind("INSERT INTO revision_cache ( file,expire_token,revisions,committers ) VALUES (?,?,?,?)",
										  $file,
										  $this->expire_token(), 
										  join(',', $this->repo_cache[$cache_key][$file]['revisions']  ),
										  join(',', $this->repo_cache[$cache_key][$file]['committers'] )
										  );
					}
				}
			}
			else {
				if ( $_SERVER['REMOTE_ADDR'] == '10.1.2.116' ) 
					bug("Invalid log output (did not match):", $file, $clog);
			}

		}
        return( array( $this->repo_cache[$cache_key][$file]['revisions'], $this->repo_cache[$cache_key][$file]['committers'] ) );
    }

    public function parse_log($clog) {
		$log = array();
		$revs = preg_split('/(^|[\r\n]+)-----------------------+([\r\n]+|$)/', $clog);
		foreach ( $revs as $rev ) {
			if ( empty( $rev ) ) continue;
			list($header, $body) = preg_split('/[\r\n]+/', $rev, 2);
			$parts = preg_split('/\s*\|\s*/', $header);
			if ( count($parts) < 3 ) continue;
			$log[ ltrim($parts[0],'r') ]
				= array( 'revision'  => ltrim($parts[0],'r'),
						 'committer' => $parts[1],
						 'date'      => strtotime($parts[2]),
						 'message'   => $body,
						 'raw'       => $rev,
						 );
		}
		return $log;
	}

    public function get_log_entry( $clog, $rev ) {
#        preg_match('/---------+\nr\Q'. $rev .'\E\s*\|.+?(?=---------+|$)/s', $clog, $m);
		$parsed = $this->parse_log($clog);
        return( isset( $parsed[ $rev ] ) ? "------------------------------------------------------------------------\n". $parsed[ $rev ]['raw'] : null );
    }

	public function expire_token() {

		///  If we haven't checked the expire token, check now
		if ( ! $this->checked_revision_cache_expire_token ) {
			///  Regardless, NOW, we've checked...
			$this->checked_revision_cache_expire_token = true;

			/// Quickest way to read the SVN address for the repo we are on (6th line of the entries file)...
            START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
            START_TIMER('REPO_CMD(repo exp token)', PROJECT_PROJECT_TIMERS);
			$svn_path = $this->get_repository_root();
			$cmd_prefix = $this->stage->config('repo_cmd_prefix');
			$svn_info = shell_exec("${cmd_prefix}svn info \"$svn_path\" 2>&1 | cat");
            END_TIMER('REPO_CMD(repo exp token)', PROJECT_PROJECT_TIMERS);
            END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);

			///  The token, is the newest revision of the Repository root
			if ( preg_match('/Revision:\s+(\d+)/', $svn_info, $m ) ) {
				$this->revision_cache_expire_token = $m[1];
			}

			if ( empty( $this->revision_cache_expire_token ) ) bug( 'PROBLEM: SVN is returning no trunk base revision! (needed for cache expire token)',
																	'SVN CMD:',"head -n5 \"$repo/.svn/entries\" | tail -n1",
																	'SVN Path:', $svn_path,
																	'SVN Output:', $svn_info,
																	$this->stage->env()->repo_base
																	);
		}


		return $this->revision_cache_expire_token;
	}

	public function get_repository_root() {
		///  If we haven't checked the expire token, check now
		if ( ! $this->checked_repository_root ) {
			///  Regardless, NOW, we've checked...
			$this->checked_repository_root = true;

			/// Quickest way to read the SVN address for the repo we are on (6th line of the entries file)...
			$repo = $this->stage->env()->repo_base;
			$this->repository_root = trim(`head -n5 "$repo/.svn/entries" | tail -n1`);
		}

		return $this->repository_root;
	}


    #########################
    ###  Repo-wide actions
    
    public function get_ls($dir = '') {
        $full_dir_path = $this->stage->env()->repo_base .'/'. $dir . '/.';
        $all_files = array();  foreach ( scandir($full_dir_path) as $file ) if ( $file != '.' && $file != '..' && $file != '.svn' ) $all_files[] = $file;
        return $all_files;
    }
    
    public function get_dir_status($dir = '') {
        ///  If it is a file, then fall back to get_status()
        if ( file_exists( $this->stage->env()->repo_base .'/'. $dir ) )
            return $this->get_status( $dir );

        $full_dir_path = $this->stage->env()->repo_base .'/'. $dir;

        $cache_key = ( strlen( $dir ) == 0 ? '*ROOTDIR*' : $dir ); 
        ###  If not cached, get it and cache
        if ( ! $this->repo_cache['dir_status'][$cache_key] ) {
            $parent_dir = dirname($dir);
            if ( is_dir($this->stage->env()->repo_base ."/$parent_dir") ) {
                START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
                START_TIMER('REPO_CMD(dir status)', PROJECT_PROJECT_TIMERS);
				$cmd_prefix = $this->stage->config('repo_cmd_prefix');
                $this->repo_cache['dir_status'][$cache_key] = `${cmd_prefix}svn -v status "$dir" 2>&1 | cat`;
                END_TIMER('REPO_CMD(dir status)', PROJECT_PROJECT_TIMERS);
                END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
            }
            else {
                $this->repo_cache['dir_status'][$cache_key] = "svn [status aborted]: no such directory `$parent_dir'";;
            }
        }
    
        return $this->repo_cache['dir_status'][$cache_key];
    }

    public function analyze_dir_status($dir = '') {
        $report = array( 'has_modified' => false,
                        );
        $status = $this->get_dir_status($dir);
        foreach ( preg_split('/\n/', $status ) as $line ) {
            if ( preg_match('/^\s*M/', $line) ) $report['has_modified'] = true;
            if ( preg_match('/^\s*C/', $line) ) $report['has_conflicts'] = true;
        }
        return $report;
    }

    public function diff_dir_from_tag($tag, $dir = '') {
        $report = array( 'files_no_tag'       => 0,
                         'files_behind_tag'   => 0,
                         'files_ahead_of_tag' => 0,
                         'files_on_tag'       => 0,
                         'files_unknown'      => 0,
                        );
        $status = $this->get_dir_status($dir);
        foreach ( preg_split('/\n/', $status ) as $line ) {
            if ( preg_match('/^\s*[A-Z\?]?\s*\d+\s+(\d+)\s+\S+\s+(\S.*)$/', $line, $m) ) {
                $cur_rev = $m[1];
                $file = rtrim($m[2],"\n\r");

                ###  Skip dirs in SVN (for now)...
                if ( is_dir( $this->stage->env()->repo_base .'/'. $file ) ) continue;

                ###  See what the tag is...
                $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
                $tag_rev = $sth->fetch(PDO::FETCH_NUM);
                $sth->closeCursor();

                ###  Mark the group
                if ( empty( $tag_rev ) )        { $report['files_no_tag'      ]++; continue; }
                else $tag_rev = $tag_rev[0];
                if      ( $cur_rev < $tag_rev ) $report['files_behind_tag'  ]++;
                else if ( $cur_rev > $tag_rev ) $report['files_ahead_of_tag']++;
                else if ( $cur_rev = $tag_rev ) $report['files_on_tag'      ]++;
            }
            else if ( preg_match('/^\s*[\?]?\s+(\S.*)$/', $line, $m) ) {
                $report['files_unknown']++;
            }
            else if ( ! empty( $line ) ) {
                bug("SVN status line didn't match to extract rev for tag diff! (/^\s*[A-Z\?]?\s*\d+\s+(\d+)\s+\S+\s+(\S.*)$/ nor /^\s*[\?]?\s+(\S.*)$/)", $status, $line);
            }
        }
        return $report;
    }

    public function tagEntireRepoAction($tag, $user) {

		set_time_limit( 0 );

        ###  Start out by updating all rows in the tag table as mass_edit=1
        ###    as we delete and update, the ones that have tags will be set back to 0
        $rv = dbh_do_bind("UPDATE file_tag SET mass_edit=1 AND tag=?", $tag);

        $command_output = '';

        $status = $this->get_dir_status();
        foreach ( preg_split('/\n/', $status ) as $line ) {
            if ( preg_match('/^\s*[A-Z]?\s*\d+\s+(\d+)\s+\S+\s+(\S.*)$/', $line, $m) ) {
                $cur_rev = $m[1];
                $file = rtrim($m[2],"\n\r");

				///  Override this with the SLOW, but accurate alternate.  This turns a 2 min update into a 2+ hour update
                list( $cur_rev ) = $this->get_current_rev($file);

                ###  Skip dirs in SVN (for now)...
                if ( is_dir( $this->stage->env()->repo_base .'/'. $file ) ) continue;
				echo ".\n";

				///  Trick to get the browser to display NOW!
				print str_repeat(' ',100);
				flush();ob_flush();

                ###  See what the tag is...
                $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
                $old_rev = $sth->fetch(PDO::FETCH_NUM);
                $sth->closeCursor();

                ###  Update the Tag DB for this file...
                $rv = dbh_do_bind("DELETE FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
                $rv = dbh_do_bind("INSERT INTO file_tag ( file,tag,revision ) VALUES (?,?,?)", $file, $tag, $cur_rev);

                ###  Add to Command output whether we really changed the tag or not
                if ( ! empty( $old_rev ) && $old_rev[0] != $cur_rev ) {
                    $command_output .=          "Moved $tag on $file from ". $old_rev[0] . " to $cur_rev\n";
                    $this->log_repo_action("TAG: Moved $tag on $file from ". $old_rev[0] . " to $cur_rev", 'entire_repo', $user);
                }
                else if ( empty( $old_rev ) ) {
                    $command_output .=          "Set $tag on $file to ". $cur_rev ."\n";
                    $this->log_repo_action("TAG: Set $tag on $file to ". $cur_rev, 'entire_repo', $user);
                }
                else {
                    $command_output .=          "Preserved $tag on $file at ". $old_rev[0] ."\n";
                    $this->log_repo_action("TAG: Preserved $tag on $file at ". $old_rev[0], 'entire_repo', $user);
                }
            }
        }

        ###  The rows the mass_edit still need to be Un-tagged...
        ###  See what the tag was before...
        $sth = dbh_query_bind("SELECT file FROM file_tag WHERE mass_edit=1 AND tag=?", $tag);
        while (list( $file ) = $sth->fetch(PDO::FETCH_NUM) ) {
            $command_output .=          "Removed $tag on $file\n";
            $this->log_repo_action("TAG: Removed $tag on $file", 'entire_repo', $user);
        }
        $sth->closeCursor();

        $rv = dbh_do_bind("DELETE FROM file_tag WHERE mass_edit=1 AND tag=?", $tag);

        $cmd = "TAG entire repo: $tag";

        if ( empty( $command_output ) ) $command_output = '</xmp><i>No output</i>';

        return( array($cmd, $command_output) );
    }


    public function updateEntireRepoAction($tag, $user) {

		set_time_limit( 0 );

        $cmd = '';  $command_output = '';

        ###  Prepare for a MASS HEAD update if updating to HEAD
        $doing_indiv_dir_update = array();
        if ( $tag == 'HEAD' ) {
            $head_update_cmd = "svn update";
            START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
            START_TIMER('REPO_CMD(update entire repo)', PROJECT_PROJECT_TIMERS);
            $this->log_repo_action($head_update_cmd, 'entire_repo', $user);
            $command_output .= shell_exec($this->stage->config('repo_cmd_prefix') ."$head_update_cmd 2>&1 | cat -");
            END_TIMER('REPO_CMD(update entire repo)', PROJECT_PROJECT_TIMERS);
            END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
            $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $head_update_cmd;
        }
        ###  All other tags, do individual file updates
        else {

            #############################
            ###  Step 1 : First find any files that we have tags for but don't exist

            ###  Start out by updating all rows in the tag table as mass_edit=1
            ###    as we delete and update, the ones that have tags will be set back to 0
            $rv = dbh_do_bind("UPDATE file_tag SET mass_edit=1 AND tag=?", $tag);
    
            $command_output = '';
    
            $status = $this->get_dir_status();
            foreach ( preg_split('/\n/', $status ) as $line ) {
                if ( preg_match('/^\s*[A-Z]?\s*\d+\s+(\d+)\s+\S+\s+(\S.*)$/', $line, $m) ) {
                    $cur_rev = $m[1];
                    $file = rtrim($m[2],"\n\r");

					///  Override this with the SLOW, but accurate alternate.  This turns a 2 min update into a 2+ hour update
					list( $cur_rev ) = $this->get_current_rev($file);
    
                    ###  Skip dirs in SVN (for now)...
                    if ( is_dir( $this->stage->env()->repo_base .'/'. $file ) ) continue;
    
###  We could be cache-ing the tags here, but in some repos with long file paths and hundreds of thousands of files, we would run out of memory                    
#                    ###  See what the tag is...
#                    $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
#                    $old_rev = $sth->fetch(PDO::FETCH_NUM);
#                    $sth->closeCursor();
    
                    ###  ALL we are doing for the files that exist for this loop is marking mass_edit as off
                    $rv = dbh_do_bind("UPDATE file_tag SET mass_edit=0 WHERE file = ? AND tag = ?", $file, $tag);
                }
            }

            #############################
            ###  Step 2 : Update the files that didn't exist...

            ###  The rows the mass_edit still need to be Un-tagged...
            ###  See what the tag was before...
            $sth = dbh_query_bind("SELECT file,revision FROM file_tag WHERE mass_edit=1 AND tag=?", $tag);
            while (list( $file, $rev ) = $sth->fetch(PDO::FETCH_NUM) ) {
                ///  Each loop, do a $individual_file_rev_updates instead of globally
                $individual_file_rev_updates = array();
                $dir_test = $file;
                ###  Before we do Inidividual Tag updates on files the containing dirs must exist
                $dirs_to_update = array();
                while ( ! empty( $dir_test )
                        && ! is_dir( dirname( $this->stage->env()->repo_base ."/$dir_test" ) )
                        && $this->stage->env()->repo_base != dirname( $this->stage->env()->repo_base ."/$dir_test" )
                        && ! array_key_exists(dirname($dir_test), $doing_indiv_dir_update)
                        ) {
                    $dir = dirname($dir_test);
                    $dirs_to_update[] = $dir;
                    $doing_indiv_dir_update[$dir] = true;

                    $dir_test = $dir; // iterate backwards
                }
                ///  Need to add in parent-first order
                ///    NOTE: we only need to do the parent one, because the in-between ones will be included
                if ( count( $dirs_to_update ) )
                    $individual_file_rev_updates[] = array( array_pop($dirs_to_update), $rev );
                
                $individual_file_rev_updates[] = array( $file, $rev );

                foreach ( $individual_file_rev_updates as $update ) {
                    list($up_file, $up_rev) = $update;

					$depth_empty = $this->depth_empty;
                    $indiv_update_cmd = "svn update $depth_empty -r$up_rev ". escapeshellcmd($up_file);
                    START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
                    START_TIMER('REPO_CMD(update entire repo indiv)', PROJECT_PROJECT_TIMERS);
                    $this->log_repo_action($indiv_update_cmd, 'entire_repo', $user);
                    $command_output .= shell_exec($this->stage->config('repo_cmd_prefix') ."$indiv_update_cmd 2>&1 | cat -");
                    END_TIMER('REPO_CMD(update entire repo indiv)', PROJECT_PROJECT_TIMERS);
                    END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
                    $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $indiv_update_cmd;
                }
            }
            $sth->closeCursor();
    
            $rv = dbh_do_bind("UPDATE file_tag SET mass_edit=0 WHERE tag=?", $tag);



            #############################
            ###  Step 3 : NOW, get a new Status output, and go through again, now that all files are present and set everything to the right tags

            ###  Reset Cache on dir status
            unset( $this->repo_cache['dir_status']['*ROOTDIR*'] );

            $status = $this->get_dir_status();
            foreach ( preg_split('/\n/', $status ) as $line ) {
                if ( preg_match('/^\s*[A-Z]?\s*\d+\s+(\d+)\s+\S+\s+(\S.*)$/', $line, $m) ) {
                    $cur_rev = $m[1];
                    $file = rtrim($m[2],"\n\r");
                    
                    if ( is_dir($this->stage->env()->repo_base ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
                         ) continue;
      
                    ///  Each loop, do a $individual_file_rev_updates instead of globally
                    $individual_file_rev_updates = array();
      
                    ###  Get the tag rev for this file...
                    $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
                    $rev = $sth->fetch(PDO::FETCH_NUM);
                    $sth->closeCursor();
                    if ( ! empty( $rev ) ) {
                        $dir_test = $file;
                        ###  Before we do Inidividual Tag updates on files the containing dirs must exist
                        $dirs_to_update = array();
                        while ( ! empty( $dir_test )
                                && ! is_dir( dirname( $this->stage->env()->repo_base ."/$dir_test" ) )
                                && $this->stage->env()->repo_base != dirname( $this->stage->env()->repo_base ."/$dir_test" )
                                && ! array_key_exists(dirname($dir_test), $doing_indiv_dir_update)
                                ) {
                            $dir = dirname($dir_test);
                            $dirs_to_update[] = $dir;
                            $doing_indiv_dir_update[$dir] = true;
      
                            $dir_test = $dir; // iterate backwards
                        }
                        ///  Need to add in parent-first order
                        ///    NOTE: we only need to do the parent one, because the in-between ones will be included
                        if ( count( $dirs_to_update ) ) {
#                            bug("Parent Dir update", $file);
                            $individual_file_rev_updates[] = array( array_pop($dirs_to_update), $rev[0] );
                        }
                        else if ( $cur_rev != $rev[0] ) {
#                            bug("Regular update", $file);
                            $individual_file_rev_updates[] = array( $file, $rev[0] );
                        }
                    } else {

                         list($first_rev, $error) = $this->get_first_rev( $file );
#                         bug("NO TAG, intent-to-rm update", $file, $first_rev);
                         if ( empty( $error ) ) {
                             $rev_before_first = $first_rev - 1;
                             $individual_file_rev_updates[] = array( $file, $rev_before_first );
                         }
                    }
      
                    ///  Do updates...
                    foreach ( $individual_file_rev_updates as $update ) {
                        list($up_file, $up_rev) = $update;
      
						$depth_empty = $this->depth_empty;
                        $indiv_update_cmd = "svn update $depth_empty -r$up_rev ". escapeshellcmd($up_file);
                        START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
						START_TIMER('REPO_CMD(update entire repo indiv2)', PROJECT_PROJECT_TIMERS);
                        $this->log_repo_action($indiv_update_cmd, 'entire_repo', $user);
                        $command_output .= shell_exec($this->stage->config('repo_cmd_prefix') ."$indiv_update_cmd 2>&1 | cat -");
						END_TIMER('REPO_CMD(update entire repo indiv2)', PROJECT_PROJECT_TIMERS);
                        END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
                        $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $indiv_update_cmd;
                    }
                }
            }
        }

        if ( empty( $command_output ) ) $command_output = '</xmp><i>No output</i>';

        return( array($cmd, $command_output) );
    }
}
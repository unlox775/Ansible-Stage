<?php

/**
 * SVN Spefic hooks for Ansible Stage
 */
class Ansible__Repo__SVN extends Ansible__Repo {
	public $stage = null;
    public $display_name = 'SVN';
    public $command_name = 'svn';

	///  Check if we have done the once-per-session check
	public $checked_revision_cache_expire_token = false;
	public $revision_cache_expire_token = false;
	public $checked_repository_root = false;
	public $repository_root = false;
 
    #########################
    ###  Action Methods

    public function updateAction($project, $tag, $user) {

        $individual_file_rev_updates = array();

        ###  Target mode
        $target_mode_update = false;
        if ( $tag == 'Target' ) {
            $target_mode_update = true;
            $tag = 'HEAD';
            ###  Read in the file tags CSV
            $file_tags = $project->get_file_tags();
        }

        ###  Prepare for a MASS HEAD update if updating to HEAD
        $doing_indiv_dir_update = array();
        if ( $tag == 'HEAD' ) {
            $mass_head_update_files = array();
            foreach ( $project->get_affected_files() as $file ) {
                if ( is_dir($this->stage->env()->repo_base ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
                     ###  Skip this file if in TARGET MODE and it's on the list
                     || ( $target_mode_update && array_key_exists( $file, $file_tags) )
                     ) continue;
                $mass_head_update_files[] = $file;
            }

            ###  Get Target Mode files
            if ( $target_mode_update ) {
                foreach ( $project->get_affected_files() as $file ) {
                    if ( is_dir($this->stage->env()->repo_base ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
                         ) continue;
                    if ( ! empty( $file_tags[ $file ] ) && abs( floor( $file_tags[ $file ] ) ) == $file_tags[ $file ] ) 
                        $individual_file_rev_updates[] = array( $file, $file_tags[ $file ] );
                }
            }
        }
        ###  All other tags, do individual file updates
        else {
            foreach ( $project->get_affected_files() as $file ) {
                if ( is_dir($this->stage->env()->repo_base ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
                     ) continue;

                ###  Get the tag rev for this file...
                $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
                $rev = $sth->fetch(PDO::FETCH_NUM);
                $sth->closeCursor();
                if ( ! empty( $rev ) ) { # I guess if there isn't a rev, we should REMOVE THE FILE?  Maybe later...

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
                        $individual_file_rev_updates[] = array( array_pop($dirs_to_update), $rev[0] );
                    
                    $individual_file_rev_updates[] = array( $file, $rev[0] );
                } else {
                    list($first_rev, $error) = $this->get_first_rev( $file );
                    if ( empty( $error ) ) {
                        $rev_before_first = $first_rev - 1;
                        $individual_file_rev_updates[] = array( $file, $rev_before_first );
                    }
                }
            }
        }

        ###  Run the MASS HEAD update (if any)
        if ( ! empty($mass_head_update_files) ) {
            $head_update_cmd = "svn update ";
            foreach ( $mass_head_update_files as $file ) $head_update_cmd .= ' '. escapeshellcmd($file);
            START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
            $this->log_repo_action($head_update_cmd, $project, $user);
			$cmd_prefix = $this->stage->config('repo_cmd_prefix');
            $command_output .= shell_exec("$cmd_prefix$head_update_cmd 2>&1 | cat -");
            END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
            $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $head_update_cmd;
        }

        ###  File tag update
        if ( ! empty($individual_file_rev_updates) ) {
            foreach ( $individual_file_rev_updates as $update ) {
                list($file, $rev) = $update;

                $indiv_update_cmd = "svn update -r$rev ". escapeshellcmd($file);
                START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
                $this->log_repo_action($indiv_update_cmd, $project, $user);
				$cmd_prefix = $this->stage->config('repo_cmd_prefix');
                $command_output .= shell_exec("$cmd_prefix$indiv_update_cmd 2>&1 | cat -");
                END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
                $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $indiv_update_cmd;
            }
        }

        if ( empty( $command_output ) ) $command_output = '</xmp><i>No output</i>';

        return( array($cmd, $command_output) );
    }

    public function tagAction($project, $tag, $user) {

        ###  Look and update tags
        foreach ( $project->get_affected_files() as $file ) {
            ###  Make sure this file exists
            if ( file_exists($this->stage->env()->repo_base ."/$file")
                 && ! is_dir($this->stage->env()->repo_base ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
                 ) {
                list( $cur_rev ) = $this->get_current_rev($file);

                ###  See what the tag was before...
                $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
                $old_rev = $sth->fetch(PDO::FETCH_NUM);
                $sth->closeCursor();
            
                ###  Update the Tag DB for this file...
                $rv = dbh_do_bind("DELETE FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
                $rv = dbh_do_bind("INSERT INTO file_tag ( file,tag,revision ) VALUES (?,?,?)", $file, $tag, $cur_rev);

                ###  Add to Command output whether we really changed the tag or not
                if ( ! empty( $old_rev ) && $old_rev[0] != $cur_rev ) {
                    $command_output .=          "Moved $tag on $file from ". $old_rev[0] . " to $cur_rev\n";
                    $this->log_repo_action("TAG: Moved $tag on $file from ". $old_rev[0] . " to $cur_rev", $project, $user);
                }
                else if ( empty( $old_rev ) ) {
                    $command_output .=          "Set $tag on $file to ". $cur_rev ."\n";
                    $this->log_repo_action("TAG: Set $tag on $file to ". $cur_rev, $project, $user);
                }
                else {
                    $command_output .=          "Preserved $tag on $file at ". $old_rev[0] ."\n";
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
                    $command_output .=          "Removed $tag on $file\n";
                    $this->log_repo_action("TAG: Removed $tag on $file", $project, $user);
                }
            }
        }

        $cmd = "TAG all files: $tag";

        if ( empty( $command_output ) ) $command_output = '</xmp><i>No output</i>';

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
                $limit_arg = ! empty( $limit ) ? ' --limit '. $limit : '';
				$cmd_prefix = $this->stage->config('repo_cmd_prefix');
                $this->repo_cache['log'][$file] = `${cmd_prefix}svn log $limit_arg -r HEAD:1 "$file" 2>&1 | cat`;
                END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);


                              ###  Use HEAD:1 so we aren't limited to don't just get the revs up to the file's current rev
#                bug("${cmd_prefix}svn log -r HEAD:1 \"$file\" 2>&1 | cat"); #exit;
                $limit_arg = ! empty( $limit ) ? ' --limit '. $limit : '';
				$cmd_prefix = $this->stage->config('repo_cmd_prefix');
                $this->repo_cache['log'][$file] = `${cmd_prefix}svn log $limit_arg -r HEAD:1 "$file" 2>&1 | cat`;

				###  If we get "file not found" but the file does exist, then try again without the HEAD:1
				###    NOTE: this MAY be misleading if there are revs after ours but before the file
				###         was deleted.  We won't see those one...  SVN Sux!
#                bug('Retrying?',$this->repo_cache['log'][$file], preg_match('/^svn: .*?(path not found)\s*$/s', $this->repo_cache['log'][$file]));
				if ( preg_match('/^svn: .*?(is not under version control|path not found)\s*$/s', $this->repo_cache['log'][$file])
					 && file_exists($this->stage->env()->repo_base ."/$file")
					 ) {
#                    bug('Retrying',$this->repo_cache['log'][$file] );
					$this->repo_cache['log'][$file] = `${cmd_prefix}svn log $limit_arg "$file" 2>&1 | cat`;
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
					$parent_verbose = `${cmd_prefix}svn log --verbose -r HEAD:1 --limit 50 "$parent_dir" 2>&1 | cat`;
#                    bug('Parent Log', substr($parent_verbose,0,1000 ));
					if ( preg_match_all('/---------+\nr(\d+)\s*\|\s*([^\s\|]+)\s*\|.+?(?=---------+|$)/s', $parent_verbose, $m, PREG_SET_ORDER) != 0 ) {
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

							///  First check out a copy of it's parent directory
							if ( ! is_dir( $this->stage->config('operation_tmp_base'). '/ansible' ) ) mkdir($this->stage->config('operation_tmp_base'). '/ansible', 0777, true);
							$svn_path = $this->get_repository_root();
							if ( is_dir( $this->stage->config('operation_tmp_base'). '/ansible') && ! empty( $svn_path ) ) {
								///  Checkout an empty copy of it's parent
								$cd_path = $this->stage->config('operation_tmp_base') .'/ansible/'. ( dirname($file) == DIRECTORY_SEPARATOR ? 'trunk' : basename( dirname($file) ) );
								`rm -Rf "$cd_path"`;
								$parent_checkout = $svn_path .( dirname($file) == DIRECTORY_SEPARATOR ? '' : DIRECTORY_SEPARATOR. dirname($file) );
								$tmp_path = $this->stage->config('operation_tmp_base');
#                                bug('CHEKOUT PARENT', "cd $tmp_path/ansible; ${REPO_CMD_MINIMUM_PREFIX}${REPO_CMD_BINARY_PATH}svn co --depth empty -r $pre_deletion_rev \"$parent_checkout\" 2>&1 | cat");
								$par_checkout = `cd "$tmp_path/ansible"; ${REPO_CMD_MINIMUM_PREFIX}${REPO_CMD_BINARY_PATH}svn co --depth empty -r $pre_deletion_rev "$parent_checkout" 2>&1 | cat`;
#                                bug($par_checkout);

								if ( is_dir( $cd_path ) ) {
									///  Now, checkout an pre-deletion copy of this file
									$file_basename = basename( $file );
#                                    bug('GETTING UNDELETED FILE', "cd \"$cd_path\"; ${REPO_CMD_MINIMUM_PREFIX}${REPO_CMD_BINARY_PATH}svn update -r $pre_deletion_rev \"$file_basename\" 2>&1 | cat");
									$up_justthisfile = `cd "$cd_path"; ${REPO_CMD_MINIMUM_PREFIX}${REPO_CMD_BINARY_PATH}svn update -r $pre_deletion_rev "$file_basename" 2>&1 | cat`;
#                                    bug($up_justthisfile);

									///  Now, get the log, up to just before the deletion
#                                    bug('Retrying',$this->repo_cache['log'][$file], "cd \"$cd_path\"; ${REPO_CMD_MINIMUM_PREFIX}${REPO_CMD_BINARY_PATH}svn log $limit_arg \"$file_basename\" 2>&1 | cat" );
									$this->repo_cache['log'][$file] = `cd "$cd_path"; ${REPO_CMD_MINIMUM_PREFIX}${REPO_CMD_BINARY_PATH}svn log $limit_arg "$file_basename" 2>&1 | cat`;
#                                    bug('Retried',$this->repo_cache['log'][$file]);
									
									///  Remove the temp dir
									`rm -Rf "$cd_path"`;
									///  If dir still exists, then do our best to minimize the file space creep
									if ( is_dir( $cd_path ) ) {
										$up_to_remove = `cd "$cd_path"; ${REPO_CMD_MINIMUM_PREFIX}${REPO_CMD_BINARY_PATH}svn update -r HEAD "$file_basename" 2>&1 | cat`;
#                                        bug("UP TO REMOVE", $up_to_remove);
										
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
				
                END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
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
			$cmd_prefix = $this->stage->config('repo_cmd_prefix');
			$all_entries = `${cmd_prefix}svn log $round_str 2>&1 | cat`;
    #        bug substr($all_entries, -200);
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
        if ( ! $this->repo_cache['status'][$file] ) {
            $parent_dir = dirname($file);
            if ( is_dir($this->stage->env()->repo_base ."/$parent_dir") ) {
                START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
				$cmd_prefix = $this->stage->config('repo_cmd_prefix');
#				bug("${cmd_prefix}svn -v status \"$file\" 2>&1 | cat");
                $this->repo_cache['status'][$file] = `${cmd_prefix}svn -v status "$file" 2>&1 | cat`;
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
			$cmd_prefix = $this->stage->config('repo_cmd_prefix');
			$all_entries = `${cmd_prefix}svn status $round_str 2>&1 | cat`;
    #        bug substr($all_entries, -200);
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
        if ( preg_match('/^(\w?)\s*(\d+)\s+\d+\s/', $cstat, $m) ) {
            $letter = $m[1];
            if ( empty($letter) ) $letter = '';
            $letter_trans = array( '' => 'Up-to-date', 'M' => 'Locally Modified', 'A' => 'To-be-added' );
            $status = ( isset( $letter_trans[ $letter ] ) ? $letter_trans[ $letter ] : 'Other: "'. $letter .'"' );
			///  Determine the revision by taking the first revision number and working backwards in the revision list
			///    until we find an actual revision.  We used to trust the second number, but that didn't take into
			///    account SVN move operations, maybe because of and SVN bug ???  Either way, this is the only safe way.
            if ( preg_match('/^\w?\s*(\d+)\s+\d+\s/', $cstat, $m) ) {
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
					if ( $_SERVER['REMOTE_ADDR'] == '10.1.2.116' ) bug('[ TRUNCATING REVISION CACHE ]');
					///  Then continue, so we can store a new value...
				}
			}

			///  If we got here then it means we need to get and store a new value
			
			///  Start out by getting an SVN Log...
			$clog = $this->get_log($file);

			///  Match
			$matches = preg_match_all('/---------+\nr(\d+)\s*\|\s*([^\s\|]+)\s*\|.+?(?=---------+|$)/s', $clog, $m, PREG_PATTERN_ORDER);
			if ( ! empty( $matches ) ) {

				///  Set it from the regex
				$this->repo_cache[$cache_key][$file]['revisions']  = empty( $m ) ? array() : $m[1];
				$this->repo_cache[$cache_key][$file]['committers'] = empty( $m ) ? array() : $m[2];

				/// Store it in the database if we can.  (the only reason we couldn't would be an uninitialize Repo or an error)
				if ( $this->expire_token() !== false ) {
					$rv = dbh_do_bind("REPLACE INTO revision_cache ( file,expire_token,revisions,committers ) VALUES (?,?,?,?)",
									  $file,
									  $this->expire_token(), 
									  join(',', $this->repo_cache[$cache_key][$file]['revisions']  ),
									  join(',', $this->repo_cache[$cache_key][$file]['committers'] )
									  );
				}
			}
			else {
				if ( $_SERVER['REMOTE_ADDR'] == '10.1.2.116' ) 
					bug("Invalid log output (did not match):", $file, $clog);
			}

		}
        return( array( $this->repo_cache[$cache_key][$file]['revisions'], $this->repo_cache[$cache_key][$file]['committers'] ) );
    }

    public function get_log_entry( $clog, $rev ) {
        preg_match('/---------+\nr\Q'. $rev .'\E\s*\|.+?(?=---------+|$)/s', $clog, $m);
        return $m[0];
    }

	public function expire_token() {

		///  If we haven't checked the expire token, check now
		if ( ! $this->checked_revision_cache_expire_token ) {
			///  Regardless, NOW, we've checked...
			$this->checked_revision_cache_expire_token = true;

			/// Quickest way to read the SVN address for the repo we are on (6th line of the entries file)...
            START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
			$svn_path = $this->get_repository_root();
			$cmd_prefix = $this->stage->config('repo_cmd_prefix');
			$svn_info = shell_exec("${cmd_prefix}svn info \"$svn_path\" 2>&1 | cat");
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
        global $REPO_CMD_PREFIX;

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
				$cmd_prefix = $this->stage->config('repo_cmd_prefix');
                $this->repo_cache['dir_status'][$cache_key] = `${cmd_prefix}svn -v status "$dir" 2>&1 | cat`;
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
            $this->log_repo_action($head_update_cmd, 'entire_repo', $user);
            $command_output .= shell_exec($this->stage->config('repo_cmd_prefix') ."$head_update_cmd 2>&1 | cat -");
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

                    $indiv_update_cmd = "svn update -r$up_rev ". escapeshellcmd($up_file);
                    START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
                    $this->log_repo_action($indiv_update_cmd, 'entire_repo', $user);
                    $command_output .= shell_exec($this->stage->config('repo_cmd_prefix') ."$indiv_update_cmd 2>&1 | cat -");
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
      
                        $indiv_update_cmd = "svn update -r$up_rev ". escapeshellcmd($up_file);
                        START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
                        $this->log_repo_action($indiv_update_cmd, 'entire_repo', $user);
                        $command_output .= shell_exec($this->stage->config('repo_cmd_prefix') ."$indiv_update_cmd 2>&1 | cat -");
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
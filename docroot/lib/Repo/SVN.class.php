<?php

/**
 * SVN Spefic hooks for Ansible Stage
 */
class Ansible__Repo__SVN extends Ansible__Repo {
    public $display_name = 'SVN';
    public $command_name = 'svn';

    #########################
    ###  Action Methods

    public function updateAction($project, $tag) {
        global $REPO_CMD_PREFIX;

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
                if ( is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
                     ###  Skip this file if in TARGET MODE and it's on the list
                     || ( $target_mode_update && array_key_exists( $file, $file_tags) )
                     ) continue;
                $mass_head_update_files[] = $file;
            }

            ###  Get Target Mode files
            if ( $target_mode_update ) {
                foreach ( $project->get_affected_files() as $file ) {
                    if ( is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
                         ) continue;
                    if ( ! empty( $file_tags[ $file ] ) && abs( floor( $file_tags[ $file ] ) ) == $file_tags[ $file ] ) 
                        $individual_file_rev_updates[] = array( $file, $file_tags[ $file ] );
                }
            }
        }
        ###  All other tags, do individual file updates
        else {
            foreach ( $project->get_affected_files() as $file ) {
                if ( is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
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
                            && ! is_dir( dirname( $_SERVER['PROJECT_REPO_BASE'] ."/$dir_test" ) )
                            && $_SERVER['PROJECT_REPO_BASE'] != dirname( $_SERVER['PROJECT_REPO_BASE'] ."/$dir_test" )
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
                    list($head_rev, $error) = $this->get_head_rev( $file );
                    if ( empty( $error ) ) {
                        $rev_before_head = $head_rev - 1;
                        $individual_file_rev_updates[] = array( $file, $rev_before_head );
                    }
                }
            }
        }

        ###  Run the MASS HEAD update (if any)
        if ( ! empty($mass_head_update_files) ) {
            $head_update_cmd = "svn update ";
            foreach ( $mass_head_update_files as $file ) $head_update_cmd .= ' '. escapeshellcmd($file);
            if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
            $this->log_repo_action($head_update_cmd);
            $command_output .= shell_exec("$REPO_CMD_PREFIX$head_update_cmd 2>&1 | cat -");
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
            $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $head_update_cmd;
        }

        ###  File tag update
        if ( ! empty($individual_file_rev_updates) ) {
            foreach ( $individual_file_rev_updates as $update ) {
                list($file, $rev) = $update;

                $indiv_update_cmd = "svn update -r$rev ". escapeshellcmd($file);
                if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
                $this->log_repo_action($indiv_update_cmd);
                $command_output .= shell_exec("$REPO_CMD_PREFIX$indiv_update_cmd 2>&1 | cat -");
                if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
                $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $indiv_update_cmd;
            }
        }

        if ( empty( $command_output ) ) $command_output = '</xmp><i>No output</i>';

        return( array($cmd, $command_output) );
    }

    public function tagAction($project, $tag) {
        global $REPO_CMD_PREFIX;

        ###  Look and update tags
        foreach ( $project->get_affected_files() as $file ) {
            ###  Make sure this file exists
            if ( file_exists($_SERVER['PROJECT_REPO_BASE'] ."/$file")
                 && ! is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
                 && preg_match('/^\w?\s*\d+\s+(\d+)\s/', $this->get_status($file), $m) 
                 ) {
                $cur_rev = $m[1];

                ###  See what the tag was before...
                $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
                $old_rev = $sth->fetch(PDO::FETCH_NUM);
                $sth->closeCursor();
            
                ###  Update the Tag DB for this file...
                $rv = dbh_do_bind("DELETE FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
                $rv = dbh_do_bind("INSERT INTO file_tag ( file,tag,revision ) VALUES (?,?,?)", $file, $tag, $cur_rev);

                ###  Add to Command output whether we really changed the tag or not
                if ( ! empty( $old_rev ) && $old_rev[0] != $cur_rev ) {
                    $command_output .= "Moved $tag on $file from ". $old_rev[0] . " to $cur_rev\n";
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
                    $command_output .= "Rmoved $tag on $file\n";
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
        global $REPO_CMD_PREFIX;
    
        ###  If not cached, get it and cache
        if ( ! $this->repo_cache['log'][$file] ) {
            $parent_dir = dirname($file);
            if ( is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$parent_dir") ) {
                if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
    
    
                
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
                $this->repo_cache['log'][$file] = `${REPO_CMD_PREFIX}svn log $limit_arg -r HEAD:1 "$file" 2>&1 | cat`;
                if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
            }
            else {
                $this->repo_cache['log'][$file] = "svn [status aborted]: no such directory `$parent_dir'";
            }
        }
    
        return $this->repo_cache['log'][$file];
    }
    
    public function cache_logs( $files ) {
        global $REPO_CMD_PREFIX, $MAX_BATCH_SIZE, $MAX_BATCH_STRING_SIZE;
    
        $cache_key = 'log';
    
        ###  Batch and run the command
        while ( count($files) > 0 ) {
            $round = array();
            $round_str = '';
            while ( $files && $round < $MAX_BATCH_SIZE && strlen($round_str) < $MAX_BATCH_STRING_SIZE ) {
                $file = array_shift( $files );
    
                ###  Skip ones whos parent dir ! exists
                $parent_dir = dirname($file);
                if ( ! is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$parent_dir") ) continue;
    
                array_push( $round, $file );
                $round_str .= " \"$file\"";
            }
    
            $round_checkoff = array_flip($round);
            if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
            $all_entries = `${REPO_CMD_PREFIX}svn log $round_str 2>&1 | cat`;
    #        bug substr($all_entries, -200);
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
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
        global $REPO_CMD_PREFIX;
    
        ###  If not cached, get it and cache
        if ( ! $this->repo_cache['status'][$file] ) {
            $parent_dir = dirname($file);
            if ( is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$parent_dir") ) {
                if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
                $this->repo_cache['status'][$file] = `${REPO_CMD_PREFIX}svn -v status "$file" 2>&1 | cat`;
                if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
            }
            else {
                $this->repo_cache['status'][$file] = "svn [status aborted]: no such directory `$parent_dir'";;
            }
        }
    
        return $this->repo_cache['status'][$file];
    }
    
    public function cache_statuses( $files ) {
        global $REPO_CMD_PREFIX, $MAX_BATCH_SIZE, $MAX_BATCH_STRING_SIZE;
    
        $cache_key = 'status';
    
        ###  Batch and run the command
        while ( count($files) > 0 ) {
            $round = array();
            $round_str = '';
            while ( $files && $round < $MAX_BATCH_SIZE && strlen($round_str) < $MAX_BATCH_STRING_SIZE ) {
                $file = array_shift( $files );
    
                ###  Skip ones whos parent dir ! exists
                $parent_dir = dirname($file);
                if ( ! is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$parent_dir") ) continue;
    
                array_push( $round, $file );
                $round_str .= " \"$file\"";
            }
    
            $round_checkoff = array_flip( $round );
            if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
            $all_entries = `${REPO_CMD_PREFIX}svn status $round_str 2>&1 | cat`;
    #        bug substr($all_entries, -200);
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
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
        $revs = array();  foreach ( range( ($from+1), $to ) as $_ )  { $entry = $this->get_log_entry( $this->get_log($file), $_);  if ( ! empty($entry) ) $revs[] = $_; }

        return $revs;
    }

    public function get_head_rev( $file ) {
        $clog = $this->get_log($file);
        
        $head_rev = null;  $error = '';
        if ( preg_match('/^-------+\nr(\d+)\s/m', $clog, $m) ) {
            $head_rev = $m[1];
        } else if ( preg_match('/is not under version control|no such directory/', $clog, $m) ) {
            $error = "Not in $this->display_name";
        } else {
            $error = "malformed $this->command_name log";
        }
        
        return( array( $head_rev, $error) );
    }

    public function get_current_rev( $file ) {
        $cstat = $this->get_status($file);
        
        $cur_rev = null;  $error = '';  $status = '';  $state_code = '';  $is_modified = false;
        if ( preg_match('/^(\w?)\s*\d+\s+(\d+)\s/', $cstat, $m) ) {
            $letter = $m[1];
            if ( empty($letter) ) $letter = '';
            $letter_trans = array( '' => 'Up-to-date', 'M' => 'Locally Modified', 'A' => 'To-be-added' );
            $status = ( isset( $letter_trans[ $letter ] ) ? $letter_trans[ $letter ] : 'Other: "'. $letter .'"' );
            if ( preg_match('/^\w?\s*\d+\s+(\d+)\s/', $cstat, $m) ) {
                $cur_rev = $m[1];
            } else {
                $error = "malformed $repo->command_name status";
                $error_code = 'malformed';
                $is_modified = true;
            }
            //  States (Should be ding this by letter.... TODO)
            if      ( $status == 'Locally Modified' )            $state_code = 'locally_modified';
            else if ( $status == 'Needs Merge' )                 $state_code = 'needs_merge';
            else if ( $status == 'File had conflicts on merge' ) $state_code = 'conflict';
        } else {
            $error = "malformed cvs status";
            $error_code = 'malformed';
            $is_modified = true;
        }
        
        return( array( $cur_rev, $error, $status, $state_code, $is_modified ) );
    }

    public function get_rev_committer( $file, $rev ) {
        $entry = $this->get_log_entry( $this->get_log($file), $rev);

        if ( ! empty( $entry ) && preg_match('/^r\d+\s*\|\s*([^\|]+)\s*\|\s*\d{4}/m', $entry, $m) )
            return $m[1];
        return null;
    }

    public function get_prev_rev( $file, $rev ) {

        $clog = $this->get_log($file);

        ///  Get a list of ALL entries...
        $entries = array();
        list($head_rev, $err) = $this->get_head_rev($file);
        list($first_rev, $err) = $this->get_first_rev($file);
        if ( ! $head_rev || ! $first_rev ) return null;
        foreach ( $this->get_revs_in_diff($file, $first_rev, $head_rev) as $_ ) {
            $entry = $this->get_log_entry( $clog, $_ );
            if ( ! empty($entry) ) $entries[] = array($_, $entry);
        } 

        ///  Loop through (Low to high)
        $prev_rev = null;
        foreach ( $entries as $e ) {
            if ( $e[0] >= $rev ) return $prev_rev;
            $prev_rev = $e[0];
        }
        return $prev_rev;
    }

    public function get_first_rev( $file ) {
        $clog = $this->get_log($file);
        
        $first_rev = null;  $error = '';
        if ( preg_match_all('/^-------+\nr(\d+)\s/m', $clog, $m, PREG_SET_ORDER) ) {
            $last_match = array_pop( $m );
            $first_rev = $last_match[1];
        } else if ( preg_match('/is not under version control|no such directory/', $clog, $m) ) {
            $error = "Not in $this->display_name";
        } else {
            $error = "malformed $this->command_name log";
        }
        
        return( array( $first_rev, $error) );
    }

    public function get_log_entry( $clog, $rev ) {
        preg_match('/---------+\nr\Q'. $rev .'\E\s*\|.+?(?=---------+|$)/s', $clog, $m);
        return $m[0];
    }

}
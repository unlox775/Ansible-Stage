<?php

/**
 * CVS Spefic hooks for Ansible Stage
 */
class Ansible__Repo__CVS extends Ansible__Repo {
    public $display_name = 'CVS';
    public $command_name = 'cvs';

    #########################
    ###  Action Methods

    public function updateAction($project, $tag, $user) {
        global $REPO_CMD_PREFIX;


        ###  Target mode
        $do_file_tag_update = false;
        $file_tags;
        if ( $tag == 'Target' ) {
            $do_file_tag_update = true;
            $tag = 'HEAD';
            ###  Read in the file tags CSV
            $file_tags = $project->get_file_tags();
        }

        ###  Prepare Update/Checkouts (to Tag, Head or specific revision)
        $update_cmd =   array_merge( array('update', '-r' ), array( $tag ) );
        $checkout_cmd = array_merge( array('co',     '-r' ), array( $tag ) );
        if ( $tag == 'HEAD' ) {
            $update_cmd =    array('update', '-PAd' );
            $checkout_cmd =  array('co',     '-rHEAD' );
        }
        $tag_files = array();
        list( $do_update, $do_checkout ) = array(false,false);
        foreach ( $project->get_affected_files() as $file ) {
            $parent_dir = dirname($file);
            ###  Remove files with specific tags from the main update
            if ( $do_file_tag_update && $file_tags[$file] ) {
                array_push( $tag_files, $file );
                continue;
            }
            ###  Do files that don't exist in a "checkout" batch command
            else if ( ! is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$parent_dir") ) {
                $my_file = $file;
                if ( ! preg_match('/^project/', $file, $m) ) $my_file = "project/$file";
                array_push( $checkout_cmd, '"'. $my_file .'"' );
                if ( empty($do_checkout) ) $do_checkout = true;
                continue;
            }
            ###  Normal batch update of existing files
            array_push( $update_cmd, '"'. $file .'"' );
            if ( empty($do_update) ) $do_update = true;
        }

        ###  Run the UPDATE command (if any)
        $cmd = '';
        $command_output = '';
        if ( $do_update ) {
            $update_cmd = "cvs ". join(' ', $update_cmd);
            if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
            $this->log_repo_action($update_cmd, $project, $user);
            $command_output .= `$REPO_CMD_PREFIX$update_cmd 2>&1 | cat -`;
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
            $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $update_cmd;
        }

        ###  Run the CHECKOUT command (if any)
        if ( $do_checkout ) {
            $checkout_cmd = "cvs ". join(' ', $checkout_cmd);
            if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
            $this->log_repo_action($checkout_cmd, $project, $user);
            $command_output .= `$REPO_CMD_PREFIX_4CO$checkout_cmd 2>&1 | cat -`;
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
            $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $checkout_cmd;
        }

        ###  File tag update
        if ( $do_file_tag_update ) {
            foreach ( $tag_files as $file ) {
                $tag_cmd = array_merge( array('update', '-r' ), array($file_tags[$file], '"'. $file .'"') );
                $tag_cmd = "cvs ". join(' ', $tag_cmd);
                if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
                $this->log_repo_action($tag_cmd, $project, $user);
                $command_output .= "\n--\n". `$REPO_CMD_PREFIX$tag_cmd 2>&1 | cat -`;
                if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
                $cmd .= ( strlen($cmd) ? ' ; ' : ''). $tag_cmd;
            }
        }



        return( array($cmd, $command_output) );
    }

    public function tagAction($project, $tag, $user) {
        global $REPO_CMD_PREFIX;



        ###  Prepare Update/Remove_Tags (to Tag, Head or specific revision)
        $tag_cmd =   array_merge( array('tag', '-F'), array($tag) );
        $remove_tag_cmd = array_merge( array('tag', '-d'), array($tag) );
        list( $do_tag, $do_remove_tag ) = array(false,false);
        foreach ( $project->get_affected_files() as $file ) {
            $parent_dir = dirname($file);
            ###  Do files that don't exist in a "remove_tag" batch command
            if ( ! is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$parent_dir") || ! is_file($_SERVER['PROJECT_REPO_BASE'] ."/$file") ) {
                array_push( $remove_tag_cmd, '"'. $file .'"' );
                if ( empty($do_remove_tag) ) $do_remove_tag = true;
                continue;
            }
            ###  Normal batch tag of existing files
            else {
                array_push( $tag_cmd, '"'. $file .'"' );
                if ( empty($do_tag) ) $do_tag = true;
            }
        }

        ###  Run the TAG command (if any)
        $cmd = '';
        $command_output = '';
        if ( $do_tag ) {
            $tag_cmd = "cvs ". join(' ', $tag_cmd);
            if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
            $this->log_repo_action($tag_cmd, $project, $user);
            $command_output .= `$REPO_CMD_PREFIX$tag_cmd 2>&1 | cat -`;
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
            $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $tag_cmd;
        }

        ###  Run the REMOVE_TAG command (if any)
        if ( $do_remove_tag ) {
            $remove_tag_cmd = "cvs ". join(' ', $remove_tag_cmd);
            if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
            $this->log_repo_action($remove_tag_cmd, $project, $user);
            $command_output .= `$REPO_CMD_PREFIX$remove_tag_cmd 2>&1 | cat -`;
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
            $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $remove_tag_cmd;
        }


        return( array($cmd, $command_output) );
    }


    #########################
    ###  CVS file Log and Status caching (for speed)
    
    public function get_log( $file ) {
        global $REPO_CMD_PREFIX;
    
        ###  If not cached, get it and cache
        if ( ! $this->repo_cache['log'][$file] ) {
            $parent_dir = dirname($file);
            if ( is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$parent_dir") ) {
                if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
                $this->repo_cache['log'][$file] = `${REPO_CMD_PREFIX}cvs log "$file" 2>&1 | cat`;
                if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
            }
            else {
                $this->repo_cache['log'][$file] = "cvs [status aborted]: no such directory `$parent_dir'";
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
            $all_entries = `${REPO_CMD_PREFIX}cvs log $round_str 2>&1 | cat`;
    #        bug substr($all_entries, -200);
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
            foreach ( preg_split('@===================================================================+\n@', $all_entries) as $entry ) {
                if ( preg_match('/^\s*$/s', $entry, $m) ) continue;
    
                ###  Get the filename
                $file;
                if ( preg_match('@^\s*RCS file: /sandbox/cvsroot/(?:project/)?(.+?),v\n@', $entry, $m ) ) {
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
                $this->repo_cache['status'][$file] = `${REPO_CMD_PREFIX}cvs status "$file" 2>&1 | cat`;
                if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
            }
            else {
                $this->repo_cache['status'][$file] = "cvs [status aborted]: no such directory `$parent_dir'";;
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
            $all_entries = `${REPO_CMD_PREFIX}cvs status $round_str 2>&1 | cat`;
    #        bug substr($all_entries, -200);
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');
            foreach ( preg_split('@===================================================================+\n@', $all_entries) as $entry ) {
                if ( preg_match('/^\s*$/s', $entry, $m) ) continue;
    
                ###  Get the filename
                if ( preg_match('@Repository revision:\s*[\d\.]+\s*/sandbox/cvsroot/(?:project/)?(.+?),v\n@', $entry, $m) ) {
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

    public function get_revs_in_diff( $from, $to ) {
        if ( preg_match('/[^\d\.]/', $from, $m) || preg_match('/[^\d\.]/', $to, $m) ) return;
        if ( $from == $to ) return;

        $revs = array();

        ###  Determine revisions between
        preg_match('/^([\d\.]+?)(\d+)$/', $from, $m);  list($x, $f_trunk, $f_rev) = $m;
        preg_match('/^([\d\.]+?)(\d+)$/', $to, $m);     list($x, $t_trunk, $t_rev) = $m;
        ###  If along the same trunk it's easy
        if ( $f_trunk == $t_trunk ) {
            if ( $f_rev >= $t_rev ) return;
            $revs = array();  foreach ( range( ($f_rev+1), $t_rev ) as $_ )  { $revs[] =  "$f_trunk$_" ; }
        }
        ###  If moving to a branch from non-branch, check ...
        else if ( preg_match('/^\d+\.$/', $f_trunk, $m)           && preg_match('/^\d+\.\d+\.\d+\.$/', $t_trunk, $m) ) {
            preg_match('/^$f_trunk(\d+)\.\d+\.$/', $t_trunk, $m);  list( $x, $t_branch_start_rev ) = $m;
            if ( ! $t_branch_start_rev ) trigger_error("Should not happen.  OUCH!", E_USER_ERROR);

            ###  If branch is a leap back, just show the branch revs
            if ( $t_branch_start_rev <= $f_rev ) {
                if ( $t_rev < 1 ) return;
                $revs = array();  foreach ( range( 1, $t_rev ) as $_ )  { $revs[] =  "$t_trunk$_" ; }
            }
            ###  Otherwise, show all the trunk revs up to the 
            ###    branch, and then then branch revs
            else {
                if ( $t_rev < 1 ) return;
                $revs = array();
                foreach ( range( ($f_rev+1), $t_branch_start_rev ) as $_ ) { $revs[] = "$f_trunk$_"; }
                foreach ( range( 1, $t_rev ) as $_ )                       { $revs[] = "$t_trunk$_"; }
            }
        }
        ###  If moving back to a non-branch from a branch
        else if ( preg_match('/^\d+\.\d+\.\d+\.$/', $f_trunk, $m) && preg_match('/^\d+\.$/', $t_trunk, $m)           ) {
            preg_match('/^$t_trunk(\d+)\.\d+\.$/', $f_trunk, $m);  list( $x, $f_branch_start_rev ) = $m;
            if ( $f_branch_start_rev ) trigger_error("Should not happen.  OUCH!", E_USER_ERROR);
            if ( $f_branch_start_rev >= $t_rev ) return;
            $revs = array();  foreach ( range( ($f_branch_start_rev+1), $t_rev ) as $_ )  { $revs[] =  "$t_trunk$_" ; }
        }
        ###  Moving from o!= branch to another (rare)
        ###    just jump back to the head of the from-branch
        ###    and run self as if moving from that trunk rev
        else if ( preg_match('/^\d+\.\d+\.\d+\.$/', $f_trunk, $m) && preg_match('/^\d+\.\d+\.\d+\.$/', $t_trunk, $m) ) {
            preg_match('/^(\d+\.\d+)\.\d+\.$/', $f_trunk, $m);  list( $x, $f_branch_start ) = $m;
            if ( ! $f_branch_start ) trigger_error("Should not happen.  OUCH!", E_USER_ERROR);
            return $this->get_revs_in_diff($f_branch_start, $to);
        }
        ###  Else, DIE!
        else { return trigger_error("What the heck are ya!? ($from, $to)", E_USER_ERROR); }

        return $revs;
    }

    public function get_head_rev( $file ) {
        $clog = $this->get_log($file);
        
        $head_rev = null;  $error = '';  $error_code = '';
        if ( preg_match('/^head:\s*(\S+)/m', $clog, $m) ) {
            $head_rev = $m[1];
        } else if ( preg_match('/nothing known about|no such directory/', $clog, $m) ) {
            $error = "Not in CVS";
            $error_code = 'not_exists';
        } else {
            $error = "malformed cvs log";
            $error_code = 'malformed';
        }
        
        return( array( $head_rev, $error, $error_code) );
    }

    public function get_current_rev( $file ) {
        $cstat = $this->get_status($file);
        
        $cur_rev = null;  $error = '';  $status = '';  $state_code = '';  $is_modified = false;
        if ( preg_match('/Status:\s*([^\n]+)/', $cstat, $m) ) {
            $status = $m[1];
            if ( preg_match('/Working revision:\s*(\S+)/', $cstat, $m) ) {
                $cur_rev = $m[1];
            } else {
                $error = "malformed cvs status";
                $error_code = 'malformed';
                $is_modified = true;
            }
            //  States
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
        bug($file, $rev, $entry);

        if ( ! empty( $entry ) && preg_match('/author:\s+(\w+);/', $entry, $m) )
            return $m[1];
        return null;
    }

    public function get_prev_rev( $file, $rev ) {
        if ( $rev == '1.1' ) return $rev;

        if ( preg_match('/^(\d+\.\d+)\.\d+\.1$/', $rev, $m) ) {
            return $m[1];
        }
        else if ( preg_match('/^(\d+\.(?:\d+\.\d+\.)?)(\d+)$/', $rev, $m) ) {
            return $m[1].($m[2]-1);
        }
    }

    public function get_first_rev( $file ) {
        /// Simple answer for CVS
        return( array( '1.1', '') );
    }

    public function get_log_entry( $clog, $rev ) {
        preg_match('/---------+\n(revision \Q'. $rev .'\E\n.+?)(?:\n=========+$|\n---------+\nrevision )/s', $clog, $m);
        return $m[0];
    }


    ###########################
    ###   CVS-based tag storage

    ###  Overriding the default Database-based tag storage
    
    public function get_tag_rev($file, $tag) {
        $clog = $this->get_log($file);

        if ( preg_match('/^\t\Q'. $tag .'\E:\s*(\S+)/m', $clog, $m) )
            return $m[1];
        return null;
    }


}
<?php

class Ansible__actions extends Stark__Controller__Base {
    protected $extra_functions = array();

#	public function directory_handler($ctl, $path) {
#
#		///  All dir handlers must return true or it will give a 403: Forbidden
#		return true;
#	}

    public function __construct($ctl) {
        $extra_functions = array();
        require($ctl->stage->extend->run_hook('actions_controller', -50));

        foreach ( $extra_functions as $code ) {
            if ( ! is_array( $code ) || ! isset( $code[0] ) || ! isset( $code[1] ) )
                trigger_error("Extension defined an Invalid extra function (should be an array with first index as the action name, and second index as the function code): ". var_export($code), E_USER_ERROR );
            $this->extra_functions[ $code[0] ] = create_function( '$ctl', $code[1] );
        }
    }

    public function __call( $name, $args ) {
        if ( ! empty( $this->extra_functions[ $name ] ) ) {
            return call_user_func_array( $this->extra_functions[ $name ], $args );
        }
        else {
            trigger_error('Call to undefined method '. get_class($this) .'::'. $name .'() in '. trace_blame_line(array('__call')), E_USER_ERROR);
        }
    }
    public function real_method_exists($name) { return( method_exists($this, $name) || ! empty( $this->extra_functions[ $name ] ) ); }


	########################
	###  Project Management Actions

	public function archive_project_page ($ctl) {
		if ( ! empty( $_REQUEST['p'] ) ) {
			if ( is_array( $_REQUEST['p'] ) || preg_match('/[^\w\_\-]/', $_REQUEST['p']) ) 
				return trigger_error("Please don't hack...", E_USER_ERROR);
			$project = new Ansible__Project( $_REQUEST['p'], $ctl->stage );
			if ( $project->exists() && ! $project->archived() ) {
				$user = ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous';
				$project->archive($user);
			}
		}
		$ctl->redirect('../list.php');
		exit;
	}

	public function unarchive_project_page ($ctl) {
		if ( ! empty( $_REQUEST['p'] ) ) {
			if ( is_array( $_REQUEST['p'] ) || preg_match('/[^\w\_\-]/', $_REQUEST['p']) ) 
				return trigger_error("Please don't hack...", E_USER_ERROR);
			$project = new Ansible__Project( $_REQUEST['p'], $ctl->stage, true );
			if ( $project->exists() && $project->archived() ) {
				$user = ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous';
				$project->unarchive($user);
			}
		}
		$ctl->redirect('../list.php?cat=archived');
		exit;
	}


	########################
	###  File Operation Actions

	public function update_page($ctl) {
		/* HOOK */$__x = $ctl->stage->extend->x('update_action', 0); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh();if($__x->hr()) return $__x->get_return();
		if ( $ctl->stage->read_only_mode() ) return trigger_error("Permission Denied", E_USER_ERROR);
		if ( empty( $_REQUEST['p'] ) ) return trigger_error("Missing project_name", E_USER_ERROR);
		if ( empty( $_REQUEST['tag']   ) ) return trigger_error("Missing tag", E_USER_ERROR);
		if ( preg_match('/[^\w\_\-\.]/', $_REQUEST['tag'], $m) 
			 || ( ! empty( $_REQUEST['set_group']  ) && preg_match('/\W/', $_REQUEST['set_group']) )
			 ) return trigger_error("Please don't hack...", E_USER_ERROR);

		$projects = $ctl->stage->get_projects_from_param($_REQUEST['p']);

		###  Set Group..
		if ( ! empty( $_REQUEST['set_group'] ) ) {
			foreach ( $projects as $project )
				$project->set_group($_REQUEST['set_group']);
		}

		/* HOOK */$__x = $ctl->stage->extend->x('update_action', 5); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh();

		###  Run the action
		list( $cmd, $command_output ) = $ctl->stage->repo()->updateAction( $projects, $_REQUEST['tag'], ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous' );

		/* HOOK */$__x = $ctl->stage->extend->x('update_action', 10); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh();if($__x->hr()) return $__x->get_return();

		return $this->generic_cmd_action_return($ctl, 'project.php', $projects, $cmd, $command_output);
	}

	public function generic_cmd_action_return($ctl, $page, $projects, $cmd, $command_output) {
        require($ctl->stage->extend->run_hook('command_output', -20));
		$bounce_url = ( "../". $page ."?pid=". getmypid()
						. (! empty( $projects ) ? '&'.$ctl->stage->get_projects_url($projects) : '' )
						."&cmd=". urlencode(base64_encode(gzdeflate($cmd, 9)))
						."&command_output=". urlencode(base64_encode(gzdeflate($command_output, 9)))
						);
        require($ctl->stage->extend->run_hook('command_output', -25));
		###  If the Bounce URL is too long for HTTP protocol maximum then just echo out the stuff...
		if ( 1 || strlen( $bounce_url ) > 2000 ) {
			return( array( 'cmd' => $cmd,
						   'command_output' => $command_output,
						   ) );
		}
		###  Else, just bounce
		else {
			$ctl->redirect( $bounce_url );
			exit;
		}

	}

	public function tag_page($ctl) {
		if ( $ctl->stage->read_only_mode() ) return trigger_error("Permission Denied", E_USER_ERROR);
		if ( empty( $_REQUEST['p'] ) ) return trigger_error("Missing project_name", E_USER_ERROR);
		if ( empty( $_REQUEST['tag']   ) ) return trigger_error("Missing tag", E_USER_ERROR);
		if ( preg_match('/[^\w\_\-\.]/', $_REQUEST['tag'], $m) 
			 || ( ! empty( $_REQUEST['set_group']  ) && preg_match('/\W/', $_REQUEST['set_group']) )
			 ) return trigger_error("Please don't hack...", E_USER_ERROR);

		$projects = $ctl->stage->get_projects_from_param($_REQUEST['p']);

		###  Set Group..
		if ( ! empty( $_REQUEST['set_group'] ) ) {
			foreach ( $projects as $project )
				$project->set_group($_REQUEST['set_group']);
		}
    
		###  Run the action
		list( $cmd, $command_output ) = $ctl->stage->repo()->tagAction( $projects, $_REQUEST['tag'], ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous' );

		return $this->generic_cmd_action_return($ctl, 'project.php', $projects, $cmd, $command_output);
	}

	public function full_log_page($ctl) {
		$file     = $_REQUEST['file'];
		if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) ) 
			return trigger_error("Please don't hack...", E_USER_ERROR);

		###  Get the partial log
		$clog = $ctl->stage->repo()->get_log($file);
		$this->full_log_page_tmp = array($file, undef, '<xmp>', "</xmp>");
		$this->ctl = $ctl;
		$clog = preg_replace_callback('/(\n(r([\d]+)[^\n]+\n))/s',array( $this, 'full_log_page_preplace_callback'),$clog);

		return( array( 'clog' => $clog,
					   'file' => $file,
					   'command_name' => $ctl->stage->repo()->command_name,
					   ) );
	}
	function full_log_page_preplace_callback($m) {
		list( $file, $project_name, $s_esc, $e_esc ) = $this->full_log_page_tmp;
		return $this->revision_link($file, $m[3], $m[2], $project_name, $s_esc, $e_esc, $m[1]);
	}

	function revision_link( $file, $rev, $str, $project_name, $s_esc, $e_esc, $whole_match) {
		list($first_rev, $err) = $this->ctl->stage->repo()->get_first_rev($file);
		
		if ( $first_rev && $rev == $first_rev ) return $whole_match;
		if ( empty($s_esc) ) $s_esc = '';
		if ( empty($e_esc) ) $e_esc = '';
		
		$tag = "$e_esc<a href=\"". $this->ctl->stage->url_prefix ."/actions/diff.php?from_rev=". $this->ctl->stage->repo()->get_prev_rev($file, $rev) ."&to_rev=". $rev ."&file=". urlencode($file) ."\">$s_esc";
		return $tag . $str ."$e_esc</a>$s_esc";
	}
	
	function diff_page($ctl) {
		$file     = $_REQUEST['file'];
		$from_rev = $_REQUEST['from_rev'];
		$to_rev   = $_REQUEST['to_rev'];
		if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) || preg_match('/[^\d\.]+/', $from_rev, $m) || ! preg_match('/^([\d\.]+|local)$/', $to_rev, $m) ) 
			return trigger_error("Please don't hack...", E_USER_ERROR);


		###  Get the partial diff
		$to_rev_clause = ($to_rev == 'local' ? "" : "-r $to_rev");
		if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
		$revision_arg = ($to_rev == 'local') ? "-r$from_rev" : "-r$from_rev:$to_rev";
		$cmd_prefix = $ctl->stage->config('repo_cmd_prefix');
		$cmd_name = $ctl->stage->repo()->command_name;
		$cdiff = `${cmd_prefix}$cmd_name diff $revision_arg "$file" 2>&1 | cat`;
		if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');


		return( array( 'cdiff' => $cdiff,
					   'from_rev' => $from_rev,
					   'to_rev' => $to_rev,
					   'file' => $file,
					   'command_name' => $ctl->stage->repo()->command_name,
					   ) );
	}

	function part_log_page($ctl) {
		$file     = $_REQUEST['file'];
		$from_rev = $_REQUEST['from_rev'];
		$to_rev   = $_REQUEST['to_rev'];
		if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) || preg_match('/[^\d\.]+/', $from_rev, $m) || preg_match('/[^\d\.]+/', $to_rev, $m) ) 
			return trigger_error("Please don't hack...", E_USER_ERROR);


		#    ###  TESTING
#    bug [$repo->get_revs_in_diff($file, qw(1.15 1.17))];
#    bug [$repo->get_revs_in_diff($file, qw(1.17 1.15))];
#    bug [$repo->get_revs_in_diff($file, qw(1.15 1.12.2.12))];
#    bug [$repo->get_revs_in_diff($file, qw(1.15 1.17.2.12))];
#    bug [$repo->get_revs_in_diff($file, qw(1.12.2.12 1.16))];
#    bug [$repo->get_revs_in_diff($file, qw(1.12.2.12 1.10))];
#    bug [$repo->get_revs_in_diff($file, qw(1.12.2.12 1.10.11.17))];
#    bug [$repo->get_revs_in_diff($file, qw(1.10.2.12 1.12.11.17))];

		###  Get the partial log
		$clog = $ctl->stage->repo()->get_log($file);
		$entries = array();
		foreach ( array_reverse( $ctl->stage->repo()->get_revs_in_diff($file, $from_rev, $to_rev) ) as $_ ) {
			$entries[] = array($_, $ctl->stage->repo()->get_log_entry( $clog, $_ ));
		}

		###  Turn the revision labels into links
		$this->ctl = $ctl;
		foreach ( array_keys( $entries ) as $i ) {
			$this->part_log_page_tmp = array($file, $entries[$i][0], undef, '<xmp>', '</xmp>');
			$entries[$i][1] = preg_replace_callback('/(\n(r([\d]+)[^\n]+\n))/', array($this, 'part_log_page_preplace_callback'), $entries[$i][1]);
		}

		$tmp = array();  foreach ( $entries as $entry ) $tmp[] = $entry[1];

		return( array( 'clog' => join("\n----------------------------", $tmp),
					   'from_rev' => $from_rev,
					   'to_rev' => $to_rev,
					   'file' => $file,
					   'command_name' => $ctl->stage->repo()->command_name,
					   ) );
	}
	function part_log_page_preplace_callback($m) {
		list( $file, $rev, $project_name, $s_esc, $e_esc ) = $this->part_log_page_tmp;
		return $this->revision_link($file, $rev, $m[2], $project_name, $s_esc, $e_esc, $m[1]);
	}


	########################
	###  Repository Admin Actions

	public function entire_repo_tag_page($ctl) {
		if ( $ctl->stage->read_only_mode() ) return trigger_error("Permission Denied", E_USER_ERROR);
		if ( empty( $_REQUEST['tag']   ) ) return trigger_error("Missing tag", E_USER_ERROR);
		if ( preg_match('/[^\w\_\-\.]/', $_REQUEST['tag'], $m) ) return trigger_error("Please don't hack...", E_USER_ERROR);

		###  These can take a while...
		set_time_limit( 0 );

		###  Run the action
		list( $cmd, $command_output ) = $ctl->stage->repo()->tagEntireRepoAction( $_REQUEST['tag'], ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous' );

		return $this->generic_cmd_action_return($ctl, 'admin.php', null, $cmd, $command_output);
	}

	public function entire_repo_update_page($ctl) {
		if ( $ctl->stage->read_only_mode() ) return trigger_error("Permission Denied", E_USER_ERROR);
		if ( empty( $_REQUEST['tag']   ) ) return trigger_error("Missing tag", E_USER_ERROR);
		if ( preg_match('/[^\w\_\-\.]/', $_REQUEST['tag'], $m) ) return trigger_error("Please don't hack...", E_USER_ERROR);

		###  These can take a while...
		set_time_limit( 0 );

		###  Run the action
		list( $cmd, $command_output ) = $ctl->stage->repo()->updateEntireRepoAction( $_REQUEST['tag'], ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous' );

		return $this->generic_cmd_action_return($ctl, 'admin.php', null, $cmd, $command_output);
	}

}

<?php

/**
 *  Base Repo Class -
 */
class Ansible__Repo {
    public $repo_cache = array();
    public $display_name = 'Generic';

    ///  Logging Function
    public function log_repo_action( $command, $project, $user ) {
        $project_name = is_string( $project ) ? '**'. $project .'**' : $project->project_name;
        $log_line = join(',', array(time(), getmypid(), date(DATE_RFC822,time()), $user, $project_name, $command)). "\n";
        
        $file = $this->stage->config('safe_base') ."/project_svn_log_". $this->stage->env .".csv";
        file_put_contents($file, $log_line, FILE_APPEND);
    }

	###  Helper action to single-log a file's command for multiple projects
	public function log_projects_action($command, $project_sets, $user) {
		///  If it's not an array of arrays, switch it
		$project_sets = array_keys( $project_sets );
		if ( is_array( $project_sets ) && ! is_array( $project_sets[0] ) )
			$project_sets = array( $project_sets );

		$already_logged = array();
		foreach ($project_sets as $project_set) {
			foreach ( $project_set as $project ) {
				if ( $already_logged[ $project->project_name ] ) continue;
				$this->log_repo_action($cmd, $project, $user);
				$already_logged[ $project->project_name ] = true;
			}
		}
	}

    ###########################
    ###   Database-based tag storage

    ###  These methods will be overridden by versioning systems like CVS that have built in per-file tagging systems
    
    public function get_tag_rev($file, $tag) {
        $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
        $row = $sth->fetch(PDO::FETCH_NUM);
        $sth->closeCursor();

        $tag_rev = null;
        if ( ! empty( $row ) )
            list( $tag_rev ) = $row;
        return $tag_rev;
    }

	public function get_update_revision($file, $tag, $projects) {
		if ( $tag == 'HEAD' ) {
			return 'HEAD';
		} elseif ( $tag == 'Target' ) {
			$rev = null;
			foreach ( $projects as $project ) {
				$tags = $project->get_file_tags();
				if ( //////  OR if this tag is "later" than the stored one
					 ///  If no file tag for this project, and cur iter is set to non-Head rev, set to latest -> HEAD
					 ( empty($tags[$file])   && $rev != 'HEAD' )
					 ///  If the file has a tag, and it is greater (later in time) than the cur iter => File rev
					 ///    Note: if cur iter is already HEAD, can't beat it, so skip
					 || ( ! empty($tags[$file]) && $rev != 'HEAD' && $this->rev_greater_than( $tags[$file], $rev, $file ) )
					 ) $rev = isset( $tags[$file] ) ? $tags[$file] : 'HEAD';
			}
			return ( is_null( $rev ) ? 'HEAD' : $rev );
		}
		else if ( preg_match('/^RP-(\d+)$/',$tag, $m) ) {
			require_once(dirname(__FILE__) .'/model/RollPoint.class.php');

			///  Get the rollback point
			$point = new Ansible__RollPoint($m[1]);
			if ( ! $point->exists() )
				trigger_error("Non-existant Roll Point: ". $tag, E_USER_ERROR);

			///  Check out that this rollout includes all the files from
			///    this rollback point
			if ( ! $point->includes_same_projects($projects) )
				trigger_error("Not all selected projects are in the selected roll point: ". $tag, E_USER_ERROR);

			///  Search for this file in the RollPoint
			$files = array();
			foreach ($point->projects as $project) {
				foreach ( $project->files as $rp_file ) {
					if ( $rp_file->file == $file )
						return $rp_file->revision;
				}
			}
			///  If we couldn't find it, return null = Roll to deleted revision
			///    Note: this should *NEVER* happen, because if the tag is
			///      RP-, then group_projects_by_file() should have limited
			///      the file list to items in the RollPoint.
			return null;
		}
		else {
			///  Read from File Tag table
			$sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
			$rev = $sth->fetch(PDO::FETCH_NUM);
			$sth->closeCursor();
			return empty( $rev ) ? null : $rev[0];
		}
	}

	public function group_projects_by_file($projects, $tag, $source) {
		if ( preg_match('/^RP-(\d+)$/',$tag, $m) && $source == 'roll_point' ) {
			require_once(dirname(__FILE__) .'/model/RollPoint.class.php');

			///  Get the rollback point
			$point = new Ansible__RollPoint($m[1]);
			if ( ! $point->exists() )
				trigger_error("Non-existant Roll Point: ". $tag, E_USER_ERROR);

			$files = array();
			foreach ($point->projects as $project) {
				foreach ( $project->files as $file ) {
					if ( ! isset( $files[ $file->file ] ) ) $files[ $file->file ] = array($file->file, array($project->project_name => $project));
					else                                    $files[ $file->file ][1][                  $project->project_name] = $project;
				}
			}
			return array_values($files);
		} else if ( preg_match('/^RP-(\d+)$/',$tag, $m) && $source == 'project' ) {
			require_once(dirname(__FILE__) .'/model/RollPoint.class.php');

			///  Get the rollback point
			$point = new Ansible__RollPoint($m[1]);
			if ( ! $point->exists() )
				trigger_error("Non-existant Roll Point: ". $tag, E_USER_ERROR);

			///  First, pre-get a searchable list of the RollPoint's files
			$rp_files = array();
			foreach ($point->projects as $project) {
				foreach ( $project->files as $file ) $rp_files[ $file->file ] = true;
			}

			///  Then compile the list from the cross-product of RP and Project
			$files = array();
			foreach ($projects as $project) {
				foreach ( $project->get_affected_files() as $file ) {
					if ( ! isset(  $rp_files[ $file ] ) ) continue; # skip if not in RollPoint
					if ( ! isset( $files[ $file ] ) ) $files[ $file ] = array($file, array($project->project_name => $project));
					else                              $files[ $file ][1][                  $project->project_name] = $project;
				}
			}
			return array_values($files);
		} else {
			$files = array();
			foreach ($projects as $project) {
				foreach ( $project->get_affected_files() as $file ) {
					if ( ! isset( $files[ $file ] ) ) $files[ $file ] = array($file, array($project->project_name => $project));
					else                              $files[ $file ][1][                  $project->project_name] = $project;
				}
			}
			return array_values($files);
		}
	}

	public function parent_doesnt_exist($file) {
		return( ! is_dir( dirname( $this->stage->env()->repo_base ."/$file" ) )
				&& $this->stage->env()->repo_base != dirname( $this->stage->env()->repo_base ."/$file" )
				);
	}

	public function add_parent_dirs($file, $file_projects, $existing = null, $rev) {
		if ( empty( $existing ) ) $existing = array();
		
		$current_child = $file;
		while ( ! empty( $current_child )
				&& $this->parent_doesnt_exist( $current_child )
				) {
			###  So we have confirmation that $current_child's parent is:
			###    1) not existing (needs checkout)
			###    2) Not the root
			$parent = dirname( $current_child );
			if ( ! isset( $existing[$parent] ) ) $existing[ $parent ] = array( $parent, $file_projects, $rev );
			###  Note: array merge works because the projects lists are associative with the project_name as key
			else $existing[ $parent ]                                 = array( $parent, array_merge( $existing[ $parent ][1], $file_projects ),
																			   ( $this->rev_greater_than( $rev, $existing[ $parent ][2], $file )
																				 ? $rev
																				 : $existing[ $parent ][2]
																				 )
																			   );

			$current_child = $dir; // iterate backwards
		}

		return $existing;
	}

	///  Create rollback point optionally
	public function record_roll_point($projects, $tag, $user, $file_source) {
		require_once(dirname(__FILE__) .'/model/RollPoint.class.php');

		///  If they are Rolling to an existing rollpoint, just add a Roll
		if ( preg_match('/^RP-(\d+)$/',$tag, $m) ) {

			///  Get the rollback point
			$point = new Ansible__RollPoint($m[1]);
			if ( ! $point->exists() )
				trigger_error("Non-existant Roll Point: ". $tag, E_USER_ERROR);

			///  Check out that this rollout includes all the files from
			///    this rollback point
			if ( ! $point->includes_same_projects($projects) )
				trigger_error("Not all selected projects are in the selected roll point: ". $tag, E_USER_ERROR);

			///  Create new ROLL entry
			$point_roll = $point->new_roll($user, $this->stage->env);

			///  If we are rolling out to a 'rollout' point then auto-make a Rollback point
			if ( $point->point_type == 'rollout' ) {
				list($tag_cmd, $tag_command_output, $rb_point) = $this->tagAction($projects, 'rollback', $user, false, $file_source);
#				bug('TAGGED rollback!!', $tag_cmd, $tag_command_output);
				$point_roll->set_and_save(array('rollback_rlpt_id' => $rb_point->rlpt_id));
			}
		}
		///  For all other tags, create a new RollPoint 
		else {
			///  Create new RollPoint
			$point = new Ansible__RollPoint();
			$point->create(array( 'point_type' => 'rollout',
								  'created_by' => $user,
								  ));

			$seen_projects = array();
			$seen_files = array();
			$files_by_proj = $this->group_projects_by_file($projects, $tag, $file_source);
			foreach ( $files_by_proj as $item ) {
				list( $file, $file_projects ) = $item;
				foreach ( $file_projects as $project ) {
					if ( ! isset( $seen_projects[ $project->project_name ] ) )
						$seen_projects[ $project->project_name ] = $point->add_project($project->project_name);
					
					if ( ! isset( $seen_files[ $project->project_name ][ $file ] ) )
						$seen_projects[ $project->project_name ]->add_file($file, '['. $tag .']');
					$seen_files[ $project->project_name ][ $file ] = true;
				}
			}

			///  Create new ROLL entry
			$point_roll = $point->new_roll($user, $this->stage->env);
		}

		return $point_roll;
	}
	
}

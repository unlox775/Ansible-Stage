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
				if ( empty( $rev )
					 ///  OR if this tag is "later" than the stored one
					 || ( empty($tags[$file])   && $rev != 'HEAD' )
					 || ( ! empty($tags[$file]) && $rev != 'HEAD' && $this->rev_greater_than( $tags[$file], $rev, $file ) )
					 ) $rev = isset( $rev ) ? $rev : 'HEAD';
			}
			return ( is_null( $rev ) ? 'HEAD' : $rev );
		}
		else {
			///  Read from File Tag table
			$sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
			$rev = $sth->fetch(PDO::FETCH_NUM);
			$sth->closeCursor();
			return empty( $rev ) ? null : $rev[0];
		}
	}

	public function group_projects_by_file($projects) {
		$files = array();
		foreach ($projects as $project) {
			foreach ( $project->get_affected_files() as $file ) {
				if ( ! isset( $files[ $file ] ) ) $files[ $file ] = array($file, array($project->project_name => $project));
				else                              $files[ $file ][1][                  $project->project_name] = $project;
			}
		}
		return array_values($files);
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

	
}

<?php

/**
 *  Project Object -  Read and Cache Project file info
 */
class Ansible__ProjectProxy {
    public $project_name;
    public $stage;
    public $archived = false;
    protected $file_tags_cache = null;
    protected $mod_time_bak = null;
	public $proxy_mode = null;
	public $proxy_obj = null;
	public $proxy_obj_id = null;

	public static $get_file_cache = array();
	public static $affected_file_cache = array();
	public static $affected_file_lookup = array();
	public static $code_path_map = null;
	public static $proj_id_obj_map = null;
	public static $proj_code_obj_map = null;
	public static $rlgp_id_obj_map = null;

    public function __construct($project_name, $stage, $archived = false) {
        $this->stage = $stage;

		///  ProxyMode : RollGroup
		if ( strpos($project_name, 'RollGroup|') === 0 ) {
			$rlgp_id = substr($project_name, 10);
			require_once(dirname(__FILE__) .'/model/RollGroup.class.php');
			$this->proxy_obj = new Ansible__RollGroup($rlgp_id);
			if ( ! $this->proxy_obj->exists() ) 
				return trigger_error("Bad RollGroup ID: ". $rlgp_id, E_USER_ERROR);
			$this->proxy_obj_id = $rlgp_id;
			$this->proxy_mode = 'group';
			$this->project_name = $project_name;
		}
		///  ProxyMode : Project by ProjectID
		else if ( strpos($project_name, 'ProjectID|') === 0 ) {
			$proj_id = substr($project_name, 10);
			require_once(dirname(__FILE__) .'/model/Project.class.php');
			$this->proxy_obj = new Ansible__Project($proj_id);
			if ( ! $this->proxy_obj->exists() ) 
				return trigger_error("Bad Project ID: ". $proj_id, E_USER_ERROR);
			$this->proxy_obj_id = $proj_id;
			$this->proxy_mode = 'project';

			list( $path, $is_archived ) = self::get_path_by_code($this->proxy_obj->project_code);
			if ( $path === null ) 
				return trigger_error("ProjectID ". $proj_id ." existed in DB, but had no Dir", E_USER_ERROR);

			$this->project_name = $path;
			$this->archived = $is_archived;
		}
		///  ProxyMode : Project by ProjectCode
		else if ( strpos($project_name, 'ProjectCode|') === 0 ) {
			$project_code = substr($project_name, 12);
			list( $path, $is_archived ) = self::get_path_by_code($project_code);
			if ( $path === null ) 
				return trigger_error("Bad ProjectCode: ". $project_code, E_USER_ERROR);

			require_once(dirname(__FILE__) .'/model/Project.class.php');
			$this->proxy_obj = new Ansible__Project($project_code);
			$this->proxy_obj_id = $this->proxy_obj->proj_id;
			$this->proxy_mode = 'project';
			$this->project_name = $path;
			$this->archived = $is_archived;
		}
		///  ProxyMode : Project by Project Path
		else {
			$this->proxy_mode = 'project';
			$this->project_name = $project_name;
			$this->archived = $archived;
			$this->proxy_obj = $this->get_project_object();
		}
    }

    public static function get_by_project_id(  $proj_id,      $stage) { return( self::$proj_id_obj_map[   $proj_id      ] ?: self::$proj_id_obj_map[   $proj_id      ] = new Ansible__ProjectProxy('ProjectID|'.   $proj_id,      $stage) ); }
    public static function get_by_project_code($project_code, $stage) { return( self::$proj_code_obj_map[ $project_code ] ?: self::$proj_code_obj_map[ $project_code ] = new Ansible__ProjectProxy('ProjectCode|'. $project_code, $stage) ); }
    public static function get_by_rollgroup_id($rlgp_id,      $stage) { return( self::$rlgp_id_obj_map[   $rlgp_id      ] ?: self::$rlgp_id_obj_map[   $rlgp_id      ] = new Ansible__ProjectProxy('RollGroup|'.   $rlgp_id,      $stage) ); }


	public function is_roll_group() {
		return( $this->proxy_mode == 'group' );
	}
	public function get_roll_group() {
		return( $this->is_roll_group() ? null : ( $this->proxy_obj->rlgp_id ? self::get_by_rollgroup_id( $this->proxy_obj->rlgp_id, $this->stage ) : null ) );
	}

    public function exists() {
        return( ( $this->proxy_mode == 'project' ) ? $this->file_exists('affected_files.txt') : true );
    }

    public function get_project_object() {
		if ( $this->proxy_mode != 'project' ) return false;

		require_once(dirname(__FILE__) .'/model/Project.class.php');

        if ( $this->file_exists('.project_code') ) {
			return Ansible__Project::get_where(array('project_code' => $this->get_file('.project_code')), true);
		}
		else {
			$new_proj = new Ansible__Project();
			$newcode = false;
			while( $newcode === false ) {
				$newcode = strtolower( md5(rand().microtime(true)) );
				if ( Ansible__Project::get_where(array('project_code' => $newcode), true) ) 
					$newcode = false;
			}
			$new_proj->create(array( 'project_name' => $this->project_name,
									 'project_code' => $newcode,
									 'creator'      => $this->get_creator(),
									 ));
			$new_proj->extract_task_id($this->project_name);

			///  Write the .project_code file
			if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
				return trigger_error("Please don't hack...", E_USER_ERROR);

			$project_base = $this->stage->config('project_base');
#			if ( ! is_dir($project_base) ) return call_remote( __FUNCTION__, func_get_args() );

			if ( preg_match('/\W/', $newcode) )
				return trigger_error("Please don't hack...", E_USER_ERROR);

			$this->backup_project_mod_time();
			$archived = $this->archived() ? 'archive/' : '';
			file_put_contents("$project_base/$archived$this->project_name/.project_code", $newcode);
			link("$project_base/$archived$this->project_name/.project_code","$project_base/$archived$this->project_name/.project_code_$newcode");
			$this->restore_project_mod_time();

			return $new_proj;
		}
    }

	public static function get_path_by_code($code) {
		///  Seed the Map
		if ( self::$code_path_map === null ) {
			START_TIMER('Project->get_path_by_code()', PROJECT_PROJECT_TIMERS);
			self::$code_path_map = array();
			$project_base = $GLOBALS['controller']->stage->config('project_base');
			// Archived
			foreach ( glob("$project_base/archive/*/.project_code_*") as $code_file ) {
				if ( preg_match('/^\.project_code_([0-9a-f]+)$/',basename($code_file),$m) ) {
					// array Index:
					//  0 -> project_name
					//  1 -> bool archived
					self::$code_path_map[ $m[1] ] = array(basename(dirname($code_file)), true);
				}
			}
			// Not Achived
			foreach ( glob("$project_base/*/.project_code_*") as $code_file ) {
				if ( preg_match('/^\.project_code_([0-9a-f]+)$/',basename($code_file),$m) ) {
					// array Index:
					//  0 -> project_name
					//  1 -> bool archived
					self::$code_path_map[ $m[1] ] = array(basename(dirname($code_file)), false);
				}
			}
			END_TIMER('Project->get_path_by_code()', PROJECT_PROJECT_TIMERS);
		}
		return( isset( self::$code_path_map[ $code ] ) ? self::$code_path_map[ $code ] : array(null,null) );
	}

    public function archived() {
        if ( $this->proxy_mode != 'project' ) return( $this->proxy_obj->archived != 0 );

        return $this->archived;
    }

    public function get_group($just_this_project = null) {
		if ( ! $just_this_project && $this->get_roll_group() ) return $this->get_roll_group()->get_group();

        if ( $this->proxy_mode != 'project' ) return( empty( $this->proxy_obj->rollout_stage ) ? '00_none' : trim( $this->proxy_obj->rollout_stage ) );

        $group = $this->get_file('.group');
        return( empty( $group ) ? '00_none' : trim( $group) );
    }

	public function get_display_name() {
        if ( $this->proxy_mode == 'project' ) return $this->project_name;
        else return $this->proxy_obj->group_name;
	}

	public function get_creator() {
        if ( $this->proxy_mode != 'project' ) return $this->proxy_obj->creator;

		$ls = ( is_dir($this->stage->config('project_base')) ) ? (preg_split('/\s+/', $this->get_ls()) ) : array();
		return($ls[2] ?: '-');
	}

    public function get_ls() {
        if ( $this->proxy_mode != 'project' ) return '';
		START_TIMER('Project->get_ls()', PROJECT_PROJECT_TIMERS);

        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

		$project_base = $this->stage->config('project_base');
        if ( ! is_dir($project_base) ) return call_remote( __FUNCTION__, func_get_args() );
        $archived = $this->archived() ? 'archive/' : '';
		$ret = `/bin/ls -la --time-style=long-iso $project_base/$archived$this->project_name | head -n2 | tail -n1`;
		END_TIMER('Project->get_ls()', PROJECT_PROJECT_TIMERS);
        return $ret;
    }

    public function get_stat() {
        if ( $this->proxy_mode != 'project' ) return array();
		START_TIMER('Project->get_stat()', PROJECT_PROJECT_TIMERS);

        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($this->stage->config('project_base')) ) return call_remote( __FUNCTION__, func_get_args() );
        $archived = $this->archived() ? 'archive/' : '';
        $ret = stat($this->stage->config('project_base') ."/$archived$this->project_name");
		END_TIMER('Project->get_stat()', PROJECT_PROJECT_TIMERS);
		return $ret;
    }

    public function file_exists($file) {
        if ( $this->proxy_mode != 'project' ) return false;

        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) ) return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($this->stage->config('project_base')) ) return call_remote( __FUNCTION__, func_get_args() );
        $archived = $this->archived() ? 'archive/' : '';
        return ( file_exists($this->stage->config('project_base') ."/$archived$this->project_name/$file") );
    }

    public function get_file($file) {
        if ( $this->proxy_mode != 'project' ) return '';

        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) ) return trigger_error("Please don't hack...", E_USER_ERROR);

        $archived = $this->archived() ? 'archive/' : '';
        $file_path = $this->stage->config('project_base') ."/$archived$this->project_name/$file";
		if ( ! isset( self::$get_file_cache[ $file_path ] ) ) {
			START_TIMER('Project->get_file()', PROJECT_PROJECT_TIMERS);
			if ( ! is_dir($this->stage->config('project_base')) ) return call_remote( __FUNCTION__, func_get_args() );
			if ( !file_exists($file_path) ) self::$get_file_cache[ $file_path ] = '';
			else self::$get_file_cache[ $file_path ] = file_get_contents($file_path);
			END_TIMER('Project->get_file()', PROJECT_PROJECT_TIMERS);
		}

		return self::$get_file_cache[ $file_path ];
    }

    public function get_affected_files() {
        if ( ! isset( self::$affected_file_cache[ $this->project_name ] ) ) {
			START_TIMER('Project->get_affected_files()', PROJECT_PROJECT_TIMERS);
			self::$affected_file_cache[ $this->project_name ] = array();
			self::$affected_file_lookup[ $this->project_name ] = array();
			///  Group Mode
			if ( $this->proxy_mode == 'group' ) {
				$pre_list_files = array();
				foreach ( $this->proxy_obj->projects as $project ) {
					foreach ( $project->proxy()->get_affected_files() as $file ) {
						if ( preg_match('/\.sql$/', $file) ) {
							$pre_list_files[$file] = true;
						} else {
							self::$affected_file_cache[ $this->project_name ][ $file ] = true;
							self::$affected_file_lookup[ $this->project_name ][ $file ] = true;
						}
					}
				}
				$pre_list_files = array_keys($pre_list_files);
				self::$affected_file_cache[ $this->project_name ] = array_keys(self::$affected_file_cache[ $this->project_name ]);
				natsort(self::$affected_file_cache[ $this->project_name ]);
				natsort($pre_list_files);
				///  Sort some items to the top
				array_splice(self::$affected_file_cache[ $this->project_name ],0,0,$pre_list_files);
			}
			///  Project Mode
			else { 
				foreach ( explode("\n",$this->get_file( "affected_files.txt" )) as $file ) {
					$file = preg_replace('/(\s*\#.*$|\s+)$/','',$file);
					if ( strlen( $file ) == 0 ) continue;
					
					array_push( self::$affected_file_cache[ $this->project_name ], $file );
					self::$affected_file_lookup[ $this->project_name ][ $file ] = true;
				}
			}
			END_TIMER('Project->get_affected_files()', PROJECT_PROJECT_TIMERS);
        }
    
        return self::$affected_file_cache[ $this->project_name ];
    }
    
	public function includes_file($file) {
		if ( ! isset( self::$affected_file_lookup[ $this->project_name ] ) ) $this->get_affected_files();
		return isset( self::$affected_file_lookup[ $this->project_name ][ $file ] );
	}

    public function get_file_tags($just_this_project = false)  {
		if ( ! $just_this_project && $this->get_roll_group() ) return $this->get_roll_group()->get_file_tags();

        if ( empty( $this->file_tags_cache ) ) {
			START_TIMER('Project->get_file_tags()', PROJECT_PROJECT_TIMERS);
            $this->file_tags_cache = array();
			///  Group Mode
			if ( $this->proxy_mode == 'group' ) {
				foreach ( $this->proxy_obj->projects as $project ) {
					foreach ( $project->proxy()->get_file_tags(true) as $file => $rev ) {
						if ( ! isset( $this->file_tags_cache[ $file ] )
							 || $this->file_tags_cache[ $file ] < $rev
							 ) {
							$this->file_tags_cache[ $file ] = $rev;
						}
					}
				}
			}
			///  Project Mode
			else { 
				foreach ( explode("\n",$this->get_file( "file_tags.csv" )) as $line ) {
					$vals = str_getcsv($line);
					if ( count( $vals ) < 2 || preg_match('/[\"]/', $vals[1], $m) || ! preg_match('/^\d+(\.\d+(\.\d+\.\d+)?)?$/', $vals[1], $m) ) continue;
					$this->file_tags_cache[ $vals[0] ] = $vals[1];
				}
			}
			END_TIMER('Project->get_file_tags()', PROJECT_PROJECT_TIMERS);
        }
    
        return $this->file_tags_cache;
    }

    public function set_file_tag($file, $rev, $just_this_project = false) {
		if ( ! $just_this_project && $this->get_roll_group() ) return $this->get_roll_group()->set_file_tag($file, $rev);

        $archived = $this->archived() ? 'archive/' : '';
        $file_path = $this->stage->config('project_base') ."/$archived$this->project_name/file_tags.csv";

		///  Group Mode
		if ( $this->proxy_mode == 'group' ) {
			foreach ( $this->proxy_obj->projects as $project ) {
				foreach ( $project->proxy()->get_affected_files() as $proj_file ) {
					if ( $proj_file == $file ) {
						$project->proxy()->set_file_tag($file, $rev, true);
					}
				}
			}
		}
		///  Project Mode
		else { 
			$new_file = '';
			$seen_file_line = false;
			foreach ( explode("\n",$this->get_file( "file_tags.csv" )) as $line ) {
				$vals = str_getcsv($line);
				if ( $vals[0] == $file ) {
					$vals[1] = $rev;
					# cust function below
					$line = str_putcsv($vals);
					$seen_file_line = true;
					if ( ! empty( $rev ) ) $new_file .= $line."\n";
				}
				else $new_file .= $line."\n";
			}
			if ( ! $seen_file_line && ! empty( $rev ) ) $new_file .= str_putcsv(array($file, $rev))."\n";
			file_put_contents($file_path, $new_file);
		}
		###  Clear Cache
		if ( ! empty( $this->file_tags_cache ) ) $this->file_tags_cache = null;
		unset( self::$get_file_cache[ $file_path ] );
        return $this->file_tags_cache;
    }

    public function determine_target_rev($file, $head_rev = null) {
        $file_tags = $this->get_file_tags();

        $used_file_tags = false;
        if ( $file_tags[$file] ) {
            $target_rev = $file_tags[$file];
            $used_file_tags = true;
        }
        else { $target_rev = $head_rev; }

        return( array( $target_rev, $used_file_tags ) );
    }

    public function backup_project_mod_time() {
        if ( $this->proxy_mode != 'project' ) return null;

        $stat = $this->get_stat();
        $this->mod_time_bak = $stat ? $stat[9] : null;
    }


    #############################
    ###  Write-Access Actions 

    public function archive($user, $time = null) {
        if ( $this->proxy_mode != 'project' ) { 
			foreach ( $this->proxy_obj->projects as $project ) {
				$project->proxy()->archive($user, $time);
			}

			return $this->proxy_obj->set_and_save(array('archived' => 1));
		}

        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

		$project_base = $this->stage->config('project_base');
        if ( ! is_dir($project_base) ) return call_remote( __FUNCTION__, func_get_args() );
        if ( $this->archived() ) return true;

        ///  Make the archive dir if necessary
        if ( ! is_dir("$project_base/archive") ) {
            mkdir("$project_base/archive", 0755);
        }

        ///  Move to Archive Dir
        $this->backup_project_mod_time();
        print `mv $project_base/$this->project_name $project_base/archive/$this->project_name`;
        ///  Log
        if ( empty($time)        ) $time = time();
        if ( ! is_numeric($time) ) $time = strtotime( $time );
        $time = date('Y-m-d H:i:s', $time);
        print `echo '"'$time'","archived","'$user'"' > $project_base/archive/$this->project_name/archived.txt`;
        print `cat $project_base/archive/$this->project_name/archived.txt >> $project_base/archive/$this->project_name/archive.log`;
        $this->archived = true;
        $this->restore_project_mod_time();
        return true;
    }

    public function unarchive($user, $time = null) {
        if ( $this->proxy_mode != 'project' ) {
			foreach ( $this->proxy_obj->projects as $project ) {
				$project->proxy()->unarchive($user, $time);
			}

			return $this->proxy_obj->set_and_save(array('archived' => 0));
		}

        if ( $this->proxy_mode != 'project' ) return null;

        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

		$project_base = $this->stage->config('project_base');
        if ( ! is_dir($project_base) ) return call_remote( __FUNCTION__, func_get_args() );
        if ( ! $this->archived() ) return true;

        ///  Move out of the Archive Dir
        $this->backup_project_mod_time();
        print `mv $project_base/archive/$this->project_name $project_base/$this->project_name`;
        ///  Log
        if ( empty($time)        ) $time = time();
        if ( ! is_numeric($time) ) $time = strtotime( $time );
        $time = date('Y-m-d H:i:s', $time);
        print `echo '"'$time'","unarchived","'$user'"' > $project_base/$this->project_name/archived.txt`;
        print `cat $project_base/$this->project_name/archived.txt >> $project_base/$this->project_name/archive.log`;
        print `rm -f $project_base/$this->project_name/archived.txt`;
        $this->archived = false;
        $this->restore_project_mod_time();
        return true;
    }

    public function set_group($group) {
        if ( $this->proxy_mode != 'project' ) return $this->proxy_obj->set_and_save(array('rollout_stage' => $group));

        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

		$project_base = $this->stage->config('project_base');
        if ( ! is_dir($project_base) ) return call_remote( __FUNCTION__, func_get_args() );

        if ( preg_match('/\W/', $group) )
            return trigger_error("Please don't hack...", E_USER_ERROR);

        $this->backup_project_mod_time();
        $archived = $this->archived() ? 'archive/' : '';
        if ( $group == '00_none' ) {
            print `rm -f       $project_base/$archived$this->project_name/.group`;
        } else {
            print `echo $group > $project_base/$archived$this->project_name/.group`;
        }
        $this->restore_project_mod_time();
        return true;
    }

    public function restore_project_mod_time() {
        if ( $this->proxy_mode != 'project' ) return '';

        if ( empty( $this->mod_time_bak ) ) return false;

        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($this->stage->config('project_base')) ) return call_remote( __FUNCTION__, func_get_args() );
        
        ///  Restore to the backup
        $archived = $this->archived() ? 'archive/' : '';
        @touch($this->stage->config('project_base') ."/$archived$this->project_name", $this->mod_time_bak);
        
        return true;
    }

}

###  Helper for above
if(!function_exists('str_putcsv')) {
    function str_putcsv($input, $delimiter = ',', $enclosure = '"') {
        // Open a memory "file" for read/write...
        $fp = fopen('php://temp', 'r+');
        // ... write the $input array to the "file" using fputcsv()...
        fputcsv($fp, $input, $delimiter, $enclosure);
        // ... rewind the "file" so we can read what we just wrote...
        rewind($fp);
        // ... read the entire line into a variable...
        $data = fgets($fp);
        // ... close the "file"...
        fclose($fp);
        // ... and return the $data to the caller, with the trailing newline from fgets() removed.
        return rtrim( $data, "\n" );
    }
}
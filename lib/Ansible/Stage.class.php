<?php

//  Load debugging
if ( ! function_exists('sort_objects') )
	require_once(dirname(__FILE__). '/../advanced_sort.inc.php');
if ( ! function_exists('START_TIMER') )
	require_once(dirname(__FILE__). '/../debug.inc.php');
if ( ! class_exists('Stark__Extend') )
	require_once(dirname(__FILE__). '/../Stark/Extend.class.php');

class Ansible__Stage {
	public $env = null;
	protected $__repo = null;
	public $extend;

	///  Config
	public $lib_path = null;
	public $config_path = null;
	public $url_prefix = null;
	
	public $staging_areas = array();
	public $default_url_protocol = 'http';
	public $qa_rollout_phase_host   = '';
	public $prod_rollout_phase_host = '';
	public $url_base = '';

	public $repo_cmd_path = '';
	public $repo_cmd_cd = 'cd {REPO_BASE}';
	public $repo_cmd_minimum_prefix = ''; # especially for a sudo directive
	public $repo_cmd_prefix = '{REPO_CMD_CD} ; {REPO_CMD_MINIMUM_PREFIX}{REPO_CMD_PATH}';
	public $obscure_sandbox_root = false;

	public $project_base = null;
	public $safe_base = null;
	public $operation_tmp_base = '{SAFE_BASE}/tmp';
	public $db_file_name = 'ansible_db.sq3';
	public $db_file_path = '{PROJECT_BASE}/{DB_FILE_NAME}';
	public $db_dsn = 'sqlite:{DB_FILE_PATH}';
	public $db_username = '';
	public $db_password = '';
	public $project_base_ignore_regexp = '{DB_FILE_NAME}|{DB_FILE_NAME}.NFSLock'; # note, this is POSIX egrep-style
	public $encoding = 'UTF-8';

	public $repo_type  = 'SVN';
	public $repo_file  = '{lib_path}/Ansible/Repo/{REPO_TYPE}.class.php';
	public $repo_class = 'Ansible__Repo__{REPO_TYPE}';

	public $max_batch_size = 500;
	public $max_batch_string_size = 4096;

	public $guest_users = array('guest', 'pmgr_tunnel');

	public $sub_stage_name_by_class
		= array( 'switch_env'       => 'Switch to {ENV_NAME}',
				 'update_to_target' => 'Update to Target',
				 'create_rollpoint' => 'Creating Roll Point from {TARGET_ENV_NAME}',
				 'update_to_that_rollpoint' => 'Updating {TARGET_ENV_NAME} to Roll Point',
				 'update_to_last_rollback_point' => 'Roll Back to Roll Point',
				 'update_to_last_rollout_point'  => 'Re-Rolling to Roll Point',
				 );

	protected $send_cmd_i = 1;
	protected $flush_i = 1;

	///  Debugging Params
	public $ANSIBLE_PROFILING = false;

	public function __construct( $env, $path_config ) {
		$this->env = $env;

		///  Load the paths
		foreach ( array('lib_path','config_path','url_prefix') as $var ) {
			if ( isset( $path_config[ $var ] ) ) $this->$var = $path_config[ $var ];
		}

		///  Pre-load Stark__Extend, because we will be using it...
		$this->extend = new Stark__Extend();

		///  If we have 'config_path', then run the config
		if ( ! empty( $this->config_path ) ) $this->read_config( $this->config_path );
	}


	public function read_config($config_path) {
		///  Set $this into a few convenience var names...
		$controller = $this;
		$ctl = $this;
		$config = $this;

		///  This will generate errors if it doesn't exist.
		///    TODO: Do our own file_exists() check and our own error...
		require( $config_path );
	}

	public function repo() {
		###  Cache
		if ( empty( $this->__repo ) ) {
			require_once( $this->config('repo_file') );
			
			$repo_class = $this->config('repo_class');
			$this->__repo = new $repo_class ();
			$this->__repo->stage = $this;

			###  Make sure the database is connected
			$this->dbh();
		}
		return $this->__repo;
	}

	public function dbh() {
		###  Cache
		if ( empty( $this->__dbh ) ) {

			###  Connect to the tags DB
			$INIT_DB_NOW = false;
			if ( strpos($this->db_dsn, 'sqlite') !== false && ! empty($this->db_file_path) ) {
				if ( ! file_exists( $this->config('db_file_path') ) ) $INIT_DB_NOW = true;
    
				###  Get an exclusive File_NFSLock on the DB file...
#				$this->db_file_lock = new File_NFSLock( $this->config('db_file_path'),LOCK_EX,10,30*60); # stale lock timeout after 30 minutes
			}

			if ( ! empty($this->db_dsn) ) {
				$this->__dbh = new PDO($this->config('db_dsn'), $this->config('db_username'), $this->config('db_password'));  $GLOBALS['orm_dbh'] = $this->__dbh;
				$this->__dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );

				///  HACK : For now, just change the below number to whatever you want to upgrade from
				///    and run this once, 
				$cur_revision = 0.23;
#				$cur_revision = 0.20;

				///  Version 0.1.0
				if ( $INIT_DB_NOW || $cur_revision < 0.10 ) {
					$this->__dbh->exec("CREATE TABLE file_tag (
                      					   file      character varying(1000) NOT NULL,
                      					   tag       character varying(25) NOT NULL,
                      					   revision  int NOT NULL,
                      					   mass_edit int NOT NULL DEFAULT 0,
                      					   CONSTRAINT file_tag_pk PRIMARY KEY ( file, tag )
                      					 )
                      					");

					//$this->__dbh->exec("ALTER TABLE file_tag ADD COLUMN
					//             mass_edit int NOT NULL DEFAULT 0");
					//exit;

					$this->__dbh->exec("CREATE TABLE revision_cache (
										  file         character varying(900) NOT NULL PRIMARY KEY,
										  expire_token character varying(50)  NOT NULL,
										  revisions    text 		 		  NOT NULL,
										  committers   text 		 		  NOT NULL
										)");
					$this->__dbh->exec("CREATE INDEX expire_token_idx ON revision_cache(expire_token)");
				}

				///  Version 0.2.1
				if ( $INIT_DB_NOW || $cur_revision < 0.21 ) {
					$this->__dbh->exec("CREATE TABLE roll_point (
										  rlpt_id      INTEGER PRIMARY KEY AUTOINCREMENT,
										  creation_date INTEGER               NOT NULL DEFAULT (strftime('%s','now')),
										  point_type   character(5)           NOT NULL,
                                          created_by NOT NULL
										)");
					$this->__dbh->exec("CREATE INDEX point_type_idx ON roll_point(point_type)");

					$this->__dbh->exec("CREATE TABLE rlpt_project (
										  rlpp_id      INTEGER PRIMARY KEY AUTOINCREMENT,
										  rlpt_id      INTEGER                NOT NULL,
										  project      character varying(200) NOT NULL,
                                          CONSTRAINT rp_file_uk UNIQUE(rlpt_id,project)
										)");
					$this->__dbh->exec("CREATE INDEX project_idx ON rlpt_project(project)");

					$this->__dbh->exec("CREATE TABLE rlpt_roll (
										  rlpr_id       INTEGER PRIMARY KEY AUTOINCREMENT,
										  rlpt_id       INTEGER               NOT NULL,
										  creation_date INTEGER               NOT NULL DEFAULT (strftime('%s','now')),
                                          created_by                          NOT NULL,
                                          cmd           text                      NULL,
                                          cmd_output    text                      NULL,
										  rollback_rlpt_id INTEGER                NULL
										)");
					$this->__dbh->exec("CREATE INDEX roll_point_idx ON rlpt_roll(rlpt_id)");
				}
				///  Version 0.2.2
				if ( $INIT_DB_NOW || $cur_revision < 0.22 ) {
					if ( $INIT_DB_NOW || $cur_revision < 0.21 )
						$this->__dbh->exec("DROP TABLE rlpt_file");
					$this->__dbh->exec("CREATE TABLE rlpt_proj_file (
										  rlpf_id      INTEGER PRIMARY KEY AUTOINCREMENT,
										  rlpp_id      INTEGER                NOT NULL,
										  file         character varying(900) NOT NULL,
										  revision     character varying(32)      NULL,
                                          CONSTRAINT rp_file_uk UNIQUE(rlpp_id,file)
										)");
					$this->__dbh->exec("CREATE INDEX rp_file_idx ON rlpt_proj_file(file)");
				}
				///  Version 0.2.3
				if ( $INIT_DB_NOW || $cur_revision < 0.23 ) {
					$this->__dbh->exec("ALTER TABLE rlpt_roll 
                                          ADD COLUMN env character varying(50) NULL
                                       ");
					$this->__dbh->exec("CREATE INDEX rlpt_roll_env_idx ON rlpt_roll(env)");
				}

				///  Debugging for the Database
				$this_debug = 0;
				if ( $this_debug ) {
					$debug = array(
								   ///  Show DB Tables:
#								   "SELECT * FROM sqlite_master WHERE type='table'",
								   ///  Rollpoints and sub-tables
								   "SELECT *
                                      FROM roll_point
                                     ",
								   "SELECT *
                                      FROM rlpt_project
                                     ",
								   "SELECT *
                                      FROM rlpt_roll
                                     ",
								   "SELECT *
                                      FROM roll_point
                                 LEFT JOIN rlpt_project p
                                 LEFT JOIN rlpt_roll    r
                                     WHERE roll_point.rlpt_id = p.rlpt_id
                                       AND roll_point.rlpt_id = r.rlpt_id
                                       AND p.project IN ('another')
                                       AND env     = 'staging' AND rollback_rlpt_id IS NOT NULL
                                     ",
								   ///  Test Project Lookup
								   "SELECT *
                                      FROM roll_point
                                     WHERE EXISTS(SELECT 1 FROM rlpt_project p WHERE roll_point.rlpt_id = p.rlpt_id AND project IN ('another'))
						               AND EXISTS(SELECT 1 FROM rlpt_roll    r WHERE roll_point.rlpt_id = r.rlpt_id AND env     = 'staging' AND rollback_rlpt_id IS NOT NULL )
                                     ",
#								   "SELECT * FROM rlpt_roll",
								   );

					foreach ( $debug as $sql ) {
						bug($sql);
						$sth = $this->__dbh->query($sql);
						bug($sth->fetchAll(PDO::FETCH_ASSOC));
					}
				}
			}
		}
		return $this->__dbh;
	}

	#########################
	###  Remote Call

	function call_remote($sub, $params) {
		trace_dump();
		return trigger_error("Couldn't locate the project directory: ". $this->config_swap( $this->project_base ) ." ...", E_USER_ERROR);

		###      $sub = preg_replace('/^.+::/','',$sub);
		###  
		###      $url = "https://admin.beta.project.org/project_manager/";
		###      if ( $_SERVER['REMOTE_USER'] ) $url = "https://pmgr_tunnel:h53clK88FvB5\@admin.beta.project.org/project_manager/";
		###  
		###      $params = array( 'action'      => 'remote_call',
		###                       'remote_call' => $sub,
		###                       'params'      => urlencode( nfreeze( $params ) ),
		###                       'wantarray' => (wantarray ? true : false),
		###                       );
		###  #    $agent = LWP::UserAgent->new;
		###      $response = $agent->post($url, $params);
		###  
		###      list($frozen) = preg_match('/\|=====\|(.+)\|=====\|/', ($response->content, $m));
		###      $response_obj;
		###      if ( $frozen ) {
		###          $response_obj = thaw(urldecode($frozen));
		###          if ( ! ref($response_obj) ) {
		###              BUG ["Not a ref", $frozen, $response_obj];
		###              return trigger_error("Not a ref : ", E_USER_ERROR). $response->content;
		###          }
		###      }
		###      else {
		###          BUG ["Bad Response", $response->content];
		###          return trigger_error("Bad Response : ", E_USER_ERROR). $response->content;
		###      }
		###  
		###      return( wantarray && UNIVERSAL::isa($response_obj, 'ARRAY')
		###              ? (@[$response_obj])
		###              : $$response_obj
		###            );
	}


	#########################
	###  Staging Environs

	public function env($env = null) {
		/* HOOK */$__x = $this->extend->x('env', 0); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh();if($__x->hr()) return $__x->get_return();
		if ( empty( $env ) ) $env = $this->env;
		if ( empty( $env ) ) { trace_dump(); return trigger_error("Tried to load env when \$_SESSION[env] was not set.", E_USER_ERROR); }
		$return = ( isset( $this->staging_areas[ $env ] ) ) ? (object) $this->staging_areas[ $env ] : (object) array();
		/* HOOK */$__x = $this->extend->x('env', 10, $this); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh();if($__x->hr()) return $__x->get_return();
		return $return;
	}
	public function sandbox_areas() {
		$areas = array();
		foreach ( $this->staging_areas as $env => $area ) {
			if ( ! empty( $area['environment'] ) )
				$areas[ $env ] = $area;
		}
		return $areas;
	}
	public function get_area_url($role, $action = 'list.php') {
		$query_string = $_SERVER['QUERY_STRING'];
		$query_string = preg_replace('/[\&\?](cmd|command_output|tag)=[^\&]+/','',$query_string);
		$query_string = preg_replace('/action=(update|tag)/','action=view_project',$query_string);

		$area = null;  $env = null;
		foreach ( array_keys($this->staging_areas) as $test_env ) {
			$area = $this->env( $test_env );
			if ( $area->role == $role ) {
				$env = $test_env;
				break;
			}
		}
		if ( ! empty( $env ) ) {
			return( $this->config('default_url_protocol') ."://".
					( ! empty( $area->host ) ? $area->host : $_SERVER['HTTP_HOST'] ) . $this->url_prefix .'/change_env.php?env='. $env .'&redirect=/'. $action
					. urlencode("?". $query_string )
					);
		}

		return null;
	}
	public function safe_self_url($script_name = null, $query_string = null) {
		if ( is_null( $script_name  ) ) $script_name  = $_SERVER['SCRIPT_NAME'];
		if ( strlen($stage->url_prefix) > 0 && substr($script_name, 0, strlen($stage->url_prefix) ) == $stage->url_prefix ) $script_name = substr($script_name, strlen($stage->url_prefix));
		if ( is_null( $query_string ) ) $query_string = $_SERVER['QUERY_STRING'];

		parse_str($query_string, $query_params);
		
		///  Hide command output...
		foreach ( array('cmd','command_output','tag') as $x ) if ( isset( $query_params[$x] ) ) unset( $query_params[$x] );

		///  Scrub script name to not stay on pages that run un-wanted actions a second time
		if ( preg_match('@/actions/(update|tag).php@',$query_string) ) $script_name = '/project.php';
		if ( preg_match('@/actions/(entire_repo_update|entire_repo_tag).php@',$query_string) ) $script_name = '/repo_admin.php';
		
		///  Condense long project URLs...
		$projects = $this->read_projects_param( $query_params['p'] );
		if ( ! empty( $projects ) ) {
			parse_str($this->get_projects_url($projects), $p_url);
			$query_params = array_merge( $query_params, $p_url);
		}

		return $stage->url_prefix . preg_replace('@^\Q'. $stage->url_prefix .'\E@','', $script_name) . (empty($query_params) ? '' : "?". http_build_query( $query_params ) );
	}
	public function get_rollout_tree() {
		$areas = array();

		///  Move the env key into the env because sorting loses the keys
		$sort_areas = array();  foreach( $this->staging_areas as $env => $area ) { $area['__env'] = $env;  $sort_areas[] = $area; }

		$last_sequence = null;
		$last_areas = array();
		foreach ( sort_objects($sort_areas, array('stage_sequence','ASC') ) as $area ) {
			///  Extract the key
			$env = $area['__env'];  unset( $area['__env'] );

			if ( ! empty( $area['development'] ) ) continue;
			if ( empty( $area['stage_sequence'] ) )
				return trigger_error("ansible config error: $env area has no 'stage_sequence'", E_USER_ERROR);

			///  Link in the 'next_stage' for all past envs
			$prev_envs = 0;
			foreach ( $last_areas as $last_env => $x ) {
				if ( ! empty( $areas[ $last_env ]->proceeds_to ) ) {
					if ( $areas[ $last_env ]->proceeds_to == $env ) {
						$areas[ $last_env ]->next_stage = $env;
						unset( $last_areas[$last_env] );
						$prev_envs++;
					}
				}
				///  No Explicit flow, then if we have advanced past that sequence
				///    Then set this as next
				else if ( $areas[ $last_env ]->stage_sequence != $area['stage_sequence'] ) {
					$areas[ $last_env ]->next_stage = $env;
					unset( $last_areas[$last_env] );
					$prev_envs++;
				}
			}
				
			///  Add some params to the config's area
			$add_area = array_merge( $area,
									 array( 'stage_class' => 'stage',
										    'rollout_stages' => array(),
										    'reroll_stages' => array(),
											'rollback_stages' => array(),
											)
									 );
			
			///  Depending on environment, set sub-stages
			///    NOTES:
			///      1) The rollback (e.g. from Production to Staging) will show as sub-lines of Production
			///  CASE 1: From dev environment to anything: update to Head, rollback to Next Env
			if ( $prev_envs == 0 ) {
				///  Out
				$add_area['rollout_stages'][]  = (object) array( 'stage_class' => 'switch_env',       		  'target_env' => 'this' );
				$add_area['rollout_stages'][]  = (object) array( 'stage_class' => 'update_to_target', 		  'target_env' => 'this' );
				///  Back
				$add_area['rollback_stages'][] = (object) array( 'stage_class' => 'create_rollpoint',         'target_env' => 'next' );
				$add_area['rollback_stages'][] = (object) array( 'stage_class' => 'update_to_that_rollpoint', 'target_env' => 'this' );
				///  Re-Roll
				$add_area['reroll_stages'][]   = (object) array( 'stage_class' => 'update_to_target',         'target_env' => 'this' );
			}
			///  CASE 2: From Non-Dev Env to Another Non-Dev: snapshot revisions, and roll precisely
			else {
				///  Out
				$add_area['rollout_stages'][]  = (object) array( 'stage_class' => 'create_rollpoint', 		  'target_env' => 'this' );
				$add_area['rollout_stages'][]  = (object) array( 'stage_class' => 'switch_env',       		  'target_env' => 'next' );
				$add_area['rollout_stages'][]  = (object) array( 'stage_class' => 'update_to_that_rollpoint', 'target_env' => 'next' );
				///  Back
				$add_area['rollback_stages'][] = (object) array( 'stage_class' => 'update_to_last_rollback_point', 'target_env' => 'this' );
				$add_area['rollback_stages'][] = (object) array( 'stage_class' => 'switch_env' );
				///  Re-Roll
				$add_area['reroll_stages'][]   = (object) array( 'stage_class' => 'update_to_last_rollout_point' );
			}
			
			$areas[$env] = (object) $add_area;
			$last_areas[$env] = $env;
			$last_sequence = $area['stage_sequence'];
		}
		return $areas;
	}
	public function env_is_first_after_development( $env ) {
		foreach( $this->get_rollout_tree() as $test_env => $env_area ) {
			if ( $env_area->next_stage == $env )
				return false;
		}
		return true;
	}
	private $get_sub_stage_name_by_class_vars = null;
	public function get_sub_stage_name_by_class($class, $vars) {
		$this->get_sub_stage_name_by_class_vars =& $vars;
		$return = preg_replace_callback('/\{\w+\}/', array($this, 'get_sub_stage_name_by_class_replace'), $this->sub_stage_name_by_class[$class]);
		$this->get_sub_stage_name_by_class_vars = null;
		return $return;
	}
	public function get_sub_stage_name_by_class_replace($m) {
		$var = strtolower(trim($m[0],'{}'));
		return( isset( $this->get_sub_stage_name_by_class_vars[ $var ] ) ? $this->get_sub_stage_name_by_class_vars[ $var ] : "{ERROR: VARS->$var is not defined}" );
	}

	public function read_only_mode() {
		return ( in_array( $_SERVER['REMOTE_USER'], $this->guest_users ) ) ? true : false;
	}
	public function onAlpha() { return( $this->env()->role != 'beta' && $this->env()->role != 'live' ); }
	public function onBeta()  { return( $this->env()->role == 'beta'  ); }
	public function onLive()  { return( $this->env()->role == 'live'  ); }


	#########################
	###  Projects Access

	public function get_projects_url($projects, $exclude = false) {
		$params = array();
		$project_codes = array();
		foreach ( (array) $projects as $project ) {
			$project_name = ( $project instanceof Ansible__Project ? $project->project_name : $project );
			if ( $exclude && $exclude == $project_name ) continue;
			$project_codes[] = $project_name;
			$params[] = "p[]=". urlencode($project_name);
		}
 		if ( strlen(join('&',$params)) > 100 ) {
 			$plist_key = md5(print_r($project_codes, true));
 			$_SESSION['project_list_sets'][$plist_key] = $project_codes;
 			return 'p[]='. urlencode("$$". $plist_key ."$$");
 		}

		return join('&',$params);
	}
	public function read_projects_param($param) {
		$projects = array();
		foreach ( (array) $param as $p ) {
			///  If the first charactar is 
			if ( preg_match('/^\$\$([a-z0-9]+)\$\$$/i', $p, $m) && isset($_SESSION['project_list_sets'][$m[1]]) ) {
				foreach ( $_SESSION['project_list_sets'][$m[1]] as $saved_p ) {
					$projects[] = $saved_p;
				}
			}
			else {
				$projects[] = $p;
			}
		}
		return $projects;
	}
	public function get_projects_from_param($param) {
		require_once($this->config('lib_path'). '/Ansible/Project.class.php');
		$projects = array();
		foreach ( $this->read_projects_param($param) as $p ) {
			$project = new Ansible__Project( $p, $this );
			if ( ! $project->exists() ) return trigger_error("Invalid project: ". $p, E_USER_ERROR);
			$projects[] = $project;
		}
		return $projects;
	}

	public function get_projects() {
		$tmp = func_get_args();
		$project_base = $this->config('project_base');
		$ignore_regex = $this->config('project_base_ignore_regexp');
		if ( ! is_dir($project_base) ) return $this->call_remote( __FUNCTION__, $tmp );
		return explode("\n",`unset GREP_OPTIONS; /bin/ls -1 $project_base | /bin/grep -E -v '^(archive|logs|$ignore_regex)\$'`);
	}

	public function get_archived_projects() {
		$tmp = func_get_args();
		$project_base = $this->config('project_base');
		$ignore_regex = $this->config('project_base_ignore_regexp');
		if ( ! is_dir($project_base) ) return $this->call_remote( __FUNCTION__, $tmp );
		return explode("\n",`unset GREP_OPTIONS; /bin/ls -1 $project_base/archive | /bin/grep -E -v '^($ignore_regex)\$'`);
	}

	public function get_projects_by_group($category = 'active') {
		require_once($this->config('lib_path'). '/Ansible/Project.class.php');

		###  Project Groups
		$groups = array( '00_none'              => 'New Projects - In Development',
						 '01_staging'           => 'Step 1 : Updated to Staging for Testing',
						 '03_testing_done'      => 'Step 3 : Testing Done - Tagged as PROD_TEST',
						 '04_prod_rollout_prep' => 'Step 4 : Production tagged as PROD_SAFE',
						 '05_rolled_out'        => 'Step 5 : Rolled out to Production',
						 );

		$projects = array();
		$category_list = $category == 'archived' ? $this->get_archived_projects() : $this->get_projects();
		$file_lines = array();
		foreach ( $category_list as $project_name ) {
			if ( empty( $project_name ) ) continue;

			$project = new Ansible__Project( $project_name, $this, ($category == 'archived') );

			###  Get more info from ls
			$ls = ( is_dir($SYSTEM_PROJECT_BASE)) ? (preg_split('/\s+/', $project->get_ls()) ) : array();
			#        $stat = (is_dir($SYSTEM_PROJECT_BASE)) ? ($project->get_stat()) : ();
			$stat = $project->get_stat();

			$project_info = array( 'name'                => $project_name,
								   'creator'             => ($ls[2] || '-'),
								   'group'               => ($ls[3] || '-'),
								   'mod_time'            => ($stat ? $stat[9] : 0),
								   'mod_time_display'    => ($stat ? date('n/j/y',$stat[9])  : '-'),
								   'has_summary'         => ( (is_dir($SYSTEM_PROJECT_BASE))
															  ? ( $project->file_exists( "summary.txt" ) ? "YES" : "")
															  : '-'
															  ),
								   'aff_file_count'      => count($project->get_affected_files()),
								   );
        
			//  Make array key unique, but sortable
			$projects[ $project->get_group() ][ sprintf("%011d",$project_info['mod_time']) .'_'.$project_name ] = $project_info;
		}

		///  Nested Sort
		ksort($projects, SORT_NUMERIC);
		foreach( $projects as $group => $x ) { ksort($projects[ $group ], SORT_NUMERIC);  $projects[ $group ] = array_reverse( $projects[ $group ] ); }

		return( array($projects, $groups) );
	}


	#########################
	###  Utility

	public function config($var) { return $this->config_swap($this->$var, $var); }
	public function config_swap($str, $var) {
		/* HOOK */$__x = $this->extend->x('config_swap', 10); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh();if($__x->hr()) return $__x->get_return();
		return preg_replace_callback('/\{\w+\}/', array($this, 'config_swap_replace'), $str);
	}
	public function config_swap_replace($m) {
		$var = strtolower(trim($m[0],'{}'));
		return( $var == 'repo_base' ? $this->env()->repo_base : (isset( $this->$var ) ? $this->config($var) : "{ERROR: \$config->$var is not defined}") );
	}


	########################
	###  AJAX output helpers

	public function sendJsCommand($js_str) {
		$this->send_cmd_i++;
		echo ("<script>"
#			  . "if ( output_cmd_block < ". $this->send_cmd_i .") {"
			  .     $js_str
#			  .     "; output_cmd_block = ". $this->send_cmd_i .';'
#			  . "}"
			  . "</script>"
			  );
		$this->flushAJAXOutput();
	}
	public function flushAJAXOutput() {
		echo '<!-- flush('. $this->flush_i++ .') --><!--'. str_repeat(' ',2048) .'-->';
		ob_implicit_flush(true);
		ob_end_flush();
		flush();ob_flush();
	}

	public function updateCommand($echo, $cmd) {
		if ( ! $echo ) return;
		$this->sendJsCommand('if (updateDrawerCommand) updateDrawerCommand('. json_encode($cmd) .')');
	}
	public function updateStatus($echo, $status_code, $count = null) {
		if ( ! $echo ) return;

		$status_str = ( ucwords( str_replace('_',' ',$status_code) )
						. ( is_null($count) ? '' : ' ( '. $count .' )')
						);

		$this->sendJsCommand('if (updateDrawerStatus)   updateDrawerStatus('.   json_encode($status_str) .');
                              if (set_sub_stage_status) set_sub_stage_status('. json_encode($status_code) .');');
	}
	public function appendOutput($echo, &$command_output, $append_str) {
		$command_output .= $append_str;
		if ( $echo ) echo $append_str;
		$this->flushAJAXOutput();
	}
	public function appendOutputFromShellExec($echo, &$command_output, $cmd) {
		if ( ! $echo ) $command_output .= shell_exec($cmd);
		else {
			$fh = popen($cmd, 'r');
			
			while( $read = fread($fh, 1) ) {
				echo $read;
				$command_output .= $read;
			}
		}
	}

}

#########################
###  FROM: http://us2.php.net/manual/en/function.str-getcsv.php

###  If your version of PHP doesn't have `str_getcsv` and you don't need custom $escape or $eol values, try this:
if (!function_exists('str_getcsv')) {
 
    function str_getcsv($input, $delimiter=',', $enclosure='"', $escape=null, $eol=null) {
      $temp=fopen("php://memory", "rw");
      fwrite($temp, $input);
      fseek($temp, 0);
      $r=fgetcsv($temp, 4096, $delimiter, $enclosure);
      fclose($temp);
      return $r;
    }
 
}

///  Remove this soon...
if ( ! function_exists( 'dbh_query_bind' ) ) {

// Debugging
define('ORM_SQL_PROFILE', false);
define('ORM_SQL_DEBUG', false);
define('ORM_SQL_WRITE_DEBUG', false);

/**
 * dbh_query_bind() - Run a read-only SQL query with bound parameters
 *
 * @param string $sql      The SQL query to run
 * @param mixed $params   this can either be called passing an array of bind params, or just by passing the bind params as args after the SQL arg
 * @return PDOStatement
 */
function dbh_query_bind( $sql ) {
    if ( isset( $GLOBALS['orm_dbh'] ) ) $use_dbh = $GLOBALS['orm_dbh'];
    if ( ORM_SQL_PROFILE ) START_TIMER('dbh_query_bind');
    $bind_params = array_slice( func_get_args(), 1 );
    ###  Allow params passed in an array or as args
    if ( is_a( $bind_params[ count($bind_params) - 1 ], 'PDO' ) || is_a( $bind_params[ count($bind_params) - 1 ], 'PhoneyPDO' ) ) $use_dbh = array_pop($bind_params);
    if ( ! isset( $GLOBALS['orm_dbh'] ) ) $GLOBALS['orm_dbh'] = $use_dbh; # steal their DBH for global use, hehehe
    if ( count( $bind_params ) == 1 && is_array(array_shift(array_values($bind_params))) ) { $bind_params = array_shift(array_values($bind_params)); };
#    if (ORM_SQL_DEBUG) trace_dump();
    reverse_t_bools($bind_params);
    if (ORM_SQL_DEBUG) bug($sql, $bind_params);
    try { 
        $sth = $use_dbh->prepare($sql);
        $rv = $sth->execute($bind_params);
    } catch (PDOException $e) {
        trace_dump();
        $err_msg = 'There was an error running a SQL statement, ['. $sql .'] with ('. join(',',$bind_params) .'): '. $e->getMessage() .' in ' . trace_blame_line();
        if ( strlen($err_msg) > 1024 ) {
            bug($err_msg,$sql,$bind_params,$e->getMessage());
            $sql = substr($sql,0,1020 + strlen($sql) - strlen($err_msg) ).'...';
        }
        trigger_error( 'There was an error running a SQL statement, ['. $sql .'] with ('. join(',',$bind_params) .'): '. $e->getMessage() .' in ' . trace_blame_line(), E_USER_ERROR);
        return false;
    }
    if ( ORM_SQL_PROFILE ) END_TIMER('dbh_query_bind');
    return $sth;
}
/**
 * dbh_do_bind() - Execute a (possibly write access) SQL query with bound parameters
 *
 * @param string $sql      The SQL query to run
 * @param mixed $params   this can either be called passing an array of bind params, or just by passing the bind params as args after the SQL arg
 * @return PDOStatement
 */
function dbh_do_bind( $sql ) {
    if ( isset( $GLOBALS['orm_dbh'] ) ) $use_dbh = $GLOBALS['orm_dbh'];
    if ( ORM_SQL_PROFILE ) START_TIMER('dbh_do_bind');
    $bind_params = array_slice( func_get_args(), 1 );
    ###  Allow params passed in an array or as args
    if ( is_a( $bind_params[ count($bind_params) - 1 ], 'PDO' ) || is_a( $bind_params[ count($bind_params) - 1 ], 'PhoneyPDO' ) ) $use_dbh = array_pop($bind_params);
    if ( ! isset( $GLOBALS['orm_dbh'] ) ) $GLOBALS['orm_dbh'] = $use_dbh; # steal their DBH for global use, hehehe
    if ( count( $bind_params ) == 1 && is_array(array_shift(array_values($bind_params))) ) { $bind_params = array_shift(array_values($bind_params)); };
    
    reverse_t_bools($bind_params);
    if (ORM_SQL_DEBUG || ORM_SQL_WRITE_DEBUG) bug($sql, $bind_params);
    try { 
        $sth = $use_dbh->prepare($sql);
        $rv = $sth->execute($bind_params);
    } catch (PDOException $e) {
        trace_dump();
        $err_msg = 'There was an error running a SQL statement, ['. $sql .'] with ('. join(',',$bind_params) .'): '. $e->getMessage() .' in ' . trace_blame_line();
        if ( strlen($err_msg) > 1024 ) {
            bug($err_msg,$sql,$bind_params,$e->getMessage());
            $sql = substr($sql,0,1020 + strlen($sql) - strlen($err_msg) ).'...';
        }
        trigger_error( 'There was an error running a SQL statement, ['. $sql .'] with ('. join(',',$bind_params) .'): '. $e->getMessage() .' in ' . trace_blame_line(), E_USER_ERROR);
        return false;
    }
    if ( ORM_SQL_PROFILE ) END_TIMER('dbh_do_bind');
    return $rv;
}
function reverse_t_bools(&$ary) { if (! is_array($ary)) return;  foreach($ary as $k => $v) { if ($v === true) $ary[$k] = 't';  if ($v === false) $ary[$k] = 'f'; } }

}


function get_relative_time($date) {
	$diff = time() - $date;
	if ($diff<60)
		return $diff . " sec" . ($diff != 1 ? 's' : '') . " ago";
	$diff = round($diff/60);
	if ($diff<60)
		return $diff . " min" . ($diff != 1 ? 's' : '') . " ago";
	$diff = round($diff/60);
	if ($diff<24)
		return $diff . " hr" . ($diff != 1 ? 's' : '') . " ago";
	$diff = round($diff/24);
	if ($diff<7)
		return $diff . " day" . ($diff != 1 ? 's' : '') . " ago";
	$diff = round($diff/7);
	if ($diff<4)
		return $diff . " wk" . ($diff != 1 ? 's' : '') . " ago";
	$diff = round($diff*7/30);
	if ($diff<11)
		return $diff . " mon" . ($diff != 1 ? 's' : '') . " ago";
	return "on " . date("F j, Y", $date);
}

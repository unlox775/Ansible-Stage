<?php

//  Load debugging
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
	public $sandbox_areas = array();
	public $default_url_protocol = 'http';
	public $qa_rollout_phase_host   = '';
	public $prod_rollout_phase_host = '';
	public $url_base = '';

	public $repo_cmd_prefix = 'cd {REPO_BASE}; ';
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

	public $repo_type  = 'SVN';
	public $repo_file  = '{lib_path}/Ansible/Repo/{REPO_TYPE}.class.php';
	public $repo_class = 'Ansible__Repo__{REPO_TYPE}';

	public $max_batch_size = 500;
	public $max_batch_string_size = 4096;

	public $guest_users = array('guest', 'pmgr_tunnel');

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
				$this->db_file_lock = new File_NFSLock( $this->config('db_file_path'),LOCK_EX,10,30*60); # stale lock timeout after 30 minutes
			}

			if ( ! empty($this->db_dsn) ) {
				$this->__dbh = new PDO($this->config('db_dsn'), $this->config('db_username'), $this->config('db_password'));  $GLOBALS['orm_dbh'] = $this->__dbh;
				$this->__dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
				if ( $INIT_DB_NOW ) {
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
		if ( empty( $env ) ) $env = $this->env;
		if ( empty( $env ) ) return trigger_error("Tried to load env when \$_SESSION[env] was not set.", E_USER_ERROR);
		if ( isset( $this->staging_areas[ $env ] ) ) return (object) $this->staging_areas[ $env ];
		return( ( isset( $this->sandbox_areas[ $env ] ) ) ? (object) $this->sandbox_areas[ $env ] : (object) array() );
	}
	public function get_area_url($role, $action = 'list.php') {
		$query_string = $_SERVER['QUERY_STRING'];
		$query_string = preg_replace('/[\&\?](cmd|command_output|tag)=[^\&]+/','',$query_string);
		$query_string = preg_replace('/action=(update|tag)/','action=view_project',$query_string);

		$area = null;  $env = null;
		foreach ( array_merge(array_keys($this->staging_areas),array_keys($this->staging_areas)) as $test_env ) {
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
		foreach ( $projects as $project ) {
			if ( $exclude && $exclude == $project->project_name ) continue;
			$params[] = "p[]=". urlencode($project->project_name);
		}
		return join('&',$params);
	}
	public function get_projects_from_param($param) {
		require_once($this->config('lib_path'). '/Ansible/Project.class.php');
		$projects = array();
		foreach ( (array) $param as $p ) {
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
		return explode("\n",`ls -1 $project_base | grep -E -v '^(archive|logs|$ignore_regex)\$'`);
	}

	public function get_archived_projects() {
		$tmp = func_get_args();
		$project_base = $this->config('project_base');
		$ignore_regex = $this->config('project_base_ignore_regexp');
		if ( ! is_dir($project_base) ) return $this->call_remote( __FUNCTION__, $tmp );
		return explode("\n",`ls -1 $project_base/archive | grep -E -v '^($ignore_regex)\$'`);
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

	public function config($var) { return $this->config_swap($this->$var); }
	public function config_swap($str) {
		return preg_replace_callback('/\{\w+\}/', array($this, 'config_swap_replace'), $str);
	}
	public function config_swap_replace($m) {
		$var = strtolower(trim($m[0],'{}'));
		return( $var == 'repo_base' ? $this->env()->repo_base : (isset( $this->$var ) ? $this->config($var) : "{ERROR: \$config->$var is not defined}") );
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

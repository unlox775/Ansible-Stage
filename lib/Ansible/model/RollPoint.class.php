<?php

require_once(dirname(__FILE__) .'/Local.class.php');


class Ansible__RollPoint extends Ansible__ORM__Local {
    protected $__table       = 'roll_point';
    protected $__primary_key = array( 'rlpt_id' );
    protected $__schema = array( 'rlpt_id'       => array(),
								 'creation_date' => array(),
								 'point_type'    => array(),
								 'created_by'    => array(),
								 );
    protected $__relations = array(
#        'created_by' => array( 'relationship' => 'has_one',                 
#							   'include'      => 'model/Teacher.class.php', # A file to require_once(), (should be in include_path)
#							   'class'        => 'Teacher',                 # The class name
#							   'columns'      => 'mentor_id',               # local cols to get the PKey for the new object (can be array if >1 col..)
#							   ),
        'projects' => array( 'relationship'        => 'has_many',
							 'include'             =>          'RollPoint/ProjectProxy.class.php', # A file to require_once(), (should be in include_path)
							 'class'               => 'Ansible__RollPoint__ProjectProxy',          # The class name
							 'foreign_table'       => 'rlpt_project',                		  # The table to SELECT FROM
							 'foreign_key_columns' => 'rlpt_id',                     		  # The cols in the foreign table that correspond to Your PKey (can be array if >1 col..)
							 'foreign_table_pkey'  => 'rlpp_id',                     		  # The primary key of that table                              (can be array if >1 col..)
							 'order_by_clause'     => 'project',                     		  # custom sorting (saves local sorting cost)
							 ),
    );
    public static function get_where($where = null, $limit_or_only_one = false, $order_by = null) { return parent::get_where($where, $limit_or_only_one, $order_by); }
	private static $static_cache = array();

	public function add_project($project_name) {
		require_once(dirname(__FILE__) .'/RollPoint/Project.class.php');
		$p = new Ansible__RollPoint__Project();
		$p->create(array('rlpt_id' => $this->rlpt_id, 'project' => $project_name));
		return $p;
	}

	public function new_roll($user, $env) {
		require_once(dirname(__FILE__) .'/RollPoint/Roll.class.php');
		$r = new Ansible__RollPoint__Roll();
		$r->create(array('rlpt_id' => $this->rlpt_id, 'created_by' => $user, 'env' => $env));
		return $r;
	}


	///////////////////////////
	///  Methods for comparing / querying projects

	public function includes_same_projects($projects) {
		$shared_projects = array();
		foreach ( $this->projects as $our_project ) {
			foreach ( $projects as $their_project ) {
				if ( $our_project->project == $their_project->project_name ) {
					$shared_projects[ $our_project->project ] = true;
					break;
				}
			}
		}
		
		///  If the number of shared is not the same as our total number, then BAD
		return( ( count($shared_projects) != count( $this->projects ) ) ? false : true );
	}

	public function projects_list_diff($projects) {
		$not_on_their_list = array(); foreach ( $this->projects as $_ ) $not_included[ $_->project      ] = $_->project();
		$not_on_our_list = array();   foreach ( $projects       as $_ ) $not_included[ $_->project_name ] = $_;
		$shared_projects = array();
		foreach ( $this->projects as $our_project ) {
			foreach ( $projects as $their_project ) {
				if ( $our_project->project == $their_project->project_name ) {
					$shared_projects[ $our_project->project ] = $their_proeject;
					unset($not_on_their_list[  $our_project->project ],
						  $not_our_their_list[ $our_project->project ]
						  );
					break;
				}
			}
		}
		
		return( array( $shared_projects, $not_on_their_list, $not_on_our_list ) );
	}

	public static function get_by_env_and_project($env, $projects) {
		$dbh = $GLOBALS['controller']->stage->dbh();
		
		$projs = array();
		foreach( $projects as $project ) $projs[] = $dbh->quote($project->project_name);
		sort($projs);
		$key = 'get_by_env_and_project_'. md5($env .'_'. print_r($projs, true));
		if ( ! isset( self::$static_cache[$key] ) ) {
			$require_rb_point = $GLOBALS['controller']->stage->env_is_first_after_development( $env ) ? '' : "AND rollback_rlpt_id IS NOT NULL";
			self::$static_cache[$key] = Ansible__RollPoint::get_where(array( 'EXISTS(SELECT 1 FROM rlpt_project p WHERE roll_point.rlpt_id = p.rlpt_id AND project IN ('. join(',',$projs) .'))',
																			 'EXISTS(SELECT 1 FROM rlpt_roll    r WHERE roll_point.rlpt_id = r.rlpt_id AND env     = '. $dbh->quote($env) .' '. $require_rb_point .' )',
																			 ), null, 'rlpt_id DESC');
		}
		return self::$static_cache[$key];
	}
	
	/* ---  IRRELEVANT now that files are per proj?  ---
	public function get_file_projects($file) {
		$projects = array();
		foreach ( $this->projects as $project ) {
			if ( ! $project->project()->exists() ) continue;

			///  If this project has the file, set and continue...
			foreach ( $project->project()->get_affected_files() as $p_file ) {
				if ( $file == $p_file ) {
					$projects[ $project->project()->project_name ] = $project->project();
					break;
				}
			}
		}
		return $projects;
	}
	*/
}

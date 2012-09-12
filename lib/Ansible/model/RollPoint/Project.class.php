<?php

require_once(dirname(dirname(__FILE__)) .'/Local.class.php');

class Ansible__RollPoint__Project extends Ansible__ORM__Local {
    protected $__table       = 'rlpt_project';
    protected $__primary_key = array( 'rlpp_id' );
    protected $__schema = array( 'rlpp_id' => array(),
								 'rlpt_id' => array(),
								 'project' => array(),
								 );
    protected $__relations = array( 'files' => array( 'relationship'        => 'has_many',
													  'include'             =>          'RollPoint/Project/File.class.php', # A file to require_once(), (should be in include_path)
													  'class'               => 'Ansible__RollPoint__Project__File',         # The class name
													  'foreign_table'       => 'rlpt_proj_file',                			# The table to SELECT FROM
													  'foreign_key_columns' => 'rlpp_id',                  			# The cols in the foreign table that correspond to Your PKey (can be array if >1 col..)
													  'foreign_table_pkey'  => 'rlpf_id',                 			# The primary key of that table                              (can be array if >1 col..)
													  'order_by_clause'     => 'file',                     			# custom sorting (saves local sorting cost)
													  ),
									);
    public static function get_where($where = null, $limit_or_only_one = false, $order_by = null) { return parent::get_where($where, $limit_or_only_one, $order_by); }

	public function project() {
		require_once(dirname(dirname(dirname(__FILE__))). '/Project.class.php');

		$project = new Ansible__Project( $this->project, $GLOBALS['controller']->stage, false );
		
		return( $project->exists() ? $project : null );
	}

	public function add_file($file, $revision) {
		require_once(dirname(__FILE__) .'/Project/File.class.php');
		$f = new Ansible__RollPoint__Project__File();
		$f->create(array('rlpp_id' => $this->rlpp_id, 'file' => $file, 'revision' => $revision));
		return $f;
	}
}

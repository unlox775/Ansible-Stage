<?php

require_once(dirname(__FILE__) .'/Local.class.php');

class Ansible__Project extends Ansible__ORM__Local {
    protected $__table       = 'project';
    protected $__primary_key = array( 'proj_id' );
    protected $__schema = array( 'proj_id'       => array(),
								 'project_name'  => array(),
								 'task_id'       => array(),
								 'project_code'  => array(),
								 'creation_date' => array(),
								 'creator'       => array(),
								 'rlgp_id'       => array(),
								 );
    protected $__relations = array(
        'rollgroup' => array( 'relationship' => 'has_one',                 
							   'include'      =>          'RollGroup.class.php', # A file to require_once(), (should be in include_path)
							   'class'        => 'Ansible__RollGroup',           # The class name
							   'columns'      => 'rlgp_id',                      # local cols to get the PKey for the new object (can be array if >1 col..)
							   ),
    );
    public static function get_where($where = null, $limit_or_only_one = false, $order_by = null) { return parent::get_where($where, $limit_or_only_one, $order_by); }

	public function extract_task_id($project_name) {
		if ( preg_match('/^(\d+)/',$project_name,$m) ) {
			$this->set_and_save(array('task_id' => preg_replace('/^0+/','',$m[1]) ));
		}
	}
	public function proxy() {
		require_once(dirname(dirname(__FILE__)) .'/ProjectProxy.class.php');
		return Ansible__ProjectProxy::get_by_project_id($this->proj_id, $GLOBALS['controller']->stage);
	}
}

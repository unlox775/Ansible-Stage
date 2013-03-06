<?php

require_once(dirname(__FILE__) .'/Local.class.php');

class Ansible__RollGroup extends Ansible__ORM__Local {
    protected $__table       = 'roll_group';
    protected $__primary_key = array( 'rlgp_id' );
    protected $__schema = array( 'rlgp_id'       => array(),
								 'group_name'    => array(),
								 'creation_date' => array(),
								 'creator'       => array(),
								 'rollout_stage' => array(),
								 'archived'      => array(),
								 );
    protected $__relations = array(
        'projects' => array( 'relationship'        => 'has_many',
							 'include'             =>          'Project.class.php', # A file to require_once(), (should be in include_path)
							 'class'               => 'Ansible__Project',          # The class name
							 'foreign_table'       => 'project',                 		      # The table to SELECT FROM
							 'foreign_key_columns' => 'rlgp_id',                     		  # The cols in the foreign table that correspond to Your PKey (can be array if >1 col..)
							 'foreign_table_pkey'  => 'proj_id',                     		  # The primary key of that table                              (can be array if >1 col..)
							 'order_by_clause'     => 'project_name',                  		  # custom sorting (saves local sorting cost)
							 ),
    );
    public static function get_where($where = null, $limit_or_only_one = false, $order_by = null) { return parent::get_where($where, $limit_or_only_one, $order_by); }
}

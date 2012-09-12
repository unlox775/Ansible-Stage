<?php

require_once(dirname(dirname(__FILE__)) .'/Local.class.php');

class Ansible__RollPoint__Roll extends Ansible__ORM__Local {
    protected $__table       = 'rlpt_roll';
    protected $__primary_key = array( 'rlpr_id' );
    protected $__schema = array( 'rlpr_id' 	     	=> array(),
								 'rlpt_id' 	     	=> array(),
								 'creation_date' 	=> array(),
								 'created_by'    	=> array(),
								 'cmd'           	=> array(),
								 'cmd_output'    	=> array(),
								 'rollback_rlpt_id' => array(),
								 );
    protected $__relations = array();
    public static function get_where($where = null, $limit_or_only_one = false, $order_by = null) { return parent::get_where($where, $limit_or_only_one, $order_by); }
}

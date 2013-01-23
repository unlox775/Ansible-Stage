<?php

require_once(dirname(__FILE__) .'/Local.class.php');

class Ansible__Committer extends Ansible__ORM__Local {
    protected $__table       = 'committer';
    protected $__primary_key = array( 'cmtr_id' );
    protected $__schema = array( 'cmtr_id'       => array(),
								 'committer_name'      => array(),
								 );
    protected $__relations = array(
    );
	protected static $__id_by_name = array();
    public static function get_where($where = null, $limit_or_only_one = false, $order_by = null) { return parent::get_where($where, $limit_or_only_one, $order_by); }

	public static function new_by_name($committer_name) {
		if ( isset( self::$__id_by_name[$committer_name] ) ) 
			return new Ansible__Committer( self::$__id_by_name[$committer_name] );

		$obj = self::get_where(array('committer_name' => $committer_name),true);
		if ( ! $obj ) {
			$obj = new Ansible__Committer();
			$obj->create(array('committer_name' => $committer_name));
		}
		self::$__id_by_name[$committer_name] = $obj->cmtr_id;
		return $obj;
	}

}

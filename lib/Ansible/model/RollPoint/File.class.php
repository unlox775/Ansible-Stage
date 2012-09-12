<?php

require_once(dirname(dirname(__FILE__)) .'/Local.class.php');

class Ansible__RollPoint__File extends Ansible__ORM__Local {
    protected $__table       = 'rlpt_file';
    protected $__primary_key = array( 'rlpf_id' );
    protected $__schema = array( 'rlpf_id'  => array(),
                               'rlpt_id'  => array(),
                               'file'     => array(),
                               'revision' => array(),
							   );
    protected $__relations = array();
    public static function get_where($where = null, $limit_or_only_one = false, $order_by = null) { return parent::get_where($where, $limit_or_only_one, $order_by); }

}

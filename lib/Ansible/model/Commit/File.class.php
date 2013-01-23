<?php

require_once(dirname(dirname(__FILE__)) .'/Local.class.php');

class Ansible__Commit__File extends Ansible__ORM__Local {
    protected $__table       = 'cmmt_file';
    protected $__primary_key = array( 'cmfl_id' );
    protected $__schema = array( 'cmfl_id'         => array(),
								 'cmmt_id'         => array(),
								 'file_id'         => array(),
								 'commit_type'     => array(),
								 'md5_fingerprint' => array(),
								 );
    protected $__relations = array( 'commit' => array( 'relationship'        => 'has_one',
													   'include'             =>          'Commit.class.php', # A file to require_once(), (should be in include_path)
													   'class'               => 'Ansible__Commit',          # The class name
													   'columns'             => 'cmmt_id',           
													   ),
									'file' => array( 'relationship'        => 'has_one',
													 'include'             =>          'File.class.php', # A file to require_once(), (should be in include_path)
													 'class'               => 'Ansible__File',          # The class name
													 'columns'             => 'file_id',           
													 ),
									);
    public static function get_where($where = null, $limit_or_only_one = false, $order_by = null) { return parent::get_where($where, $limit_or_only_one, $order_by); }
}

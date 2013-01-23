<?php

require_once(dirname(__FILE__) .'/Local.class.php');

class Ansible__File extends Ansible__ORM__Local {
    protected $__table       = 'repo_file';
    protected $__primary_key = array( 'file_id' );
    protected $__schema = array( 'file_id'   => array(),
								 'file_path' => array(),
								 );
	public static $__id_by_file = array();
    protected $__relations = array(
        'commits' => array( 'relationship'        => 'has_one',
							'include'             =>          'Commit/File.class.php', # A file to require_once(), (should be in include_path)
							'class'               => 'Ansible__Commit__File',          # The class name
							'foreign_table'       => 'cmmt_file',                      # The table to SELECT FROM
							'foreign_key_columns' => 'file_id',                        # The cols in the foreign table that correspond to Your PKey (can be array if >1 col..)
							'foreign_table_pkey'  => 'cmfl_id',                        # The primary key of that table                              (can be array if >1 col..)
							'order_by_clause'     => 'cmmt_id',                        # custom sorting (saves local sorting cost)
							),
    );
    public static function get_where($where = null, $limit_or_only_one = false, $order_by = null) { return parent::get_where($where, $limit_or_only_one, $order_by); }

	public static function new_by_file($file_path) {
		if ( isset( self::$__id_by_file[$file_path] ) ) 
			return new Ansible__File( self::$__id_by_file[$file_path] );

		$obj = self::get_where(array('file_path' => $file_path),true);
		if ( ! $obj ) {
			$obj = new Ansible__File();
			$obj->create(array('file_path' => $file_path));
		}
		self::$__id_by_file[$file_path] = $obj->file_id;
		return $obj;
	}
}

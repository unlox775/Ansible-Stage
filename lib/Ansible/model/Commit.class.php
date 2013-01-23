<?php

require_once(dirname(__FILE__) .'/Local.class.php');

class Ansible__Commit extends Ansible__ORM__Local {
    protected $__table       = 'commit_cache';
    protected $__primary_key = array( 'cmmt_id' );
    protected $__schema = array( 'cmmt_id'       => array(),
								 'revision'      => array(),
								 'cmtr_id'       => array(),
								 'commit_date'   => array(),
								 'message'       => array(),
								 );
	protected static $__id_by_revision = array();
    protected $__relations = array(
        'committer' => array( 'relationship'        => 'has_one',
							  'include'             =>          'Committer.class.php', # A file to require_once(), (should be in include_path)
							  'class'               => 'Ansible__Committer',          # The class name
							  'columns'             => 'cmtr_id',           
							  ),
    );
    public static function get_where($where = null, $limit_or_only_one = false, $order_by = null) { return parent::get_where($where, $limit_or_only_one, $order_by); }

	public static function new_by_revision($revision) {
		if ( isset( self::$__id_by_revision[$revision] ) ) 
			return new Ansible__File( self::$__id_by_revision[$revision] );

		$obj = self::get_where(array('revision' => $revision),true);
		if ( ! $obj ) {
			return null;
		}
		self::$__id_by_revision[$revision] = $obj->cmmt_id;
		return $obj;
	}

	public function add_files($changed_paths) {
		require_once(dirname(__FILE__) .'/Commit/File.class.php');
		require_once(dirname(__FILE__) .'/File.class.php');
		$added_paths = array();
		foreach ( $changed_paths as $path ) {
			if ( isset( $added_paths[$path['file']] ) ) continue;
			$file = new Ansible__Commit__File();
			$file->create(array( 'cmmt_id'     => $this->cmmt_id,
								 'file_id'     => Ansible__File::new_by_file($path['file'])->file_id,
								 'commit_type' => $path['action'],
								 ));
			$added_paths[$path['file']] = true;
			if ( $path['action'] == 'remove'
				 || ( $path['action'] == 'add' && isset( $path['from_revision'] ) )
				 ) {
				///  Now add sub-files
				$sql = "SELECT file_path
	                      FROM repo_file f
	                     WHERE file_path LIKE ?
	                       ". /* WHERE: file file existed at this point
								        the last remove date is after
	                                    the last add date */ "
	                       AND COALESCE( ( SELECT MAX(c.revision)
	                                         FROM cmmt_file cf
	                                         JOIN commit_cache c USING(cmmt_id)
	                                        WHERE cf.file_id = f.file_id
	                                          AND cf.commit_type = 'remove'
                                              AND cf.cmmt_id != ". ( (int) $this->cmmt_id ) ."
	                                          ". ( isset( $path['from_revision'] ) ? "AND c.revision <= ?" : '' ) ."
	                                        ), 1000000000)
	                           >
	                           COALESCE( ( SELECT MAX(c.revision)
	                                         FROM cmmt_file cf
	                                         JOIN commit_cache c USING(cmmt_id)
	                                        WHERE cf.file_id = f.file_id
	                                          AND cf.commit_type = 'add'
                                              AND cf.cmmt_id != ". ( (int) $this->cmmt_id ) ."
	                                          ". ( isset( $path['from_revision'] ) ? "AND c.revision <= ?" : '' ) ."
	                                        ), 0)
	                    ";
				$file_search = ( $path['action'] == 'add' && isset( $path['from_revision'] ) ) ? $path['from_file'] : $path['file'];
				$params = array( $file_search .'/%' );
				if ( isset( $path['from_revision'] ) ) {
					$params[] = $path['from_revision'];
					$params[] = $path['from_revision'];
				}
#				bug($this->revision, $path,$sql,$params);
				$sth = dbh_query_bind($sql, $params);
				while( $row = $sth->fetch() ) {
					$new_file = $path['file'] . substr($row['file_path'], strlen($file_search) );
#					bug($file_search,$row['file_path'],$new_file);
					if ( isset( $added_paths[$new_file] ) ) continue;
					$sub_file = new Ansible__Commit__File();
					$sub_file->create(array( 'cmmt_id'     => $this->cmmt_id,
											 'file_id'     => Ansible__File::new_by_file($new_file)->file_id,
											 'commit_type' => $path['action'],
											 ));
					$added_paths[$new_file] = true;

					###  Clear cache
					Ansible__File::$__id_by_file = array();
					$GLOBALS['Stark__ORM_OBJECT_CACHE'] = array();
					$GLOBALS['Stark__ORM_DBH_CACHE'] = array();
					$GLOBALS['ORM_SQL_LOG'] = array();
				}
			}
		}
	}
}

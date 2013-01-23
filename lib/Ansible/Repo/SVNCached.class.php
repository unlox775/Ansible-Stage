<?php

require_once(dirname(__FILE__).'/SVN.class.php');

/**
 * SVN Spefic hooks for Ansible Stage
 */
class Ansible__Repo__SVNCached extends Ansible__Repo__SVN {
	public static $not_exists_fingerprint = 'not-exists-a9fb227701e0d0e45d979';
	public static $__loaded_new_revs = array();
	public static $__fingerprint_cache = array();

	public function get_repo_new_revisions() {
		$svn_root = $this->get_svn_root();

		if ( ! isset( self::$__loaded_new_revs[$svn_root] ) ) {
#			bug($this->expire_token());
			START_TIMER('get_repo_new_revisions()', PROJECT_PROJECT_TIMERS);
			foreach ( $this->stage->dbh()->query("SELECT MAX(revision) FROM commit_cache") as $row ) {
				list( $starting_rev ) = $row;
			};
			if ( empty( $starting_rev ) ) $starting_rev = 30700;
			$starting_rev++;

			$hit_empty_rev = false;
			$accel = 0;
			
			$limit_arg = ! empty( $limit ) ? ' --limit '. $limit : '';
			$cmd_prefix = $this->stage->config('repo_cmd_prefix');
			require_once(dirname(dirname(__FILE__)) .'/model/Commit.class.php');
			require_once(dirname(dirname(__FILE__)) .'/model/Committer.class.php');
			while(true){
				$range_to = $starting_rev + $accel;
		
				START_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
				START_TIMER('REPO_CMD(log)', PROJECT_PROJECT_TIMERS);
#				bug($svn_root);
#				bug("${cmd_prefix}svn log -v $limit_arg -r $starting_rev:$range_to \"$svn_root\" 2>&1 | cat");
				$clog = `${cmd_prefix}svn log -v $limit_arg -r $starting_rev:$range_to "$svn_root" 2>&1 | cat`;
				END_TIMER('REPO_CMD(log)', PROJECT_PROJECT_TIMERS);
				END_TIMER('REPO_CMD', PROJECT_PROJECT_TIMERS);
		
				$parsed = $this->parse_log($clog);
#				bug($parsed, $clog);
				if ( empty( $parsed ) || !( $firstrev = current( $parsed ) ) || empty( $firstrev['revision'] ) ) {
					$hit_empty_rev = true;
					$re_run = ( $accel != 0 );
					$accel = 0;
					if ( $re_run ) continue;
					break;
				}
				foreach( $parsed as $revision) {
					if ( $revision['message'] == 'This is an empty revision for padding.'
						 && $revision['committer'] == '(no author)'
						 ) {
						$starting_rev = $revision['revision'] + 1;
						continue;
					}
#					bug($revision['revision'], date('Y-m-d H:i:s', $revision['date']), $revision['committer']);
					$commit = Ansible__Commit::new_by_revision($revision['revision']);
					if ( ! $commit ) {
						$savepoint = $this->stage->dbh_open_savepoint();
						$commit = new Ansible__Commit();
						$commit->create(array( 'revision' 	 => $revision['revision'],
											   'cmtr_id'  	 => Ansible__Committer::new_by_name($revision['committer'])->cmtr_id,
											   'commit_date' => date('Y-m-d H:i:s', $revision['date']),
											   'message'     => ( is_null($revision['message']) ? '' : $revision['message']),
											   ));
						$commit->add_files($revision['changed_paths']);
						$savepoint->commit();
					}
					$starting_rev = $revision['revision'] + 1;
				}
				if ( !$hit_empty_rev ) $accel++;
				END_TIMER('get_repo_new_revisions()', PROJECT_PROJECT_TIMERS);
			}
		}

		self::$__loaded_new_revs[$svn_root] = true;
	}

	public function get_log($file, $limit = null) {
		list($revs,$committers,$full) = $this->get_all_log_revs( $file );

		$output = '';
		foreach ( $full as $commit ) {
			$lines = preg_split('/(\r\n|\n\r|\r|\n)/', $commit->message);
			$output .= '
------------------------------------------------------------------------
r'. $commit->revision .' | '. $commit->committer->committer_name .' | '. date('Y-m-d H:i:s O (D, j M Y)',strtotime($commit->commit_date)) .' | '. count($lines) .' lines

'. $commit->message .'
';
		}
		
        return( $output );
	}

	public function get_all_log_revs( $file ) {
		START_TIMER('get_all_log_revs(cached)', PROJECT_PROJECT_TIMERS);
		$file = preg_replace('/\/+$/','',$file);
        $cache_key = 'all_log_revs';

		if ( empty( $file ) && ! is_numeric( $file ) ) {
			END_TIMER('get_all_log_revs(cached)', PROJECT_PROJECT_TIMERS);
			return( array( array(), array() ) );
		}

		if ( ! isset( $this->repo_cache[$cache_key][$file] ) ) {
			$this->get_repo_new_revisions();
			START_TIMER('get_all_log_revs(cached)/inner', PROJECT_PROJECT_TIMERS);
			START_TIMER('get_all_log_revs(cached)/inner(1)', PROJECT_PROJECT_TIMERS);

			require_once(dirname(dirname(__FILE__)) .'/model/Commit.class.php');
			require_once(dirname(dirname(__FILE__)) .'/model/Committer.class.php');
			END_TIMER('get_all_log_revs(cached)/inner(1)', PROJECT_PROJECT_TIMERS);
			START_TIMER('get_all_log_revs(cached)/inner(2)', PROJECT_PROJECT_TIMERS);
			$sql = "SELECT c.*,cr.committer_name
                      FROM commit_cache c
                      JOIN cmmt_file cf USING(cmmt_id)
                      JOIN repo_file f  USING(file_id)
                      JOIN committer cr USING(cmtr_id)
                     WHERE f.file_path = ". $this->stage->dbh()->quote($this->get_repository_relative_path() .'/'. $file) ."
                  ORDER BY c.commit_date DESC
                     ";
			$data = $this->stage->dbh()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
# 			$commits = Ansible__Commit::get_where(array('EXISTS( SELECT 1
#                                                        			   FROM cmmt_file cf
#                                                        			   JOIN repo_file f USING(file_id)
#                                                        			  WHERE cf.cmmt_id = commit_cache.cmmt_id
#                                                        			    AND f.file_path = '. $this->stage->dbh()->quote($this->get_repository_relative_path() .'/'. $file) .'
#                                                        			 )'), false, 'revision DESC');
			END_TIMER('get_all_log_revs(cached)/inner(2)', PROJECT_PROJECT_TIMERS);
			START_TIMER('get_all_log_revs(cached)/inner(3)', PROJECT_PROJECT_TIMERS);
			$this->repo_cache[$cache_key][$file]['revisions'] = array();
			$this->repo_cache[$cache_key][$file]['committers'] = array();
			foreach ( $data as $row ) {
				$committer = new Ansible__Committer($row['cmtr_id'],array('cmtr_id' => $row['cmtr_id'], 'committer_name' => $row['committer_name']));
				$this->repo_cache[$cache_key][$file]['committers'][] = $row['committer_name'];
				unset( $row['committer_name'] );
				$commit = new Ansible__Commit($row['cmmt_id'], $row);
				$this->repo_cache[$cache_key][$file]['commits'][] = $commit;
				$this->repo_cache[$cache_key][$file]['revisions'][] = $commit->revision;
			}

			END_TIMER('get_all_log_revs(cached)/inner(3)', PROJECT_PROJECT_TIMERS);
			END_TIMER('get_all_log_revs(cached)/inner', PROJECT_PROJECT_TIMERS);
		}
		END_TIMER('get_all_log_revs(cached)', PROJECT_PROJECT_TIMERS);
        return( array( $this->repo_cache[$cache_key][$file]['revisions'], $this->repo_cache[$cache_key][$file]['committers'], $this->repo_cache[$cache_key][$file]['commits'] ) );
	}

	function get_current_rev($file) {
		$file = preg_replace('/\/+$/','',$file);

        $cache_key = 'get_current_rev-cached';

		if ( empty( $file ) && ! is_numeric( $file ) ) {
			END_TIMER('get_all_log_revs(cached)', PROJECT_PROJECT_TIMERS);
			return( array( null, null, null, null, null ) );
		}

		###  Skip out if cache
		if ( isset( $this->repo_cache[$cache_key][$file] ) ) 
			return $this->repo_cache[$cache_key][$file];

		START_TIMER('get_current_rev(cached)', PROJECT_PROJECT_TIMERS);
		require_once(dirname(dirname(__FILE__)) .'/model/Commit.class.php');

		$fingerprint = $this->get_file_fingerprint($file);

		START_TIMER('get_current_rev(cached)-q1', PROJECT_PROJECT_TIMERS);
		$sql = "SELECT c.revision
                  FROM commit_cache c
                  JOIN cmmt_file cf USING(cmmt_id)
                  JOIN repo_file f  USING(file_id)
                 WHERE f.file_path = ". $this->stage->dbh()->quote($this->get_repository_relative_path() .'/'. $file) ."
                   AND md5_fingerprint = ". $this->stage->dbh()->quote($fingerprint) ."
                 ";
		$fp_revs = $this->stage->dbh()->query($sql)->fetchAll(PDO::FETCH_COLUMN, 0);
# 		$fp_matches = Ansible__Commit::get_where(array( 'EXISTS( SELECT 1
#                                                        			   FROM cmmt_file cf
#                                                        			   JOIN repo_file f USING(file_id)
#                                                        			  WHERE cf.cmmt_id = commit_cache.cmmt_id
#                                                                     AND md5_fingerprint = '. $this->stage->dbh()->quote($fingerprint) .'
#                                                        			    AND f.file_path = '. $this->stage->dbh()->quote($this->get_repository_relative_path() .'/'. $file) .'
#                                                        			 )'
# 														), false,'cmmt_id DESC');
		END_TIMER('get_current_rev(cached)-q1', PROJECT_PROJECT_TIMERS);
#		bug($file,$fingerprint,count($fp_matches));
		###  If only one match, then we'll assume we got it...
		if ( count($fp_revs) == 1 ) {
			END_TIMER('get_current_rev(cached)', PROJECT_PROJECT_TIMERS);
			return( $this->repo_cache[$cache_key][$file] = array( $fp_revs[0], '',     'Up-to-date', '',          false        ) );
		}

		###  Skip out if file doesn't exist
		if ( $fingerprint == self::$not_exists_fingerprint ) {
			END_TIMER('get_current_rev(cached)', PROJECT_PROJECT_TIMERS);
			return( $this->repo_cache[$cache_key][$file] = array( null, null, null, 'malformed', null ) );
		}

		PAUSE_TIMER('get_current_rev(cached)', PROJECT_PROJECT_TIMERS);
		###  Otherwise, get it the old way...
		list(              $cur_rev,              $error, $status,      $state_code, $is_modified ) = parent::get_current_rev($file);
		RESUME_TIMER('get_current_rev(cached)', PROJECT_PROJECT_TIMERS);

		### ... and if not-modified, cache
		if ( ! $is_modified && empty($error) ) {
			require_once(dirname(dirname(__FILE__)) .'/model/Commit/File.class.php');
			START_TIMER('get_current_rev(cached)-q2', PROJECT_PROJECT_TIMERS);
			$cmmt_file = Ansible__Commit__File::get_where(array( 'EXISTS( SELECT 1
                                                                            FROM commit_cache c
                                                                           WHERE c.cmmt_id = cmmt_file.cmmt_id
                                                                             AND c.revision = '. $this->stage->dbh()->quote($cur_rev) .'
                                                                          )',
																 'EXISTS( SELECT 1
                                                                            FROM repo_file f
                                                                           WHERE f.file_id = cmmt_file.file_id
                                                                             AND f.file_path = '. $this->stage->dbh()->quote($this->get_repository_relative_path() .'/'. $file) .'
                                                                          )'
																 ), true);
			END_TIMER('get_current_rev(cached)-q2', PROJECT_PROJECT_TIMERS);
			if ( ! $cmmt_file ) 
				return trigger_error('ERROR: ran into a revision/file that had a valid SVN status, but was not in the log cache.'. bug($file, $this->get_repository_relative_path(), $cur_rev, $error, $status, $state_code, $is_modified), E_USER_ERROR);
			
			###  Save the fingerprint for next time...
			$cmmt_file->set_and_save(array('md5_fingerprint' => $fingerprint));
		}

		END_TIMER('get_current_rev(cached)', PROJECT_PROJECT_TIMERS);
        return( $this->repo_cache[$cache_key][$file] = array( $cur_rev, $error, $status, $state_code, $is_modified ) );
	}

	function get_file_fingerprint($file) {
		$file = preg_replace('/\/+$/','',$file);
		$test = $this->stage->env()->repo_base .'/'. $file;
		if ( isset( self::$__fingerprint_cache[$test] ) )
			return( self::$__fingerprint_cache[$test] );

		START_TIMER('fingerprint', PROJECT_PROJECT_TIMERS);
		if ( is_dir($test) ) {
			$return = ( substr( 'd'. md5( join('|',array( 'directory',
														  ) ) ), 0, 32 ) );
		}
		else if ( is_link($test) ) {
			$return = ( substr( 'l'. md5( join('|',array( 'link',
														  readlink($test)
														  ) ) ), 0, 32 ) );
		}
		else if ( file_exists($test) ) {
			$return = ( substr( ( is_executable($test) ? 'x' : 'f' )
								. md5_file($test), 0, 32 ) );
		}
		else {
			$return = self::$not_exists_fingerprint;
		}
		END_TIMER('fingerprint', PROJECT_PROJECT_TIMERS);
		return( self::$__fingerprint_cache[$test] = $return );
	}
}

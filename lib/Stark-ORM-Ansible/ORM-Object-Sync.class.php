<?php

//  Load debugging
if ( ! function_exists('START_TIMER') )
	require_once(dirname(__FILE__). '/../debug.inc.php');

class ORM_Object_Sync {
	protected $envs = array();
	public $dry_run     = false;
	public $verbose     = false;
	public $debugging   = false;
	public $delete      = false;
	public $execute     = false; //  Must be set to TRUE for final commit
	public $from_env    = null;
	public $to_env      = null;

	public $max_collisions = 2;
	
	protected $ukey_cache = array();

	public function __construct($config) {
		if ( isset($config['envs']) ) 
			$this->envs = $config['envs'];
	}

	public function sync_objects($main_class, $from_where) {
	    SimpleORM::optimization_mode('memory');

		///  Get all the FROM objects
		$this->switch_to_db($this->from_env);
		$from_db = $this->get_db($this->from_env);
		$from_db->beginTransaction(); ///  just in case
		$from = $main_class::get_where(array($from_where)); 


		///  Switch, as we are working on the TO db now...
		$this->switch_to_db($this->to_env);
		$to_db = $this->get_db($this->to_env);
		$to_db->beginTransaction();

		$to   = $main_class::get_where(array($from_where));

		$from_ukeys = array();
		foreach ($from as $from_o) {
			if ( ! ($by_ukey = $this->get_ukey( $from_o ) ) ) {
				bugw("ERROR : Can't clone ". get_class($from_o) ." because it doesn't have a 'clone_by_ukey' setting yet. Skipping...");
				exit;
			}
			$from_ukeys[ join('||--||', $by_ukey ) ] = $from_o;
		}

		$to_ukeys = array();
		foreach ( $to as $to_o ) {
			$by_ukey = $this->get_ukey( $to_o );
			$key = join('||--||', $by_ukey );

			///  If it doesn't exist in the FROM, then DELETE
			if ( ! isset( $from_ukeys[ $key ] ) ) {
				if ( $this->verbose && $this->delete ) bugw("DELETING ". get_class($to_o) .": ", $key);
				if ( ! $this->dry_run && $this->delete ) {
					if ( $to_o->can_mark_deleted() ) {
						$to_o->mark_deleted();
					}
					else $to_o->delete();
				}
				continue;
			}

			if ( isset( $to_ukeys[ $key ] ) ) {
				if ( $this->verbose && $this->delete ) bugw("DELETING ". get_class($to_o) ."->". $to_o->prod_id .": ", $key);
				if ( ! $this->dry_run && $this->delete ) {
					if ( $to_o->can_mark_deleted() ) {
						$to_o->mark_deleted();
					}
					else $to_o->delete();
				}
				continue;
			}

	
			$to_o->reset_state();
			$to_ukeys[ $key ] = $to_o;
		}

		$i = 0;
		$queue = array_keys($from_ukeys);
		while( ! empty( $queue ) ) {
			$collisions = 0;
			$key = array_shift( $queue );
			if ( is_array( $key ) )
				list( $key, $collisions ) = $key;
			$from_o = $from_ukeys[ $key ];

			///  Save on Memory at the slight cost of performance
#			$GLOBALS['SimpleORM_OBJECT_CACHE'] = array();
#		     foreach( array_keys( $GLOBALS['SimpleORM_OBJECT_CACHE'] ) as $a ) foreach ($GLOBALS['SimpleORM_OBJECT_CACHE'][$i] as $o) $o->reset_state();
			$GLOBALS['ukey_cache'] = array();
			$GLOBALS['ORM_SQL_LOG'] = array();

			///  If Duplication errors, move to the end of the queue
			try {
				$to_set = $from_o->get_all();
				foreach( $from_o->get_primary_key() as $col ) if ( preg_match('/^\d+$/',  $to_set[ $col ] ) ) unset( $to_set[ $col ] );
				$has_ones = $this->get_has_ones( $from_o, $from_o->clone_relations );
				$to_set = array_merge($to_set, $has_ones);

				///  else, they both exist, so UPDATE
				if ( isset( $to_ukeys[ $key ] ) ) {
					///  Check for other-UKey collisions (and throw a Retry-Later Exception if so)
					$this->check_for_other_ukey_collisions( $to_ukeys[ $key ], $to_set, /* Is Update = */true, /* Exception = */true, "UPDATING ". get_class($from_o) );

					$before = $to_ukeys[ $key ]->get_all();
					$to_ukeys[ $key ]->remember_old_values(true);
					$to_ukeys[ $key ]->set($to_set);

					$changed = false;
					foreach ( array_keys( $to_set ) as $col ) if ( $to_ukeys[ $key ]->column_has_changed($col) ) $changed = true;
					if ( $changed ) {
						if ( $this->verbose ) bugw("UPDATING ". get_class($to_ukeys[ $key ]) .": ", $before, "TO: ", $to_set);
						if ( ! $this->dry_run ) $to_ukeys[ $key ]->save();
					}
				}
				///  Only FROM exists, so INSERT
				else {
					///  Check for other-UKey collisions (and throw a Retry-Later Exception if so)
					$this->check_for_other_ukey_collisions( $from_o, $to_set, /* Is Update = */false, /* Exception = */true, "INSERTING ". get_class($from_o) );

					$to_o = new $main_class();
					if ( $this->verbose ) bugw("INSERTING: ", $to_set);
					if ( ! $this->dry_run ) $to_o->create($to_set);
					$to_ukeys[ $key ] = $to_o;
				}
				$this->run_post_sync_handler($from_o, $to_ukeys[ $key ], $to_set);

				$this->clone_relations($from_o, $to_ukeys[ $key ], $from_o->clone_relations);

				$from_o->reset_state();
				$to_ukeys[ $key ]->reset_state();
				$i++;
				if ( $this->verbose ) echo "-------------------------------------------------------\n------   $main_class $i  ----------------------------------\n-------------------------------------------------------\n-------------------------------------------------------\n-------------------------------------------------------\n-------------------------------------------------------\n-------------------------------------------------------\n-------------------------------------------------------\n-------------------------------------------------------\n-------------------------------------------------------\n-------------------------------------------------------\n";
			}
			catch (ORM_Object_Sync__HadCollisionRetryAtEnd $e) {
				$collisions++;

				///  If the collision count is still lower than the max,
				///    then throw it to the end of the queue, hoping that
				///    other rows will update and clear out the conflict
				if ( $collisions <= $this->max_collisions ) {
					if ( $this->verbose ) bugw('COLLISION '. $collisions .' ON: '. $e->getMessage() ."\n\n");
					array_push( $queue, array( $key, $collisions ));
				}
				///  Otherwise, die fatal and roll back
				else {
					if ( $this->verbose ) bugw('FINAL COLLISION '. $collisions .' ON: '. $e->getMessage() ."\n\n");
					$to_db->rollback();
					if ( $this->verbose ) bugw("rolled back \n\n");
					die( $e->getMessage() );
					if ( $this->verbose ) bugw("Thrown \n\n");
					return false;
				}
			}
		}

		///  Just in case...
		$from_db->rollback();

		if ( $this->execute ) {
			$to_db->commit();
			if ( $this->verbose ) bugw("Committing changes\n");
		}
		else {
			$to_db->rollback();
			if ( $this->verbose ) bugw("ROLLED BACK!  (You must use the -x option to commit the changes)\n");
		}
	}

	public function clone_relations($from_o, $to_o, $relation_search) {

		if ( empty( $relation_search ) ) return;

		$pkeys = $to_o->get_primary_key();

		foreach ( $from_o->get_relations() as $relation => $def ) {
			$mode = 'complete';
			$found = false;
			foreach ( $relation_search as $m => $rel ) {
				if (  $relation != $rel ) continue;
				$found = true;  if ( ! is_int( $m ) ) $mode = preg_replace('/\d+$/','',$m);
			}
			if ( ! $found ) continue;
			if ( $this->debugging ) bugw("STARTING ON RELATION ". get_class( $from_o ) .".$relation in $mode MODE\n");
		
			if ( $def['relationship'] == 'has_many' ) {
				$from_ukeys = array();
				$skip_relation = false;
				foreach ( $from_o->get_relation( $relation ) as $from_rel ) {
					if ( ! ($by_ukey = $this->get_ukey( $from_rel ) ) ) {
						bugw("ERROR : Can't clone ". get_class($from_rel) ." because it doesn't have a 'clone_by_ukey' setting yet. Skipping...");
						$skip_relation = true;
						break;
					}
					$from_ukeys[ join('||--||', $by_ukey ) ] = $from_rel;
				}
				if ( $skip_relation ) continue;
			
				$to_ukeys = array();
				foreach ( $to_o->get_relation( $relation ) as $to_rel ) {
					$by_ukey = $this->get_ukey( $to_rel );
					$key = join('||--||', $by_ukey );

					///  If it doesn't exist in the FROM, then DELETE
					if ( $this->debugging ) bugw("LOOKING IN FROM for has_many ". get_class($to_rel) ." (FOR DELETE) by UKey:", $key);
					if ( ! $from_ukeys[ $key ] && $this->debugging ) bugw("NOT FOUND IN FROM for has_many ". get_class($to_rel) ." by UKey:", $key);
					if ( ! $from_ukeys[ $key ] && $this->check_mode('delete', $mode) ) {
						if ( $this->verbose && $this->delete ) bugw("DELETING ". get_class($to_rel) .": ", $key);
						if ( ! $this->dry_run && $this->delete ) {
							if ( $to_rel->can_mark_deleted() ) {
								$to_rel->mark_deleted();
							}
							else $to_rel->delete();
						}
					}
					///  Otherwise we need to loop in this in the next loop
					else $to_ukeys[ $key ] = $to_rel;
				}
				///  Now find the FROM ones that weren't in TO and INSERT
				foreach ( $from_ukeys as $key => $from_rel ) {
				
					$to_set = $from_rel->get_all();
					foreach( $from_rel->get_primary_key() as $col ) if ( preg_match('/^\d+$/',  $to_set[ $col ] ) ) unset( $to_set[ $col ] );
					foreach( (array) $def['foreign_key_columns'] as $i => $col ) $to_set[ $pkeys[$i] ] = $to_o->get($col);
					$rel_has_ones = $this->get_has_ones( $from_rel, $this->descend_relation( $relation, $relation_search ) );
					if ( $this->debugging ) bugw("GOT THESE has_ones in TO for ". get_class( $from_rel ) .":", $this->descend_relation( $relation, $relation_search ), $rel_has_ones);
					$to_set = array_merge($to_set, $rel_has_ones);

					///  IF they both exist, then UPDATE
					if ( $this->debugging ) bugw("LOOKING IN TO for has_many ". get_class($from_rel) ." by UKey:", $key);
					if ( isset( $to_ukeys[ $key ] ) ) {
						if ( $this->debugging ) bugw("Found has_many ". get_class($from_rel) ." in TO side!");
						$before = $to_ukeys[ $key ]->get_all();
						$to_ukeys[ $key ]->remember_old_values(true);
						$to_ukeys[ $key ]->set($to_set);

						$changed = false;
						foreach ( array_keys( $to_set ) as $col ) if ( $to_ukeys[ $key ]->column_has_changed($col) ) $changed = true;
						if ( $changed && $this->check_mode('update', $mode) ) {
							///  Check for other-UKey collisions (and throw a Retry-Later Exception if so)
							$this->check_for_other_ukey_collisions( $to_ukeys[ $key ], $to_set, /* Is Update = */true, /* Exception = */true, "UPDATING ". get_class($to_ukeys[ $key ]) ." for ". get_class($from_o) .'->'.$relation.'('.$def['relationship'].')' );

							if ( $this->verbose ) bugw("UPDATING ". get_class($to_ukeys[ $key ]) .": ", $before, "TO: ", $to_set);
							if ( ! $this->dry_run ) $to_ukeys[ $key ]->save();
						}

						$this->run_post_sync_handler($from_rel, $to_ukeys[ $key ], $to_set);

						///  Now, recurse and run this on this sub-relational object
						$this->clone_relations($from_rel, $to_ukeys[ $key ], $this->descend_relation( $relation, $relation_search ));
					}
					///  Otherwise, only the FROM exists, so INSERT
					else {
                       if ( $this->debugging ) bugw("Did not Find has_many ". get_class($from_rel) ." in TO side!");
						if ( $this->check_mode('create', $mode) ) {
							///  Check for other-UKey collisions (and throw a Retry-Later Exception if so)
							$this->check_for_other_ukey_collisions( $from_rel, $to_set, /* Is Update = */false, /* Exception = */true, "INSERTING ". get_class($from_rel) ." for ". get_class($from_o) .'->'.$relation.'('.$def['relationship'].')' );

							$class = get_class($from_rel);
							$to_rel = new $class();
							if ( $this->verbose ) bugw("INSERTING ". get_class($to_rel) .": ", $to_set);
							if ( ! $this->dry_run ) $to_rel->create($to_set);
						}

						$this->run_post_sync_handler($from_rel, $to_rel, $to_set);
					
						///  Now, recurse and run this on this sub-relational object
						$this->clone_relations($from_rel, $to_rel, $this->descend_relation( $relation, $relation_search ));
					}
				}
			}
			else if ( $def['relationship'] == 'many_to_many' ) {

				$class = $def['class'];

				///  Get all the From Objects
				$to_set_complete = array();
				$skip_relation = false;
				foreach ( $from_o->get_relation( $relation ) as $from_rel ) {
					if ( ! ($by_ukey = $this->get_ukey( $from_rel ) ) ) {
						bugw("ERROR : Can't clone ". get_class($from_rel) ." because it doesn't have a 'clone_by_ukey' setting yet. Skipping...");
						$skip_relation = true;
						break;
					}
					$key = join('||--||', $by_ukey );
					$from_ukeys[ $key ] = $from_rel;

					///  Load this has_many object in the $this->to_env
					if ( ! isset( $GLOBALS['ukey_cache']['to'][ $class ][ $key ] ) ) {
						$this->switch_to_db($this->to_env);
						if ( $this->debugging ) bugw("LOOKING IN TO for many_to_many $class by UKey:", $by_ukey);
						$to_rel = $class::get_where($by_ukey, true);
						if ( !empty( $to_rel ) && $this->debugging ) bugw("Found many_to_many $class in TO side!");

						if ( empty( $to_rel ) ) {
							$to_set = $from_rel->get_all();
							foreach( $from_rel->get_primary_key() as $col ) if ( preg_match('/^\d+$/',  $to_set[ $col ] ) ) unset( $to_set[ $col ] );
							$rel_has_ones = $this->get_has_ones( $from_rel, $this->descend_relation( $relation, $relation_search ) );
							$to_set = array_merge($to_set, $rel_has_ones);

							if ( $this->check_mode('create', $mode) ) {
								///  Check for other-UKey collisions (and throw a Retry-Later Exception if so)
								$this->check_for_other_ukey_collisions( $from_rel, $to_set, /* Is Update = */false, /* Exception = */true, "INSERTING ". get_class($from_rel) ." for ". get_class($from_o) .'->'.$relation.'('.$def['relationship'].')' );
								$to_rel = new $class();
								if ( $this->verbose ) bugw("INSERTING ". get_class($to_rel) .": ", $to_set);
								if ( ! $this->dry_run ) $to_rel->create($to_set);
								///  Below it would try to use $to_rel's primary key.  So just skip...
								else {
									if ( $this->verbose ) bugw("Skipping the rest of ". get_class($from_o) ."->". $relation .": after an insert, the ID's wouldn't be available anyways...");
									$skip_relation = true;
									break;
								}
							}
						
							$GLOBALS['ukey_cache']['to'][ $class ][ $key ] = $to_rel;
						}
					}
					else $to_rel = $GLOBALS['ukey_cache']['to'][ $class ][ $key ];

					if ( $to_rel ) {
						$pkey = array();
						foreach ( $from_rel->get_primary_key() as $col ) $pkey[] = $to_rel->get($col);
						$to_set_complete[] = count( $pkey ) > 1 ? $pkey : $pkey[0];
					}
				}
				if ( $skip_relation ) continue;
			
				///  Now, set the complete relation
				if ( $this->check_mode('link_many_to_many', $mode) ) {
					///  See if there is anything to do...
					if ( $this->debugging ) bugw("BEFORE many_to_many SET COMPLETE RELATION on ". get_class($to_o) ."->". $relation ." checking if changed:", $this->arrays_differ($to_set_complete, $to_o->get_complete_relation($relation) ), $to_set_complete, $to_o->get_complete_relation($relation) );
					if ( $this->arrays_differ($to_set_complete, $to_o->get_complete_relation($relation) ) ) {
						if ( $this->verbose ) bugw("SETTING COMPLETE RELATION ON ". get_class($to_o) ."->". $relation .": ", $to_set_complete);
						if ( ! $this->dry_run ) $to_o->set_complete_relation( $relation, $to_set_complete );
					}
				}

				foreach ( $to_o->get_relation( $relation ) as $to_rel ) {
					$by_ukey = $this->get_ukey( $to_rel );
					$key = join('||--||', $by_ukey );
					$from_rel = $from_ukeys[ $key ];

					///  Now, recurse and run this on this sub-relational object
					$this->clone_relations($from_rel, $to_rel, $this->descend_relation( $relation, $relation_search ));
				}
			}
		}
	}

	public function get_ukey($o) {
		if ( ! empty( $o->clone_by_ukey_method ) ) {
			$method = $o->clone_by_ukey_method;
			return $o->$method();
		}
		if ( empty( $o->clone_by_ukey ) ) return false;
		$by_ukey = array();
		foreach ( $o->clone_by_ukey as $col ) {
			if ( is_null( $o->$col ) ) 
				$by_ukey[] = "$col IS NULL";
			else $by_ukey[ $col ] = $o->$col;
		}
		return $by_ukey;
	}

	public function get_has_ones($from_o, $relation_search) {

#	bugw("SEARCHING IN", get_class($from_o), "FOR", $relation_search);
		$has_ones = array();
		if ( empty( $relation_search ) ) return $has_ones;

		foreach ( $from_o->get_relations() as $relation => $def ) {
			if ( $def['relationship'] != 'has_one' ) continue;
			$mode = 'complete';
			$found = false;
			foreach ( $relation_search as $m => $rel ) {
				if (  $relation != $rel ) continue;
				$found = true;  if ( ! is_int( $m ) ) $mode = preg_replace('/\d+$/','',$m);
			}
			if ( ! $found ) continue;
			if ( $this->debugging ) bugw("STARTING ON RELATION ". get_class( $from_o ) .".$relation in $mode MODE\n");

			$class = $def['class'];

			$from_rel = $from_o->get_relation( $relation );
			if ( empty( $from_rel ) ) {
#			trace_dump();
#			bug('FROM RELATION DID NOT EXIST for '. get_class($from_o) .'->'. $relation, $from_o->pk_values);
				if ( $this->check_mode('has_one_set_null', $mode) ) {
					foreach( (array) $def['columns'] as $col ) $has_ones[$col] = null;
				}
				continue;
			}
			if ( ! ($by_ukey = $this->get_ukey( $from_rel ) ) ) {
				bugw("ERROR : Can't clone ". get_class($from_rel) ." because it doesn't have a 'clone_by_ukey' setting yet. Skipping...");
				continue;
			}
			
			$this->switch_to_db($this->to_env);
			if ( $this->debugging ) bugw("LOOKING IN TO for ". get_class( $from_rel ) ." by UKey:", $by_ukey);
			$to_rel = $class::get_where($by_ukey, true);
			if ( $to_rel && $this->debugging ) bugw("Found has_one ". get_class( $from_rel ) ." in TO side!");

			$to_set = $from_rel->get_all();
			foreach( $from_rel->get_primary_key() as $col ) if ( preg_match('/^\d+$/',  $to_set[ $col ] ) ) unset( $to_set[ $col ] );
			$rel_has_ones = $this->get_has_ones( $from_rel, $this->descend_relation( $relation, $relation_search ) );
			$to_set = array_merge($to_set, $rel_has_ones);

			///  Didn't exist, try to INSERT
			if ( $to_rel && $this->debugging ) bugw("STILL HAVE has_one ". get_class( $from_rel ) ." in TO side!", $to_set);
			if ( empty( $to_rel ) ) {
				///  Check for other-UKey collisions (and throw a Retry-Later Exception if so)
				$this->check_for_other_ukey_collisions( $from_rel, $to_set, /* Is Update = */false, /* Exception = */true, "INSERTING ". get_class($from_rel) ." for ". get_class($from_o) .'->'.$relation.'('.$def['relationship'].')' );

				if ( $this->check_mode('create', $mode) ) {
					$to_rel = new $class();
					if ( $this->verbose ) bugw("INSERTING ". $class .": ", $to_set);
					if ( ! $this->dry_run ) $to_rel->create($to_set);
				}
				else if ( $this->check_mode('has_one_set_null', $mode) ) {
					if ( $this->verbose ) bugw('Skipping an INSERT on '. $class .' because of per-relation sync mode.  Setting NULL instead.', $by_ukey, $from_o->get_all());
					foreach( (array) $def['columns'] as $col ) $has_ones[$col] = null;
				}

				///  Since they will need $to_rel's primary key below, just skip for now...
				if ( $this->dry_run ) continue;

				$this->run_post_sync_handler($from_rel, $to_rel, $to_set);

				///  Now, recurse and run this on this sub-relational object
				$this->clone_relations($from_rel, $to_rel, $this->descend_relation( $relation, $relation_search ));
			}
			///  We found the same object, so UPDATE
			else {
				///  Check for other-UKey collisions (and throw a Retry-Later Exception if so)
				$this->check_for_other_ukey_collisions( $to_rel, $to_set, /* Is Update = */true, /* Exception = */true, "UPDATING ". get_class($to_rel) ." for ". get_class($from_o) .'->'.$relation.'('.$def['relationship'].')' );

				$before = $to_rel->get_all();
				$to_rel->remember_old_values(true);
				$to_rel->set($to_set);
			
				$changed = false;
				foreach ( array_keys( $to_set ) as $col ) if ( $to_rel->column_has_changed($col) ) $changed = true;
				if ( $changed ) {
					if ( $this->check_mode('update', $mode) ) {
						if ( $this->verbose ) bugw("UPDATING ". get_class($to_rel) .": ", $before, "TO: ", $to_set);
						if ( ! $this->dry_run ) $to_rel->save();
					}
					else {
						if ( $this->verbose ) bugw('Skipping an UPDATE on '. $class .' because of per-relation sync mode.', $to_rel->get_all());
					}
				}

				$this->run_post_sync_handler($from_rel, $to_rel, $to_set);

				///  Now, recurse and run this on this sub-relational object
				$this->clone_relations($from_rel, $to_rel, $this->descend_relation( $relation, $relation_search ));
			}

			if ( $this->check_mode('link_has_one', $mode) ) {
				$cols = (array) $def['columns'];
				foreach( $to_rel->get_primary_key() as $i => $col ) $has_ones[ $cols[$i] ] = $to_rel->get($col);
			}
			if ( $this->debugging ) bugw('RELATION has ones: ',$has_ones);
		}

		return $has_ones;
	}

	public function descend_relation( $relation, $relation_search ) {
		$new_rel_search = array();
		foreach ( $relation_search as $mode => $rel ) {
			if ( substr( $rel, 0, strlen( $relation ) + 1) == "$relation." ) {
				if ( is_int( $mode ) )
					$new_rel_search[] = substr( $rel, strlen( $relation ) + 1);
				else $new_rel_search[$mode] = substr( $rel, strlen( $relation ) + 1);
			}
		}
		return $new_rel_search;
	}

	public function check_for_other_ukey_collisions( $obj, $to_set, $is_update, $throw_exception = false, $operation_type = null ) {
		if ( ! isset( $obj->other_ukeys ) ) return false;
		
		foreach ( $obj->other_ukeys as $ukey ) {
			$by_ukey = array();
			foreach ( $ukey as $col ) {
				if ( ! isset( $to_set[ $col ] ) )
					continue 2;
				else $by_ukey[ $col ] = $to_set[ $col ];
			}
			///  If is update, then exclude this primary key
			if ( $is_update ) {
				$exclude = array();
				foreach( $obj->get_primary_key() as $i => $col ) {
					///  If the UKey has a NULL in it, then Skip.  No collision is possible
					///    because null values in the UKey permit duplicates
					if ( is_null($obj->$col) ) continue 2;
					$exclude[] = "$col != ". $obj->dbh()->quote( $obj->$col );
				}
				$by_ukey[] = '('. join(' OR ', $exclude ) .')';
			}

			///  Check for collision
			$class = get_class($obj);
			$collision = $class::get_where( $by_ukey, true);

			///  Either Throw an Exception, or return true
			if ( ! empty( $collision ) ) {

               if ( $this->verbose ) bugw("COLLISION ON Unique Key.  Experimental: Deleting ". get_class($collision) ." object that we collided with. A later update will hopefully re-insert it without issue.  FINGERS CROSSED!INSERTING:", $by_ukey, $collision->get_all());

               if ( $collision->can_mark_deleted() ) {
                   $collision->mark_deleted();
               }
               else $collision->delete();
               return true;


#              if ( $this->debugging ) bugw(__LINE__);
#              if ( $throw_exception ) {
#                          if ( $this->debugging ) bugw(__LINE__);
#                  throw new ORM_Object_Sync__HadCollisionRetryAtEnd($operation_type .' ('. var_export($by_ukey, true) .')');
#              }
#              else return true;
#                          if ( $this->debugging ) bugw(__LINE__);
			}
		}
	}

	public function check_mode($action, $mode) {
		if ( $mode == 'complete' ) return true;
		else if ( $mode == 'link_or_set_null' && in_array($action, array('link_has_one','has_one_set_null','link_many_to_many')) ) return true;
		return false;
	}

	///  Utility Methods
	public function switch_to_db($env_name) {
		$env = $this->envs[$env_name];
		return call_user_func_array( $env['switch_db'], array($env['dbname']) );
	}
	public function get_db($env_name) {
		$env = $this->envs[$env_name];
		return call_user_func_array( $env['get_db'], array($env['dbname']) );
	}
	public function from_env() { return (object) $this->envs[$this->from_env]; }
	public function to_env()   { return (object) $this->envs[$this->to_env]; }
	public function arrays_differ($ary1, $ary2) {
		if ( count($ary1) != count( $ary2 ) ) return true;
		
		$diff = array_diff( $ary1, $ary2 );
		if ( ! empty( $diff ) ) return true;
		$diff = array_diff( $ary2, $ary1 );
		if ( ! empty( $diff ) ) return true;

		return false;
	}

	public function run_post_sync_handler($from_o, $to_o, &$to_set) {
		$obj = ( empty( $to_o ) || ! $to_o->exists() ) ? $from_o : $to_o;
		
		if ( method_exists($obj, 'post_sync_handler') ) {
			return $obj->post_sync_handler($this, $from_o, $to_o, $to_set );
		}
		return false;
	}
}

class ORM_Object_Sync__HadCollisionRetryAtEnd extends Exception {

}
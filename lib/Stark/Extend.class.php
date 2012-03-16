<?php

/*  Stark/Extend.php - Generic System Extension framework
 * 
 * Example usage:
 * <code> */
 # class MyApp {
 #     public $extend = null;
 #     
 #     public function __construct() {
 #         /// ...
 #     
 #         $this->extend = new Stark__Extend();
 #     }
 #     
 #     class MyApp {
 #     ///  This is your function in your Application
 #     function my_app_header($my_context) {
 #         //  This hook, position 0 will trigger and run all extension hooks for 'my_app_header'
 #         //  with a position of: <= 0
 #         //
 #         //  It has the return hook, so if any extension returns a non-empty value, it will cause my_app_header() to return as well
 #         //
 #         //  This potision 0 w/ return is useful to give extenders the ability to completely replace your function
 #         //  and run their code instead...
 #     
 #         /* HOOK */$__x = $this->extend->x('my_app_header', 0); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh();if($__x->hr()) return $__x->get_return();
 #         
 #         $my_info = $this->get_info();
 #     
 #         //  This hook, position 5 will trigger and run all extension hooks for 'my_app_header'
 #         //  from with a position of: > 0 and <= 5
 #         //
 #         //  It does NOT have the return hook, so return values of any extension hooks will be ignored
 #         //
 #         //  This is a common useful extend point, where a variable (e.g. $my_info) has just been
 #         //  defined, and you want to allow extenders the ability to override it before you operate on
 #         //  it, or check it's contents.  In the hook, they simply need to re-set $my_info and the
 #         //  changes to $my_info will be reflected in this local $my_info.
 #     
 #         /* HOOK */$__x = $this->extend->x('my_app_header', 5); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh();
 #     
 #         $content = '';
 #         if ( ! empty( $my_info ) ) {
 #             $content .= $this->get_extra_info( $my_info );
 #         }
 #     
 #         //  This hook, position 5 will trigger and run all extension hooks for 'my_app_header'
 #         //  from with a position of: > 5 and <= 10
 #         //
 #         //  This could be a last check to let them change the return value if you want them to be able to.
 #     
 #         /* HOOK */$__x = $this->extend->x('my_app_header', 5); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh();if($__x->hr()) return $__x->get_return();
 #         return $prefix. $content .$suffix;
 #     }
 # }
 # 
 # ///  ...  In another file, e.g. DavesExtension.php
 # 
 # class GlassesCom {
 #     
 #     public function __construct() {
 #         ///  You could call hook here	 
 #     }
 #     
 #     public function hook_ansible($extend) {
 #         ///  But assuming this class does more than just Extend MyApp, we'll do it here...
 # 
 #         $extend->hook('my_app_header',    array( $this, 'on_command_output'), 0); // hooking onto
 # 
 #         $extend->hook('other_myapp_hook', array( $this, 'define_new_actions'), -50);
 #     }
 #     
 #     public function on_command_output($scope) {
 #     	require( $scope->import_scope() );
 #     
 #            if ( $stage->onLive() && ! preg_match('/^TAG/', $view->previous_command['cmd'] ) ) $this->print_GLASSES_COM_uncache_block($stage);
 #     }
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
 # 
/* </code>
 *
 * This 
 *
 * Hook Syntax - (Application-Side)
 *
 * Hook Syntax - (Extension-Side)
 *
 * @author Dave Buchanan <dave@joesvolcano.net>
 * @package SimpleORM
 * @version $Id: Extend.php 1.0 2012/03/09 22:30:23 dave Exp $
 */

///  Profiling
if ( ! defined(STARK_EXTEND_PROFILE) ) define('STARK_EXTEND_PROFILE', false);

class Stark__Extend {
	protected $hooks = array();
	public $nest_depth = 0;

	///  Runtime vars
	protected $__scope = array();
	protected $dirname_file = null;
    protected $run_hook_params = array();
	public $last_hook_caller = array();
	public $__dont_import_vars = array('__Stark__Extend__scope_var__'=>1,'__Stark__Extend__object__'=>1,'__Stark__Extend__hooks__'=>1,'__Stark__Extend__scope_vars__'=>1,'__x'=>1,'__xi'=>1,
									   'GLOBALS'=>1,'_SERVER'=>1,'_GET'=>1,'_POST'=>1,'_FILES'=>1,'_COOKIE'=>1,'_SESSION'=>1,'_REQUEST'=>1,'_ENV'=>1,
									   'HTTP_COOKIE_VARS'=>1,'HTTP_GET_VARS'=>1,'HTTP_POST_FILES'=>1,'HTTP_POST_VARS'=>1,'HTTP_SERVER_VARS'=>1,'HTTP_ENV_VARS'=>1,'HTTP_SESSION_VARS'=>1,
									   'this'=>1
									   );

	public function __construct( ) {
		$this->dirname_file = dirname(__FILE__);
	}
    
    public function get_hooks() {
        return $this->hooks;
    }

	public function extract_vars_keys(&$defined_vars) {
		$out = array();
		foreach ( array_keys($defined_vars) as $scope_var ) if ( ! isset($this->__dont_import_vars[ $scope_var ] ) ) $out[] = $scope_var;
		return $out;
	}

	/* hook($area, $callback, [$sequence = 0, [$priority = 0]]) register a new Hook
	 *
	 * The $callback parameter can be in any of these formats:
	 *     'my_hook_function'                    										 - For static non-class functions (without parameters)
	 *     array( 'my_hook_function', array( $param1, $param2, ...) ) 				     - For static non-class functions (with parameters)
	 *     array( 'MyClass', 'my_hook_method' )  										 - For static class methods (without parameters)
	 *     array( $my_object, 'my_hook_method' ) 										 - For non-static class methods (without parameters)
	 *     array( array( 'MyClass', 'my_hook_method' ), array( $param1, $param2, ...) )  - For static class methods (with parameters)
	 *     array( array( $my_object, 'my_hook_method' ), array( $param1, $param2, ...) ) - For non-static class methods (with parameters)
	 */
	public function hook($area, $callback, $sequence = 0, $priority = 0) {
		if ( ! is_numeric( $sequence ) || ! is_numeric( $priority ) ) return trigger_error("Both Stark__Extend->hook() parameters 3 and 4 (sequence and priority) must be numeric", E_USER_ERROR);
		if ( ! is_array( $this->hooks[$area] ) || ! is_array( $this->hooks[$area][$sequence] || ! is_array( $this->hooks[$area][$sequence][$priority] ) ) )
			$this->hooks[$area][$sequence][$priority] = array();

		///  Verify that the callback matches the suported formats
		if ( ! ( is_string( $callback )
				 ///  Static static or non-static class methods (without parameters)
				 || ( is_array( $callback )
					  && isset( $callback[0] ) && ! is_array( $callback[0] )
					  && isset( $callback[1] ) && ! is_array( $callback[1] )
					  )
				 ///  Static static or non-static class methods (without parameters)
				 || ( is_array( $callback )
					  && isset( $callback[0] ) && ! is_object( $callback[0] )
					  && isset( $callback[1] ) && is_array( $callback[1] )
					  )
				 )
			 )
			trigger_error("Invalid Stark__Extend hook format in ". trace_blame_line(array('hook')), E_USER_ERROR);

		///  Add the Hook in place
		$this->hooks[$area][$sequence][$priority][] = $callback;
	}

	public function remove_hook($callback) {
		foreach( array_keys( $this->hooks ) as $area ) {
			foreach( array_keys( $this->hooks[$area] ) as $sequence ) {
				foreach( array_keys( $this->hooks[$area][$sequence] ) as $priority ) {
					///  Check if this is exactly the same callback (quick recursive compare)
					if ( var_export( $callback, true) == var_export( $this->hooks[$area][$sequence][$priority], true) ) {
						unset(                                                $this->hooks[$area][$sequence][$priority] );
						if ( empty( $this->hooks[$area][$sequence] ) ) unset( $this->hooks[$area][$sequence] );
						if ( empty( $this->hooks[$area]            ) ) unset( $this->hooks[$area] );
					}
				}
			}
		}
	}

	public function run_hook($area, $sequence_from, $sequence_to = null) {
		if ( STARK_EXTEND_PROFILE ) START_TIMER('Stark__Extend->run_hook');
		if ( ! is_numeric( $sequence_from ) ) return trigger_error("Stark__Extend->run_hook() parameter 2 (sequence_from) must be numeric in ". trace_blame_line(array('run_hook')), E_USER_ERROR);

		$trace = debug_backtrace();
		///  If caller was rhni (and call_user_func_array(), then skip those 2
		if ( empty( $trace[0]['file'] ) && $trace[1]['file'] == __FILE__  && $trace[2]['function'] == 'rhni' ) {
			array_splice($trace,0,2);
		}
		if ( is_null( $sequence_to ) ) {
			if ( empty( $this->last_hook_caller[ $area ] )
				 ///  Check to see if the sequence number went down (will cause re-running if people have out-of-order sequnce nums)
				 || $sequence_from < $this->last_hook_caller[ $area ]['sequence_to']
				 ///  Check if they are still in a different file
				 || $trace[0]['file'] != $this->last_hook_caller[ $area ]['file']
				 ///  Check if they are in the same file, but moved backwards
				 || $trace[0]['line'] < $this->last_hook_caller[ $area ]['line']
				 ) { 
				$sequence_to = $sequence_from;
				$sequence_from = -10000;
			}
            ///  If they are at the same line, this may be a loop.
            else if ( $trace[0]['line'] == $this->last_hook_caller[ $area ]['line'] ) {
				$sequence_to = $sequence_from;
            }
			///  Otherwise, we know we are in the same file, and have advanced in line number...
			else { 
				$sequence_to = $sequence_from;
				$sequence_from = $this->last_hook_caller[ $area ]['sequence_to'] + .00001;
			}
		}
		$this->last_hook_caller[ $area ] = array( 'sequence_to' => $sequence_to,
												  'file' => $trace[0]['file'],
												  'line' => $trace[0]['line']
												  );

		$GLOBALS['__Stark__Extend__object__'] = $this;

		if ( ! is_numeric( $sequence_to   ) ) return trigger_error("Stark__Extend->run_hook() parameter 3 (sequence_to) must be numeric in ". trace_blame_line(array('run_hook')), E_USER_ERROR);
		if ( $sequence_to < $sequence_from ) return trigger_error("In Stark__Extend->run_hook() sequence_to cannot be less than sequence_from in ". trace_blame_line(array('run_hook')), E_USER_ERROR);
		
		$scope = $this->enter_scope();
		if ( ! empty( $this->hooks[$area] ) ) {
			ksort( $this->hooks[$area], SORT_NUMERIC );
			foreach( array_keys( $this->hooks[$area] ) as $sequence ) {
				ksort( $this->hooks[$area][$sequence], SORT_NUMERIC );
                if ( $sequence >= $sequence_from && $sequence <= $sequence_to ) {
                    foreach( array_keys( $this->hooks[$area][$sequence] ) as $priority ) {
                        ///  If this hook is in the range, then include it...
						ksort( $this->hooks[$area][$sequence][$priority], SORT_NUMERIC );
						foreach($this->hooks[$area][$sequence][$priority] as $callback ) $scope->__hooks[] = $callback;
					}
				}
			}
		}

		///  This is the file to be included
		$return = $this->dirname_file. '/Extend/run_hooks.inc.php';
		return $return;
	}
	public function x($area, $sequence_from, $sequence_to = null) { $this->run_hook_params = array( $area, $sequence_from, $sequence_to );  return $this; }
	public function rhni(&$defined_vars, $run_hook_params = null) {
        if ( is_null( $run_hook_params ) ) $run_hook_params = $this->run_hook_params;
		call_user_func_array(array($this, 'run_hook'), $run_hook_params);
		return $this->extract_vars_keys($defined_vars);
	}

	public function import_scope($dont_import = array() ) {
		if ( STARK_EXTEND_PROFILE ) START_TIMER('Stark__Extend->import_scope');
		if ( is_array( $dont_import ) ) $this->__dont_import = $dont_import;

		///  This is the file to be included
		$return = $this->dirname_file. '/Extend/import_scope.inc.php';
		if ( STARK_EXTEND_PROFILE ) PAUSE_TIMER('Stark__Extend->import_scope');
		if ( STARK_EXTEND_PROFILE ) START_TIMER('Stark__Extend->include overhead');
		return $return;
	}

	

	/////////////////////////
	///  Scope Accessors

	public function enter_scope() {
		$this->leave_finished_scopes();

		$this->__scope[$this->nest_depth] = new Stark__Extend__Scope($this);
		$this->nest_depth++;
		return $this->scope();
	}
	public function leave_finished_scopes() {
		///  Close previous FINISHED scopes
		foreach ( array_reverse( array_keys($this->__scope) ) as $scope_idx ) {
			if ($this->__scope[ $scope_idx ]->finished()) {
				unset( $this->__scope[ $scope_idx ] );
				$this->nest_depth--;
			}
			else break;
		}
	}
	public function scope() { return $this->__scope[$this->nest_depth - 1]; }
	public function has_return() { return $this->__scope[$this->nest_depth - 1]->has_return(); }
	public function hr() { return $this->__scope[$this->nest_depth - 1]->has_return(); }
	public function sv($key, &$val) { return $this->__scope[$this->nest_depth - 1]->set_var($key, $val); }
	public function srh() { return $this->__scope[$this->nest_depth - 1]->run_hook(); }
	public function &get_return($as_array = false) { $return =& $this->__scope[$this->nest_depth - 1]->get_return($as_array); return $return; }


	/////////////////////////
	///  Automated Extension Loader (just provide a class)

	public function load_extension($class, $file = null, $method = '__load_hooks') {
		///  Optional in case they want to rely on Autoloader
		if ( ! is_null( $file )
			 ///  In case they have autoloader on, but don't know it...
			 && ! class_exists( $class, true )
			 ) {
			require_once( $file );
		}

		///  Constructor style load
		if ( class_exists( $class, true ) ) {
			$class_methods = get_class_methods($class);
			///  If they don't have the Hook method defined
			if ( ! is_array( $method, $class_methods ) ) {
				$ext = new $class($this);

				///  This should have done the hook loading..
			}
			
			///  Method style load
			else {
				$ext = new $class();
				$ext->$method( $this );
			}
		}
		else {
			trigger_error('Class did not exist', E_USER_ERROR);
		}
		return true;
	}

}

class Stark__Extend__Scope {
	private $extend = null;
	private $vars = null;
	private $locked = false;
	private $finished = false; //  All hooks have finished

	///  Runtime temp vars
	public $__hooks = array();
	public $__scope = null;
	public $__dont_import = null;
	protected $last_hook_return_value = array();

	public function __construct(Stark__Extend $extend) {
		$this->extend = $extend;
		$this->vars = array();
	}
	public function extend() { return $this->extend; }
	public function lock() { $this->locked = true; }
	public function finish() { $this->finished = true; }
	public function finished() { return $this->finished; }


	//////////////////////////
	///  Scope Vars access

	public function vk() { return $this->vars_keys(); }
	public function vars_keys() {
		$out = array();
		foreach ( array_keys( $this->vars ) as $var ) 
			if ( ! in_array(         $var, $this->extend->__dont_import )
				 && ! in_array( '$'. $var, $this->extend->__dont_import )
				 ) $out[] = $var;

		///  Only can call this once!
		$this->extend->__dont_import = array();

		return $out;
	}
	public function set_var($key, &$val) { if ( $this->locked ) return; $this->vars[$key] =& $val; }
	public function &gv(     $key) { if ( isset( $this->vars[ $key ] ) ) return $this->vars[ $key ]; }
	public function &get_var($key) { if ( isset( $this->vars[ $key ] ) ) return $this->vars[ $key ]; }
	public function i(           $dont_import = array() ) {
		$return = $this->extend->import_scope($dont_import);
		if ( STARK_EXTEND_PROFILE ) END_TIMER('Stark__Extend->include overhead');
		if ( STARK_EXTEND_PROFILE ) RESUME_TIMER('Stark__Extend->import_scope');
		if ( STARK_EXTEND_PROFILE ) END_TIMER('Stark__Extend->import_scope');
		return $return;
	}
	public function import_scope($dont_import = array() ) { return $this->extend->import_scope($dont_import); }

	/////////////////////////
	///  Handle return values

	public function set_return_value(&$ret_value) {
		$this->last_hook_return_value[ count($this->last_hook_return_value) ] =& $ret_value;
	}
	public function has_return() { return( (count($this->last_hook_return_value) > 0) ? true : false ); }
	public function hr() { return( (count($this->last_hook_return_value) > 0) ? true : false ); }
	///  TODO: This has a possible injection point
	public function &get_return($as_array = false) {
		if ( $as_array ) { return $this->last_hook_return_value; }
		else if (count($this->last_hook_return_value) > 0) { return $this->last_hook_return_value[count($this->last_hook_return_value) - 1]; }
		else {$n = null; return $n; }
	}

	/////////////////////////
	///  Run Hooks Runtime

	public function run_hook() {
		/// Stark__Extend->run_hook has a RESUME_TIMER at the beginning of the include (or the rhni func)

		$this->lock();

		$hooks = $this->__hooks;
		foreach( $hooks as $callback ) {
			////// Supported Formats:
			///  Static non-class functions (without parameters)
			if ( is_string( $callback )
				 ///  Static static or non-static class methods (without parameters)
				 || ( is_array( $callback )
					  && isset( $callback[0] ) && ! is_array( $callback[0] )
					  && isset( $callback[1] ) && ! is_array( $callback[1] )
					  )
				 )
				list($func, $params) = array($callback, array() );
			///  Static static or non-static class methods (with parameters)
			else if ( is_array( $callback )
					  && isset( $callback[0] ) && ! is_object( $callback[0] )
					  && isset( $callback[1] ) && is_array( $callback[1] )
					  )
				list($func, $params) = $callback;
			else continue; // Should never happen, because of validation in hook()
	
			///  Always add the Extend object as the first parameter
			array_unshift( $params, $this );

			if ( STARK_EXTEND_PROFILE ) PAUSE_TIMER('Stark__Extend->run_hook');
			$ret_val = call_user_func_array( $func, $params );
			if ( STARK_EXTEND_PROFILE ) RESUME_TIMER('Stark__Extend->run_hook');
			///  If they had a non-null return value, then record it
			if ( ! is_null( $ret_val ) ) {
				$this->set_return_value($ret_val);
				///  But don't break, because the other hooks need to run...
			}
			$this->extend->leave_finished_scopes();
		}
		$this->finish(); // This will later trigger the closing of this scope and cleanup of the vars
		if ( STARK_EXTEND_PROFILE ) END_TIMER('Stark__Extend->run_hook');
	}
	
}
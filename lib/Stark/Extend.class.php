<?php

class Stark__Extend {
	protected $hooks = array();
	public $last_hook_caller = array();

	///  Runtime temp vars
	public $__scope = null;
	public $__hooks = null;
	public $__dont_import = null;

	public function __construct( ) {
	}
    
    public function get_hooks() {
        return $this->hooks;
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
		if ( ! is_numeric( $sequence_from ) ) return trigger_error("Stark__Extend->run_hook() parameter 2 (sequence_from) must be numeric in ". trace_blame_line(array('run_hook')), E_USER_ERROR);

		$trace = debug_backtrace();
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
		
		$this->__hooks = array();
 		
		if ( ! empty( $this->hooks[$area] ) ) {
			ksort( $this->hooks[$area], SORT_NUMERIC );
			foreach( array_keys( $this->hooks[$area] ) as $sequence ) {
				ksort( $this->hooks[$area][$sequence], SORT_NUMERIC );
                if ( $sequence >= $sequence_from && $sequence <= $sequence_to ) {
                    foreach( array_keys( $this->hooks[$area][$sequence] ) as $priority ) {
                        ///  If this hook is in the range, then include it...
						ksort( $this->hooks[$area][$sequence][$priority], SORT_NUMERIC );
						foreach($this->hooks[$area][$sequence][$priority] as $callback ) $this->__hooks[] = $callback;
					}
				}
			}
		}

		///  This is the file to be included
		return dirname(__FILE__). '/Extend/run_hooks.inc.php';
	}

	public function import_scope($dont_import = array() ) {
		if ( is_array( $dont_import ) ) $this->__dont_import = $dont_import;

		///  This is the file to be included
		return dirname(__FILE__). '/Extend/import_scope.inc.php';
	}
}
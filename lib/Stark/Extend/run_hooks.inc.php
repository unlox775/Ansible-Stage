<?php

if ( ! isset( $GLOBALS['__Stark__Extend__object__'] ) ) 
	trigger_error('import_scope.inc.php included without $GLOBALS[\'__Stark__Extend__object__\'] being defined', E_USER_ERROR);

///  Extract the current function scope
$GLOBALS['__Stark__Extend__object__']->__scope = array();
foreach( array_keys(get_defined_vars()) as $__Stark__Extend__scope_var__ ) {
	if ( in_array( $__Stark__Extend__scope_var__, array('__Stark__Extend__scope_var__','__Stark__Extend__object__',
														'GLOBALS','_SERVER','_GET','_POST','_FILES','_COOKIE','_SESSION','_REQUEST','_ENV',
														'HTTP_COOKIE_VARS','HTTP_GET_VARS','HTTP_POST_FILES','HTTP_POST_VARS','HTTP_SERVER_VARS','HTTP_ENV_VARS','HTTP_SESSION_VARS',
                                                        'this'
														) ) ) continue;
	$GLOBALS['__Stark__Extend__object__']->__scope[$__Stark__Extend__scope_var__] =& $$__Stark__Extend__scope_var__;
}
unset( $__Stark__Extend__scope_var__ );

foreach( $GLOBALS['__Stark__Extend__object__']->__hooks as $callback ) {
	////// Supported Formats:
	///  Static non-class functions (without parameters)
	if ( is_string( $callback )
		 ///  Static static or non-static class methods (without parameters)
		 || ( is_array( $callback )
			  && isset( $callback[0] ) && ! is_array( $callback[0] )
			  && isset( $callback[1] ) && ! is_array( $callback[1] )
			  )
		 )
		list($callback, $params) = array($callback, array() );
	///  Static static or non-static class methods (without parameters)
	else if ( is_array( $callback )
			  && isset( $callback[0] ) && ! is_object( $callback[0] )
			  && isset( $callback[1] ) && is_array( $callback[1] )
			  )
		list($callback, $params) = $callback;
	else continue; // Should never happen, because of validation in hook()
	
	///  Always add the Extend object as the first parameter
	array_unshift( $params, $GLOBALS['__Stark__Extend__object__'] );

	call_user_func_array( $callback, $params );
}
$GLOBALS['__Stark__Extend__object__']->__hooks = null;
$GLOBALS['__Stark__Extend__object__']->__scope = null;

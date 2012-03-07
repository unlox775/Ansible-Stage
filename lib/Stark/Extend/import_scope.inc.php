<?php

if ( ! isset( $GLOBALS['__Stark__Extend__object__'] ) ) 
	trigger_error('import_scope.inc.php included without $GLOBALS[\'__Stark__Extend__object__\'] being defined', E_USER_ERROR);

///  Import ALL local scope variables from the hook point
///    we could do this with extract(), but first we'd have
///    to modify the scope, which we can't do because other
///    hooks in the same sequence will be using it.
foreach( array_keys( $GLOBALS['__Stark__Extend__object__']->__scope ) as $__Stark__Extend__import_var__ ) {
	///  Ignore requested variables
	if ( in_array(         $__Stark__Extend__import_var__, $GLOBALS['__Stark__Extend__object__']->__dont_import )
		 || in_array( '$'. $__Stark__Extend__import_var__, $GLOBALS['__Stark__Extend__object__']->__dont_import )
		 )
		continue;
	$$__Stark__Extend__import_var__ =& $GLOBALS['__Stark__Extend__object__']->__scope[ $__Stark__Extend__import_var__ ];
}
unset( $__Stark__Extend__import_var__ );
	
///  Clear these out (don't clear scope, because it needs to exist for later...
$GLOBALS['__Stark__Extend__object__']->__dont_import = array();

<?php

///  Allow either form, REDIRECT_URL or $_GET['__t__']
$target = null;
if ( ! isset( $_SERVER['REDIRECT_URL'] ) && ! empty( $_GET['__t__'] ) ) {
	$target = $_GET['__t__'];
	unset($_GET['__t__']);
	unset($_REQUEST['__t__']);
	///  Rewrite QUERY_STRING, etc
	$qs = explode('&', $_SERVER['QUERY_STRING']);
	$_SERVER['QUERY_STRING'] = join('&', array_diff( $qs, array('__t__='. $target ) ));
	if ( isset( $_SERVER['argv'] ) && isset( $_SERVER['argv'][0] ) ) $_SERVER['argv'][0] = $_SERVER['QUERY_STRING'];
	$_SERVER['REQUEST_URI']     = dirname(dirname($_SERVER['SCRIPT_NAME'])) .'/'. $target . ( empty($_SERVER['QUERY_STRING'] ) ? '' : '?'. $_SERVER['QUERY_STRING'] ) ;

} else if ( isset( $_SERVER['REDIRECT_URL'] ) ) {
	$target = basename( $_SERVER['REDIRECT_URL'] );
	unset( $_SERVER['REDIRECT_URL'] );
	if ( isset($_SERVER['REDIRECT_QUERY_STRING']) ) unset( $_SERVER['REDIRECT_QUERY_STRING'] );
	if ( isset($_SERVER['REDIRECT_UNIQUE_ID']) ) unset( $_SERVER['REDIRECT_UNIQUE_ID'] );
	if ( isset($_SERVER['REDIRECT_file_gzip']) ) unset( $_SERVER['REDIRECT_file_gzip'] );
}

///  Determine our path to the controller
$levels = explode( DIRECTORY_SEPARATOR, preg_relace('^.*'. DIRECTORY_SEPARATOR .'ajax'. DIRECTORY_SEPARATOR .'','', $_SERVER['SCRIPT_NAME']) );
$__controller_path = $_SERVER['SCRIPT_FILENAME'];
foreach ( range( 1, count( $levels ) ) ) $__controller_path = dirname( $__controller_path );
unset( $levels );

///  Simulate as if this request was actually for [PREFIX]/[TARGET]
$_SERVER['SCRIPT_FILENAME'] = dirname(dirname($_SERVER['SCRIPT_FILENAME'])) .'/'. $target;
$_SERVER['SCRIPT_NAME']     = dirname(dirname($_SERVER['SCRIPT_NAME']))     .'/'. $target;
$_SERVER['PHP_SELF']        = dirname(dirname($_SERVER['PHP_SELF']))        .'/'. $target;

if ( isset($_SERVER['REDIRECT_STATUS']) ) unset( $_SERVER['REDIRECT_STATUS'] );
unset( $target );

///  Set a flag so that Stark knows we are in AJAX_MODE
$_SERVER['__STARK_AJAX_MODE__'] = true;

///  Now, run the controller as if we were [PREFIX]/[TARGET]
require($__controller_path .'/ansible-controller.inc.php');

///  Note for when the AJAX handler doesn't exit...
?>
ERROR : AJAX Handler didn't exit or wasn't defined...

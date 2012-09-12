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
	$_SERVER['REQUEST_URI']     = dirname($_SERVER['SCRIPT_NAME'])     .'/'. $target . ( empty($_SERVER['QUERY_STRING'] ) ? '' : '?'. $_SERVER['QUERY_STRING'] ) ;

} else if ( isset( $_SERVER['REDIRECT_URL'] ) ) {
	$target = basename( $_SERVER['REDIRECT_URL'] );
	unset( $_SERVER['REDIRECT_URL'] );
	if ( isset($_SERVER['REDIRECT_QUERY_STRING']) ) unset( $_SERVER['REDIRECT_QUERY_STRING'] );
	if ( isset($_SERVER['REDIRECT_UNIQUE_ID']) ) unset( $_SERVER['REDIRECT_UNIQUE_ID'] );
	if ( isset($_SERVER['REDIRECT_file_gzip']) ) unset( $_SERVER['REDIRECT_file_gzip'] );
}

///  Simulate as if this request was actually for [PREFIX]/actions/[TARGET]
$_SERVER['SCRIPT_FILENAME'] = dirname($_SERVER['SCRIPT_FILENAME']) .'/'. $target;
$_SERVER['SCRIPT_NAME']     = dirname($_SERVER['SCRIPT_NAME'])     .'/'. $target;
$_SERVER['PHP_SELF']        = dirname($_SERVER['PHP_SELF'])        .'/'. $target;

if ( isset($_SERVER['REDIRECT_STATUS']) ) unset( $_SERVER['REDIRECT_STATUS'] );
unset( $target );

///  Now, run the controller as if we were [PREFIX]/actions/[TARGET]
require(dirname(dirname($_SERVER['SCRIPT_FILENAME'])) .'/ansible-controller.inc.php');

///  Check that a controller did run, and it seems to be a Command-Running one...
if ( empty( $view->cmd ) ) {
	echo "ERROR : Page Handler didn't exit...";
	exit;
}
<?php require($stage->extend->run_hook('command_output', 0)) ?>
<font color="red">
	<xmp>> <?php echo $view->cmd ."\n\n". $view->command_output ?></xmp>
</font>

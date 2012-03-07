<?php

//  Load debugging
if ( ! function_exists('START_TIMER') )
	require_once(dirname(__FILE__). '/../debug.inc.php');
if ( ! class_exists('Stark__Extend') )
	require_once(dirname(__FILE__). '/Extend.class.php');

class Stark__Controller {
	public $path = null;
	public $view = null;
	public $ajax_mode = false;

	///  Config
	public $lib_path = null;
	public $config_path = null;
	public $controller_path = null;
	public $model_path = null;
	public $CONTROLLER_CLASS_PREFIX = '';
	public $CONTROLLER_FILE_SUFFIX = '.class.php';
	public $CONTROLLER_DEFAULT_INDEX = 'index.php';
	public $CONTROLLER_PAGE_SUFFIX_REGEX = '/.php$/';
	public $CONTROLLER_ROOT_NAME = 'root';
	public $CONTROLLER_PRELOAD_LIBS = array();
	public $scope_global_vars = array('ctl','controller');

	///  Debugging Params
	public $STARK_PROFILING = false;

	public function __construct( $path, $path_config ) {
		$this->path = $path;

		///  Load the paths
		//		foreach ( array('lib_path','config_path','controller_path','model_path') as $var ) {
		///  HACK until we get Stark__Extend written
		foreach ( array_keys($path_config) as $var ) {
			if ( isset( $path_config[ $var ] ) ) $this->$var = $path_config[ $var ];
		}

		///  If we have 'config_path', then run the config
		if ( ! empty( $this->config_path ) ) $this->read_config( $this->config_path );

		///  Pre-load Stark__Extend, because we will be using it...
		$this->extend = new Stark__Extend();
	}


	public function read_config($config_path) {
		///  Set $this into a few convenience var names...
		$controller = $this;
		$ctl = $this;
		$config = $this;

		///  This will generate errors if it doesn't exist.
		///    TODO: Do our own file_exists() check and our own error...
		require( $config_path );
	}

	public function handler() {
		//  Load Main Libraries
		if ( ! empty( $this->STARK_PROFILING ) ) START_TIMER('STARK-controller-lib_load');
		require_once($this->lib_path. '/Stark/View.class.php');
		require_once($this->lib_path. '/Stark/Controller/Base.class.php');
		if ( isset( $this->CONTROLLER_PRELOAD_LIBS ) ) foreach( $this->CONTROLLER_PRELOAD_LIBS as $lib ) require_once($lib);
		if ( ! empty( $this->STARK_PROFILING ) ) END_TIMER('STARK-controller-lib_load');

		//  Main Controller Sequence
		if ( ! empty( $this->STARK_PROFILING ) ) START_TIMER('STARK-controller-handlers');
		$this->view = new Stark__View($_SERVER['SCRIPT_NAME']);
		$this->view->controller = $this;

		///  Reguster AJAX mode
		if ( ! empty( $_SERVER['__STARK_AJAX_MODE__'] ) ) $this->ajax_mode = true;

		///  Catch the Previous Step
		# TODO

		///  Page Handler
		list( $page_ctl, $page_page ) = $this->load_controller_by_path($this->path);
		$this->run_directory_handlers($this->path, $page_ctl);
		if ( ! empty( $page_ctl ) ) { // If the controller is defined...
			$method = $page_page . ( ( ! empty( $_SERVER['__STARK_AJAX_MODE__'] ) ) ? '_ajax' : '_page' );

			///  Call the handler if it is defined
            $exists = ( method_exists($page_ctl, 'real_method_exists' ) ? $page_ctl->real_method_exists($method) : method_exists($page_ctl, $method ) );
			if ( $exists ) {
				$scope = $page_ctl->$method( $this );
				///  AJAX returnin
				if ( ! empty( $_SERVER['__STARK_AJAX_MODE__'] ) ) {
					header('Content-type: application/json');
					print( ! empty( $_REQUEST['callback'] ) ? 'function '. $_REQUEST['callback'] .'(){return '. json_encode($scope) .'}' : "{}&&\n". json_encode($scope) );
					exit(); # if we let it proceed it might print report_timers(), which isn't waht we want!
				}
				///  Standard mode, pass the values in the $view object
				else if ( ! empty( $scope ) && is_array( $scope ) ) {
					foreach( array_keys( $scope ) as $var ) {
						if ( isset( $scope[ $var ] ) ) $this->view->$var = $scope[ $var ];
					}
				}
			}
		}

		if ( ! empty( $this->STARK_PROFILING ) ) END_TIMER('STARK-controller-handlers');
	}

	public function load_controller_by_path($path) {
		///  Add CONTROLLER_DEFAULT_INDEX if it ends with a /
		if ( preg_match('/\\'. DIRECTORY_SEPARATOR .'$/',$path) ) $path .= $this->CONTROLLER_DEFAULT_INDEX;

		///  Get the path name
		$page = basename($path);
		if ( ! empty( $this->CONTROLLER_PAGE_SUFFIX_REGEX ) ) $page = preg_replace($this->CONTROLLER_PAGE_SUFFIX_REGEX, '', $page);
		
		///  Get the controller
		$ctl_path = dirname( $path );
		if ( $ctl_path == DIRECTORY_SEPARATOR ) $ctl_path = '/'. $this->CONTROLLER_ROOT_NAME;
		$ctl_path = preg_replace('/^\\'. DIRECTORY_SEPARATOR .'/', '',$ctl_path);
		$ctl_class = $this->CONTROLLER_CLASS_PREFIX . preg_replace('/\\'. DIRECTORY_SEPARATOR .'/','__',$ctl_path);
		if ( file_exists( $this->controller_path .'/'. $ctl_path . $this->CONTROLLER_FILE_SUFFIX ) )
			require_once($this->controller_path .'/'. $ctl_path . $this->CONTROLLER_FILE_SUFFIX );
		$ctl = null;
		if ( class_exists( $ctl_class ) )
			$ctl = new $ctl_class($this);

		return( array( $ctl, $page ) );
	}

	public function run_directory_handlers($path, $ctl = null, $orig_path = null) {
		if ( empty( $ctl ) ) list( $ctl ) = $this->load_controller_by_path( $path );

		if ( empty( $orig_path ) ) $orig_path = $path;

		///  Run the Parent's first
		if ( dirname( dirname( $path ) ) != dirname( $path ) ) {
			$this->run_directory_handlers( dirname( $path ), null, $orig_path );
		}

		///  If it has a directory handler, run it...
		if ( ! empty( $ctl ) && method_exists( $ctl, 'directory_handler') ) {
			$success = $ctl->directory_handler( $this, $orig_path );
			if ( empty( $success ) && ! headers_sent() ) {
				header('Status: 403 Forbidden');
				exit;
			}
		}
	}

	public function redirect($bounce_url, $work_around_headers_sent = true, $dont_exit = false) {
		if ( $work_around_headers_sent && headers_sent() ) {
			echo '<html><head><meta http-equiv="refresh" content="0;url='. htmlentities($bounce_url) .'"><script type="text/javascript">window.location = "'. addslashes($bounce_url) .'";</script><head><body><!-- Redirecting... --></body></html>';
			if ( ! $dont_exit ) exit;
			return true;
		}
		else if ( ! headers_sent() ) {
			header("Location: $bounce_url");
			if ( ! $dont_exit ) exit;
			return true;
		}
		return false;
	}
}
<?php

///  DEFINE THE PATHS relative to this file
///  If our include'ers didn't set this, or someone is just linking directly to this docroot
///  which is NOT RECCOMMENDED, but only is a problem if you want to easily upgrade the lib
///  dir without it blowing away your local changes to your ansible-config.inc.php
///  
///  Instead, try cd'ing to the directory that you want the docroot installed into and run
///      path/to/ansible/lib/Ansible/install.sh
///
///  This will make symlinks to the necessary files in the current working directory, making it a workable Ansible docroot
///  This script also should be re-run like this after an upgrade.
if ( ! isset( $lib_path ) )            $lib_path            = dirname(__FILE__) .'/../..';
if ( ! isset( $ansible_config_path ) ) $ansible_config_path = dirname(__FILE__) .'/ansible-config.inc.php.dist';
if ( ! isset( $ansible_uri_prefix ) )  $ansible_uri_prefix  = dirname($_SERVER['SCRIPT_NAME']) == DIRECTORY_SEPARATOR ? '' : dirname($_SERVER['SCRIPT_NAME']);

///  MODIFY this line to set the path to the Controller.class.php
require_once(dirname(__FILE__). '/../../Stark/Controller.class.php');
$controller = new Stark__Controller
	///  MODIFY these to set paths to each resource
	( $_SERVER['SCRIPT_NAME'],
	  array( 'lib_path'    	   => $lib_path, // directory with Stark libraries
			 'config_path' 	   => dirname(__FILE__) .'/../stark-config.inc.php',
			 'controller_path' => dirname(__FILE__) .'/../controller',
			 'model_path'      => dirname(__FILE__) .'/../model',

			 ///  Ansible Config
			 'ansible_config_path' => $ansible_config_path,
			 'ansible_uri_prefix'  => $ansible_uri_prefix,
			 )
	 );
$ctl = $controller;

///  Common: add the model path to include_path
set_include_path(get_include_path() . PATH_SEPARATOR . $ctl->model_path);

///  Run the Main Handler
$ctl->handler();

///  Define a shortcut to the view...
$view = $ctl->view;

/// Ansible customization
$stage = $ctl->stage;
<?php

///  DEFINE THE PATHS relative to this file
$lib_path            = dirname(__FILE__) .'/lib';
$ansible_config_path = dirname(__FILE__) .'/ansible-config.inc.php';

///  You shouldn't have to change this...
///    This lets ansible create URI absolute paths...
$ansible_uri_prefix = dirname($_SERVER['SCRIPT_NAME']) == DIRECTORY_SEPARATOR ? '' : dirname($_SERVER['SCRIPT_NAME']);
										 
/// Include the main Stark Controller (in the lib dir as to be upgradeable)
include($lib_path .'/Ansible/docroot/ansible-controller.inc.php');

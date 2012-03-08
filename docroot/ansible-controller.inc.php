<?php

///  DEFINE THE PATHS relative to this file
$lib_path            = dirname(__FILE__) .'/lib';
$ansible_config_path = dirname(__FILE__) .'/ansible-config.inc.php';

///  You may need to change this if:
///    1) The path from the DOCUMENT_ROOT to this file crosses a symbilc link
///    AND 2) the ansible path is NOT the DOCUMENT_ROOT
$url_prefix = $_SERVER['DOCUMENT_ROOT'] != substr(dirname(__FILE__),0,strlen($_SERVER['DOCUMENT_ROOT'])) ? '' : substr(dirname(__FILE__),strlen($_SERVER['DOCUMENT_ROOT']));
										 
/// Include the main Stark Controller (in the lib dir as to be upgradeable)
include($lib_path .'/Ansible/docroot/ansible-controller.inc.php');

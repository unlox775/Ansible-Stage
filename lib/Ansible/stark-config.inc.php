<?php

/////////////////////////
/// Stark MVC Config
$config->CONTROLLER_PRELOAD_LIBS
= array($config->lib_path. '/Ansible/Repo.class.php',
		$config->lib_path. '/Ansible/Project.class.php',
        $config->lib_path. '/delayed_load.inc.php',
        $config->lib_path. '/File_NFSLock.class.php'
		);

///  Controller configuration
$config->CONTROLLER_CLASS_PREFIX = 'Ansible__';

$config->close_session_after_view = true;

require_once($config->lib_path. '/Ansible/Stage.class.php');
$config->stage = new Ansible__Stage( '',
									 array( 'lib_path'    => $config->lib_path,
											'config_path' => $config->ansible_config_path,
											'url_prefix'  => $config->url_prefix,
											)
									 );


/////////////////////////
///  Ansible Skin Configuration

$ctl->SKIN_BASE = $config->url_prefix .'/skins/ugly_v0.1';

///  Make scoped_include() keep the $stage var
$config->scope_global_vars[] = 'stage';

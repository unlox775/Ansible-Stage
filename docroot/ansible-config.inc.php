<?php

#########################
###  Globals

###  Turns on Time Profiling (Benchmarking) output (change to true)
$config->ANSIBLE_PROFILING = true;

###  Directory Locations
$config->project_base = '/my/location/for/ansible/projects';
$config->safe_base = '/my/location/for/ansible/projects/logs';
$config->repo_type  = 'SVN';

######  Database Configuration
###  Deafult: SQLite
$config->db_file_name = 'ansible_db.sq3';
$config->db_file_path = '{PROJECT_BASE}/{DB_FILE_NAME}';

# ###  Alternate: any PDO DSN (Mysql and PostgreSQL supported)
# $config->db_dsn = 'dblib:host=your_hostname;dbname=your_db;charset=UTF-8';
# $config->db_username = 'myusername';
# $config->db_password = 'myPas$w0rd';

###  If you want the full path of the repo not to show (e.g. for publicly available demos)
$config->obscure_sandbox_root = false;

$config->repo_cmd_prefix =     'cd {REPO_BASE}; /usr/bin/';

######  Sandbox Configuration
###  Staging Areas
$config->default_url_protocol = 'http';
$config->qa_rollout_phase_host   = '';
$config->prod_rollout_phase_host = '';
$config->url_base = '';
         
$config->staging_areas =
    array( 'beta' => array( 'role' => 'beta', // Will be used for the process link
							'repo_base' => '/beta/docroot',
							'label' => 'QA Staging Area',
							),
           'live' => array( 'role' => 'live', // Will be used for the process link
							'label' => 'Live Production',
							'repo_base' => '/live/docroot',
							),
           );
$config->sandbox_areas =
    array( 'dave' => array( 'label' => 'Dave',
							'repo_base' => '/dave/docroot',
							),
           'jon' => array( 'label' => 'Jon',
						   'repo_base' => '/jon/docroot',
						   ),
           );

# ///  Extend with TestExtension
# require_once( $config->lib_path. '/TestExtension.class.php' );
# $test_ext = new TestExtension();
# $test_ext->hook_ansible( $config->extend );

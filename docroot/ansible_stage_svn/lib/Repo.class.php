<?php

/**
 *  Base Repo Class -
 */
class Ansible__Repo {
    public $repo_cache = array();
    public $display_name = 'Generic';

    ///  Logging Function
    public function log_repo_action( $command ) {
        global $PROJECT_SAFE_BASE, $env_mode;
        
        $log_line = join(',', array(time(), getmypid(), date(DATE_RFC822,time()), $command)). "\n";
        
        $file = "$PROJECT_SAFE_BASE/project_svn_log_".$env_mode.".csv";
        file_put_contents($file, $log_line, FILE_APPEND);
    }
}

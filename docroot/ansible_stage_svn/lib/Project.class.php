<?php

/**
 *  Project Object -  Read and Cache Project file info
 */
class Ansible__Project {
    public $project_name;

    public function __construct($project_name) {
        $this->project_name = $project_name;
    }

    public function exists() {
        global $SYSTEM_PROJECT_BASE;
        return $this->file_exists('affected_files.txt');
    }

    public function get_ls() {
        global $SYSTEM_PROJECT_BASE;
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
        return `/bin/ls -la --time-style=long-iso $SYSTEM_PROJECT_BASE/$this->project_name | head -n2 | tail -n1`;
    }

    public function get_stat() {
        global $SYSTEM_PROJECT_BASE;
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
        return stat($SYSTEM_PROJECT_BASE ."/$this->project_name");
    }

    public function file_exists($file) {
        global $SYSTEM_PROJECT_BASE;
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) ) return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
        return ( file_exists($SYSTEM_PROJECT_BASE ."/$this->project_name/$file") );
    }

    public function get_file($file) {
        global $SYSTEM_PROJECT_BASE;
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) ) return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
        if ( ! file_exists("$SYSTEM_PROJECT_BASE/$this->project_name/$file") )
            return('');
        return file_get_contents("$SYSTEM_PROJECT_BASE/$this->project_name/$file");
    }

    public function get_affected_files() {
    
        $files = array();
        foreach ( explode("\n",$this->get_file( "affected_files.txt" )) as $file ) {
            $file = preg_replace('/(\s*\#.*$|\s+)$/','',$file);
            if ( strlen( $file ) == 0 ) continue;
    
            array_push( $files, $file );
        }
    
        return $files;
    }
    
    public function get_file_tags() {
    
        $file_tags = array();
        foreach ( explode("\n",$this->get_file( "file_tags.csv" )) as $line ) {
            $vals = str_getcsv($line);
            if ( ! $vals >= 2 && ! preg_match('/[\"]/', $vals[1], $m) && preg_match('/^\d+\.\d+(\.\d+\.\d+)?$/', $vals[1], $m) ) continue;
            $file_tags{ $vals[0] } = $vals[1];
        }
    
        return $file_tags;
    }


}

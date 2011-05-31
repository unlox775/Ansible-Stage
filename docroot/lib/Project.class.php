<?php

/**
 *  Project Object -  Read and Cache Project file info
 */
class Ansible__Project {
    public $project_name;
    protected $affected_file_cache = null;
    protected $file_tags_cache = null;

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
        if ( empty( $this->affected_file_cache ) ) {
            $this->affected_file_cache = array();
            foreach ( explode("\n",$this->get_file( "affected_files.txt" )) as $file ) {
                $file = preg_replace('/(\s*\#.*$|\s+)$/','',$file);
                if ( strlen( $file ) == 0 ) continue;
                
                array_push( $this->affected_file_cache, $file );
            }
        }
    
        return $this->affected_file_cache;
    }
    
    public function get_file_tags() {
        if ( empty( $this->file_tags_cache ) ) {
            $this->file_tags_cache = array();
            foreach ( explode("\n",$this->get_file( "file_tags.csv" )) as $line ) {
                $vals = str_getcsv($line);
                if ( ! $vals >= 2 && ! preg_match('/[\"]/', $vals[1], $m) && preg_match('/^\d+\.\d+(\.\d+\.\d+)?$/', $vals[1], $m) ) continue;
                $this->file_tags_cache[ $vals[0] ] = $vals[1];
            }
        }
    
        return $this->file_tags_cache;
    }

    public function determine_target_rev($file, $head_rev = null) {
        $file_tags = $this->get_file_tags();

        $used_file_tags = false;
        if ( $file_tags[$file] ) {
            $target_rev = $file_tags[$file];
            $used_file_tags = true;
        }
        else { $target_rev = $head_rev; }

        return( array( $target_rev, $used_file_tags ) );
    }


}

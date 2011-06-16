<?php

/**
 *  Project Object -  Read and Cache Project file info
 */
class Ansible__Project {
    public $project_name;
    public $archived = false;
    protected $affected_file_cache = null;
    protected $file_tags_cache = null;
    protected $mod_time_bak = null;

    public function __construct($project_name, $archived = false) {
        $this->project_name = $project_name;
        $this->archived = $archived;
    }

    public function exists() {
        return $this->file_exists('affected_files.txt');
    }

    public function archived() {
        return $this->archived;
    }

    public function get_group() {
        $group = $this->get_file('.group');
        return( empty( $group ) ? '00_none' : trim( $group) );
    }

    public function get_ls() {
        global $SYSTEM_PROJECT_BASE;
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
        $archived = $this->archived ? 'archive/' : '';
        return `/bin/ls -la --time-style=long-iso $SYSTEM_PROJECT_BASE/$archived$this->project_name | head -n2 | tail -n1`;
    }

    public function get_stat() {
        global $SYSTEM_PROJECT_BASE;
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
        $archived = $this->archived ? 'archive/' : '';
        return stat($SYSTEM_PROJECT_BASE ."/$archived$this->project_name");
    }

    public function file_exists($file) {
        global $SYSTEM_PROJECT_BASE;
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) ) return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
        $archived = $this->archived ? 'archive/' : '';
        return ( file_exists($SYSTEM_PROJECT_BASE ."/$archived$this->project_name/$file") );
    }

    public function get_file($file) {
        global $SYSTEM_PROJECT_BASE;
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) ) return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
        $archived = $this->archived ? 'archive/' : '';
        if ( ! file_exists("$SYSTEM_PROJECT_BASE/$archived$this->project_name/$file") )
            return('');
        return file_get_contents("$SYSTEM_PROJECT_BASE/$archived$this->project_name/$file");
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

    public function backup_project_mod_time() {
        $stat = $this->get_stat();
        $this->mod_time_bak = $stat ? $stat[9] : null;
    }


    #############################
    ###  Write-Access Actions 

    public function archive($user, $time = null) {
        global $SYSTEM_PROJECT_BASE;
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
        if ( $this->archived() ) return true;

        ///  Make the archive dir if necessary
        if ( ! is_dir("$SYSTEM_PROJECT_BASE/archive") ) {
            mkdir("$SYSTEM_PROJECT_BASE/archive", 0755);
        }

        ///  Move to Archive Dir
        $this->backup_project_mod_time();
        print `mv $SYSTEM_PROJECT_BASE/$this->project_name $SYSTEM_PROJECT_BASE/archive/$this->project_name`;
        ///  Log
        if ( empty($time)        ) $time = time();
        if ( ! is_numeric($time) ) $time = strtotime( $time );
        $time = date('Y-m-d H:i:s', $time);
        print `echo '"'$time'","archived","'$user'"' > $SYSTEM_PROJECT_BASE/archive/$this->project_name/archived.txt`;
        print `cat $SYSTEM_PROJECT_BASE/archive/$this->project_name/archived.txt >> $SYSTEM_PROJECT_BASE/archive/$this->project_name/archive.log`;
        $this->archived = true;
        $this->restore_project_mod_time();
        return true;
    }

    public function unarchive($user, $time = null) {
        global $SYSTEM_PROJECT_BASE;
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
        if ( ! $this->archived() ) return true;

        ///  Move out of the Archive Dir
        $this->backup_project_mod_time();
        print `mv $SYSTEM_PROJECT_BASE/archive/$this->project_name $SYSTEM_PROJECT_BASE/$this->project_name`;
        ///  Log
        if ( empty($time)        ) $time = time();
        if ( ! is_numeric($time) ) $time = strtotime( $time );
        $time = date('Y-m-d H:i:s', $time);
        print `echo '"'$time'","unarchived","'$user'"' > $SYSTEM_PROJECT_BASE/$this->project_name/archived.txt`;
        print `cat $SYSTEM_PROJECT_BASE/$this->project_name/archived.txt >> $SYSTEM_PROJECT_BASE/$this->project_name/archive.log`;
        print `rm -f $SYSTEM_PROJECT_BASE/$this->project_name/archived.txt`;
        $this->archived = false;
        $this->restore_project_mod_time();
        return true;
    }

    public function set_group($group) {
        global $SYSTEM_PROJECT_BASE;
        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );

        if ( preg_match('/\W/', $group) )
            return trigger_error("Please don't hack...", E_USER_ERROR);

        $this->backup_project_mod_time();
        $archived = $this->archived ? 'archive/' : '';
        if ( $group == '00_none' ) {
            print `rm -f       $SYSTEM_PROJECT_BASE/$archived$this->project_name/.group`;
        } else {
            print `echo $group > $SYSTEM_PROJECT_BASE/$archived$this->project_name/.group`;
        }
        $this->restore_project_mod_time();
        return true;
    }

    public function restore_project_mod_time() {
        global $SYSTEM_PROJECT_BASE;

        if ( empty( $this->mod_time_bak ) ) return false;

        if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $this->project_name, $m) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);

        if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
        
        ///  Restore to the backup
        $archived = $this->archived ? 'archive/' : '';
        touch("$SYSTEM_PROJECT_BASE/$archived$this->project_name", $this->mod_time_bak);
        
        return true;
    }

}

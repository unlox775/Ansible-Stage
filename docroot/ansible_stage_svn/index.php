<?php

#########################
###  Project Manager
#
# Version : $Id: index.pl,v 1.1 2010/11/17 23:34:19 project Exp $
#
#########################

#########################
###  Configuration, Setup

require_once(dirname(__FILE__) .'/config.php');
require_once(dirname(__FILE__) .'/debug.inc.php');
require_once(dirname(__FILE__) .'/File_NFSLock.class.php');

# phpinfo(); exit;

###  Globals
$SVN_CMD_PREFIX =     'cd ' .$_SERVER['PROJECT_SVN_BASE']. ';      /usr/bin/';
# $SVN_CMD_PREFIX_4CO = 'cd ' .$_SERVER['PROJECT_SVN_BASE']. '/..; /usr/bin/';

$MAX_BATCH_SIZE = 500;
$MAX_BATCH_STRING_SIZE = 4096;
$svn_cache = array();

###  Connect to the tags DB
if ( ! file_exists( $SYSTEM_TAGS_DB ) ) $INIT_DB_NOW = true;

###  Get an exclusive File_NFSLock on the DB file...
#$db_file_lock = new File_NFSLock($SYSTEM_TAGS_DB,LOCK_EX,10,30*60); # stale lock timeout after 30 minutes

$dbh = new PDO('sqlite:'.$SYSTEM_TAGS_DB);  $GLOBALS['orm_dbh'] = $dbh;
$dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
if ( ! empty($INIT_DB_NOW) ) {
    $dbh->exec("CREATE TABLE file_tag (
                     file      character varying(1000) NOT NULL,
                     tag       character varying(25) NOT NULL,
                     revision  int NOT NULL,
                     CONSTRAINT file_tag_pk PRIMARY KEY ( file, tag )
                   )
                  ");
}


#########################
###  Main Runtime

###  See if we are doing a read-only user
$READ_ONLY_MODE = ( in_array( $_SERVER['REMOTE_USER'], array('guest', 'pmgr_tunnel') ) ) ? true : false;
if ( PROJECT_PROJECT_TIMERS ) 
    reset_timers();

###  Action Handler
if ( ! empty( $_REQUEST['action'] ) && $_REQUEST['action'] == 'view_project' ) {
    echo style_sheet();
    view_project_page();
}
else if ( $_REQUEST['action'] == 'update' ) {
    if ( $READ_ONLY_MODE ) return trigger_error("Permission Denied", E_USER_ERROR);

    $project_name = $_REQUEST['pname'];
    $tag = $_REQUEST['tag'];
    if ( empty( $tag ) ) {
        echo style_sheet();
        view_project_page();
    }
    if ( preg_match('/[^\w\_\-\.]/', $tag, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);


    $individual_file_rev_updates = array();

    ###  Target mode
    $target_mode_update = false;
    if ( $tag == 'Target' ) {
        $target_mode_update = true;
        $tag = 'HEAD';
        ###  Read in the file tags CSV
        $file_tags = get_file_tags( $project_name );
    }

    ###  Prepare for a MASS HEAD update if updating to HEAD
    if ( $tag == 'HEAD' ) {
        $mass_head_update_files = array();
        foreach ( get_affected_files($project_name) as $file ) {
            if ( is_dir($_SERVER['PROJECT_SVN_BASE'] ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
                 ###  Skip this file if in TARGET MODE and it's on the list
                 || ( $target_mode_update && array_key_exists( $file, $file_tags) )
                ) continue;
            $mass_head_update_files[] = $file;
        }

        ###  Get Target Mode files
        if ( $target_mode_update ) {
            foreach ( get_affected_files($project_name) as $file ) {
                if ( is_dir($_SERVER['PROJECT_SVN_BASE'] ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
                     ) continue;
                if ( ! empty( $file_tags[ $file ] ) && abs( floor( $file_tags[ $file ] ) ) == $file_tags[ $file ] ) 
                    $individual_file_rev_updates[] = array( $file, $file_tags[ $file ] );
            }
        }
    }
    ###  All other tags, do individual file updates
    else {
        foreach ( get_affected_files($project_name) as $file ) {
            if ( is_dir($_SERVER['PROJECT_SVN_BASE'] ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
                 ) continue;

            ###  Get the tag rev for this file...
            $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
            $rev = $sth->fetch(PDO::FETCH_NUM);
            if ( ! empty( $rev ) ) # I guess if there isn't a rev, we should REMOVE THE FILE?  Maybe later...
                $individual_file_rev_updates[] = array( $file, $rev[0] );
        }
    }

    ###  Run the MASS HEAD update (if any)
    if ( ! empty($mass_head_update_files) ) {
        $head_update_cmd = "svn update ";
        foreach ( $mass_head_update_files as $file ) $head_update_cmd .= ' '. escapeshellcmd($file);
        if ( PROJECT_PROJECT_TIMERS ) START_TIMER('SVN_CMD');
        log_svn_action($head_update_cmd);
#        $command_output .= shell_exec("$SVN_CMD_PREFIX_PREFIX$head_update_cmd 2>&1 | cat -");
        if ( PROJECT_PROJECT_TIMERS ) END_TIMER('SVN_CMD');
        $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $head_update_cmd;
    }

    ###  File tag update
    if ( ! empty($individual_file_rev_updates) ) {
        foreach ( $individual_file_rev_updates as $update ) {
            list($file, $rev) = $update;

            $indiv_update_cmd = "svn update -r$rev ". escapeshellcmd($file);
            if ( PROJECT_PROJECT_TIMERS ) START_TIMER('SVN_CMD');
            log_svn_action($indiv_update_cmd);
#            $command_output .= shell_exec("$SVN_CMD_PREFIX$indiv_update_cmd 2>&1 | cat -");
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('SVN_CMD');
            $cmd .= "\n".( strlen($cmd) ? ' ; ' : ''). $indiv_update_cmd;
        }
    }

    if ( empty( $command_output ) ) $command_output = '</xmp><i>No output</i>';

    ###  If the Bounce URL is too long for HTTP protocol maximum then just echo out the stuff...
    $bounce_url = "?action=view_project&pid=". getmypid() ."&pname=". urlencode($project_name) ."&cmd=". urlencode($cmd) ."&command_output=". urlencode($command_output);
    if ( strlen( $bounce_url ) > 2000 ) {
        echo style_sheet();
        echo "<font color=red><h3>Command Output (Too Large for redirect)</h3>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n";
        echo "<xmp>> $cmd\n\n$command_output\n</xmp>\n\n";
        echo "</font>\n\n";
    }
    ###  Else, just bounce
    else {
        header("Location: $bounce_url");
    }
}
else if ( $_REQUEST['action'] == 'tag' ) {
    if ( $READ_ONLY_MODE ) return trigger_error("Permission Denied", E_USER_ERROR);

    $project_name = $_REQUEST['pname'];
    $tag = $_REQUEST['tag'];
    if ( empty( $tag ) ) {
        echo style_sheet();
        view_project_page();
    }
    if ( preg_match('/[^\w\_\-\.]/', $tag, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);
    
    ###  Look and update tags
    foreach ( get_affected_files($project_name) as $file ) {
        ###  Make sure this file exists
        if ( file_exists($_SERVER['PROJECT_SVN_BASE'] ."/$file")
             && ! is_dir($_SERVER['PROJECT_SVN_BASE'] ."/$file") # Even tho, I guess SVN is OK with versioning directories...  Updating a directory has undesired effects..
             && preg_match('/^\w?\s*\d+\s+(\d+)\s/', get_svn_status($file), $m) 
             ) {
            $cur_rev = $m[1];

            ###  See what the tag was before...
            $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
            $old_rev = $sth->fetch(PDO::FETCH_NUM);
            
            ###  Update the Tag DB for this file...
            $rv = dbh_do_bind("INSERT OR REPLACE INTO file_tag ( file,tag,revision ) VALUES ( ?,?,?)", $file, $tag, $cur_rev);

            ###  Add to Command output whether we really changed the tag or not
            if ( ! empty( $old_rev ) && $old_rev[0] != $cur_rev ) {
                $command_output .= "Moved $tag on $file from ". $old_rev[0] . " to $cur_rev\n";
            }
        }
        ###  If it doesn't exist, we need to remove the tag...
        else {
            ###  See what the tag was before...
            $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);
            $old_rev = $sth->fetch(PDO::FETCH_NUM);
            
            ###  Update the Tag DB for this file...
            $rv = dbh_do_bind("DELETE FROM file_tag WHERE file = ? AND tag = ?", $file, $tag);

            ###  Add to Command output whether we really changed the tag or not
            if ( ! empty( $old_rev ) ) {
                $command_output .= "Rmoved $tag on $file\n";
            }
        }
    }

    $cmd = "TAG all files: $tag";

    if ( empty( $command_output ) ) $command_output = '</xmp><i>No output</i>';

    ###  If the Bounce URL is too long for HTTP protocol maximum then just echo out the stuff...
    $bounce_url = "?action=view_project&pid=". getmypid() ."&pname=". urlencode($project_name) ."&cmd=". urlencode($cmd) ."&command_output=". urlencode($command_output);
    if ( strlen( $bounce_url ) > 2000 ) {
        echo style_sheet();
        echo "<font color=red><h3>Command Output (Too Large for redirect)</h3>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n";
        echo "<xmp>> $cmd\n\n$command_output\n</xmp>\n\n";
        echo "</font>\n\n";
    }
    ###  Else, just bounce
    else {
        header("Location: $bounce_url");
    }
}
else if ( $_REQUEST['action'] == 'part_log' ) {
    echo style_sheet();
    part_log_page();
}
else if ( $_REQUEST['action'] == 'full_log' ) {
    echo style_sheet();
    full_log_page();
}
else if ( $_REQUEST['action'] == 'diff' ) {
    echo style_sheet();
    diff_page();
}
else if ( $_REQUEST['action'] == 'remote_call' ) {
###      $remote_call = $_REQUEST['remote_call'];
###      $params = $_REQUEST['params'];
###      $wantarray = $_REQUEST['wantarray'];
###      if ( ! preg_match( '/^(get_project_ls|get_project_stat|project_file_exists|get_project_file|get_projects)$/', $remote_call, $m ) ) 
###          return trigger_error("Please don't hack...", E_USER_ERROR);
###  
###      $params = thaw(urldecode($params));
###  
###      $send_obj;
###      if ( $wantarray ) { $send_obj =          [ &{ $remote_call }( @$params ) ]; }
###      else              { $send_obj =  \ scalar( &{ $remote_call }( @$params ) ); }
###  
###      echo "|=====|". urlencode(nfreeze($send_obj)) ."|=====|";
}
else {
    echo style_sheet();
    index_page();
}
if ( PROJECT_PROJECT_TIMERS ) 
report_timers(  );

# exit 0;



#########################
###  Hacked Page handlers (use Template Toolkit asap!)

function index_page() {
    global $SYSTEM_PROJECT_BASE;

    ###  List of projects
    echo "<h3>List of Projects</h3>\n";
    print( "<table width=100%>\n"
            . "<tr>"
            . "<th width=30% align=left>Name</th>"
            . "<th align=center>Created by</th>"
            . "<th align=center>Last Modified</th>"
            . "<th align=center>Number of files</th>"
            . "<th align=center>Summary File</th>"
            . "<th align=left>Actions</th>"
            . "</tr>\n"
            );

    $projects = array();
    foreach ( get_projects() as $project ) {
        if ( empty( $project ) ) continue;

        ###  Get more info from ls
        $ls = ( is_dir($SYSTEM_PROJECT_BASE)) ? (preg_split('/\s+/', get_project_ls($project)) ) : array();
#        $stat = (is_dir($SYSTEM_PROJECT_BASE)) ? (get_project_stat($project)) : ();
        $stat = get_project_stat($project);

        $project = array( 'name'                => $project,
                          'creator'             => ($ls[2] || '-'),
                          'group'               => ($ls[3] || '-'),
                          'mod_time'            => ($stat[9] || 0),
                          'mod_time_display'    => ($stat ? date('n/j/y',$stat[9])  : '-'),
                          'has_summary'         => ( (is_dir($SYSTEM_PROJECT_BASE))
                                                     ? ( project_file_exists( $project, "summary.txt" ) ? "YES" : "")
                                                     : '-'
                                                     ),
                          'aff_file_count'      => count(get_affected_files($project)),
                          );

        array_push( $projects, $project );
    }

    # sort {$b['mod_time'] cmp $a['mod_time']} 
    foreach ( $projects as $project ) {
#        echo "<tr><td></li>\n";
        print( "<tr>"
               . "<td><a href=\"?action=view_project&pname=". urlencode($project['name']) ."\">". $project['name'] ."</a></td>"
               . "<td align=center>". $project['creator'] ."</td>"
               . "<td align=center>". $project['mod_time_display'] ."</td>"
               . "<td align=center>". $project['aff_file_count'] ."</td>"
               . "<td align=center>". $project['has_summary'] ."</td>"
               . "<td><a href=\"?action=view_project&pname=". urlencode($project['name']) ."\">View</a> | <a href=\"javascript:alert('Not supported yet...  Sorry.')\">Archive</a></td>"
               . "</tr>\n"
               );
    }
    echo "</table>\n";

    echo "</ul>\n\n";
}

function view_project_page() {
    list( $cmd, $command_output ) = array( $_REQUEST['cmd'], $_REQUEST['command_output'] );
    $project_name = $_REQUEST['pname'];

    ###  Command output
    if ( ! empty( $cmd ) ) {
        echo "<font color=red><h3>Command Output</h3>\n";
        echo "<xmp>> $cmd\n\n$command_output\n</xmp>\n\n";
        echo "</font>\n\n";
        echo "<br><br><a href=\"?action=view_project&pname=$project_name\" style=\"font-size:70%\">&lt;&lt;&lt; Click here to hide Command output &gt;&gt;&gt;</a><br>\n\n";
    }

    echo "<h2>Project: $project_name</h2>\n\n";

    ###  Actions
    if ( $READ_ONLY_MODE ) {
        echo <<<ENDHTML
<table width="100%" border=0 cellspacing=0 cellpadding=0>
<tr>
  <td align="left" valign="top">
    <h3>Actions</h3>
    <i>You must log in as a privileged user to perform SVN actions.  Sorry.</i>
  </td>
  <td align="left" valign="top">
ENDHTML;
    }
    else {
        echo <<<ENDHTML
<table width="100%" border=0 cellspacing=0 cellpadding=0>
<tr>
  <td align="left" valign="top">
    <h3>Actions</h3>
    Update to: <a href="javascript: confirmAction('UPDATE','?action=update&pname=$project_name&tag=Target')"   >Target</a>
                 | <a href="javascript: confirmAction('UPDATE','?action=update&pname=$project_name&tag=HEAD')"     >HEAD</a>
                 | <a href="javascript: confirmAction('UPDATE','?action=update&pname=$project_name&tag=PROD_TEST')">PROD_TEST</a>
                 | <a href="javascript: confirmAction('UPDATE','?action=update&pname=$project_name&tag=PROD_SAFE')">PROD_SAFE</a>
    <br>Tag as:    <a href="javascript: confirmAction('TAG',   '?action=tag&pname=$project_name&tag=PROD_TEST')"     >PROD_TEST</a>
                 | <a href="javascript: confirmAction('TAG',   '?action=tag&pname=$project_name&tag=PROD_SAFE')"     >PROD_SAFE</a>
  </td>
  <td align="left" valign="top">
ENDHTML;
    }

    ###  Rollout process for different phases
    if ( onAlpha() ) {
        echo <<<ENDHTML
            <h3>Rollout Process</h3>
            When you are ready, review the below file list to make sure:
            <ol>
            <li>All needed code and display logic files are here</li>
            <li>Any needed database patch scripts are listed (if any)</li>
            <li>In the "Current Status" column everything is "Up-to-date"</li>
            <li>In the "Changes by" column, they are all ychanges</li>
            </ol>
            Then, tell QA and they will continue in the <a href="https://admin.beta.project.org/project_manager/?action=view_project&pname=$project_name">QA Staging Area</a>
ENDHTML;
    }
    else if ( onBeta() ) {
        if ( $READ_ONLY_MODE ) {
            echo <<<ENDHTML
            <h3>Rollout Process - QA STAGING PHASE</h3>
            <b>Step 1</b>: Once developer is ready, Update to Target<br>
            <b>Step 2</b>: <i> -- Perform QA testing -- </i><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2a</b>: For minor updates, Update to Target again<br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2b</b>: If major problems, Roll back to PROD_TEST<br>
            <b>Step 3</b>: When everything checks out, Tag as PROD_TEST<br>
            <br>
            Then, <a href="https://admin.project.org/project_manager/?action=view_project&pname=$project_name">Switch to Live Production Area</a>
ENDHTML;
        }
        else {
            echo <<<ENDHTML
            <h3>Rollout Process - QA STAGING PHASE</h3>
            <b>Step 1</b>: Once developer is ready, <a href="javascript: confirmAction('UPDATE','?action=update&pname=$project_name&tag=Target')"   >Update to Target</a><br>
            <b>Step 2</b>: <i> -- Perform QA testing -- </i><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2a</b>: For minor updates, <a      href="javascript: confirmAction('UPDATE','?action=update&pname=$project_name&tag=Target')"   >Update to Target again</a><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2b</b>: If major problems, <a      href="javascript: confirmAction('UPDATE','?action=update&pname=$project_name&tag=PROD_TEST')">Roll back to PROD_TEST</a><br>
            <b>Step 3</b>: When everything checks out, <a href="javascript: confirmAction('TAG',   '?action=tag&pname=$project_name&tag=PROD_TEST')"     >Tag as PROD_TEST</a><br>
            <br>
            Then, <a href="https://admin.project.org/project_manager/?action=view_project&pname=$project_name">Switch to Live Production Area</a>
ENDHTML;
        }            
    }
    else if ( onLive() ) {
        if ( $READ_ONLY_MODE ) {
            echo <<<ENDHTML
            <h3>Rollout Process - LIVE PRODUCTION PHASE</h3>
            Check that in the "Current Status" column there are <b><u>no <b>"Locally Modified"</b> or <b>"Needs Merge"</b> statuses</u></b>!!
            <br>
            <b>Step 4</b>: Set set a safe rollback point, Tag as PROD_SAFE<br>
            <b>Step 5</b>: Then to roll it all out, Update to PROD_TEST<br>
            <b>Step 6</b>: <i> -- Perform QA testing -- </i><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6a</b>: If any problems, Roll back to PROD_SAFE<br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6b</b>: While fixes are made, Re-tag to PROD_TEST<br>
            Then, go back to the <a href="https://admin.beta.project.org/project_manager/?action=view_project&pname=$project_name">QA Staging Area</a> and continue with <b>Step 1</b> or <b>Step 2</b>.
ENDHTML;
        }
        else {
            echo <<<ENDHTML
            <h3>Rollout Process - LIVE PRODUCTION PHASE</h3>
            Check that in the "Current Status" column there are <b><u>no <b>"Locally Modified"</b> or <b>"Needs Merge"</b> statuses</u></b>!!
            <br>
            <b>Step 4</b>: Set set a safe rollback point, <a href="javascript: confirmAction('TAG',   '?action=tag&pname=$project_name&tag=PROD_SAFE')"     >Tag as PROD_SAFE</a><br>
            <b>Step 5</b>: Then to roll it all out, <a      href="javascript: confirmAction('UPDATE','?action=update&pname=$project_name&tag=PROD_TEST')">Update to PROD_TEST</a><br>
            <b>Step 6</b>: <i> -- Perform QA testing -- </i><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6a</b>: If any problems, <a      href="javascript: confirmAction('UPDATE','?action=update&pname=$project_name&tag=PROD_SAFE')">Roll back to PROD_SAFE</a><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6b</b>: While fixes are made, <a href="javascript: confirmAction('TAG','?action=tag&pname=$project_name&tag=PROD_TEST')">Re-tag to PROD_TEST</a><br>
            Then, go back to the <a href="https://admin.beta.project.org/project_manager/?action=view_project&pname=$project_name">QA Staging Area</a> and continue with <b>Step 1</b> or <b>Step 2</b>.
ENDHTML;
        }
    }

    ###  End table
    echo <<<ENDHTML
  </td>
</table>
ENDHTML;

    ###  Read in the file tags CSV "
    $file_tags = get_file_tags( $project_name );

    ###  Echo File details
    ###    Hack for now... When we rewrite, use open!!
    echo "<h3>Affected Files</h3>\n";
    print( "<table width=100%>\n"
            . "<tr><td>&nbsp;</td><td colspan=5 align=center style=\"border: solid black; border-width: 1px 1px 0px 1px\"><b>Revisions</b></td><td>&nbsp;</td><td>&nbsp;</td></tr>"
            . "<tr>"
            . "<td width=30%><b>File Name</b></td>"
            . "<td align=center><b>Current Status</b></td>"
            . "<td align=center><b>Target</b></td>"
            . "<td align=center><b>HEAD</b></td>"
            . "<td align=center><b>PROD_TEST</b></td>"
            . "<td align=center><b>PROD_SAFE</b></td>"
            . "<td align=center><b>Changes By</b></td>"
            . "<td align=left><b>Action</b></td>"
            . "</tr>\n"
            );
    $files = get_affected_files($project_name);
#    cache_svn_logs( $files );
#    cache_svn_statuses( $files );
    $locally_modified = false;
    foreach ( $files as $file ) {

        list($cur_vers, $head_vers, $prod_test_vers, $prod_safe_vers) = array('','','','');
        $target_vers = '->';

        ###  Get Current Version
        if ( ! file_exists($_SERVER['PROJECT_SVN_BASE'] ."/$file") ) {
            $cur_vers = '<i>-- n/a --</i>';
        } else if ( is_dir($_SERVER['PROJECT_SVN_BASE'] ."/$file") ) {
            $cur_vers = '<i>Directory</i>';
        } else {
            $cstat = get_svn_status($file);
            if ( preg_match('/^(\w?)\s*\d+\s+(\d+)\s/', $cstat, $m) ) {
                $letter = $m[1];
                if ( empty($letter) ) $letter = '';
                $letter_trans = array( '' => 'Up-to-date', 'M' => 'Locally Modified', 'A' => 'To-be-added' );
                $status = ( isset( $letter_trans[ $letter ] ) ? $letter_trans[ $letter ] : 'Other: "'. $letter .'"' );
                if ( preg_match('/^\w?\s*\d+\s+(\d+)\s/', $cstat, $m) ) {
                    $cur_rev = $m[1];
                } else {
                    $cur_vers = "<i>malformed svn status</i><!--$cstat-->";
                }

                ###  Add a diff link if Locally Modified
                if ( $status == 'Locally Modified'
                     || $status == 'Needs Merge'
                     || $status == 'File had conflicts on merge'
                   ) {
                    $cur_vers = "<a href=\"?action=diff&from_rev=$cur_rev&to_rev=local&file=". urlencode($file) ."\">$status</a>, $cur_rev";
                    $locally_modified = true;
                }
                else { $cur_vers = "$status, $cur_rev"; }
            } else {
                $cur_vers = "<i>exists, but not in SVN!</i><!--$cstat-->";
            }
        }

        $clog = get_svn_log($file);

        ###  Get PROD_SAFE Version
        $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, 'PROD_SAFE');
        $row = $sth->fetch(PDO::FETCH_NUM);
        if ( ! empty( $row ) ) {
            list( $prod_safe_rev ) = $row;
            if ( $prod_safe_rev != $cur_rev ) {
                $prod_safe_vers = "<b><font color=red>$prod_safe_rev</font></b>";
            }
            else { $prod_safe_vers = $prod_safe_rev; }
        }
        else { $prod_safe_vers = '<i>-- n/a --</i>'; }

        ###  Get PROD_TEST Version
        $sth = dbh_query_bind("SELECT revision FROM file_tag WHERE file = ? AND tag = ?", $file, 'PROD_TEST');
        $row = $sth->fetch(PDO::FETCH_NUM);
        if ( ! empty( $row ) ) {
            list( $prod_test_rev ) = $row;
            if ( $prod_test_rev != $cur_rev ) {
                $prod_test_vers = "<b><font color=red>$prod_test_rev</font></b>";
            }
            else { $prod_test_vers = $prod_test_rev; }
        }
        else { $prod_test_vers = '<i>-- n/a --</i>'; }

        ###  Get HEAD Version
        if ( preg_match('/^-------+\nr(\d+)\s/m', $clog, $m) ) {
            $head_rev = $m[1];
            if ( $head_rev != $cur_rev
                 && ( ! $file_tags[$file]
                      || $file_tags[$file] == $cur_rev
                      )
                 ) {
                $head_vers = "<b><font color=red>$head_rev</font></b>";
            }
            else { $head_vers = $head_rev; }
            
            ###  Set Target version if it's there
            if ( $file_tags[$file] ) {
                if ( $file_tags[$file] != $cur_rev ) {
                    $target_vers = "<b><font color=red>". $file_tags[$file] ."</font></b>";
                }
                else { $target_vers = $file_tags[$file]; }
                
                $target_rev = $file_tags[$file];
            }
            else { $target_rev = $head_rev; }
        } else if ( preg_match('/nothing known about|no such directory/', $clog, $m) ) {
            $head_vers = "<i>Not in SVN</i><!--$clog-->";
        } else {
            $head_vers = "<i>malformed svn log</i><!--$clog-->";
        }

        ###  Changes by
        $changes_by = '<i>n/a</i>';
#         $c_by_rev = onLive() ? $cur_rev : $prod_test_rev;
#         if ( $c_by_rev && $target_rev ) {
#             $entries = array();  foreach ( array_reverse( get_revs_in_diff($c_by_rev, $target_rev) ) as $_ ) { $entries[] = get_log_entry( $clog, $_ ); } 
#             $names = array();  foreach ( $entries as $_ ) { preg_match('/author:\s+(\w+);/', $_, $m);  $names[] = $m[0]; } $names = array_unique($names);
# 
#             ###  Find regressions!
#             $changes_by = undef;
#             if ( count($entries) == 0 && $c_by_rev != $target_rev ) {
#                 $reverse_revs = get_revs_in_diff($target_rev, $c_by_rev);
#                 if ( count($reverse_revs) > 0 ) {
#                     $changes_by = '<font color=red><b><i>-'. $reverse_revs .' rev'. (count($reverse_revs) == 1 ? '' : 's'). '!!!</i></b></font>';
#                 }
#             }
#             if ( empty($changes_by) ) $changes_by = $entries .' rev'. (count($entries) == 1 ? '' : 's') . ($names ? (', '. join(', ',$names)) : '');
#         }

        ###  Actions
        $actions = '<i>n/a</i>';
        if ( $c_by_rev && $target_rev ) {
            $actions = ( "<a         href=\"?action=part_log&from_rev=$c_by_rev&to_rev=$target_rev&file=". urlencode($file) ."\">Log</a>"
                         . "&nbsp;<a     href=\"?action=diff&from_rev=$c_by_rev&to_rev=$target_rev&file=". urlencode($file) ."\">Diff</a>"
                         );
        }

        print( "<tr>"
                . "<td><a href=\"?action=full_log&file=". urlencode($file) ."\">$file</a></td>"
                . "<td align=center>$cur_vers</td>"
                . "<td align=center>$target_vers</td>"
                . "<td align=center>$head_vers</td>"
                . "<td align=center>$prod_test_vers</td>"
                . "<td align=center>$prod_safe_vers</td>"
                . "<td align=center>$changes_by</td>"
                . "<td align=left>$actions</td>"
                . "</tr>\n"
                );
    }
    echo "</table>\n";

    ###  If there were any locally modified files, then
    ###    DISABLE Updating until they are fixed
    if ( $locally_modified ) {
        echo <<<ENDHTML
<script>
disable_actions = 1;
</script>
ENDHTML;
    }

    ###  Summary File
    echo "<h3>Summary</h3>\n<pre>";
    if ( project_file_exists( $project_name, "summary.txt" ) ) {
        echo get_project_file( $project_name, "summary.txt" );
    } else {
        echo "-- No project summary entered --\n\n";
    }
    echo "</pre>\n\n";

}

function part_log_page() {
    $file     = $_REQUEST['file'];
    $from_rev = $_REQUEST['from_rev'];
    $to_rev   = $_REQUEST['to_rev'];
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) || preg_match('/[^\d\.]+/', $from_rev, $m) || preg_match('/[^\d\.]+/', $to_rev, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);

    echo "<h2>svn log entries of $file from -r $from_rev to -r $to_rev</h2>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n\n";

#    ###  TESTING
#    bug [get_revs_in_diff(qw(1.15 1.17))];
#    bug [get_revs_in_diff(qw(1.17 1.15))];
#    bug [get_revs_in_diff(qw(1.15 1.12.2.12))];
#    bug [get_revs_in_diff(qw(1.15 1.17.2.12))];
#    bug [get_revs_in_diff(qw(1.12.2.12 1.16))];
#    bug [get_revs_in_diff(qw(1.12.2.12 1.10))];
#    bug [get_revs_in_diff(qw(1.12.2.12 1.10.11.17))];
#    bug [get_revs_in_diff(qw(1.10.2.12 1.12.11.17))];

    ###  Get the partial log
    $clog = get_svn_log($file);
    $entries = array();  foreach ( array_reverse( get_revs_in_diff($from_rev, $to_rev) ) as $_ ) { $entries[] = array($_, get_log_entry( $clog, $_ )); }

    ###  Turn the revision labels into links
    foreach ( $entries as $entry ) {
        $GLOBALS['part_log_page_tmp'] = array($file, $entry[0], undef, '<xmp>', "<\/xmp>");
        $entry[1] = preg_replace_callback('/^((revision [\d\.]+)\s+)/','revision_link', $entry[1]);
    }

    $tmp = array();  foreach ( $entries as $entry ) $tmp = $entry[1];
    echo "<xmp>\n". join("\n----------------------------", $tmp) ."\n</xmp>";
}
function part_log_page_preplace_callback($m) {
    list( $file, $rev, $project_name, $s_esc, $e_esc ) = $GLOBALS['part_log_page_tmp'];
    revision_link($file, $rev, $m[2], $project_name, $s_esc, $e_esc, $m[1]);
}

function revision_link( $file, $rev, $str, $project_name, $s_esc, $e_esc, $whole_match) {
    if ( $rev == '1.1' ) return $whole_match;
    if ( empty($s_esc) ) $s_esc = '';
    if ( empty($e_esc) ) $e_esc = '';

    $tag = "$e_esc<a href=\"?action=diff&from_rev=". get_prev_rev($rev) ."&to_rev=". $rev ."&file=". urlencode($file) ."\">$s_esc";
    return $tag . $str ."$e_esc<\/a>$s_esc";
}

function full_log_page() {
    $file     = $_REQUEST['file'];
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);

    echo "<h2>svn log of $file</h2>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n\n";

    ###  Get the partial log
    $clog = get_svn_log($file);
    $GLOBALS['full_log_page_tmp'] = array($file, undef, '<xmp>', "<\/xmp>");
    $clog = preg_replace_callback('/(\r?\n(revision ([\d\.]+))\r?\n)','revision_link',$clog);
    echo "<xmp>\n$clog\n</xmp>";
}
function full_log_page_preplace_callback($m) {
    list( $file, $project_name, $s_esc, $e_esc ) = $GLOBALS['full_log_page_tmp'];
    revision_link($file, $m[3], $m[2], $project_name, $s_esc, $e_esc, $m[1]);
}

function diff_page() {
    global $SVN_CMD_PREFIX;

    $file     = $_REQUEST['file'];
    $from_rev = $_REQUEST['from_rev'];
    $to_rev   = $_REQUEST['to_rev'];
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) || preg_match('/[^\d\.]+/', $from_rev, $m) || ! preg_match('/^([\d\.]+|local)$/', $to_rev, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);

    echo "<h2>svn diff of $file from -r $from_rev to -r $to_rev</h2>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n\n"; # "

    ###  Get the partial diff
    $to_rev_clause = ($to_rev == 'local' ? "" : "-r $to_rev");
    if ( PROJECT_PROJECT_TIMERS ) START_TIMER('SVN_CMD');
    $cdiff = `${SVN_CMD_PREFIX}svn diff -bc -r $from_rev $to_rev_clause "$file" 2>&1 | cat`;
    if ( PROJECT_PROJECT_TIMERS ) END_TIMER('SVN_CMD');

    echo "<xmp>\n$cdiff\n</xmp>";
}

#########################
###  SVN batch caching (for speed)

function get_svn_log( $file ) {
    global $SVN_CMD_PREFIX;

    ###  If not cached, get it and cache
    if ( ! $svn_cache['log'][$file] ) {
        $parent_dir = preg_replace('@/[^/]+$@','',$file);
        if ( is_dir($_SERVER['PROJECT_SVN_BASE'] ."/$parent_dir") ) {
            if ( PROJECT_PROJECT_TIMERS ) START_TIMER('SVN_CMD');


            
            #########################
            #########################
            ###  DIRTY hack until we can get access...
            $cstat = get_svn_status($file);
            if ( preg_match('/^(\w?)\s*\d+\s+(\d+)\s/', $cstat, $m) ) {
                $last_rev = $m[2];
                $svn_cache['log'][$file] = <<<HACK_LOG
------------------------------------------------------------------------
r$last_rev | nobody | 2012-12-21 12:21:12 -0100 (Not, 21 Dec 2012) | 2 lines

Ugh! I cringe!
HACK_LOG;
            } else $svn_cache['log'][$file] = '';
            #########################
            #########################

#            $svn_cache['log'][$file] = `${SVN_CMD_PREFIX}svn log -r HEAD:1 "$file" 2>&1 | cat`;
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('SVN_CMD');
        }
        else {
            $svn_cache['log'][$file] = "svn [status aborted]: no such directory `$parent_dir'";
        }
    }

    return $svn_cache['log'][$file];
}

function cache_svn_logs( $files ) {
    global $SVN_CMD_PREFIX;

    $cache_key = 'log';

    ###  Batch and run the command
    while ( count($files) > 0 ) {
        $round = array();
        $round_str = '';
        while ( $files && $round < $MAX_BATCH_SIZE && strlen($round_str) < $MAX_BATCH_STRING_SIZE ) {
            $file = array_shift( $files );

            ###  Skip ones whos parent dir ! exists
            $parent_dir = preg_replace('@/[^/]+$@','',$file);
            if ( ! is_dir($_SERVER['PROJECT_SVN_BASE'] ."/$parent_dir") ) continue;

            array_push( $round, $file );
            $round_str .= " \"$file\"";
        }

        $round_checkoff = array_flip($round);
        if ( PROJECT_PROJECT_TIMERS ) START_TIMER('SVN_CMD');
        $all_entries = `${SVN_CMD_PREFIX}svn log $round_str 2>&1 | cat`;
#        bug substr($all_entries, -200);
        if ( PROJECT_PROJECT_TIMERS ) END_TIMER('SVN_CMD');
        foreach ( preg_split('@===================================================================+\n@', $all_entries) as $entry ) {
            if ( preg_match('/^\s*$/s', $entry, $m) ) continue;

            ###  Get the filename
            $file;
            if ( preg_match('@^\s*RCS file: /sandbox/svnroot/(?:project/)?(.+?),v\n@', $entry, $m ) ) {
                $file = $m[1];
            }
            ###  Other than "normal" output
            else {
                # silently skip
                continue;
            }

            ###  Cache
            if ( ! array_key_exists( $round_checkoff[$file] ) ) {
                continue;
#                BUG [$file,$round_checkoff];
#                return trigger_error("file not in round", E_USER_ERROR);
            }
            unset( $round_checkoff[$file] );
            $svn_cache[$cache_key][$file] = $entry;
        }
    }
}

function get_svn_status( $file ) {
    global $SVN_CMD_PREFIX;

    ###  If not cached, get it and cache
    if ( ! $svn_cache['status'][$file] ) {
        $parent_dir = preg_replace('@/[^/]+$@','',$file);
        if ( is_dir($_SERVER['PROJECT_SVN_BASE'] ."/$parent_dir") ) {
            if ( PROJECT_PROJECT_TIMERS ) START_TIMER('SVN_CMD');
            $svn_cache['status'][$file] = `${SVN_CMD_PREFIX}svn -v status "$file" 2>&1 | cat`;
            if ( PROJECT_PROJECT_TIMERS ) END_TIMER('SVN_CMD');
        }
        else {
            $svn_cache['status'][$file] = "svn [status aborted]: no such directory `$parent_dir'";;
        }
    }

    return $svn_cache['status'][$file];
}

function cache_svn_statuses( $files ) {
    global $SVN_CMD_PREFIX;

    $cache_key = 'status';

    ###  Batch and run the command
    while ( count($files) > 0 ) {
        $round = array();
        $round_str = '';
        while ( $files && $round < $MAX_BATCH_SIZE && strlen($round_str) < $MAX_BATCH_STRING_SIZE ) {
            $file = array_shift( $files );

            ###  Skip ones whos parent dir ! exists
            $parent_dir = preg_replace('@/[^/]+$@','',$file);
            if ( ! is_dir($_SERVER['PROJECT_SVN_BASE'] ."/$parent_dir") ) continue;

            array_push( $round, $file );
            $round_str .= " \"$file\"";
        }

        $round_checkoff = array_flip( $round );
        if ( PROJECT_PROJECT_TIMERS ) START_TIMER('SVN_CMD');
        $all_entries = `${SVN_CMD_PREFIX}svn status $round_str 2>&1 | cat`;
#        bug substr($all_entries, -200);
        if ( PROJECT_PROJECT_TIMERS ) END_TIMER('SVN_CMD');
        foreach ( preg_split('@===================================================================+\n@', $all_entries) as $entry ) {
            if ( preg_match('/^\s*$/s', $entry, $m) ) continue;

            ###  Get the filename
            if ( preg_match('@Repository revision:\s*[\d\.]+\s*/sandbox/svnroot/(?:project/)?(.+?),v\n@', $entry, $m) ) {
                $file = $m[1];
                array_shift( $round );
            }
            else if ( preg_match('@^File: (?:no file )?(.+?)\s+Status@', $entry, $m) ) {
                $file = $m[1];

                if ( preg_match('@/\Q$file\E$@', $round[0], $m) ) {
                    $file = array_shift( $round );
                }
                else {
#                    bug [$entry, $file];
                }
            }
            ###  Other than "normal" output
            else {
 #               bug [$entry];
                # silently skip
                continue;
            }

            ###  Cache
            if ( ! array_key_exists( $round_checkoff[$file] ) ) { 
                continue;
                # BUG [$entry, $round, $file,$round_checkoff];
                # return trigger_error("file not in round", E_USER_ERROR); 
            }
            unset( $round_checkoff[$file] );
            $svn_cache[$cache_key][$file] = $entry;
        }
    }
}


#########################
###  Project base access subroutines

function get_project_ls($project) {
    global $SYSTEM_PROJECT_BASE;
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $project, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);

    if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
    return `/bin/ls -la --time-style=long-iso $SYSTEM_PROJECT_BASE/$project | head -n2 | tail -n1`;
}

function get_project_stat($project) {
    global $SYSTEM_PROJECT_BASE;
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $project, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);

    if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
    return stat($SYSTEM_PROJECT_BASE ."/$project");
}

function project_file_exists($project, $file) {
    global $SYSTEM_PROJECT_BASE;
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $project, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) ) return trigger_error("Please don't hack...", E_USER_ERROR);

    if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
    return ( file_exists($SYSTEM_PROJECT_BASE ."/$project/$file") );
}

function get_project_file($project, $file) {
    global $SYSTEM_PROJECT_BASE;
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $project, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) ) return trigger_error("Please don't hack...", E_USER_ERROR);

    if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, func_get_args() );
    return `cat $SYSTEM_PROJECT_BASE/$project/$file`;
}

function get_projects() {
    global $SYSTEM_PROJECT_BASE, $PROJECTS_DIR_IGNORE_REGEXP;
    $tmp = func_get_args();
    if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, $tmp );
    return explode("\n",`ls -1 $SYSTEM_PROJECT_BASE | grep -E -v '^(archive|logs|$PROJECTS_DIR_IGNORE_REGEXP)\$'`);
}

function call_remote($sub, $params) {
    global $SYSTEM_PROJECT_BASE;
    
    return trigger_error("Couldn't locate the project directory: $SYSTEM_PROJECT_BASE ...", E_USER_ERROR);

###      $sub = preg_replace('/^.+::/','',$sub);
###  
###      $url = "https://admin.beta.project.org/project_manager/";
###      if ( $_SERVER['REMOTE_USER'] ) $url = "https://pmgr_tunnel:h53clK88FvB5\@admin.beta.project.org/project_manager/";
###  
###      $params = array( 'action'      => 'remote_call',
###                       'remote_call' => $sub,
###                       'params'      => urlencode( nfreeze( $params ) ),
###                       'wantarray' => (wantarray ? true : false),
###                       );
###  #    $agent = LWP::UserAgent->new;
###      $response = $agent->post($url, $params);
###  
###      list($frozen) = preg_match('/\|=====\|(.+)\|=====\|/', ($response->content, $m));
###      $response_obj;
###      if ( $frozen ) {
###          $response_obj = thaw(urldecode($frozen));
###          if ( ! ref($response_obj) ) {
###              BUG ["Not a ref", $frozen, $response_obj];
###              return trigger_error("Not a ref : ", E_USER_ERROR). $response->content;
###          }
###      }
###      else {
###          BUG ["Bad Response", $response->content];
###          return trigger_error("Bad Response : ", E_USER_ERROR). $response->content;
###      }
###  
###      return( wantarray && UNIVERSAL::isa($response_obj, 'ARRAY')
###              ? (@[$response_obj])
###              : $$response_obj
###            );
}


#########################
###  Utility functions

function log_svn_action( $command ) {
    global $PROJECT_SAFE_BASE;

    $log_line = join(',', array(time(), getmypid(), date(DATE_RFC822,time()), $command)). "\n";

    $file = "$PROJECT_SAFE_BASE/project_svn_log_".$env_mode.".csv";
    file_put_contents($file, $log_line, FILE_APPEND);
}

function get_affected_files( $project_name ) {

    $files = array();
    foreach ( explode("\n",get_project_file( $project_name, "affected_files.txt" )) as $file ) {
        $file = preg_replace('/(\s*\#.*$|\s+)$/','',$file);
        if ( strlen( $file ) == 0 ) continue;

        array_push( $files, $file );
    }

    return $files;
}

function get_file_tags( $project_name ) {

    $file_tags = array();
    foreach ( explode("\n",get_project_file( $project_name, "file_tags.csv" )) as $line ) {
        $vals = str_getcsv($line);
        if ( ! $vals >= 2 && ! preg_match('/[\"]/', $vals[1], $m) && preg_match('/^\d+\.\d+(\.\d+\.\d+)?$/', $vals[1], $m) ) continue;
        $file_tags{ $vals[0] } = $vals[1];
    }

    return $file_tags;
}

function get_revs_in_diff( $from, $to ) {
    if ( preg_match('/[^\d\.]/', $from, $m) || preg_match('/[^\d\.]/', $to, $m) ) return;
    if ( $from == $to ) return;

    $revs = array();

    ###  Determi!= revisions between
    list($f_trunk, $f_rev) = preg_match('/^([\d\.]+?)(\d+)$/', $from, $m);
    list($t_trunk, $t_rev) = preg_match('/^([\d\.]+?)(\d+)$/', $to, $m);
    ###  If along the same trunk it's easy
    if ( $f_trunk == $t_trunk ) {
        if ( $f_rev >= $t_rev ) return;
        $revs = array();  foreach ( range( ($f_rev+1), $t_rev ) as $_ )  { $revs[] =  "$f_trunk$_" ; }
    }
    ###  If moving to a branch from non-branch, check ...
    else if ( preg_match('/^\d+\.$/', $f_trunk, $m)           && preg_match('/^\d+\.\d+\.\d+\.$/', $t_trunk, $m) ) {
        list( $t_branch_start_rev ) = ( preg_match('/^$f_trunk(\d+)\.\d+\.$/', $t_trunk, $m) );
        if ( ! $t_branch_start_rev ) trigger_error("Should not happen.  OUCH!", E_USER_ERROR);

        ###  If branch is a leap back, just show the branch revs
        if ( $t_branch_start_rev <= $f_rev ) {
            if ( $t_rev < 1 ) return;
            $revs = array();  foreach ( range( 1, $t_rev ) as $_ )  { $revs[] =  "$t_trunk$_" ; }
        }
        ###  Otherwise, show all the trunk revs up to the 
        ###    branch, and then then branch revs
        else {
            if ( $t_rev < 1 ) return;
            $revs = array();
            foreach ( range( ($f_rev+1), $t_branch_start_rev ) as $_ ) { $revs[] = "$f_trunk$_"; }
            foreach ( range( 1, $t_rev ) as $_ )                       { $revs[] = "$t_trunk$_"; }
        }
    }
    ###  If moving back to a non-branch from a branch
    else if ( preg_match('/^\d+\.\d+\.\d+\.$/', $f_trunk, $m) && preg_match('/^\d+\.$/', $t_trunk, $m)           ) {
        list( $f_branch_start_rev ) = ( preg_match('/^$t_trunk(\d+)\.\d+\.$/', $f_trunk, $m) );
        if ( $f_branch_start_rev ) trigger_error("Should not happen.  OUCH!", E_USER_ERROR);
        if ( $f_branch_start_rev >= $t_rev ) return;
        $revs = array();  foreach ( range( ($f_branch_start_rev+1), $t_rev ) as $_ )  { $revs[] =  "$t_trunk$_" ; }
    }
    ###  Moving from o!= branch to another (rare)
    ###    just jump back to the head of the from-branch
    ###    and run self as if moving from that trunk rev
    else if ( preg_match('/^\d+\.\d+\.\d+\.$/', $f_trunk, $m) && preg_match('/^\d+\.\d+\.\d+\.$/', $t_trunk, $m) ) {
        list( $f_branch_start ) = ( preg_match('/^(\d+\.\d+)\.\d+\.$/', $f_trunk, $m) );
        if ( ! $f_branch_start ) trigger_error("Should not happen.  OUCH!", E_USER_ERROR);
        return get_revs_in_diff($f_branch_start, $to);
    }
    ###  Else, DIE!
    else { return trigger_error("What the heck are ya!? ($from, $to)", E_USER_ERROR); }

    return $revs;
}

function get_prev_rev( $rev ) {
    if ( $rev == '1.1' ) return $rev;

    if ( preg_match('/^(\d+\.\d+)\.\d+\.1$/', $rev, $m) ) {
        return $m[1];
    }
    else if ( preg_match('/^(\d+\.(?:\d+\.\d+\.)?)(\d+)$/', $rev, $m) ) {
        return $m[1].($m[2]-1);
    }
}

function get_log_entry( $clog, $rev ) {
    preg_match('/---------+\n(revision \Q$rev\E\n.+?)(?:\n=========+$|\n---------+\nrevision )/s', $clog, $m);
    return $m[0];
}


#########################
###  Display Logic

function style_sheet() {
    $ret = <<<ENDSTYLE
<style>
body, td        { font-family: Verdana, Arial, Helvetica;
                  font-color: #111111;
                  color: #111111;
                  font-size: 10pt;
                }
td { white-space: nowrap }
th              { font-weight: bold;
                  font-color: #000000;
                  color: #000000;
                }
a               { text-decoration: no!=;  white-space: nowrap }

</style>
ENDSTYLE;

    ###  HACK, add JavaScript...
    $ret .= <<<ENDSCRIPT
<script>
var disable_actions = 0;

function confirmAction(which,newLocation) {
    //  If locally modified files, diabled actions
    if ( disable_actions ) {
        alert("Some of the below files are locally modified, or have conflicts.  SVN update actions would possibly conflict the file leaving code files in a broken state.  Please resolve these differences manually (command line) before continuing.\\n\\nActions are currently DISABLED.");
        return void(null);
    }

    var confirmed = confirm("Please confirm this action.\\n\\nAre you sure you want to "+which+" these files?");
    if (confirmed) { location.href = newLocation }
}
</script>
ENDSCRIPT;

    ###  HACK, add a line of status for sandbox location
    $ret .= env_header();

    return $ret;
}

function env_header() {
    global $PROJECT_STAGING_AREAS, $PROJECT_SANDBOX_AREAS;

    ###  A line of status for sandbox location
    $ret = "<table width=\"100%\" cellspacing=0 cellpadding=0 border=0><tr><td><div style=\"font-size:70%\">";
    $ret .= "<b>Go to:</b> <a href=\"?\">Project List</a>\n";
    $ret .= "<br><b>Current Sandbox Root</b>: ". $_SERVER['PROJECT_SVN_BASE'];

    $ret .= "</div></td><td align=right><div style=\"font-size:70%\">";

    ###  And stuff to switch between environments
    $uri = $_SERVER['HTTP_HOST'];
    $query_string = $_SERVER['QUERY_STRING'];
    $query_string = preg_replace('/[\&\?](cmd|command_output|tag)=[^\&]+/','',$query_string);
    $query_string = preg_replace('/action=(update|tag)/','action=view_project',$query_string);
    
    ###  Output Staging Area Switch line
    $tmp = array();
    foreach ( $PROJECT_STAGING_AREAS as $area ) {
        $selected = false;
        if ( ! empty( $area['test_by_func'] ) )   $selected = call_user_func($area['test_by_func']);
        if ( ! empty( $area['test_uri_regex'] ) ) $selected = preg_match($area['test_uri_regex'], $uri);
        $tmp[] = "<a href=\"http://". $area['host'] . $_SERVER['SCRIPT_NAME'] ."?". $query_string ."\">".  ($selected ? "<b>" : "") . $area['label'] ."</b></a>";
    }
    $ret .= '  '. join("\n|  ", $tmp). ": <b>Switch to Staging Area</b>";

    ###  Output Sandbox Switch line
    $tmp = array();
    foreach ( $PROJECT_SANDBOX_AREAS as $area ) {
        $selected = false;
        if ( ! empty( $area['test_by_func'] ) )   $selected = call_user_func($area['test_by_func']);
        if ( ! empty( $area['test_uri_regex'] ) ) $selected = preg_match($area['test_uri_regex'], $uri);
        $tmp[] = "<a href=\"http://". $area['host'] . $_SERVER['SCRIPT_NAME'] ."?". $query_string ."\">".  ($selected ? "<b>" : "") . $area['label'] ."</b></a>";
    }
    $ret .= '<br>'. join("\n|  ", $tmp) . ": <b>Switch to Sandbox</b>";
    $ret .= "</div></td></td></table>";

    return $ret;
}

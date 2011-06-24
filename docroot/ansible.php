<?php

#########################
###  Project Manager
#
# Version : $Id: index.pl,v 1.1 2010/11/17 23:34:19 project Exp $
#
#########################

#########################
###  Configuration, Setup

require_once(dirname(__FILE__) .'/lib/config.php');
require_once(dirname(__FILE__) .'/lib/debug.inc.php');
require_once(dirname(__FILE__) .'/lib/Repo.class.php');
require_once(dirname(__FILE__) .'/lib/Project.class.php');
require_once(dirname(__FILE__) .'/'. $ANSIBLE_REPO_FILE);

#$cmd = $REPO_CMD_PREFIX.'svn log index.php';
#bug( `$cmd` );
#exit;


# phpinfo(); exit;

$delayed_load_id = 1;
$delayed_load_calls = array();

###  Connect to the tags DB
if ( ! empty($SYSTEM_TAGS_DB_FILE) ) {
    if ( ! file_exists( $SYSTEM_TAGS_DB_FILE ) ) $INIT_DB_NOW = true;
    
    ###  Get an exclusive File_NFSLock on the DB file...
    require_once(dirname(__FILE__) .'/lib/File_NFSLock.class.php');
    $db_file_lock = new File_NFSLock($SYSTEM_TAGS_DB,LOCK_EX,10,30*60); # stale lock timeout after 30 minutes
}

if ( ! empty($SYSTEM_TAGS_DB) ) {
    $dbh = new PDO($SYSTEM_TAGS_DB, $SYSTEM_TAGS_DB_USERNAME, $SYSTEM_TAGS_DB_PASSWORD);  $GLOBALS['orm_dbh'] = $dbh;
    $dbh->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    if ( ! empty($INIT_DB_NOW) ) {
        $dbh->exec("CREATE TABLE file_tag (
                         file      character varying(1000) NOT NULL,
                         tag       character varying(25) NOT NULL,
                         revision  int NOT NULL,
                         mass_edit int NOT NULL DEFAULT 0,
                         CONSTRAINT file_tag_pk PRIMARY KEY ( file, tag )
                       )
                      ");

//$dbh->exec("ALTER TABLE file_tag ADD COLUMN
//             mass_edit int NOT NULL DEFAULT 0");
//exit;

    }
}
$BUG_ON = true;



#########################
###  Main Runtime

###  Get Repo
$repo = new $ANSIBLE_REPO_CLASS ();

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

    $project = new Ansible__Project( $_REQUEST['pname'] );
    $tag = $_REQUEST['tag'];
    if ( empty( $tag ) || ! $project->exists() ) {
        echo style_sheet();
        view_project_page();
    }
    if ( preg_match('/[^\w\_\-\.]/', $tag, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);

    ###  Set Group..
    if ( ! empty( $_REQUEST['set_group'] ) ) {
        if ( preg_match('/\W/', $_REQUEST['set_group']) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);
        $project->set_group($_REQUEST['set_group']);
    }

    ###  Run the action
    list( $cmd, $command_output ) = $repo->updateAction( $project, $tag, ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous' );

    ###  If the Bounce URL is too long for HTTP protocol maximum then just echo out the stuff...
    $bounce_url = "?action=view_project&pid=". getmypid() ."&pname=". urlencode($project->project_name) ."&cmd=". urlencode(base64_encode(gzdeflate($cmd, 9))) ."&command_output=". urlencode(base64_encode(gzdeflate($command_output, 9)));
    if ( strlen( $bounce_url ) > 2000 ) {
        echo style_sheet();
        echo "<font color=red><h3>Command Output (Too Large for redirect)</h3>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n";
        echo "<xmp>> $cmd\n\n$command_output\n</xmp>\n\n";
        echo "</font>\n\n";
    }
    ###  Else, just bounce
    else {
        header("Location: $bounce_url");
        exit;
    }
}
else if ( $_REQUEST['action'] == 'tag' ) {
    if ( $READ_ONLY_MODE ) return trigger_error("Permission Denied", E_USER_ERROR);

    $project = new Ansible__Project( $_REQUEST['pname'] );
    $tag = $_REQUEST['tag'];
    if ( empty( $tag ) ) {
        echo style_sheet();
        view_project_page();
    }
    if ( preg_match('/[^\w\_\-\.]/', $tag, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);

    ###  Set Group..
    if ( ! empty( $_REQUEST['set_group'] ) ) {
        if ( preg_match('/\W/', $_REQUEST['set_group']) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);
        $project->set_group($_REQUEST['set_group']);
    }
    
    ///  Run the action
    list( $cmd, $command_output ) = $repo->tagAction( $project, $tag, ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous' );

    ###  If the Bounce URL is too long for HTTP protocol maximum then just echo out the stuff...
    $bounce_url = "?action=view_project&pid=". getmypid() ."&pname=". urlencode($project->project_name) ."&cmd=". urlencode(base64_encode(gzdeflate($cmd, 9))) ."&command_output=". urlencode(base64_encode(gzdeflate($command_output, 9)));
    if ( strlen( $bounce_url ) > 2000 ) {
        echo style_sheet();
        echo "<font color=red><h3>Command Output (Too Large for redirect)</h3>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n";
        echo "<xmp>> $cmd\n\n$command_output\n</xmp>\n\n";
        echo "</font>\n\n";
    }
    ###  Else, just bounce
    else {
        header("Location: $bounce_url");
        exit;
    }
}
else if ( $_REQUEST['action'] == 'entire_repo_tag' ) {
    if ( $READ_ONLY_MODE ) return trigger_error("Permission Denied", E_USER_ERROR);

    ///  These can take a while...
    set_time_limit( 0 );

    $tag = $_REQUEST['tag'];
    if ( empty( $tag ) ) {
        echo style_sheet();
        repo_admin_page();
    }
    if ( preg_match('/[^\w\_\-\.]/', $tag, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);
    
    ///  Run the action
    list( $cmd, $command_output ) = $repo->tagEntireRepoAction( $tag, ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous' );

    ###  If the Bounce URL is too long for HTTP protocol maximum then just echo out the stuff...
    $bounce_url = "?action=repo_admin&pid=". getmypid() ."&cmd=". urlencode(base64_encode(gzdeflate($cmd, 9))) ."&command_output=". urlencode(base64_encode(gzdeflate($command_output, 9)));
    if ( strlen( $bounce_url ) > 2000 ) {
        echo style_sheet();
        echo "<font color=red><h3>Command Output (Too Large for redirect)</h3>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n";
        echo "<xmp>> $cmd\n\n$command_output\n</xmp>\n\n";
        echo "</font>\n\n";
    }
    ###  Else, just bounce
    else {
        header("Location: $bounce_url");
        exit;
    }
}
else if ( $_REQUEST['action'] == 'entire_repo_update' ) {
    if ( $READ_ONLY_MODE ) return trigger_error("Permission Denied", E_USER_ERROR);

    ///  These can take a while...
    set_time_limit( 0 );

    $update = $_REQUEST['update'];
    if ( empty( $tag ) ) {
        echo style_sheet();
        repo_admin_page();
    }
    if ( preg_match('/[^\w\_\-\.]/', $tag, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);
    
    ///  Run the action
    list( $cmd, $command_output ) = $repo->updateEntireRepoAction( $tag, ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous' );

    ###  If the Bounce URL is too long for HTTP protocol maximum then just echo out the stuff...
    $bounce_url = "?action=repo_admin&pid=". getmypid() ."&cmd=". urlencode(base64_encode(gzdeflate($cmd, 9))) ."&command_output=". urlencode(base64_encode(gzdeflate($command_output, 9)));
    if ( strlen( $bounce_url ) > 2000 ) {
        echo style_sheet();
        echo "<font color=red><h3>Command Output (Too Large for redirect)</h3>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n";
        echo "<xmp>> $cmd\n\n$command_output\n</xmp>\n\n";
        echo "</font>\n\n";
    }
    ###  Else, just bounce
    else {
        header("Location: $bounce_url");
        exit;
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
else if ( $_REQUEST['action'] == 'repo_admin' ) {
    echo style_sheet();
    repo_admin_page();
}
else if ( $_REQUEST['action'] == 'archive_project' ) {
    if ( ! empty( $_REQUEST['pname'] ) ) {
        if ( preg_match('/[^\w\_\-]/', $_REQUEST['pname']) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);
        $project = new Ansible__Project( $_REQUEST['pname'] );
        if ( $project->exists() && ! $project->archived() ) {
            $user = ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous';
            $project->archive($user);
        }
    }
    header("Location: ?");
    exit;
}
else if ( $_REQUEST['action'] == 'unarchive_project' ) {
    if ( ! empty( $_REQUEST['pname'] ) ) {
        if ( preg_match('/[^\w\_\-]/', $_REQUEST['pname']) ) 
            return trigger_error("Please don't hack...", E_USER_ERROR);
        $project = new Ansible__Project( $_REQUEST['pname'], true );
        if ( $project->exists() && $project->archived() ) {
            $user = ( ! empty( $_SERVER['REMOTE_USER'] ) ) ? $_SERVER['REMOTE_USER'] : 'anonymous';
            $project->unarchive($user);
        }
    }
    header("Location: ?action=archived");
    exit;
}
else if ( $_REQUEST['action'] == 'archived' ) {
    echo style_sheet();
    index_page('archived');
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
run_delayed_load();
if ( PROJECT_PROJECT_TIMERS ) 
report_timers();
# exit 0;



#########################
###  Hacked Page handlers (use Template Toolkit asap!)

function index_page($category = 'active') {
    global $SYSTEM_PROJECT_BASE, $repo;

    ###  Project Groups
    $groups = array( '00_none'              => 'New Projects / In Development',
                     '01_staging'           => 'Step 1 : Updated to Staging for Testing',
                     '03_testing_done'      => 'Step 3 : Testing Done - Tagged as PROD_TEST',
                     '04_prod_rollout_prep' => 'Step 4 : Production tagged as PROD_SAFE',
                     '05_rolled_out'        => 'Step 5 : Rolled out to Production',
                     );

    ###  List of projects
    echo "<h3>List of ". ( $category == 'archived' ? 'Archived' : '' ) ." Projects</h3>\n";

    $projects = array();
    $category_list = $category == 'archived' ? get_archived_projects() : get_projects();
    foreach ( $category_list as $project_name ) {
        if ( empty( $project_name ) ) continue;

        $project = new Ansible__Project( $project_name, ($category == 'archived') );

        ###  Get more info from ls
        $ls = ( is_dir($SYSTEM_PROJECT_BASE)) ? (preg_split('/\s+/', $project->get_ls()) ) : array();
#        $stat = (is_dir($SYSTEM_PROJECT_BASE)) ? ($project->get_stat()) : ();
        $stat = $project->get_stat();

        $project_info = array( 'name'                => $project_name,
                               'creator'             => ($ls[2] || '-'),
                               'group'               => ($ls[3] || '-'),
                               'mod_time'            => ($stat ? $stat[9] : 0),
                               'mod_time_display'    => ($stat ? date('n/j/y',$stat[9])  : '-'),
                               'has_summary'         => ( (is_dir($SYSTEM_PROJECT_BASE))
                                                          ? ( $project->file_exists( "summary.txt" ) ? "YES" : "")
                                                          : '-'
                                                          ),
                               'aff_file_count'      => count($project->get_affected_files()),
                               );
        
        //  Make array key unique, but sortable
        $projects[ $project->get_group() ][ sprintf("%011d",$project_info['mod_time']) .'_'.$project_name ] = $project_info;
    }

    ksort($projects, SORT_NUMERIC);
    foreach ( array_keys( $projects ) as $group ) {
        ksort($projects[ $group ], SORT_NUMERIC);  $projects[ $group ] = array_reverse( $projects[ $group ] );
        print( "<h2>". ( isset( $groups[$group] ) ? $groups[$group] : $group ) ."</h2>" 
               . "<table width=100%>\n"
               . "<tr>"
               . "<th width=30% align=left>Name</th>"
               . "<th align=center>Created by</th>"
               . "<th align=center>Last Modified</th>"
               . "<th align=center>Number of files</th>"
               . "<th align=center>Summary File</th>"
               . "<th align=left>Actions</th>"
               . "</tr>\n"
               );
        foreach ( $projects[ $group ] as $project ) {
#            echo "<tr><td></li>\n";
            print( "<tr>"
                   . "<td>"
                   . ( $category == 'archived'
                       ? $project['name']
                       : "<a href=\"?action=view_project&pname=". urlencode($project['name']) ."\">". $project['name'] ."</a>"
                       )
                   . "</td>"
                   . "<td align=center>". $project['creator'] ."</td>"
                   . "<td align=center>". $project['mod_time_display'] ."</td>"
                   . "<td align=center>". $project['aff_file_count'] ."</td>"
                   . "<td align=center>". $project['has_summary'] ."</td>"
                   . "<td>"
                   . ( $category == 'archived'
                       ? "<a href=\"?action=unarchive_project&pname=". urlencode($project['name']) ."\">Un-Archive</a>"
                       : "<a href=\"?action=view_project&pname=". urlencode($project['name']) ."\">View</a> | <a href=\"?action=archive_project&pname=". urlencode($project['name']) ."\">Archive</a>"
                       )
                   . "</td>"
                   . "</tr>\n"
                   );
        }
        echo "</table>\n";
    }

    if ( $category != 'archived' ) {
        echo '<p>See list of <a href="?action=archived">Archived projects</p>';
    } else  {
        echo '<p>Back to <a href="?">Active projects list</p>';
    }

    echo "</ul>\n\n";
}

function view_project_page() {
    global $repo;

    list( $cmd, $command_output ) = array( $_REQUEST['cmd'], $_REQUEST['command_output'] );
    $project = new Ansible__Project( $_REQUEST['pname'] );

    ###  Command output
    if ( ! empty( $cmd ) ) {
        echo "<font color=red><h3>Command Output</h3>\n";
        echo "<xmp>> ". gzinflate( base64_decode($cmd) ) ."\n\n". gzinflate( base64_decode($command_output) ) ."\n</xmp>\n\n";
        echo "</font>\n\n";
        echo "<br><br><a href=\"?action=view_project&pname=$project->project_name\" style=\"font-size:70%\">&lt;&lt;&lt; Click here to hide Command output &gt;&gt;&gt;</a><br>\n\n";
    }

    echo "<h2>Project: $project->project_name</h2>\n\n";

    ###  Actions
    if ( $READ_ONLY_MODE ) {
        echo <<<ENDHTML
<table width="100%" border=0 cellspacing=0 cellpadding=0>
<tr>
  <td align="left" valign="top">
    <h3>Actions</h3>
    <i>You must log in as a privileged user to perform $repo->display_name actions.  Sorry.</i>
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
    Update to: <a href="javascript: confirmAction('UPDATE','?action=update&pname=$project->project_name&tag=Target')"   >Target</a>
                 | <a href="javascript: confirmAction('UPDATE','?action=update&pname=$project->project_name&tag=HEAD')"     >HEAD</a>
                 | <a href="javascript: confirmAction('UPDATE','?action=update&pname=$project->project_name&tag=PROD_TEST')">PROD_TEST</a>
                 | <a href="javascript: confirmAction('UPDATE','?action=update&pname=$project->project_name&tag=PROD_SAFE')">PROD_SAFE</a>
    <br>Tag as:    <a href="javascript: confirmAction('TAG',   '?action=tag&pname=$project->project_name&tag=PROD_TEST')"     >PROD_TEST</a>
                 | <a href="javascript: confirmAction('TAG',   '?action=tag&pname=$project->project_name&tag=PROD_SAFE')"     >PROD_SAFE</a>
  </td>
  <td align="left" valign="top">
ENDHTML;
    }

    ###  Rollout process for different phases
    if ( onAlpha() ) {
        $beta_area_url = get_area_url('beta');
        echo <<<ENDHTML
            <h3>Rollout Process</h3>
            When you are ready, review the below file list to make sure:
            <ol>
            <li>All needed code and display logic files are here</li>
            <li>Any needed database patch scripts are listed (if any)</li>
            <li>In the "Current Status" column everything is "Up-to-date"</li>
            <li>In the "Changes by" column, they are all ychanges</li>
            </ol>
            Then, tell QA and they will continue in the <a href="$beta_area_url">QA Staging Area</a>
ENDHTML;
    }
    else if ( onBeta() ) {
        if ( $READ_ONLY_MODE ) {
            $live_area_url = get_area_url('live');
            echo <<<ENDHTML
            <h3>Rollout Process - QA STAGING PHASE</h3>
            <b>Step 1</b>: Once developer is ready, Update to Target<br>
            <b>Step 2</b>: <i> -- Perform QA testing -- </i><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2a</b>: For minor updates, Update to Target again<br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2b</b>: If major problems, Roll back to PROD_TEST<br>
            <b>Step 3</b>: When everything checks out, Tag as PROD_TEST<br>
            <br>
            Then, <a href="$live_area_url">Switch to Live Production Area</a>
ENDHTML;
        }
        else {
            $live_area_url = get_area_url('live');
            echo <<<ENDHTML
            <h3>Rollout Process - QA STAGING PHASE</h3>
            <b>Step 1</b>: Once developer is ready, <a href="javascript: confirmAction('UPDATE','?action=update&pname=$project->project_name&tag=Target&set_group=01_staging')"   >Update to Target</a><br>
            <b>Step 2</b>: <i> -- Perform QA testing -- </i><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2a</b>: For minor updates, <a      href="javascript: confirmAction('UPDATE','?action=update&pname=$project->project_name&tag=Target')"   >Update to Target again</a><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 2b</b>: If major problems, <a      href="javascript: confirmAction('UPDATE','?action=update&pname=$project->project_name&tag=PROD_TEST&set_group=00_none')">Roll back to PROD_TEST</a><br>
            <b>Step 3</b>: When everything checks out, <a href="javascript: confirmAction('TAG',   '?action=tag&pname=$project->project_name&tag=PROD_TEST&set_group=03_testing_done')"     >Tag as PROD_TEST</a><br>
            <br>
            Then, <a href="$live_area_url">Switch to Live Production Area</a>
ENDHTML;
        }            
    }
    else if ( onLive() ) {
        if ( $READ_ONLY_MODE ) {
            $beta_area_url = get_area_url('beta');
            echo <<<ENDHTML
            <h3>Rollout Process - LIVE PRODUCTION PHASE</h3>
            Check that in the "Current Status" column there are <b><u>no <b>"Locally Modified"</b> or <b>"Needs Merge"</b> statuses</u></b>!!
            <br>
            <b>Step 4</b>: Set set a safe rollback point, Tag as PROD_SAFE<br>
            <b>Step 5</b>: Then to roll it all out, Update to PROD_TEST<br>
            <b>Step 6</b>: <i> -- Perform QA testing -- </i><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6a</b>: If any problems, Roll back to PROD_SAFE<br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6b</b>: While fixes are made, Re-tag to PROD_TEST<br>
            Then, go back to the <a href="$beta_area_url">QA Staging Area</a> and continue with <b>Step 1</b> or <b>Step 2</b>.
ENDHTML;
        }
        else {
            $beta_area_url = get_area_url('beta');
            echo <<<ENDHTML
            <h3>Rollout Process - LIVE PRODUCTION PHASE</h3>
            Check that in the "Current Status" column there are <b><u>no <b>"Locally Modified"</b> or <b>"Needs Merge"</b> statuses</u></b>!!
            <br>
            <b>Step 4</b>: Set set a safe rollback point, <a href="javascript: confirmAction('TAG',   '?action=tag&pname=$project->project_name&tag=PROD_SAFE&set_group=04_prod_rollout_prep')"     >Tag as PROD_SAFE</a><br>
            <b>Step 5</b>: Then to roll it all out, <a      href="javascript: confirmAction('UPDATE','?action=update&pname=$project->project_name&tag=PROD_TEST&set_group=05_rolled_out')">Update to PROD_TEST</a><br>
            <b>Step 6</b>: <i> -- Perform QA testing -- </i><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6a</b>: If any problems, <a      href="javascript: confirmAction('UPDATE','?action=update&pname=$project->project_name&tag=PROD_SAFE&set_group=03_testing_done')">Roll back to PROD_SAFE</a><br>
            &nbsp;&nbsp;&nbsp;&nbsp;&nbsp;<b>Step 6b</b>: While fixes are made, <a href="javascript: confirmAction('TAG','?action=tag&pname=$project->project_name&tag=PROD_TEST&set_group=01_staging')">Re-tag to PROD_TEST</a><br>
            Then, go back to the <a href="$beta_area_url">QA Staging Area</a> and continue with <b>Step 1</b> or <b>Step 2</b>.
ENDHTML;
        }
    }

    ###  End table
    echo <<<ENDHTML
  </td>
</table>
ENDHTML;

    ###  Read in the file tags CSV "
    $file_tags = $project->get_file_tags();

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
    $files = $project->get_affected_files();
#    $repo->cache_logs( $files );
#    $repo->cache_statuses( $files );
    $locally_modified = false;
    foreach ( $files as $file ) {

        list($cur_vers, $head_vers, $prod_test_vers, $prod_safe_vers) = array('','','','');

        ###  Get Current Version
#        $cur_vers = delayed_load_span(array($file), create_function('$file',now_doc('DELAY')/*
#            global $repo;
            if ( ! file_exists($_SERVER['PROJECT_REPO_BASE'] ."/$file") ) {
                $cur_vers = '<i>-- n/a --</i>';
            } else if ( is_dir($_SERVER['PROJECT_REPO_BASE'] ."/$file") ) {
                $cur_vers = '<i>Directory</i>';
            } else {
                list($cur_rev, $error, $status, $state_code, $is_modified)
                    = $repo->get_current_rev( $file );
                if ( empty( $error ) ) {
            
                    ###  Add a diff link if Locally Modified
                    if ( $is_modified ) {
                        $cur_vers = "<a href=\"?action=diff&from_rev=$cur_rev&to_rev=local&file=". urlencode($file) ."\">$status</a>, $cur_rev";
                        $locally_modified = true;
                    }
                    else { $cur_vers = "$status, $cur_rev"; }
                } else {
                    $cur_vers = "<div title=\"". htmlentities( $repo->get_status($file)) ."\"><i>". $error ."</i></div>";
                }
            }

#            return $cur_vers;
#DELAY
#*/
#));

        ###  Get PROD_SAFE Version
        $prod_safe_vers = delayed_load_span(array($file,$cur_rev), create_function('$file,$cur_rev',now_doc('DELAY')/*
            global $repo;
            $clog = $repo->get_log($file, 10);

            $prod_safe_rev = $repo->get_tag_rev($file, 'PROD_SAFE');
            if ( $prod_safe_rev ) {
                if ( $prod_safe_rev != $cur_rev ) {
                    $prod_safe_vers = "<b><font color=red>$prod_safe_rev</font></b>";
                }
                else { $prod_safe_vers = $prod_safe_rev; }
            }
            else { $prod_safe_vers = '<i>-- n/a --</i>'; }

            return $prod_safe_vers;
DELAY
*/
));

        ###  Get PROD_TEST Version
        $prod_test_vers = delayed_load_span(array($file,$cur_rev), create_function('$file,$cur_rev',now_doc('DELAY')/*
            global $repo;
            $clog = $repo->get_log($file, 10);

            $prod_test_rev = $repo->get_tag_rev($file, 'PROD_TEST');
            if ( ! empty( $prod_test_rev ) ) {
                if ( $prod_test_rev != $cur_rev ) {
                    $prod_test_vers = "<b><font color=red>$prod_test_rev</font></b>";
                }
                else { $prod_test_vers = $prod_test_rev; }
            }
            else { $prod_test_vers = '<i>-- n/a --</i>'; }

            return $prod_test_vers;
DELAY
*/
));

        ###  Get HEAD Version
        $head_vers = delayed_load_span(array($file,$cur_rev,$project,$file_tags), create_function('$file,$cur_rev,$project,$file_tags',now_doc('DELAY')/*
            global $repo;
            $clog = $repo->get_log($file, 10);

            list($head_rev, $error, $error_code) = $repo->get_head_rev($file);
            if ( empty($error) ) {
                if ( $head_rev != $cur_rev
                     && ( empty( $file_tags[$file] )
                          || $file_tags[$file] == $cur_rev
                          )
                     ) {
                    $head_vers = "<b><font color=red>$head_rev</font></b>";
                }
                else { $head_vers = $head_rev; }
            } else if ( $error_code == 'not_exists' ) {
                $head_vers = "<i>". $error ."</i>";
            } else {
                $head_vers = "<div title=\"". htmlentities( $repo->get_log($file) ) ."\"><i>". $error ."</i></div>";
            }

            return $head_vers;
DELAY
*/
));

        ###  Do Target
        $target_vers = delayed_load_span(array($file,$cur_rev,$project), create_function('$file,$cur_rev,$project',now_doc('DELAY')/*
            global $repo;
            $clog = $repo->get_log($file, 10);

            list($head_rev, $error, $error_code) = $repo->get_head_rev($file);
            if ( empty($error) ) {
                ###  Set Target version if it's there
                list($target_rev, $used_file_tags) = $project->determine_target_rev($file, $head_rev);
                if ( $used_file_tags ) {
                    if ( $target_rev != $cur_rev ) { $target_vers = "<b><font color=red>". $target_rev ."</font></b>"; }
                    else {                           $target_vers = "<b>".                 $target_rev        ."</b>"; }
                }
                else { $target_vers = '-&gt;'; }
            }

            return $target_vers;
DELAY
*/
));

        ###  Changes by
        $changes_by = delayed_load_span(array($file,$cur_rev,$project), create_function('$file,$cur_rev,$project',now_doc('DELAY')/*
            global $repo;
            $clog = $repo->get_log($file, 10);

            $prod_test_rev = $repo->get_tag_rev($file, 'PROD_TEST');
            list($head_rev, $error, $error_code) = $repo->get_head_rev($file);
            list($target_rev, $used_file_tags) = $project->determine_target_rev($file, $head_rev);
            $c_by_rev = onLive() ? $cur_rev : $prod_test_rev;
            if ( $c_by_rev && $target_rev ) {
                $diff_revs = $repo->get_revs_in_diff($file, $c_by_rev, $target_rev);
                $names = array();  foreach ( array_reverse( $diff_revs ) as $_ ) { $names[] = $repo->get_rev_committer( $file, $_ ); }
                $names = array_unique($names);
    
                ###  Find regressions!
                $changes_by = null;
                if ( count($diff_revs) == 0 && $c_by_rev != $target_rev ) {
                    $reverse_revs = $repo->get_revs_in_diff($file, $target_rev, $c_by_rev);
                    if ( count($reverse_revs) > 0 ) {
                        $changes_by = '<font color=red><b><i>-'. count( $reverse_revs ) .' rev'. (count($reverse_revs) == 1 ? '' : 's'). '!!!</i></b></font>';
                    }
                }
                if ( empty($changes_by) ) $changes_by = count( $diff_revs ) .' rev'. (count($diff_revs) == 1 ? '' : 's') . ($names ? (', '. join(', ',$names)) : '');
            }

            return $changes_by;
DELAY
*/
));

        ###  Actions
        $actions = delayed_load_span(array($file,$cur_rev,$project), create_function('$file,$cur_rev,$project',now_doc('DELAY')/*
            global $repo;

            $prod_test_rev = $repo->get_tag_rev($file, 'PROD_TEST');
            list($head_rev, $error, $error_code) = $repo->get_head_rev($file);
            list($target_rev, $used_file_tags) = $project->determine_target_rev($file, $head_rev);
            $c_by_rev = onLive() ? $cur_rev : $prod_test_rev;

            $actions = '<i>n/a</i>';
            if ( $c_by_rev && $target_rev ) {
                $actions = ( "<a         href=\"?action=part_log&from_rev=$c_by_rev&to_rev=$target_rev&file=". urlencode($file) ."\">Log</a>"
                             . "&nbsp;<a     href=\"?action=diff&from_rev=$c_by_rev&to_rev=$target_rev&file=". urlencode($file) ."\">Diff</a>"
                             );
            }

            return $actions;
DELAY
*/
));

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
<script type="text/javascript">
disable_actions = 1;
</script>
ENDHTML;
    }

    ###  Summary File
    echo "<h3>Summary</h3>\n<pre>";
    if ( $project->file_exists("summary.txt") ) {
        echo $project->get_file("summary.txt");
    } else {
        echo "-- No project summary entered --\n\n";
    }
    echo "</pre>\n\n";

}

function repo_admin_page() {
    global $repo;

    list( $cmd, $command_output ) = array( $_REQUEST['cmd'], $_REQUEST['command_output'] );

    ###  Command output
    if ( ! empty( $cmd ) ) {
        echo "<font color=red><h3>Command Output</h3>\n";
        echo "<xmp>> ". gzinflate( base64_decode($cmd) ) ."\n\n". gzinflate( base64_decode($command_output) ) ."\n</xmp>\n\n";
        echo "</font>\n\n";
        echo "<br><br><a href=\"?action=repo_admin\" style=\"font-size:70%\">&lt;&lt;&lt; Click here to hide Command output &gt;&gt;&gt;</a><br>\n\n";
    }

    echo "<h2>Repository Administration and Maintenance</h2>\n\n";

    ###  Actions
    if ( $READ_ONLY_MODE ) {
        echo <<<ENDHTML
<table width="100%" border=0 cellspacing=0 cellpadding=0>
<tr>
  <td align="left" valign="top">
    <h3>Actions</h3>
    <i>You must log in as a privileged user to perform $repo->display_name actions.  Sorry.</i>
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
    Update Entire Current Repository to: <a href="javascript: confirmAction('UPDATE','?action=entire_repo_update&tag=HEAD')"      >HEAD</a>
                 | <a href="javascript: confirmAction('UPDATE','?action=entire_repo_update&tag=PROD_TEST')">PROD_TEST</a>
                 | <a href="javascript: confirmAction('UPDATE','?action=entire_repo_update&tag=PROD_SAFE')">PROD_SAFE</a>
    <br>Tag Entire Current Repository as:    <a href="javascript: confirmAction('TAG',   '?action=entire_repo_tag&tag=PROD_TEST')">PROD_TEST</a>
                 | <a href="javascript: confirmAction('TAG',   '?action=entire_repo_tag&tag=PROD_SAFE')"     >PROD_SAFE</a>
    <br>Diff Entire Current Repository to: <a href="?action=entire_repo_diff&tag=HEAD"    >HEAD</a>
                 | <a href="?action=entire_repo_diff&tag=PROD_TEST">PROD_TEST</a>
                 | <a href="?action=entire_repo_diff&tag=PROD_SAFE">PROD_SAFE</a>
  </td>
  <td align="left" valign="top">
ENDHTML;
    }

    ###  Rollout process for different phases
    if ( onAlpha() ) {
        $beta_area_url = get_area_url('beta');
        echo <<<ENDHTML
            <h3>Maintenance Operations</h3>
            <i>None yet.  Maintenance is best done manually on command-line to preserve your own user permissions</i>
ENDHTML;


            ///  Repo Status
            echo "<h4>Repository Status</h4>";
            echo "Current Status: ";
            echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
                global $repo;
                
                $dir_status = $repo->analyze_dir_status();
                $status_items = array();
                if ( ! empty( $dir_status['has_modified'] ) ) $status_items[] = $dir_status['has_modified'] .' Locally Modified';
                if ( ! empty( $dir_status['has_conflicts'] ) ) $status_items[] = '<font color="red">'. $dir_status['has_conflicts'] .' Has Conflicts</font>';
                if ( empty( $status_items ) ) $status_items[] = '<font color="green">No Local Changes</font>';
                
                $cur_vers = join(', ', $status_items);
                
                return $cur_vers;
DELAY
*/
));
            ///  Repo Diff from PROD TEST
            echo "<br/>Diff from PROD_TEST: ";
            echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
                global $repo;
                
                $dir_diff = $repo->diff_dir_from_tag('PROD_TEST');
                $diff_items = array();
                if ( ! empty( $dir_diff['files_ahead_of_tag'] ) ) $diff_items[] = $dir_diff['files_ahead_of_tag'] .' ahead of tag';
                if ( ! empty( $dir_diff['files_behind_tag']   ) ) $diff_items[] = $dir_diff['files_behind_tag']   .' behind tag';
                if ( ! empty( $dir_diff['files_no_tag']       ) ) $diff_items[] = $dir_diff['files_no_tag']       .' NO tag';
                if ( ! empty( $dir_diff['files_unknown']      ) ) $diff_items[] = $dir_diff['files_unknown']      .' unknown';
                if ( ! empty( $dir_diff['files_on_tag']       ) ) $diff_items[] = '<font color="green">'. $dir_diff['files_on_tag']       .' on tag</font>';
                
                $prod_test_vers = join(', ', $diff_items);
                
                return $prod_test_vers;
DELAY
*/
));
            echo "<br/>";

    }
    else if ( onBeta() ) {
        echo <<<ENDHTML
        <h3>Maintenance Operations - QA STAGING PHASE</h3>
        <p>
            As a general rule, staging areas are kept as close as possible as identical as
            production (which is tracked by the tag PROD_TEST).  If there are NO Projects 
            in Step 1, 3, or 4 of rollout, then it should be SAFE to update the staging area
            to PROD_TEST.
        </p>
ENDHTML;

        ///  Output a WARNING if there are any Projects in Steps 1, 3, or 4
        echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
            global $repo;

            return '';
DELAY
*/
));

            ///  Repo Status
            echo "<h4>Repository Status</h4>";
            echo "Current Status: ";
            echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
                global $repo;
                
                $dir_status = $repo->analyze_dir_status();
                $status_items = array();
                if ( ! empty( $dir_status['has_modified'] ) ) $status_items[] = $dir_status['has_modified'] .' Locally Modified';
                if ( ! empty( $dir_status['has_conflicts'] ) ) $status_items[] = '<font color="red">'. $dir_status['has_conflicts'] .' Has Conflicts</font>';
                if ( empty( $status_items ) ) $status_items[] = '<font color="green">No Local Changes</font>';
                
                $cur_vers = join(', ', $status_items);
                
                return $cur_vers;
DELAY
*/
));
            ///  Repo Diff from PROD TEST
            echo "<br/>Diff from PROD_TEST: ";
            echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
                global $repo;
                
                $dir_diff = $repo->diff_dir_from_tag('PROD_TEST');
                $diff_items = array();
                if ( ! empty( $dir_diff['files_ahead_of_tag'] ) ) $diff_items[] = $dir_diff['files_ahead_of_tag'] .' ahead of tag';
                if ( ! empty( $dir_diff['files_behind_tag']   ) ) $diff_items[] = $dir_diff['files_behind_tag']   .' behind tag';
                if ( ! empty( $dir_diff['files_no_tag']       ) ) $diff_items[] = $dir_diff['files_no_tag']       .' NO tag';
                if ( ! empty( $dir_diff['files_unknown']      ) ) $diff_items[] = $dir_diff['files_unknown']      .' unknown';
                if ( ! empty( $dir_diff['files_on_tag']       ) ) $diff_items[] = '<font color="green">'. $dir_diff['files_on_tag']       .' on tag</font>';
                
                $prod_test_vers = join(', ', $diff_items);
                
                return $prod_test_vers;
DELAY
*/
));
            echo "<br/>";

        if ( $READ_ONLY_MODE ) {
            echo <<<ENDHTML
            <h4>Actions</h4>
            Diff   Entire STAGING Repository from PROD_TEST<br>
            Update Entire STAGING Repository to PROD_TEST<br>
ENDHTML;
        }
        else {
            echo <<<ENDHTML
            <h4>Actions</h4>
            <a href="?action=entire_repo_diff&diff=PROD_TEST">Diff   Entire STAGING Repository from PROD_TEST</a><br>
            <a href="javascript: confirmAction('UPDATE','?action=entire_repo_update&tag=PROD_TEST')"    >Update Entire STAGING Repository to PROD_TEST</a><br>
ENDHTML;
        }            
    }
    else if ( onLive() ) {
        echo <<<ENDHTML
        <h3>Maintenance Operations - LIVE PRODUCTION PHASE</h3>
        <p>
            As a general rule, production areas are kept as close as possible as identical as
            production (which is tracked by the tag PROD_TEST).  If there are NO Projects 
            in Step 1, 3, or 4 of rollout, then it should be SAFE to update the production area
            to PROD_TEST.
        </p>
ENDHTML;

        ///  Output a WARNING if there are any Projects in Steps 1, 3, or 4
        echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
            global $repo;

            return '';
DELAY
*/
));

            ///  Repo Status
            echo "<h4>Repository Status</h4>";
            echo "Current Status: ";
            echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
                global $repo;
                
                $dir_status = $repo->analyze_dir_status();
                $status_items = array();
                if ( ! empty( $dir_status['has_modified'] ) ) $status_items[] = $dir_status['has_modified'] .' Locally Modified';
                if ( ! empty( $dir_status['has_conflicts'] ) ) $status_items[] = '<font color="red">'. $dir_status['has_conflicts'] .' Has Conflicts</font>';
                if ( empty( $status_items ) ) $status_items[] = '<font color="green">No Local Changes</font>';
                
                $cur_vers = join(', ', $status_items);
                
                return $cur_vers;
DELAY
*/
));
            ///  Repo Diff from PROD TEST
            echo "<br/>Diff from PROD_TEST: ";
            echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
                global $repo;
                
                $dir_diff = $repo->diff_dir_from_tag('PROD_TEST');
                $diff_items = array();
                if ( ! empty( $dir_diff['files_ahead_of_tag'] ) ) $diff_items[] = $dir_diff['files_ahead_of_tag'] .' ahead of tag';
                if ( ! empty( $dir_diff['files_behind_tag']   ) ) $diff_items[] = $dir_diff['files_behind_tag']   .' behind tag';
                if ( ! empty( $dir_diff['files_no_tag']       ) ) $diff_items[] = $dir_diff['files_no_tag']       .' NO tag';
                if ( ! empty( $dir_diff['files_unknown']      ) ) $diff_items[] = $dir_diff['files_unknown']      .' unknown';
                if ( ! empty( $dir_diff['files_on_tag']       ) ) $diff_items[] = '<font color="green">'. $dir_diff['files_on_tag']       .' on tag</font>';
                
                $prod_test_vers = join(', ', $diff_items);
                
                return $prod_test_vers;
DELAY
*/
));
            echo "<br/>";

        if ( $READ_ONLY_MODE ) {
            echo <<<ENDHTML
            <h4>Actions</h4>
            Diff   Entire PRODUCTION Repository from PROD_TEST<br>
            Tag Entire PRODUCTION Repository as PROD_TEST<br>
ENDHTML;
        }
        else {
            echo <<<ENDHTML
            <h4>Actions</h4>
            <a href="?action=entire_repo_diff&diff=PROD_TEST">Diff   Entire PRODUCTION Repository from PROD_TEST</a><br>
            <a href="javascript: confirmAction('TAG','?action=entire_repo_tag&tag=PROD_TEST')"    >Tag Entire PRODUCTION Repository as PROD_TEST</a><br>
ENDHTML;
        }            
    }

    ###  End table
    echo <<<ENDHTML
  </td>
</table>
ENDHTML;

    ###  Echo File details
    ###    Hack for now... When we rewrite, use open!!
    echo "<h3>Repository Status for Root Directory</h3>\n";
    print( "<table width=100%>\n"
            . "<tr><td>&nbsp;</td><td colspan=5 align=center style=\"border: solid black; border-width: 1px 1px 0px 1px\"><b>Revisions</b></td><td>&nbsp;</td><td>&nbsp;</td></tr>"
            . "<tr>"
            . "<td width=30%><b>File Name</b></td>"
            . "<td align=center><b>Current Status</b></td>"
            . "<td align=center><b>PROD_TEST</b></td>"
            . "<td align=center><b>PROD_SAFE</b></td>"
            . "</tr>\n"
            );
#    $repo->cache_logs( $files );
#    $repo->cache_statuses( $files );
    $locally_modified = false;
    $files = $repo->get_ls();
    foreach ( $files as $file ) {

        list($cur_vers, $head_vers, $prod_test_vers, $prod_safe_vers) = array('','','','');

        ###  Get Current Version
#        $cur_vers = delayed_load_span(array($file), create_function('$file',now_doc('DELAY')/*
#            global $repo;

            $dir_status = $repo->analyze_dir_status($file);
            $status_items = array();
            if ( ! empty( $dir_status['has_modified'] ) ) $status_items[] = $dir_status['has_modified'] .' Locally Modified';
            if ( ! empty( $dir_status['has_conflicts'] ) ) $status_items[] = '<font color="red">'. $dir_status['has_conflicts'] .' Has Conflicts</font>';
            if ( empty( $status_items ) ) $status_items[] = '<font color="green">No Local Changes</font>';

            $cur_vers = "<div>". join(', ', $status_items) ."</div>";

#            return $cur_vers;
#DELAY
#*/
#));

        ###  Get PROD_TEST Version
        $prod_test_vers = delayed_load_span(array($file,$cur_rev), create_function('$file,$cur_rev',now_doc('DELAY')/*
            global $repo;

            $dir_diff = $repo->diff_dir_from_tag('PROD_TEST', $file);
            $diff_items = array();
            if ( ! empty( $dir_diff['files_ahead_of_tag'] ) ) $diff_items[] = $dir_diff['files_ahead_of_tag'] .' ahead of tag';
            if ( ! empty( $dir_diff['files_behind_tag']   ) ) $diff_items[] = $dir_diff['files_behind_tag']   .' behind tag';
            if ( ! empty( $dir_diff['files_no_tag']       ) ) $diff_items[] = $dir_diff['files_no_tag']       .' NO tag';
            if ( ! empty( $dir_diff['files_unknown']      ) ) $diff_items[] = $dir_diff['files_unknown']      .' unknown';
            if ( ! empty( $dir_diff['files_on_tag']       ) ) $diff_items[] = '<font color="green">'. $dir_diff['files_on_tag']       .' on tag</font>';

            $prod_test_vers = "<div>". join(', ', $diff_items) ."</div>";

            return $prod_test_vers;
DELAY
*/
));

        ###  Get PROD_SAFE Version
        $prod_safe_vers = delayed_load_span(array($file,$cur_rev), create_function('$file,$cur_rev',now_doc('DELAY')/*
            global $repo;

            $dir_diff = $repo->diff_dir_from_tag('PROD_SAFE', $file);
            $diff_items = array();
            if ( ! empty( $dir_diff['files_ahead_of_tag'] ) ) $diff_items[] = $dir_diff['files_ahead_of_tag'] .' ahead of tag';
            if ( ! empty( $dir_diff['files_behind_tag']   ) ) $diff_items[] = $dir_diff['files_behind_tag']   .' behind tag';
            if ( ! empty( $dir_diff['files_no_tag']       ) ) $diff_items[] = $dir_diff['files_no_tag']       .' NO tag';
            if ( ! empty( $dir_diff['files_unknown']      ) ) $diff_items[] = $dir_diff['files_unknown']      .' unknown';
            if ( ! empty( $dir_diff['files_on_tag']       ) ) $diff_items[] = '<font color="green">'. $dir_diff['files_on_tag']       .' on tag</font>';

            $prod_safe_vers = "<div>". join(', ', $diff_items) ."</div>";

            return $prod_safe_vers;
DELAY
*/
));

        print( "<tr>"
                . "<td><a href=\"?action=entire_repo_full_log&file=". urlencode($file) ."\">$file</a></td>"
                . "<td align=center>$cur_vers</td>"
                . "<td align=center>$prod_test_vers</td>"
                . "<td align=center>$prod_safe_vers</td>"
                . "</tr>\n"
                );
    }
    echo "</table>\n";

    ###  If there were any locally modified files, then
    ###    DISABLE Updating until they are fixed
    if ( $locally_modified ) {
        echo <<<ENDHTML
<script type="text/javascript">
disable_actions = 1;
</script>
ENDHTML;
    }

}

function part_log_page() {
    global $repo;

    $file     = $_REQUEST['file'];
    $from_rev = $_REQUEST['from_rev'];
    $to_rev   = $_REQUEST['to_rev'];
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) || preg_match('/[^\d\.]+/', $from_rev, $m) || preg_match('/[^\d\.]+/', $to_rev, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);

    echo "<h2>$repo->display_name log entries of $file from -r $from_rev to -r $to_rev</h2>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n\n";

#    ###  TESTING
#    bug [$repo->get_revs_in_diff($file, qw(1.15 1.17))];
#    bug [$repo->get_revs_in_diff($file, qw(1.17 1.15))];
#    bug [$repo->get_revs_in_diff($file, qw(1.15 1.12.2.12))];
#    bug [$repo->get_revs_in_diff($file, qw(1.15 1.17.2.12))];
#    bug [$repo->get_revs_in_diff($file, qw(1.12.2.12 1.16))];
#    bug [$repo->get_revs_in_diff($file, qw(1.12.2.12 1.10))];
#    bug [$repo->get_revs_in_diff($file, qw(1.12.2.12 1.10.11.17))];
#    bug [$repo->get_revs_in_diff($file, qw(1.10.2.12 1.12.11.17))];

    ###  Get the partial log
    $clog = $repo->get_log($file);
    $entries = array();
    foreach ( array_reverse( $repo->get_revs_in_diff($file, $from_rev, $to_rev) ) as $_ ) {
        $entries[] = array($_, $repo->get_log_entry( $clog, $_ ));
    }

    ###  Turn the revision labels into links
    foreach ( array_keys( $entries ) as $i ) {
        $GLOBALS['part_log_page_tmp'] = array($file, $entries[$i][0], undef, '<xmp>', '</xmp>');
        $entries[$i][1] = preg_replace_callback('/(\n(r([\d]+)[^\n]+\n))/','part_log_page_preplace_callback', $entries[$i][1]);
    }

    $tmp = array();  foreach ( $entries as $entry ) $tmp[] = $entry[1];
    echo "<xmp>\n". join("\n----------------------------", $tmp) ."\n</xmp>";
}
function part_log_page_preplace_callback($m) {
    list( $file, $rev, $project_name, $s_esc, $e_esc ) = $GLOBALS['part_log_page_tmp'];
    return revision_link($file, $rev, $m[2], $project_name, $s_esc, $e_esc, $m[1]);
}

function revision_link( $file, $rev, $str, $project_name, $s_esc, $e_esc, $whole_match) {
    global $repo;
    list($first_rev, $err) = $repo->get_first_rev($file);
    
    if ( $first_rev && $rev == $first_rev ) return $whole_match;
    if ( empty($s_esc) ) $s_esc = '';
    if ( empty($e_esc) ) $e_esc = '';

    $tag = "$e_esc<a href=\"?action=diff&from_rev=". $repo->get_prev_rev($file, $rev) ."&to_rev=". $rev ."&file=". urlencode($file) ."\">$s_esc";
    return $tag . $str ."$e_esc</a>$s_esc";
}

function full_log_page() {
    global $repo;

    $file     = $_REQUEST['file'];
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);

    echo "<h2>$repo->command_name log of $file</h2>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n\n";

    ###  Get the partial log
    $clog = $repo->get_log($file);
    $GLOBALS['full_log_page_tmp'] = array($file, undef, '<xmp>', "</xmp>");
    $clog = preg_replace_callback('/(\n(r([\d]+)[^\n]+\n))/s','full_log_page_preplace_callback',$clog);
    echo "<xmp>\n$clog\n</xmp>";
}
function full_log_page_preplace_callback($m) {
    list( $file, $project_name, $s_esc, $e_esc ) = $GLOBALS['full_log_page_tmp'];
    return revision_link($file, $m[3], $m[2], $project_name, $s_esc, $e_esc, $m[1]);
}

function diff_page() {
    global $repo;

    global $REPO_CMD_PREFIX;

    $file     = $_REQUEST['file'];
    $from_rev = $_REQUEST['from_rev'];
    $to_rev   = $_REQUEST['to_rev'];
    if ( preg_match('@^/|(^|/)\.\.?($|/)|[\"\'\`\(\)\[\]\&\|\>\<]@', $file, $m) || preg_match('/[^\d\.]+/', $from_rev, $m) || ! preg_match('/^([\d\.]+|local)$/', $to_rev, $m) ) 
        return trigger_error("Please don't hack...", E_USER_ERROR);

    echo "<h2>$repo->command_name diff of $file from -r $from_rev to -r $to_rev</h2>\n<p><a href=\"javascript:history.back()\">Go Back</a></p>\n<hr>\n\n"; # "

    ###  Get the partial diff
    $to_rev_clause = ($to_rev == 'local' ? "" : "-r $to_rev");
    if ( PROJECT_PROJECT_TIMERS ) START_TIMER('REPO_CMD');
    $revision_arg = ($to_rev == 'local') ? "-r$from_rev" : "-r$from_rev:$to_rev";
    $cdiff = `${REPO_CMD_PREFIX}$repo->command_name diff $revision_arg "$file" 2>&1 | cat`;
    if ( PROJECT_PROJECT_TIMERS ) END_TIMER('REPO_CMD');

    echo "<xmp>\n$cdiff\n</xmp>";
}



#########################
###  Project base access subroutines

function get_projects() {
    global $SYSTEM_PROJECT_BASE, $PROJECTS_DIR_IGNORE_REGEXP;
    $tmp = func_get_args();
    if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, $tmp );
    return explode("\n",`ls -1 $SYSTEM_PROJECT_BASE | grep -E -v '^(archive|logs|$PROJECTS_DIR_IGNORE_REGEXP)\$'`);
}

function get_archived_projects() {
    global $SYSTEM_PROJECT_BASE, $PROJECTS_DIR_IGNORE_REGEXP;
    $tmp = func_get_args();
    if ( ! is_dir($SYSTEM_PROJECT_BASE) ) return call_remote( __FUNCTION__, $tmp );
    return explode("\n",`ls -1 $SYSTEM_PROJECT_BASE/archive | grep -E -v '^($PROJECTS_DIR_IGNORE_REGEXP)\$'`);
}


#########################
###  Delayed Load

function delayed_load_span($params, $lambda_function_name, $loading_msg = '<em class="loading">Loading ...</em>') {
    global $delayed_load_calls, $delayed_load_id;
    $id = $delayed_load_id++;
    $delayed_load_calls[] = array( $id, $lambda_function_name, $params );
    return '<span id="loading_'. $id .'">'. $loading_msg .'</span>';
}
function delayed_load_div($params, $lambda_function_name, $loading_msg = '<em class="loading">Loading ...</em>') {
    global $delayed_load_calls, $delayed_load_id;
    $id = $delayed_load_id++;
    $delayed_load_calls[] = array( $id, $lambda_function_name, $params );
    return '<div id="loading_'. $id .'">'. $loading_msg .'</div>';
}
function run_delayed_load() {
    global $delayed_load_calls, $delayed_load_id;

    ///  Trick to get the browser to display NOW!
    print str_repeat(' ',100);
    flush();ob_flush();

    foreach ($delayed_load_calls as $func_call) {
        list( $id, $func, $params ) = $func_call;
        $result = call_user_func_array($func, $params);

        print( '<script type="text/javascript">document.getElementById("loading_'
               . $id .'").innerHTML = '
               ."'". str_replace(array("'","\n"), array("\\'","\\n'\n\t+'"), $result) ."'"
               .';</script>'
               );

        ///  Get the Browser to display...
        flush();ob_flush();
    }
}

###  Because PHP SUx!
function now_doc($tag) {
    $trace = debug_backtrace();

    ///  Loop thru and find the excerpt
    $handle = @fopen($trace[0]['file'], "r");
    if ($handle) {
        $line = 0;  $done = false;  $excerpt = '';
        while (($buffer = fgets($handle, 4096)) !== false) {
            $line++;
            if ( $line > $trace[0]['line'] && $buffer == $tag ."\n" ) $done = true;
            if ( $line > $trace[0]['line'] && ! $done ) $excerpt .= $buffer;
        }
        fclose($handle);
    }
    return $excerpt;
}


#########################
###  Remote Call

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
###  Display Logic

function style_sheet() {
    global $repo;

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
<script type="text/javascript">
var disable_actions = 0;

function confirmAction(which,newLocation) {
    //  If locally modified files, diabled actions
    if ( disable_actions ) {
        alert("Some of the below files are locally modified, or have conflicts.  $repo->display_name update actions would possibly conflict the file leaving code files in a broken state.  Please resolve these differences manually (command line) before continuing.\\n\\nActions are currently DISABLED.");
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
    global $PROJECT_STAGING_AREAS, $PROJECT_SANDBOX_AREAS, $DEFAULT_URL_PROTOCOL;

    ###  A line of status for sandbox location
    $ret = "<table width=\"100%\" cellspacing=0 cellpadding=0 border=0><tr><td><div style=\"font-size:70%\">";
    $ret .= "<b>Go to:</b> <a href=\"?\">Project List</a> | <a href=\"?action=repo_admin\">Repo Admin</a>\n";
    $ret .= "<br><b>Current Sandbox Root</b>: ". ( $GLOBALS['OBSCURE_SANDBOX_ROOT'] ? '... '. substr( $_SERVER['PROJECT_REPO_BASE'], -30) : $_SERVER['PROJECT_REPO_BASE'] );

    $ret .= "</div></td><td align=right><div style=\"font-size:70%\">";

    ###  And stuff to switch between environments
    $uri = $_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'].$_SERVER['PATH_INFO'];
    $query_string = $_SERVER['QUERY_STRING'];
    $query_string = preg_replace('/[\&\?](cmd|command_output|tag)=[^\&]+/','',$query_string);
    $query_string = preg_replace('/action=(update|tag)/','action=view_project',$query_string);
    $query_string = preg_replace('/action=(entire_repo_update|entire_repo_tag)/','action=repo_admin',$query_string);
    
    ###  Output Staging Area Switch line
    $tmp = array();
    foreach ( $PROJECT_STAGING_AREAS as $area ) {
        $selected = false;
        if ( ! empty( $area['test_by_func'] ) )   $selected = call_user_func($area['test_by_func']);
        if ( ! empty( $area['test_uri_regex'] ) ) $selected = preg_match($area['test_uri_regex'], $uri);
        $tmp[] = ( "<a href=\"". $DEFAULT_URL_PROTOCOL ."://". 
                   ( ! empty( $area['host'] ) ? $area['host'] : $_SERVER['HTTP_HOST'] ) . $_SERVER['SCRIPT_NAME'] . 
                   ( ! empty( $area['path_info'] ) ? $area['path_info'] : '' ) 
                   ."?". $query_string ."\">".  ($selected ? "<b>" : "") . $area['label'] ."</b></a>"
                   );
    }
    $ret .= '  '. join("\n|  ", $tmp). ": <b>Switch to Staging Area</b>";

    ###  Output Sandbox Switch line
    $tmp = array();
    foreach ( $PROJECT_SANDBOX_AREAS as $area ) {
        $selected = false;
        if ( ! empty( $area['test_by_func'] ) )   $selected = call_user_func($area['test_by_func']);
        if ( ! empty( $area['test_uri_regex'] ) ) $selected = preg_match($area['test_uri_regex'], $uri);
        $tmp[] = ( "<a href=\"". $DEFAULT_URL_PROTOCOL ."://". 
                   ( ! empty( $area['host'] ) ? $area['host'] : $_SERVER['HTTP_HOST'] ) . $_SERVER['SCRIPT_NAME'] . 
                   ( ! empty( $area['path_info'] ) ? $area['path_info'] : '' ) 
                   ."?". $query_string ."\">".  ($selected ? "<b>" : "") . $area['label'] ."</b></a>"
                   );
    }
    $ret .= '<br>'. join("\n|  ", $tmp) . ": <b>Switch to Sandbox</b>";
    $ret .= "</div></td></td></table>";

    return $ret;
}

function get_area_url($area_code) {
    global $PROJECT_STAGING_AREAS, $PROJECT_SANDBOX_AREAS, $DEFAULT_URL_PROTOCOL;

    $query_string = $_SERVER['QUERY_STRING'];
    $query_string = preg_replace('/[\&\?](cmd|command_output|tag)=[^\&]+/','',$query_string);
    $query_string = preg_replace('/action=(update|tag)/','action=view_project',$query_string);

    foreach ( $PROJECT_STAGING_AREAS as $area ) {
        if ( ! empty( $area['role'] ) && $area['role'] == $area_code )
            return( $DEFAULT_URL_PROTOCOL ."://". 
                   ( ! empty( $area['host'] ) ? $area['host'] : $_SERVER['HTTP_HOST'] ) . $_SERVER['SCRIPT_NAME'] . 
                   ( ! empty( $area['path_info'] ) ? $area['path_info'] : '' ) 
                   ."?". $query_string
                    );
    }

    return null;
}

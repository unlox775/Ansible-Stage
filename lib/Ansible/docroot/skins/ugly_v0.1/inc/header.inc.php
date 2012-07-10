<?php require($stage->extend->run_hook('header', 0)) ?>
<html>
<head>
	<link rel="stylesheet" href="<?php echo $ctl->SKIN_BASE ?>/css/screen.css" media="all" type="text/css"/>
	<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>
	<script type="text/javascript" src="<?php echo $ctl->SKIN_BASE ?>/js/skin.js"></script>
</head>
<body>

<?php require($stage->extend->run_hook('header', 5)) ?>

<!-- /////  A line of status for sandbox location  ///// -->
<table width="100%" cellspacing=0 cellpadding=0 border=0><tr><td><div style="font-size:70%">
<b>Go to:</b> <a href="list.php">Project List</a> | <a href="admin.php">Repo Admin</a>
<br><b>Current Sandbox Root</b>: <?php echo ( $stage->obscure_sandbox_root ? '... '. substr( $stage->env()->repo_base, -30) : $stage->env()->repo_base ); ?>

</div></td><td align=right><div style="font-size:70%">

<?php

    ###  And stuff to switch between environments
    $uri = $_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'].$_SERVER['PATH_INFO'];
    $query_string = $_SERVER['QUERY_STRING'];
    $query_string = preg_replace('/[\&\?](cmd|command_output|tag)=[^\&]+/','',$query_string);
    $query_string = preg_replace('/action=(update|tag)/','action=view_project',$query_string);
    $query_string = preg_replace('/action=(entire_repo_update|entire_repo_tag)/','action=repo_admin',$query_string);
    
    ###  Output Staging Area Switch line
    $tmp = array();
    foreach ( $stage->staging_areas as $env => $area ) {
        $selected = false;
        if ( ! empty( $area['test_by_func'] ) )        $selected = call_user_func($area['test_by_func']);
        else if ( ! empty( $area['test_uri_regex'] ) ) $selected = preg_match($area['test_uri_regex'], $uri);
		else                                           $selected = ( $env == $stage->env );
        $tmp[] = ( "<a href=\"". $stage->config('default_url_protocol') ."://". 
                   ( ! empty( $area['host'] ) ? $area['host'] : $_SERVER['HTTP_HOST'] ) . $stage->url_prefix .'/change_env.php?env='. $env .'&redirect=/'. basename($_SERVER['SCRIPT_NAME'])
                   . urlencode("?". $query_string) ."\">".  ($selected ? "<b>" : "") . $area['label'] ."</b></a>"
                   );
    }
    echo '  '. join("\n|  ", $tmp). ": <b>Switch to Staging Area</b>";

    ###  Output Sandbox Switch line
    $tmp = array();
    foreach ( $stage->sandbox_areas as $env => $area ) {
        $selected = false;
        if ( ! empty( $area['test_by_func'] ) )        $selected = call_user_func($area['test_by_func']);
        else if ( ! empty( $area['test_uri_regex'] ) ) $selected = preg_match($area['test_uri_regex'], $uri);
		else                                           $selected = ( $env == $stage->env );
        $tmp[] = ( "<a href=\"". $stage->config('default_url_protocol') ."://". 
                   ( ! empty( $area['host'] ) ? $area['host'] : $_SERVER['HTTP_HOST'] ) . $stage->url_prefix .'/change_env.php?env='. $env .'&redirect=/'. basename($_SERVER['SCRIPT_NAME'])
                   . urlencode("?". $query_string) ."\">".  ($selected ? "<b>" : "") . $area['label'] ."</b></a>"
                   );
    }
    echo '<br>'. join("\n|  ", $tmp) . ": <b>Switch to Sandbox</b>";
?>
</div></td></td></table>

<?php require($stage->extend->run_hook('header', 10)) ?>


<?php /* HOOK */$__x = $ctl->stage->extend->x('header', 0); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh(); ?>
<html>
<head>
	<link rel="stylesheet" href="<?php echo $ctl->SKIN_BASE ?>/css/screen.css" media="all" type="text/css"/>
	<meta name="viewport" content="width=device-width"/>
	<meta http-equiv="viewport" content="width=device-width,initial-scale=1"/>
	<script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>
	<script type="text/javascript" src="<?php echo $ctl->SKIN_BASE ?>/js/skin.js"></script>
	<script type="text/javascript" src="<?php echo $ctl->SKIN_BASE ?>/js/ansible.js"></script>
	<link href="//fonts.googleapis.com/css?family=Exo:300,300italic&subset=latin,latin-ext" rel="stylesheet" type="text/css"/>
</head>
<body class="<?php
	          echo isset( $view->mini ) ? 'mini' : '';
              echo ( $stage->env && $ctl->stage->onBeta() ) ? ' onBeta' : ( ($stage->env && $ctl->stage->onLive()) ? ' onLive' : '' );
               ?>">

<div id="main_container" class="container">
	<div class="row">
		<div id="main_header"><div class="inner">
			<div id="main_logo"><div class="inner"><a href="<?php echo $ctl->stage->url_prefix ?>/list.php"> An<span class="sib">sib</span>le<span class="stage">Stage</a></span></div></div>
			
			<? if ( ! isset( $view->mini ) ) { ?>
				<div id="main_header_nav">
					<?php /* HOOK */$__x = $ctl->stage->extend->x('header', 3); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh(); ?>
				
					<?php
					
					    ###  And stuff to switch between environments
					    $uri = $_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'].$_SERVER['PATH_INFO'];
					    $script_name = $_SERVER['SCRIPT_NAME'];
					    $script_name = preg_replace('/actions\/(update|tag).php/','project.php',$script_name);
					    $query_string = $_SERVER['QUERY_STRING'];
					    $query_string = preg_replace('/[\&\?](cmd|command_output|tag)=[^\&]+/','',$query_string);
					    $query_string = preg_replace('/action=(entire_repo_update|entire_repo_tag)/','action=repo_admin',$query_string);
					    
					    ###  Output Staging Area Switch line
					    $sandboxes = array();
					    foreach ( array_reverse( $stage->staging_areas) as $env => $area ) {
							if ( $area['development'] ) continue;
					        $selected = false;
					        if ( ! empty( $area['test_by_func'] ) )        $selected = call_user_func($area['test_by_func']);
					        else if ( ! empty( $area['test_uri_regex'] ) ) $selected = preg_match($area['test_uri_regex'], $uri);
							else                                           $selected = ( $env == $stage->env );
					        $sandboxes[] = array( 'url' => ( $stage->config('default_url_protocol') ."://". 
															 ( ! empty( $area['host'] ) ? $area['host'] : $_SERVER['HTTP_HOST'] ) . $stage->url_prefix .'/change_env.php?env='. $env
															 .'&redirect='. urlencode( $stage->safe_self_url() )
															 ),
												  'name' => $area['name'],
												  'selected' => $selected,
												  );
					    }
					  
					    ###  Separator between sandboxes and staging areas
					    $sandboxes[] = array( 'url' => null,
											  'name' => ' --- ',
											  'selected' => false,
											  );
					
					    ###  Output Sandbox Switch line
					    foreach ( $stage->staging_areas as $env => $area ) {
							if ( ! $area['development'] ) continue;
					        $selected = false;
					        if ( ! empty( $area['test_by_func'] ) )        $selected = call_user_func($area['test_by_func']);
					        else if ( ! empty( $area['test_uri_regex'] ) ) $selected = preg_match($area['test_uri_regex'], $uri);
							else                                           $selected = ( $env == $stage->env );
					        $sandboxes[] = array( 'url' => ( $stage->config('default_url_protocol') ."://". 
															 ( ! empty( $area['host'] ) ? $area['host'] : $_SERVER['HTTP_HOST'] ) . $stage->url_prefix .'/change_env.php?env='. $env
															 .'&redirect='. urlencode( $stage->safe_self_url() )
															 ),
												  'name' => $area['name'],
												  'selected' => $selected,
												  );
					    }
					?>
					<?php /* HOOK */$__x = $ctl->stage->extend->x('header', 4); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh(); ?>
				
					<ul>
						<?php /* HOOK */$__x = $ctl->stage->extend->x('header', 5); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh(); ?>
						<li>
							<form id="switch_env">
							Switch to: 
							<select name="switch_to" id="switch_to" onchange="if ( $(this).val() ) location.href = $(this).val()">
								<?php foreach( $sandboxes as $sb ) { ?>
									<option value="<?php echo $sb['url'] ?>"<?php if ( $sb['selected'] ) echo 'selected="selected"' ?>><?php echo $sb['name'] ?></option>
								<?php } ?>
							</select>
							</form>
						</li>
						<?php /* HOOK */$__x = $ctl->stage->extend->x('header', 6); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh(); ?>
						<li><a href="<?php echo $ctl->stage->safe_self_url('/list.php') ?>">All Projects</a></li>
					</ul>
					
					<?php /* HOOK */$__x = $ctl->stage->extend->x('header', 10); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh(); ?>
				</div>
			<?php } ?>

		</div></div>
		<div id="main_body" class="eleven columns centered">

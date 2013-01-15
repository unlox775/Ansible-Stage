<?php /* HOOK */$__x = $ctl->stage->extend->x('header', 0); foreach($__x->rhni(get_defined_vars()) as $__xi) $__x->sv($__xi,$$__xi);$__x->srh(); ?>
<html>
<head>
	<link rel="stylesheet" href="<?php echo $ctl->SKIN_BASE ?>/css/screen.css" media="all" type="text/css"/>
	<link rel="stylesheet" href="<?php echo $ctl->SKIN_BASE ?>/css/colorbox.css" media="all" type="text/css"/>
	<meta name="viewport" content="width=device-width"/>
	<meta http-equiv="viewport" content="width=device-width,initial-scale=1"/>
	<script type="text/javascript" src="//ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js"></script>
	<script type="text/javascript" src="<?php echo $ctl->SKIN_BASE ?>/js/skin.js"></script>
	<script type="text/javascript" src="<?php echo $ctl->SKIN_BASE ?>/js/ansible.js"></script>
	<script type="text/javascript" src="<?php echo $ctl->SKIN_BASE ?>/js/jquery.colorbox-min.js"></script>
	<link href="//fonts.googleapis.com/css?family=Exo:300,300italic&subset=latin,latin-ext" rel="stylesheet" type="text/css"/>
</head>
<body class="<?php
	          echo isset( $view->mini ) ? 'mini' : '';
              echo ( $stage->env && $ctl->stage->onBeta() ) ? ' onBeta' : ( ($stage->env && $ctl->stage->onLive()) ? ' onLive' : '' );
               ?>">

<div id="modal_container">
	<div id="modal_body">

<?php
if ( STARK_EXTEND_PROFILE ) END_TIMER('Stark__Extend->include overhead');
if ( STARK_EXTEND_PROFILE ) RESUME_TIMER('Stark__Extend->run_hook');
foreach ( $GLOBALS['__Stark__Extend__object__']->extract_vars_keys(get_defined_vars()) as $__Stark__Extend__var__ )
	$GLOBALS['__Stark__Extend__object__']->scope()->set_var($__Stark__Extend__var__, $$__Stark__Extend__var__);
$GLOBALS['__Stark__Extend__object__']->scope()->run_hook($v);

<?php
if ( STARK_EXTEND_PROFILE ) END_TIMER('Stark__Extend->include overhead');
if ( STARK_EXTEND_PROFILE ) RESUME_TIMER('Stark__Extend->import_scope');
foreach( $GLOBALS['__Stark__Extend__object__']->scope()->vars_keys() as $__Stark__Extend__import_var__ ) {
	$$__Stark__Extend__import_var__ =& $GLOBALS['__Stark__Extend__object__']->scope()->get_var( $__Stark__Extend__import_var__ );
}
unset( $__Stark__Extend__import_var__ );
if ( STARK_EXTEND_PROFILE ) END_TIMER('Stark__Extend->import_scope');

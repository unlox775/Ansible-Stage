<?php

#########################
###  Globals

######  Sandbox Configuration
###  Staging Areas
$QA_ROLLOUT_PHASE_HOST   = '';
$PROD_ROLLOUT_PHASE_HOST = '';
$URL_BASE = '';
$PROJECT_STAGING_AREAS =
    array( array( 'label' => 'QA Staging Area',
                  'host'  => 'beta.admin.example.com',
                  'test_by_func' => 'onBeta',
                  ),
           array( 'label' => 'Live Production',
                  'host'  => 'admin.example.com',
                  'test_by_func' => 'onLive',
                  ),
           );
$PROJECT_SANDBOX_AREAS =
    array( array( 'label' => 'Tom',
                  'host'  => 'tom.dev.admin.example.com',
                  'test_uri_regex' => '/(^|\.)tom\./',
                  ),
           array( 'label' => 'Dave',
                  'host'  => 'dave.dev.admin.example.com',
                  'test_uri_regex' => '/(^|\.)dave\./',
                  ),
           );

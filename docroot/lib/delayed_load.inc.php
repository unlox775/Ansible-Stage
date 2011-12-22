<?php

$GLOBALS['delayed_load_id'] = 1;
$GLOBALS['delayed_load_calls'] = array();

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

    ///  GLASSES.com hook
    print( '<script type="text/javascript"> if ( typeof doFileFlush != "undefined" ) doFileFlush(); </script>' );


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

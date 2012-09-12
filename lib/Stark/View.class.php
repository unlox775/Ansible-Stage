<?php

$GLOBALS['STARK_ENCODE_CHARSET'] = 'UTF-8';
class Stark__View {
    public $controller = null;
    public $current_form_id = 'main';
    public $__error = array();
    public $__error_alt = array();
    public $__fill = array();
    public $__fill_alt = array();
	public $__scripts = array();
    public $__submit_fields = array();
    public $__submit_fields_alt = array();
    public $last_input_id = '';
    public $last_input_advice_id = '';
    public $last_input_advice = '';
    public $last_input_advice_set_class = '';
    public $last_input_advice_script = '';
    public $last_input_fill_value = '';
	///  When entering a scoped_include() all but these are blown away
	public $__stark_internal_vars = array('controller','current_form_id','__error','__error_alt','__fill','__fill_alt','__scripts','__submit_fields',
										  '__submit_fields_alt','last_input_id','last_input_advice_id','last_input_advice','last_input_advice_set_class',
										  'last_input_advice_script','last_input_fill_value','__stark_internal_vars'
										  );

    public function input_attrs($input_type, $field, $fill_obj = null, $this_value = null, $attributes = '') {
        //  Allow for field to be an array with a prefix
        $prefix = '';
        if ( is_array( $field ) && count($field) == 2 ) { $tmp = $field;  list( $prefix, $field ) = $tmp; }
        
        ///  Preserve array[] brackets for later, but remove for now...
        $array_brackets_suffix = false;
        if ( substr( $field, -2 ) == '[]' ) {
            $array_brackets_suffix = true;
            $field = substr($field, 0, strlen( $field ) - 2);
        }

        // Split Attributes
        preg_match_all( '/(\w+)\=(\"[^\"]*\"|[^\"\s]+)/', $attributes, $attr_matches, PREG_SET_ORDER);
        $attrs = array();  foreach($attr_matches as $m) { $attrs[$m[1]] = html_entity_decode( preg_replace("/^\"|\"$/",'',$m[2]) ); }

        //  Get the Input ID
        $input_id = $this->current_form_id .'__'. $prefix.$field;
        $attrs['id'] = $input_id;
        $input_advice_id = $input_id .'__advice';

        //  Determine the Fill Value (from fill areas, ORM objets, STD objects or flat arrays)
        $fill_value = $this->get_fill_value(array($prefix, $field));
        if ( ! isset( $fill_value ) 
             //  Don't fill values for submit buttons
             && ! in_array($input_type, array('submit','image','button'))
             //  Special Exception for Checkboxes (because when they aren't checked, no request field is sent at all, hence ! isset())
             && ( $input_type != 'checkbox'
                  || ! $this->are_errors()
                  )
             ) {
            $fill_value = '';
            if ( is_object($fill_obj) ) {
                if ( method_exists( $fill_obj, 'get' ) ) $fill_value = $fill_obj->get($field);
                else if ( ! is_null($tmp = $fill_obj->$field) )        $fill_value = $tmp;

                ///  Now, if we DID get a value, but it was
                ///    an array of objects, then we got an ORM relation has_many or many_to_many
                if ( $array_brackets_suffix
                     && is_array( $fill_value ) && ( $tmp = array_shift( $fill_value ) ) && is_object( $tmp )
                     && method_exists( $fill_obj, 'get_complete_relation' )
                     ) $fill_value = $fill_obj->get_complete_relation($field);
            }
            else if ( is_array($fill_obj) && isset( $fill_obj[$field] ) ) $fill_value = $fill_obj[$field];
        }

        //  Basic Inputs
        if ( in_array($input_type, array('text','file','password','checkbox','hidden')) ) {
            $attrs['type'] = $input_type;
            $attrs['name'] = $prefix.$field;
            if ( $input_type != 'password' ) $attrs['value'] = $fill_value; // No fill password inputs
        }
        //  Radio Buttons (NOTE: intentional break in conditional NOT an "else if", for checkbox option)
        if ( $input_type == 'radio' ) {
            $attrs['type'] = $input_type;
            $attrs['name'] = $prefix.$field;
            $attrs['value'] = $this_value; // Filled with THIS value
            $input_id = $attrs['id'] = $this->current_form_id .'__'. $prefix.$field .'__'. $this_value; //  Special ID
        }
        //  Select Box and Textarea
        else if ( in_array($input_type, array('select','textarea')) ) $attrs['name'] = $prefix.$field;
        //  Select Options and Checkboxes
        else if ( in_array($input_type, array('option','checkbox')) ) $attrs['value'] = (is_null( $this_value ) ? 1 : $this_value); // Filled with THIS value
        //  Submit Button and Image Buttons
        else if ( in_array($input_type, array('submit','image','button')) ) {
            if ( $input_type != 'password' ) $attrs['type'] = $input_type;
            $attrs['name'] = $prefix.$field;
            $attrs['value'] = $prefix.$field;
            ///  Hook for Extended Submit Features
            if ( ! empty( $fill_obj )
                 && is_array( $fill_obj )
                 ) {
                ///  Look for Global Submit Hook
                if ( isset( $GLOBALS['STARK_INPUT_ATTRS_SUBMIT_HOOK_FUNCTION'] ) )
                    call_user_func_array($GLOBALS['STARK_INPUT_ATTRS_SUBMIT_HOOK_FUNCTION'], array( $input_type, $prefix, $field, $fill_obj, $attrs, $input_advice_id ));
                else                                                $this->input_attrs_submit_hook( $input_type, $prefix, $field, $fill_obj, $attrs, $input_advice_id );
            }
        }

        //  Get Maxlength if text input and supported
        if ( $input_type == 'text'
             && is_object($fill_obj)
             && method_exists( $fill_obj, 'column_maxlength' )
             ) {
            $attrs['maxlength'] = $fill_obj->column_maxlength($field);;
        }

        //  Mark Checked or not (for Option, Checkboxes and Radio)
        if ( isset($this_value)
             && in_array($input_type, array('option','checkbox','radio'))
             && (!is_string($fill_value) || strlen($fill_value) > 0)
             && ( ( $array_brackets_suffix
                    && is_array( $fill_value )
                    && in_array( $this_value, $fill_value )
                    )
                  || ( ! is_array( $fill_value )
                       && ( ( ! is_numeric( $this_value )
                              && $this_value == $fill_value
                              )
                            || ( is_numeric( $this_value )
                                 && sprintf("%.6f", $this_value) == sprintf("%.6f", $fill_value)
                                 )
                            )
                       )
                  )
             ) {
            if ( $input_type == 'option' ) $attrs['selected'] = 'selected';
            else                           $attrs['checked']  = 'checked';
        }

        //  Add input Advice
        if ( $this->last_input_id != $input_id ) {
            ///  Look for Global Advice Hook
            if ( isset( $GLOBALS['STARK_INPUT_ATTRS_ADVICE_HOOK_FUNCTION'] ) )
                call_user_func_array($GLOBALS['STARK_INPUT_ATTRS_ADVICE_HOOK_FUNCTION'], array( $input_type, $prefix, $field, $fill_obj, $attrs, $input_advice_id ));
            else                                                $this->input_attrs_advice_hook( $input_type, $prefix, $field, $fill_obj, $attrs, $input_advice_id );
            
            ///  This hook should set $this->last_input_advice
        } 

        ///  Re-add the array_brakets now...
        if ( $array_brackets_suffix && ! empty( $attrs['name'] ) ) {
            $attrs['name'] .= '[]';
        }
        
        //  Wrap up
        $this->last_input_id        = $input_id;
        $this->last_input_advice_id = $input_advice_id;
        $this->last_input_fill_value = htmlentities($fill_value,ENT_COMPAT, ( empty( $GLOBALS['STARK_ENCODE_CHARSET'] ) ? "UTF-8" : $GLOBALS['STARK_ENCODE_CHARSET'] ) );
        if ( $input_type == 'option' ) unset( $attrs['id'] );  //  Don't set ID for options
        $ret_ary = array();  foreach(array_keys($attrs) as $key) { $ret_ary[] = $key .'="'. htmlentities($attrs[$key],ENT_COMPAT, ( empty( $GLOBALS['STARK_ENCODE_CHARSET'] ) ? "UTF-8" : $GLOBALS['STARK_ENCODE_CHARSET'] )) .'"'; }
        return join(' ', $ret_ary);
    }

    public function input_attrs_submit_hook(&$input_type, &$prefix, &$field, &$params, &$attrs, &$input_advice_id) {
        if ( ! isset( $params['ajax_url'] ) )
            return;
        ###  Use the 'fill value' as the AJAX Url if it's there...
        $attrs['onclick'] = ( "stark_submit('"
                              .                      $params['ajax_url'] ."', this,"
                              .                      "'". $input_advice_id ."'"
                              .                      (! empty($params['callback']) ? ",null,". $params['callback'] : '')
                              .                     ");  return false;"
                              );
    }
    
    public function input_attrs_advice_hook(&$input_type, &$prefix, &$field, &$fill_obj, &$attrs, &$input_advice_id) {
        $errors = $this->get_error(array($prefix, $field));
        if ( empty($errors) ) {
            $this->last_input_advice = '<div id="'. $input_advice_id .'" class="input_advice input_advice_'. $input_type .'" style="display: none"></div>';
        }
        else {
            $advice_items = array();
            foreach ($errors as $err) {
                $advice_items[] = '<li class="'. $err[1] .'">'. $err[0] .'</li>';
            }
            $this->last_input_advice = ( '<div id="'. $input_advice_id .'" class="input_advice input_advice_'. $input_type .'">'
                                         . '<ul>'.join("\n", $advice_items) .'</ul>'
                                         . '</div>'
                                         );
            $this->last_input_advice_set_class = 'error'; // Hopefully compatible with JQuery validator classes
            $this->last_input_advice_script .= "fade_in_input_advice($('#". $input_advice_id ."'));";
            if ( isset( $this->__scripts[ $field ] ) )
                $this->last_input_advice_script .= $this->__scripts[ $field ];
        }
    }

    public function all_input_advice($form_id = null) {
        if ( is_null( $form_id ) ) $form_id = $this->get_current_form_id();
        $err_ary = ( $form_id == 'main' ) ? $this->__error : $this->__error_alt[ $form_id ]; 
        if ( empty($err_ary) ) return '';
        ksort($err_ary);
        $advice_items = array();
        foreach ( $err_ary as $field => $errors ) {
            foreach ($errors as $err) {
                $advice_items[] = '<li class="'. $err[1] .'">'. $err[0] .'</li>';
            }
        }
        return( '<div id="'. $input_advice_id .'" class="all_input_advice" style="">'
                . '<ul>'.join("\n", $advice_items) .'</ul>'
                . '</div>'
                );
    }

    public function get_current_form_id() {
        if ( $this->current_form_id != 'main' )                   return $this->current_form_id;
        else if (      ! empty(   $_REQUEST['ajax_form_name'] ) ) return $_REQUEST['ajax_form_name'];
        else if ( ! empty( $_REQUEST['custom_form_name'] ) ) return $_REQUEST['custom_form_name'];
        else                                                 return $this->current_form_id;
    }

    public function set_current_form_id($form_id) {
        if ( empty($form_id) ) //  I guess I'm not letting them set it to false or 0...  <shucks>!
            return trigger_error("Form ID is required for set_current_form_id()", E_USER_ERROR);
        $this->current_form_id = $form_id;
        return '<input type="hidden" name="custom_form_name" value="'. htmlentities($this->current_form_id, ENT_COMPAT, ( empty( $GLOBALS['STARK_ENCODE_CHARSET'] ) ? "UTF-8" : $GLOBALS['STARK_ENCODE_CHARSET'] )) .'"/>';
    }

    public function get_fill_value($field, $default = null, $form_id = null) {
        if ( is_null( $form_id ) ) $form_id = $this->get_current_form_id();
        //  Allow for field to be an array with a prefix
        $prefix = '';
        if ( is_array( $field ) && count($field) == 2 ) { $tmp = $field;  list( $prefix, $field ) = $tmp; }

        //  Return the value from the fill form or default
        if (      $form_id == 'main' && isset( $this->__fill[                 $prefix.$field ] ) ) return $this->__fill[                 $prefix.$field ];
        else if ( $form_id != 'main' && isset( $this->__fill_alt[ $form_id ][ $prefix.$field ] ) ) return $this->__fill_alt[ $form_id ][ $prefix.$field ];
        return $default;
    }
    public function fill($ary, $form_id = null) {
        if ( is_null( $form_id ) ) $form_id = $this->get_current_form_id();
        foreach ( $ary as $field => $val ) {
            if (      $form_id == 'main' ) $this->__fill[                 $field ] = $val;
            else if ( $form_id != 'main' ) $this->__fill_alt[ $form_id ][ $field ] = $val;
        }
            
    }

    public function add_submit_fields($ary, $form_id = null) {
        if ( is_null( $form_id ) ) $form_id = $this->get_current_form_id();
        foreach ( $ary as $field => $val ) {
            if (      $form_id == 'main' ) $this->__submit_fields[                 $field ] = $val;
            else if ( $form_id != 'main' ) $this->__submit_fields_alt[ $form_id ][ $field ] = $val;
        }
    }
    public function submit_fields_hidden($form_id = null) {
        if ( is_null( $form_id ) ) $form_id = $this->get_current_form_id();

        //  Return HTML of hidden inputs
        $inputs = array();
        foreach ( ( ( $form_id == 'main' )
                    ? $this->__submit_fields
                    : ( isset( $this->__submit_fields_alt[ $form_id ] ) ? $this->__submit_fields_alt[ $form_id ] : array() )
                    ) as $field => $val
                  ) {
            $inputs[] = '<input type="hidden" name="'. $field .'" value="'. htmlentities($val, ENT_COMPAT, ( empty( $GLOBALS['STARK_ENCODE_CHARSET'] ) ? "UTF-8" : $GLOBALS['STARK_ENCODE_CHARSET'] )) .'"/>';
        }
        return join("\n",$inputs);
    }
    public function submit_fields_url($optional_prefix = '?', $form_id = null) {
        if ( is_null( $form_id ) ) $form_id = $this->get_current_form_id();

        //  Return URL params
        $params = array();
        foreach ( ( ( $form_id == 'main' )
                    ? $this->__submit_fields
                    : ( isset( $this->__submit_fields_alt[ $form_id ] ) ? $this->__submit_fields_alt[ $form_id ] : array() )
                    ) as $field => $val
                  ) {
            $params[] = urlencode( $field ) .'='. urlencode( $val );
        }
        return( ( count( $params ) > 0 ) ? ( $optional_prefix . join("&",$params) ) : '' );
    }

    public function get_error($field, $form_id = null) {
        if ( is_null( $form_id ) ) $form_id = $this->get_current_form_id();
        //  Allow for field to be an array with a prefix
        $prefix = '';
        if ( is_array( $field ) && count($field) == 2 ) { $tmp = $field;  list( $prefix, $field ) = $tmp; }

        //  Return the value from the error form
        if (      $form_id == 'main' && isset( $this->__error[                 $prefix.$field ] ) ) return $this->__error[                 $prefix.$field ];
        else if ( $form_id != 'main' && isset( $this->__error_alt[ $form_id ][ $prefix.$field ] ) ) return $this->__error_alt[ $form_id ][ $prefix.$field ];
        return null;
    }
    public function has_error($field, $form_id = null) { $tmp = $this->get_error($field, $form_id);  return( empty($tmp) ? false : true ); }

    public function are_errors($form_id = null) {
        if ( is_null( $form_id ) ) $form_id = $this->get_current_form_id();
        if (      $form_id == 'main' ) return( empty( $this->__error ) ? false : $this->__error );
        else if ( $form_id != 'main' ) return( empty( $this->__error_alt[ $form_id ] ) ? false : $this->__error_alt[ $form_id ] );
    }


    public function add_error($field, $error, $form_id = null) {
        if ( is_null( $form_id ) ) $form_id = $this->get_current_form_id();
        //  Workaround do_validation()'s default output of an array of arrays
        if ( count($error) == 1 && isset($error[0]) && is_array($error[0]) ) {
            return $this->add_errors( array( $field => $error ), $form_id );
        }

        //  Add the errpr
        if ( $form_id == 'main' ) {
            if ( ! isset( $this->__error[ $field ] ) || ! is_array($this->__error[ $field ]) ) $this->__error[ $field ] = array();
            $this->__error[ $field ][] = $error;
        }
        else {
            if ( ! isset( $this->__error_alt[ $form_id ][ $field ] ) || ! is_array($this->__error_alt[ $form_id ][ $field ]) ) $this->__error_alt[ $form_id ][ $field ] = array();
            $this->__error_alt[ $form_id ][ $field ][] = $error;
        }
    }

    public function add_errors($errors, $form_id = null, $prefix = '') {
        if ( is_null( $form_id ) ) $form_id = $this->get_current_form_id();

        //  Just pass each to add_error() with the optinal prefix...
        foreach ( $errors as $field => $errs ) { foreach ( $errs as $err ) { $this->add_error( $prefix.$field, $err, $form_id ); } }
    }

    public function handle_ajax($send_json = null, $auto_detect_ajax = true) {
        ///  Do detection of JSON purely based on the HTTP_ACCEPT
        if ( $auto_detect_ajax
             && ( ! isset( $_SERVER['HTTP_ACCEPT'] )
                  || ( strpos(    $_SERVER['HTTP_ACCEPT'], 'application/json') === false
                       && strpos( $_SERVER['HTTP_ACCEPT'], 'text/javascript')  === false
                       )
                  )
			 && (!isset($_SERVER['HTTP_X_REQUESTED_WITH']))	//for jquery support
             ) 
            return false;
        ///  Otherwise, if they passed "false", we'll assume they are doing their own checking
        ///    and they KNOW this is an AJAX request, becuase we are taking over now...

        ///  Determine what to send
        if ( ! $this->are_errors() ) {
            if ( is_null( $send_json) ) $send_json = array( 'status' => 'ok' );
        }
        else {
            ###  Get the Page errors
            $form_id = $this->get_current_form_id();
            if (      $form_id == 'main' ) $errs = &$this->__error;
            else if ( $form_id != 'main' ) $errs = &$this->__error_alt[ $form_id ];
            
            ###  Loop thru and get the HTML advice for each error message
            $ret_errors = array();
            $this->set_current_form_id( $this->get_current_form_id() ); //  Set this so the advice will not be main__ unless it needs to be...
            foreach ( array_keys( $errs ) as $field ) {
                $this->input_attrs('text', $field);
            
                $ret_errors[$field] = array( array( $this->last_input_id, $this->last_input_advice_set_class ),
                                             array( $this->last_input_advice_id, '', $this->last_input_advice, $this->last_input_advice_script),
                                             );
            }
            
            ###  Return the typical form validation stuff for showing errors, etc
            if ( is_null( $send_json) ) $send_json = array();
            $send_json['status'] = 'errors';
            $send_json['errors'] = $ret_errors;
        }

        header('Content-type: text/plain');
        print $_REQUEST['callback'].'('.json_encode($send_json).')';
        exit();
    }

	public function scoped_include($__file, $__scope = array()) {
		///  Directly bring over some of our "Superglobals"
		$extract = array();
		foreach( $this->controller->scope_global_vars as $var ) {
			if ( isset( $GLOBALS[$var] ) ) $extract[ $var ] =& $GLOBALS[$var];
		}
		extract( $extract, EXTR_REFS );
		unset( $extract );

		///  Clone the view and remove all but the default vars
		$view = clone $this;
		foreach ( get_object_vars( $this ) as $var => $value ) {
			if ( ! in_array( $var, $this->__stark_internal_vars ) ) 
				unset( $view->$var );
		}

		///  Now, set the vars they have passed
		foreach ( $__scope as $i => $value ) {
			/// Copy by reference any vars they specifically mention in a numeric array style
			if ( is_int( $i ) && is_string( $value ) ) {
				$view->$value =& $this->$value;
			}
			///  Otherwise, reference this to the array value
			else { $view->$i =& $value; }
		}
		unset( $__scope );

		///  TODO: pre-check if the file exists, and throw an error with the right referencing line number if it doesn't
		if ( substr($__file, 0, 1) != DIRECTORY_SEPARATOR ) {
			$__trace = debug_backtrace();
			$__file = dirname($__trace[0]['file']). DIRECTORY_SEPARATOR . $__file;
			unset( $__trace );
		}
		require( $__file );
	}
}

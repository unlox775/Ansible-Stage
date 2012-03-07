var starkSubmitInFlight = false;
function stark_submit(theURL,theButton, statusElm, mode, successCallBack, successCallBackParams, savingMessage, savedMessage) {
    if ( typeof savingMessage != 'string' ) savingMessage = 'Saving...';
    if ( typeof savedMessage  != 'string' ) savedMessage  = 'Saved';

    if (mode == 'hash') {
        var req_obj = theButton;
        var formName = 'main';
        var formAction = '';
    }
    else {
        var req_obj = $(theButton.form).serialize();
        var formName = $(theButton.form).attr('name') || $(theButton.form).attr('id') || 'main';
        var formAction = $(theButton.form).attr('action') || '';
    }
    req_obj.ajax_form_name = formName;
    req_obj.ajax_form_action = formAction;
    
    //  Display "Saving..."
    statusElm = $('#'+ statusElm);
    if ( statusElm.length > 0 ) { 
        statusElm.html(savingMessage).addClass('form-is-saving').css('display','');
    }
    else statusElm = false;
    
    starkSubmitInFlight = true;
    $.ajax({
        type: 'POST',
        url: theURL,
        data: req_obj,
        dataType: 'jsonp',
        cache: false,
        error: _internal_ajax_on_failure,
        timeout: 300000, // 5 mins
        statusElm: statusElm, // Our own param passed so the handlers can use it
        success: function(json, status, xhr){
            starkSubmitInFlight = false;

            // Trap any execution errors...
            try {
                var status = json['status'];
                var dont_reset_ids = [];
                
                if ( status == 'errors' ) {
                    var errors = json['errors'];
                
                    var focused_input = false;
                
                    //  Loop through each field
                    for (var field in errors) {
                        //  Loop thru the errors for this field
                        $.each(errors[field],function(i, ary) {
                            var elm = $('#'+ ary[0]);
                            if (elm.length > 0) {
                                // Set Style
                                if ( ary[1] && ary[1].length ) { var resultingID = add_input_class(elm, ary[1]); }
                                // Update content
                                if ( ary[2] && ary[2].length ) { elm.html(ary[2]); }
                                // Run any scripts
                                if ( ary[3] && ary[3].length ) { eval( ary[3] ); }
                
                                // Focus the fist 'input' element we get
                                var tagName = elm[0].tagName.toLowerCase();
                                if (!focused_input && (tagName == 'input' || tagName == 'select' || tagName == 'textarea')) {
//                                    dijit.scrollIntoView(elm);
                                    elm.focus();
                                    focused_input = true;
                                };
                
                                //  Used later when reseting elements
                                dont_reset_ids[ ary[0] ] = true;
                                dont_reset_ids[ resultingID ] = true;
                            }
                        });
                    }
                }
                
                //  Reset advice
                $('.input_advice').each(function(i,elm) {
                    if (! dont_reset_ids[elm.id]) $(elm).css('display','none');
                });
                $('.error').each(function(i,elm) {
                    if (! dont_reset_ids[elm.id]) remove_input_class($(elm),'error');
                });
                
                //  Display "Saved"
                if ( status == 'ok' ) {
                    // Status field updates
                    if ( this.statusElm ) {
                        this.statusElm.html(savedMessage).removeClass('form-is-saving').addClass('form-is-saved').css('display','');
                    
                        //  In 5 seconds hide the status message
                        setTimeout(function() {
                            // Make sure it still exists...
                            if ( this.statusElm ) {
                                this.statusElm.html('').removeClass('form-is-saved').css('display','none');
                            }
                        }, 5000 );
                    }

                    
                    //  Custom Callback if requested
                    var tmpCallBack = '';
                    if ( json['callback']
                         && json['callback'].toString().match(/^\w+$/)
                         && ( function() { eval( 'tmpCallBack = '+ json['callback'] +';');  return true; } )()
                         && typeof tmpCallBack == 'function'
                       ) {
                        tmpCallBack.apply(theButton, [successCallBackParams, json]);
                    }
                    
                    //  Run a callback if passed
                    else if ( typeof successCallBack == 'function' ) {
                        successCallBack.apply(theButton, [successCallBackParams, json]);
                    }
                }
                //  Display error message
                else if ( status == 'error_message' ) {
                    alert(json['error_message']);
                    if ( this.statusElm ) {
                        this.statusElm.html('').removeClass('form-is-saving').css('display','');
                    }
                }
                //  Otherwise hide the input
                else {
                    if ( this.statusElm ) {
                        this.statusElm.html('').removeClass('form-is-saving').css('display','');
                    }
                }
                
                //  Redirect if requested
                if ( json['redirect'] )  {
                    location.href = json['redirect'];
                }
            }
            catch(e) {
                console.error(e);
                console.dir(e);
                ///  .apply() runs the function with the provided "this"...  WOW!
                return _internal_ajax_on_failure.apply(this,[ xhr,"There was an execution error in the XHR load sequence for stark_submit(): "+ e, e]);
            }
        }
        
    });

    return false;
}
    
function _internal_ajax_on_failure(xhr, status, error){
    starkSubmitInFlight = false;
    console.log([error, status, xhr]);
    if ( BUG_ON ) {
        alert("Invalid Response: \n"+ xhr.responseText);
    }
    
    if ( this.statusElm ) {
        this.statusElm.html('Failed').removeClass('form-is-saving').addClass('form-is-failed').css('display','');
    
        //  In 5 seconds hide the status message
        setTimeout(function() {
            // Make sure it still exists...
            if ( this.statusElm ) {
                this.statusElm.html('').removeClass('form-is-failed').css('display','none');
            }
        }, 5000 );
    }
}

///  Things the input_guts() will trigger
function fade_in_input_advice(elm) { elm.show(); }
function fade_in_group_advice(elm) { elm.show(); }
function add_input_class(elm, className) {
    $(elm).addClass(className);
    return $(elm).attr('id');
}
function remove_input_class(elm, className) {
    $(elm).removeClass(className);
}

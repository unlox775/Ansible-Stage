
$(document).ready(function(){
});

function make_base_auth(user, password) {
  var tok = user + ':' + password;
  var hash = btoa(tok);
  return "Basic " + hash;
}

function doLogin(theForm) {
  	var theURL = 
  		( location.protocol+'//'
  		  + escape($('input[name="username"]').val()) +':'
  		  + escape($('input[name="password"]').val()) +'@'
  		  + location.host +'/secure/index.php'
  		);
	debugger;
 	$.ajax({
 		url: theURL

//		type: 'POST',
//		data: { "key": 'value' },
//		url: '/secure/index.php',
// 		username: $('input[name="username"]').val(),
// 		password: $('input[name="password"]').val()
//        beforeSend: function (xhr){ 
//			xhr.setRequestHeader('Authorization', make_base_auth($(theForm).find('input[name="username"]').val(), $(theForm).find('input[name="password"]').val())); 
//		}
 	}).done(function() { 
 		window.location = '/secure/index.php';
 	}).error(function() { 
 		$('#login-error').html('Invalid username or password');
 	});
// 	$('<img src="'+ theURL +'"/>'
// 	 ).load(function() {
//  		window.location = '/secure/index.php';
// 	 }).error(function() {
//  		$('#login-error').html('Invalid username or password');
// 	 });
}

function openDrawer(drawer_id, target, label) {
	///  Close any other drawers first...
	var closedDrawer = false;
	$('.drawer.open').each(function(index, elm) {
		if ( $(elm).attr('id') != drawer_id ) {
			closedDrawer = true;
			closeDrawer($(elm).find('> .inner'), function() {
				openDrawer(drawer_id, target);
			});
		}
	});
	if ( closedDrawer ) return false;

	var drawer = $('#'+ drawer_id);
	if ( ! drawer.length || drawer.find('> .inner').length == 0 || drawer.hasClass('open') ) return false;
	if ( ! target ) return false;

	var height = drawer.find('> .inner').height();
	drawer.find('> .inner').css('top', '-'+ height +'px');
	drawer.css({ top : ($(target).position().top + $(target).height() + 1) +'px',
                 left : 0
                 });
	drawer.addClass('open');
	drawer.find('.header_bar label > .main').html(label);

	resetDrawer(drawer_id);
	drawer.attr('target', $(target).attr('id') );

	drawer.show();
	drawer.find('> .inner').animate({ top: '0px'}, 500, 'swing', function(){});
}

function resetDrawer(drawer_id) {
	///  Reset the content areas
	updateDrawerStatus('Initializing...');
	updateDrawerCommand('...');
//	$('#'+ drawer_id +' .actions').removeClass('autoroll');
	$('#'+ drawer_id +' .output').html('Loading...');
}

function closeDrawer(drawer_subelm, callback) {
	var drawer = $(drawer_subelm).closest('.drawer');
	if ( ! drawer.length || drawer.find('> .inner').length == 0 ) alert('SORRY');//return false;

	var height = drawer.find('> .inner').height();
	drawer.find('> .inner').animate({ top: '-'+ height +'px'}, 350, 'swing', function(){
		drawer.hide();
		if ( $.isFunction(callback) ) callback();
	});
	drawer.removeClass('open');
}
function updateDrawerStatus(status) {
	$('.open.drawer .header_bar .status').html(status);
}
function updateDrawerCommand(cmd) {
	$('.open.drawer .drawer_window .command').html(cmd);
}

var disable_actions = 0;

function confirmAction(which,newLocation) {
    //  If locally modified files, diabled actions
    if ( disable_actions ) {
        alert("Some of the below files are locally modified, or have conflicts.  $repo->display_name update actions would possibly conflict the file leaving code files in a broken state.  Please resolve these differences manually (command line) before continuing.\n\nActions are currently DISABLED.");
        return void(null);
    }

    var confirmed = confirm("Please confirm this action.\n\nAre you sure you want to "+which+" these files?");
    if (confirmed) { location.href = newLocation }
}

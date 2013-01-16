function hiliteFilesWithNewRevs() {
	var newest_cur_rev = null;
	$('.cur-vers').each(function(i,elm){
		var vers = Number($(elm).html());
		if ( vers > 0
			 && ( newest_cur_rev === null
				  || vers > newest_cur_rev
				)
		   ) {
			newest_cur_rev = vers;
		}
	});

	var newest_head_rev = null;
	$('.blink').removeClass('.blink');
	var hilighted = 0;
	$('.head-vers').each(function(i,elm){
		var vers = Number($(elm).html());
		if ( vers > 0
			 && ( newest_cur_rev === null
				  || vers > newest_head_rev
				)
		   ) {
			newest_head_rev = vers;
		}
		if ( vers > newest_cur_rev ) {
			$(elm).addClass('blink');
			hilighted++;
		}
	});

	if ( hilighted > 0 ) alert('Hilighted '+ hilighted +' files below.  Their Head revision will be blinking.');
	else            	 alert('No Files have new revisions. (That is, the highest current version is equal to the highest head revision.)');
}


///////////////////////
///  Propose Merge

function activateLogProposeMerge() {
	/// Translate all the arrow target links into checkboxes
	$('.target-link').each(function(i,elm){
		$(elm).html('<input type="checkbox" class="propose-box"'
					+     ' id="target-link-'+ $(elm).attr('data-rev') +'"'
					+     ' value="'+ $(elm).attr('data-rev') +'"'
					+     ' onclick="logProposeMerge()"'
					+      '/>'
				   );
	});

	logProposeMerge();
}

function logProposeMerge() {
	
	/// Take the oldest un-checked rev
	var startRev = null;
	var revs = $('.propose-box');
	var instructions = '';
	var keepRevs = [];
	var showKeepRevs = [];
	var showLoseRevs = [];
	var lastRev = null;
	for ( var i = revs.length-1; i >= 0; i-- ) {
		if (startRev === null) {
			///  Grab the rev before the oldest non-checked box
			///    This is the startRev, i.e. the rev to update to to start
			if ( ! revs[i].checked ) {
				startRev = $(revs[i]).parent().attr('data-prev-rev');
				showLoseRevs.push($(revs[i]).parent().attr('data-rev'));
			}
		}
		else {
			if ( revs[i].checked ) {
				showKeepRevs.push( $(revs[i]).parent().attr('data-rev') );
				keepRevs.push([$(revs[i]).parent().attr('data-prev-rev'), $(revs[i]).parent().attr('data-rev')]);
			}
			else {
				showLoseRevs.push($(revs[i]).parent().attr('data-rev'));
			}
		}
		lastRev = $(revs[i]).parent().attr('data-rev');
	}

	var file = $('#propose-merge').attr('data-file');

	var rand = Math.floor((Math.random() * 999999)) + 100000;

	//////  Update Script
	///  Step 1 : update to the start revision
	instructions += 'svn revert '+ file +"\n";
	instructions += 'svn update -r'+ startRev +' '+ file +"\n";
	instructions += '/bin/cp -f '+ file +' /tmp/tmp_merge-'+ rand +"\n";
	instructions += 'svn update -rHEAD '+ file +"\n";
	instructions += '/bin/mv -f /tmp/tmp_merge-'+ rand +' '+ file +"\n\n";
	///  Step 2 : Merge in files to keep
	///    Add instructions line for all checked boxes after the startRev
	$(keepRevs).each(function(i,rev_data){
//		instructions += 'svn merge -r'+ rev_data[0] +':'+ rev_data[1] +' '+ file +"\n";
		instructions += 'svn merge -r'+ rev_data[0] +':'+ rev_data[1] +' --ignore-ancestry  '+ file +"\n";
//		instructions += 'svn diff -r'+ rev_data[0] +':'+ rev_data[1] +' '+ file +" | patch -p0\n";
	});
	instructions += 'svn commit -m"ROLLOUT REVISION: Equals: '+ startRev +(showKeepRevs.length > 0 ? (' + '+ showKeepRevs.join(',') ) : '') +' (Leaping over: '+ showLoseRevs.join(',') +')" '+ file +"\n\n";


	///  Step 4 : Leap back to head 
	rand = Math.floor((Math.random() * 999999)) + 100000;
	instructions += 'svn update -r'+ lastRev +' '+ file +"\n";
	instructions += '/bin/cp -f '+ file +' /tmp/tmp_merge-'+ rand +"\n";
	instructions += 'svn update -rHEAD '+ file +"\n";
	instructions += '/bin/mv -f /tmp/tmp_merge-'+ rand +' '+ file +"\n";
	///  Step 5 : commit
	instructions += 'svn commit -m"LEAPING BACK to HEAD: '+ lastRev +'" '+ file +"\n";

	///  Fallback: if not losing any revs, then tell them nothing to do
	if ( showLoseRevs.length == 0 ) {
		instructions = '-- No merging needed --';
	}

	$('#propose-merge textarea').html(instructions);

	$('#propose-merge').show();
}


///////////////////////
///  Rollout Automation

var current_rollout = null;
var rollout_drawer_i = 1;
function ansible_roll(env, roll_class) {
	var roll
		= { roll_class: roll_class,
			envs        : [],
			sub_stages  : [],
			sub_stage_i : 0,
			complete    : false,
//			autoroll    : true,
			autoroll    : false,
			that_rollpoint_tag : null,  // For when one sub_stage passes a rollpoint to another
			start_next_step_count : null,
			start_next_step_timer : null
		  };

	roll.to_stage = $('#stage_'+ env);
	if ( roll.to_stage.length == 0 ) { console.error('ansible_roll() error, invalid stage: '+ env); return false; }

	roll.latest_stage = $('.stage.latest_roll');
	if ( roll.latest_stage.length == 0 ) roll.latest_stage = null;

	///  Case 1 : Re-Update ( affects only the chosen stage )
	if ( roll.roll_class == 'reupdate' ) {
		///  Only one env
		roll.envs.push(env);
		$('.stage_'+ env +'_sub.'+ roll.roll_class +'_stage').each(function(i,sub_stage){
			///  Skip if sub-stage doesn't have a class
			if ( $(sub_stage).attr('sub_stage_class') ) {
				roll.sub_stages.push( { env : env,
										stage_class : $(sub_stage).attr('sub_stage_class'),
										line : $(sub_stage),
										roll_status: null
									  } );
			}
		});
		///  Set Current stage to this
		roll.current_env = env;
	}
	///  Case 2 : Roll or Re-Roll ( affects stages: (latest-roll.next) thru chosen stage )
	else {

		///  Find the envs affected
		var reached_stage = ( roll.latest_stage === null ? true : false );
		var break_loop = false;
		$('#rollout_pane .stage').each( function(i,stage_elm) { 
			if ( break_loop ) return;
			var stage = $(stage_elm).attr('id').toString().replace(/^stage_/,'');
			///  Skip until we get to latest_roll.next
			if ( ! reached_stage ) {
				if ( stage != roll.latest_stage.attr('next_stage') ) return; // continue
				else { reached_stage = true; } // we don't include the current stage
			}
			
			///  Add env and it's stages
			roll.envs.push(stage);
			$('.stage_'+ stage +'_sub.'+ roll.roll_class +'_stage').each(function(i,sub_stage){
				///  Skip if sub-stage doesn't have a class
				if ( $(sub_stage).attr('sub_stage_class') ) {
					roll.sub_stages.push( { env : stage,
											stage_class : $(sub_stage).attr('sub_stage_class'),
											line : $(sub_stage),
											roll_status: null
										  } );
				}
			});

			///  If this is the "chosen one", then skip out...
			if ( stage == env ) break_loop = true;
		});
		
		///  If we didn't find one, then something is wrong
		if ( roll.envs.length == 0 ) { console.error('ansible_roll() error, could not detect rollout envs from'+ roll.latest_stage.attr('next_stage') +' to '+ env); return false; }

		///  Set Current stage to first env
		roll.current_env = roll.envs[0];
	}

	///  Confirm steps
	var action_name_map
		= { 'rollout'  : 'Roll Changes to',
			'reroll'   : 'Re-Roll Changes to',
			'reupdate' : 'Re-Update Changes on'
		  };
	var action_name = action_name_map[ roll_class ] || 'ERROR BAD ROLL CLASS';
	for ( var i = 0 ; i < roll.envs.length ; i++ ) { 
		if ( ! confirm("Are you sure you want to "+ action_name +" "+ $('#stage_'+ roll.envs[i]).find('.name').html() +'?') )
			return;
	}

	///  Set and launch the first step
	current_rollout = roll;
	roll_current_step();
}

function roll_next_step() {
	clearInterval(current_rollout.start_next_step_count); current_rollout.start_next_step_count = null;

	///  What ever to close out this step
	

	///  Advance and run
	current_rollout.sub_stage_i++;
	if ( current_rollout.sub_stage_i < current_rollout.sub_stages.length ) {
		roll_current_step();
	}
	else {
		current_rollout.complete = true;
		setTimeout(function(){
			closeDrawer($('#drawer_'+ rollout_drawer_i).find('> .inner'));
		}, 5000);
	}
}
function roll_current_step() {
	var sub_stage = current_rollout.sub_stages[ current_rollout.sub_stage_i ];
	var target_env = sub_stage.line.attr('target_env');
	if ( ! target_env ) { console.error('roll_current_stage() error, no target_env on sub_stage: '+ sub_stage.line.attr('id')); return false; }


	$('#rollout_pane .sub_stage.active').removeClass('active');
	sub_stage.line.addClass('active');


	///  Show the current Stage's sub_items
	if ( ! sub_stage.line.hasClass('open') ) {
		$('#rollout_pane .sub_stage').slideUp(500,'swing').removeClass('open');
		var set_callback = function() {
			roll_current_step();
		};
		$('.stage_'+ sub_stage.env +'_sub.'+ current_rollout.roll_class +'_stage').addClass('open').each(function(i,elm) { $(elm).slideDown(500,'swing',set_callback); set_callback = null; });
		return; //  it will re-call once the animation is over
	}

	///  Open the right Drawer
	var rollout_drawer = $('#drawer_'+ rollout_drawer_i);
	if ( rollout_drawer.attr('target') != sub_stage.line.attr('id') ) {
		///  Switch drawers
		rollout_drawer_i = (rollout_drawer_i + 1) % 2;
		rollout_drawer = $('#drawer_'+ rollout_drawer_i);
		///  Open and Reset the new drawer (and close any other drawers)
		openDrawer('drawer_'+ rollout_drawer_i, sub_stage.line[0], sub_stage.line.find('.name .main').html());

	}
	else if ( ! rollout_drawer.hasClass('open') ) 
		openDrawer('drawer_'+ rollout_drawer_i, sub_stage.line[0]);
	else resetDrawer();
	///  Update the drawer's autoroll
	rollout_drawer.find('.header_bar .actions .countdown').html('&nbsp;');
	rollout_drawer.find('.header_bar .actions').removeClass('autoroll');
	if ( current_rollout.autoroll ) rollout_drawer.find('.header_bar .actions').addClass('autoroll');
	rollout_drawer.find('.header_bar .actions .autoroll .toggle').html( current_rollout.autoroll ? 'ON' : 'OFF' ); 
	

	///  Determine the URL to load in the drawer
	switch ( sub_stage.stage_class ) {
	case 'switch_env':
		///  This will change the URL and then refresh all the files lists
		url = 'actions/refresh_file_lists.php?echo=1&'+ projects_param +'&switch_env='+ target_env;
	break;
	case 'update_to_target':
		///  This will change the URL and then refresh all the files lists
		url = 'actions/update.php?echo=1&'+ projects_param +'&tag=Target&env='+ target_env;
	break;
	case 'create_rollpoint':
		///  This will change the URL and then refresh all the files lists
		url = 'actions/tag.php?echo=1&'+ projects_param +'&tag=rollout&env='+ target_env;
	break;
	case 'update_to_that_rollpoint':
		///  Make sure someone previously stored the rollpoint tag
		if ( current_rollout.that_rollpoint_tag === null ) { console.error('roll_current_step() error running update_to_that_rollpoint, no previous step stored a rollpoint tag: '+ sub_stage.env); return false; }
		url = 'actions/update.php?echo=1&'+ projects_param +'&tag='+ current_rollout.that_rollpoint_tag +'&env='+ target_env;
	break;
	case 'update_to_last_rollback_point':
		url = 'actions/update.php?echo=1&'+ projects_param +'&tag=~LAST-ROLLOUT&env='+ target_env;
	break;
	case 'update_to_last_rollout_point':
		url = 'actions/update.php?echo=1&'+ projects_param +'&tag=~LAST-ROLLBACK&env='+ target_env;
	break;
	default:
		console.error('roll_current_step() invalid stage class: '+ sub_stage.stage_class); return false;
	}


	///  Run the AJAX and let the data pass thru to the drawer output
	set_sub_stage_status('sent');
	passthru_ajax({ url: url,
					selector: rollout_drawer.find('.output'),
					complete_callback: function (html, xhr) {
						console.log('Complete: '+ current_rollout.sub_stages[ current_rollout.sub_stage_i ].roll_status );
						if ( current_rollout.sub_stages[ current_rollout.sub_stage_i ].roll_status == 'complete' && current_rollout.autoroll ) {
							autoroll_drawer(rollout_drawer);
						} 
					}
				  });
}

function set_sub_stage_status(status) {
	if ( current_rollout === null || current_rollout.complete ) { console.error('set_sub_stage_status() error, there is not a currently in-progress rollout: '+ status); return false; }
	current_rollout.sub_stages[ current_rollout.sub_stage_i ].roll_status = status;
}

function set_that_rollpoint(tag) {
	if ( current_rollout === null || current_rollout.complete ) { console.error('set_that_rollpoint() error, there is not a currently in-progress rollout: '+ tag); return false; }
	current_rollout.that_rollpoint_tag = tag;
}




////////////////////////
///  General Use AJAX call that updates an element realtime with output

function passthru_ajax(options) {
	if ( ! options.url )      { console.error('passthru_ajax() called without url');      return false; }
	if ( ! options.selector ) { console.error('passthru_ajax() called without selector'); return false; }

	var xhr = null;
	// code for IE7+, Firefox, Chrome, Opera, Safari
	if (window.XMLHttpRequest) xhr = new XMLHttpRequest();
	// code for IE6, IE5
	else                       xhr = new ActiveXObject("Microsoft.XMLHTTP");

	var flush_count = 1;
	var lastFlushIndex = 0;
	var roll_int = null;

	xhr.open("GET",options.url,true);
	xhr.send();

	var called_callback = false;
	console.log('called_callback = 0');
	var updateOutput = function () {
		var updateToken = '<!-- flush('+ flush_count +') -->';

		///  Debugging
		if ( true ) {
			console.log( xhr.readyState );
			console.log( xhr.responseText.toString().length );
			console.log(updateToken);
			console.log(xhr.responseText.toString().indexOf(updateToken));
		}

		///  Finish it off if DONE
		if ( xhr.readyState == 4 ) {
			clearInterval(roll_int);
			$(options.selector).html(xhr.responseText);

			///  Run the final callback
			if ( options.complete_callback && ! called_callback ) {
				called_callback = true;
				console.log('calling_callback');
				options.complete_callback.apply(options, [xhr.responseText, xhr]);
			}
		}
		///  Otherwise, look for a flush token...
		else if ( xhr.readyState >= 1 ) {
			var tokenIndex = xhr.responseText.toString().indexOf(updateToken);
			if ( tokenIndex != -1 ) {
				$(options.selector).append(
					xhr.responseText.toString().substr(lastFlushIndex, tokenIndex - lastFlushIndex + updateToken.toString().length)
				);
				flush_count++;
			}
		}
	}

	///  Loop in an interval until done
	roll_int = setInterval(updateOutput, 200);
}

function autoroll_toggle(a_elm) {
	$(a_elm).closest('.actions').toggleClass('autoroll');
	$(a_elm).html( $(a_elm).closest('.actions').hasClass('autoroll') ? 'ON' : 'OFF' ); 
	if ( current_rollout !== null && ! current_rollout.complete ) {
		if ( $(a_elm).closest('.actions').hasClass('autoroll') ) {
			current_rollout.autoroll = true;
			autoroll_drawer($(a_elm).closest('.drawer'));
		}
		else {
			current_rollout.autoroll = false;
			clearInterval(current_rollout.start_next_step_count);
			clearTimeout(current_rollout.start_next_step_timer);
		}
	}
}

function autoroll_drawer(rollout_drawer) {
	console.log(rollout_drawer);
	var countdown_secs = 3;
	rollout_drawer.find('.header_bar .actions .countdown').html('AutoRoll in '+ countdown_secs +' sec...');
	if ( current_rollout.start_next_step_count === null ) {
		current_rollout.start_next_step_count = setInterval( function() {
			countdown_secs--;
			if ( countdown_secs < 0 ) { clearInterval(current_rollout.start_next_step_count); current_rollout.start_next_step_count = null; }
			else rollout_drawer.find('.header_bar .actions .countdown').html('AutoRoll in '+ countdown_secs +' sec...');
		}, 1000	);
		current_rollout.start_next_step_timer = setTimeout( roll_next_step, countdown_secs * 1000 );
	}
}

	<div id="<?php echo $view->id ?>" class="drawer"><div class="inner">
		<div class="header_bar">
			<div class="actions">
				<ul>
					<li class="countdown">&nbsp;</li>
					<li class="continue"><a href="javascript:void(null)" onclick="roll_next_step()">Continue</a></li>
					<li class="autoroll"><label>AutoRoll:</label> <a href="javascript:void(null)" onclick="autoroll_toggle(this)" class="toggle">OFF</a></li>
					<li class="close"><a href="javascript:void(null)" onclick="closeDrawer(this)"><img src="<?php echo $ctl->SKIN_BASE ?>/images/white_close_x.png"/></a></li>
				</ul>
			</div>
			<label><span class="main">Drawer Label</span>: <span class="status">In Process</span></label>
			<span class="retry"><a href="javascript:void(null)" onclick="roll_current_step()"><img src="<?php echo $ctl->SKIN_BASE ?>/images/white_retry.png"/></a></span>
		</div>
			
		<div class="drawer_window">
			<div class="container" style="background: white">
				<div class="row">
					<div class="two columns" style="font-weight: bold">Command:</div>
					<div class="command ten columns" style="white-space: pre">...</div>
				</div>
			</div>
			<div class="output" style="white-space: pre">
				Loading...
			</div>
		</div>
	</div></div>

<? ini_set('display_errors',true) ?>
<? require(dirname($_SERVER['SCRIPT_FILENAME']) .'/ansible-controller.inc.php') ?>
<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/mini-header.inc.php' ); ?>


<?php

$redir_url = '/list.php';
if ( ! empty( $_REQUEST['redir'] ) ) $redir_url = $_REQUEST['redir'];

?>

<div class="choose-sandbox">
	<h2 class="main"><b>Rollout Stages</b></h2>
	<ul>
	 	<?php foreach ( $stage->staging_areas as $env => $area ) { if ( $area['development'] ) continue; ?>
			<li>
				<a href="<?php echo ( $stage->url_prefix .'/change_env.php?env='. $env .'&redirect='. $redir_url
									  ) ?>">
					<?php echo $area['name'] ?>
				</a>
			</li>
		<?php } ?>
	</ul>
</div>		

<div class="choose-sandbox">
	<h2 class="main"><b>Developer Sandboxes</b></h2>
	<ul>
	 	<?php foreach ( $stage->staging_areas as $env => $area ) { if ( ! $area['development'] ) continue; ?>
			<li>
				<a href="<?php echo ( $stage->url_prefix .'/change_env.php?env='. $env .'&redirect='. $redir_url
									  ) ?>">
					<?php echo $area['name'] ?>
				</a>
			</li>
		<?php } ?>
	</ul>
</div>
<div class="clear"></div>

<?php $view->scoped_include( $_SERVER['DOCUMENT_ROOT'] . $ctl->SKIN_BASE .'/inc/footer.inc.php' ); ?>

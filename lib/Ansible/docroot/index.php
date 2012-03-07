<? ini_set('display_errors',true) ?>
<? require(dirname($_SERVER['SCRIPT_FILENAME']) .'/ansible-controller.inc.php') ?>

<h1>Ansible Stage Admin Site</h1>
<ul>
	<? foreach ($view->stage_areas as $env => $area ) { ?>
		<li><a href="change_env.php?env=<?php echo $env ?>&redirect=/list.php"><?= $area['label'] ?> - Project Manager</a></li>
	<? } ?>
</ul>
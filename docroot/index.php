<h1>Ansible Stage Admin Site</h1>
<? require_once(dirname(__FILE__) .'/lib/config.php'); ?>
<ul>
    <? foreach (array_merge( $PROJECT_STAGING_AREAS, $PROJECT_SANDBOX_AREAS ) as $area ) { ?>
        <li><a href="ansible.php<?= $area['path_info'] ?>"><?= $area['label'] ?> - Project Manager</a></li>
    <? } ?>
</ul>
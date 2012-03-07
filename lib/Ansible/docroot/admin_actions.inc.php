
<?php if ( $stage->read_only_mode() ) { ?>
	<table width="100%" border=0 cellspacing=0 cellpadding=0>
	<tr>
	  <td align="left" valign="top">
	    <h3>Actions</h3>
	    <i>You must log in as a privileged user to perform $stage->repo()->display_name actions.  Sorry.</i>
	  </td>
	  <td align="left" valign="top">
<?php } else { ?>
<table width="100%" border=0 cellspacing=0 cellpadding=0>
<tr>
  <td align="left" valign="top">
    <h3>Actions</h3>
    Update Entire Current Repository to: <a href="javascript: confirmAction('UPDATE','actions/entire_repo_update.php?tag=HEAD')"      >HEAD</a>
                 | <a href="javascript: confirmAction('UPDATE','actions/entire_repo_update.php?tag=PROD_TEST')">PROD_TEST</a>
                 | <a href="javascript: confirmAction('UPDATE','actions/entire_repo_update.php?tag=PROD_SAFE')">PROD_SAFE</a>
    <br>Tag Entire Current Repository as:    <a href="javascript: confirmAction('TAG',   'actions/entire_repo_tag.php?tag=PROD_TEST')">PROD_TEST</a>
                 | <a href="javascript: confirmAction('TAG',   'actions/entire_repo_tag.php?tag=PROD_SAFE')"     >PROD_SAFE</a>
    <br>Diff Entire Current Repository to: <a href="actions/entire_repo_diff.php?tag=HEAD"    >HEAD</a>
                 | <a href="actions/entire_repo_diff.php?tag=PROD_TEST">PROD_TEST</a>
                 | <a href="actions/entire_repo_diff.php?tag=PROD_SAFE">PROD_SAFE</a>
  </td>
  <td align="left" valign="top">
<?php } ?>

<!-- /////  Rollout process for different phases  ///// -->
<?php if ( $stage->onAlpha() ) { ?>
	<h3>Maintenance Operations</h3>
	<i>None yet.  Maintenance is best done manually on command-line to preserve your own user permissions</i>

	<!-- /////  Repo Status  ///// -->
    <h4>Repository Status</h4>
    Current Status: 

	<?php
      echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
          global $stage
          
          $dir_status = $stage->repo()->analyze_dir_status();
          $status_items = array();
          if ( ! empty( $dir_status['has_modified'] ) ) $status_items[] = $dir_status['has_modified'] .' Locally Modified';
          if ( ! empty( $dir_status['has_conflicts'] ) ) $status_items[] = '<font color="red">'. $dir_status['has_conflicts'] .' Has Conflicts</font>';
          if ( empty( $status_items ) ) $status_items[] = '<font color="green">No Local Changes</font>';
          
          $cur_vers = join(', ', $status_items);
          
          return $cur_vers;
DELAY
*/
));
	  ?>

	<!-- /////  Repo Diff from PROD TEST  ///// -->
	<br/>Diff from PROD_TEST: 

	<?php
      echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
          global $stage
          
          $dir_diff = $stage->repo()->diff_dir_from_tag('PROD_TEST');
          $diff_items = array();
          if ( ! empty( $dir_diff['files_ahead_of_tag'] ) ) $diff_items[] = $dir_diff['files_ahead_of_tag'] .' ahead of tag';
          if ( ! empty( $dir_diff['files_behind_tag']   ) ) $diff_items[] = $dir_diff['files_behind_tag']   .' behind tag';
          if ( ! empty( $dir_diff['files_no_tag']       ) ) $diff_items[] = $dir_diff['files_no_tag']       .' NO tag';
          if ( ! empty( $dir_diff['files_unknown']      ) ) $diff_items[] = $dir_diff['files_unknown']      .' unknown';
          if ( ! empty( $dir_diff['files_on_tag']       ) ) $diff_items[] = '<font color="green">'. $dir_diff['files_on_tag']       .' on tag</font>';
          
          $prod_test_vers = join(', ', $diff_items);
          
          return $prod_test_vers;
DELAY
*/
));
	  ?>
	<br/>

    <?php } else if ( $stage->onBeta() ) { ?>
		<h3>Maintenance Operations - QA STAGING PHASE</h3>
        <p>
            As a general rule, staging areas are kept as close as possible as identical as
            production (which is tracked by the tag PROD_TEST).  If there are NO Projects 
            in Step 1, 3, or 4 of rollout, then it should be SAFE to update the staging area
            to PROD_TEST.
        </p>

        <!-- /////  Output a WARNING if there are any Projects in Steps 1, 3, or 4  ///// -->
		<?php 
          echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
              global $stage;
		    
              return '';
DELAY
*/
));
		?>

		<!-- /////  Repo Status  ///// -->
		<h4>Repository Status</h4>
		Current Status: 

		<?php
          echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
              global $stage;
              
              $dir_status = $stage->repo()->analyze_dir_status();
              $status_items = array();
              if ( ! empty( $dir_status['has_modified'] ) ) $status_items[] = $dir_status['has_modified'] .' Locally Modified';
              if ( ! empty( $dir_status['has_conflicts'] ) ) $status_items[] = '<font color="red">'. $dir_status['has_conflicts'] .' Has Conflicts</font>';
              if ( empty( $status_items ) ) $status_items[] = '<font color="green">No Local Changes</font>';
              
              $cur_vers = join(', ', $status_items);
              
              return $cur_vers;
DELAY
*/
));
		  ?>

		<!-- /////  Repo Diff from PROD TEST  ///// -->
		<br/>Diff from PROD_TEST: 

		<?php
            echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
                global $stage;
                
                $dir_diff = $stage->repo()->diff_dir_from_tag('PROD_TEST');
                $diff_items = array();
                if ( ! empty( $dir_diff['files_ahead_of_tag'] ) ) $diff_items[] = $dir_diff['files_ahead_of_tag'] .' ahead of tag';
                if ( ! empty( $dir_diff['files_behind_tag']   ) ) $diff_items[] = $dir_diff['files_behind_tag']   .' behind tag';
                if ( ! empty( $dir_diff['files_no_tag']       ) ) $diff_items[] = $dir_diff['files_no_tag']       .' NO tag';
                if ( ! empty( $dir_diff['files_unknown']      ) ) $diff_items[] = $dir_diff['files_unknown']      .' unknown';
                if ( ! empty( $dir_diff['files_on_tag']       ) ) $diff_items[] = '<font color="green">'. $dir_diff['files_on_tag']       .' on tag</font>';
                
                $prod_test_vers = join(', ', $diff_items);
                
                return $prod_test_vers;
DELAY
*/
));
		  ?>
		<br/>

        <?php if ( $stage->read_only_mode() ) { ?>
            <h4>Actions</h4>
            Diff   Entire STAGING Repository from PROD_TEST<br>
            Update Entire STAGING Repository to PROD_TEST<br>
        <?php } else { ?>
            <h4>Actions</h4>
            <a href="actions/entire_repo_diff.php?diff=PROD_TEST">Diff   Entire STAGING Repository from PROD_TEST</a><br>
            <a href="javascript: confirmAction('UPDATE','actions/entire_repo_update.php?tag=PROD_TEST')"    >Update Entire STAGING Repository to PROD_TEST</a><br>
        <?php } ?>
    <?php } else if ( $stage->onLive() ) { ?>
        <h3>Maintenance Operations - LIVE PRODUCTION PHASE</h3>
        <p>
            As a general rule, production areas are kept as close as possible as identical as
            production (which is tracked by the tag PROD_TEST).  If there are NO Projects 
            in Step 1, 3, or 4 of rollout, then it should be SAFE to update the production area
            to PROD_TEST.
        </p>

        <!-- /////  Output a WARNING if there are any Projects in Steps 1, 3, or 4  ///// -->
		<?php 
          echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
              global $stage;
		  
              return '';
DELAY
*/
));
		  ?>

		<!-- /////  Repo Status  ///// -->
        <h4>Repository Status</h4>
        Current Status: 
		<?php
          echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
              global $stage;
              
              $dir_status = $stage->repo()->analyze_dir_status();
              $status_items = array();
              if ( ! empty( $dir_status['has_modified'] ) ) $status_items[] = $dir_status['has_modified'] .' Locally Modified';
              if ( ! empty( $dir_status['has_conflicts'] ) ) $status_items[] = '<font color="red">'. $dir_status['has_conflicts'] .' Has Conflicts</font>';
              if ( empty( $status_items ) ) $status_items[] = '<font color="green">No Local Changes</font>';
              
              $cur_vers = join(', ', $status_items);
              
              return $cur_vers;
DELAY
*/
));
		  ?>

          <!-- /////  Repo Diff from PROD TEST  ///// -->
		  <br/>Diff from PROD_TEST: 
		  <?php
            echo delayed_load_span(array(), create_function('',now_doc('DELAY')/*
                global $stage;
                
                $dir_diff = $stage->repo()->diff_dir_from_tag('PROD_TEST');
                $diff_items = array();
                if ( ! empty( $dir_diff['files_ahead_of_tag'] ) ) $diff_items[] = $dir_diff['files_ahead_of_tag'] .' ahead of tag';
                if ( ! empty( $dir_diff['files_behind_tag']   ) ) $diff_items[] = $dir_diff['files_behind_tag']   .' behind tag';
                if ( ! empty( $dir_diff['files_no_tag']       ) ) $diff_items[] = $dir_diff['files_no_tag']       .' NO tag';
                if ( ! empty( $dir_diff['files_unknown']      ) ) $diff_items[] = $dir_diff['files_unknown']      .' unknown';
                if ( ! empty( $dir_diff['files_on_tag']       ) ) $diff_items[] = '<font color="green">'. $dir_diff['files_on_tag']       .' on tag</font>';
                
                $prod_test_vers = join(', ', $diff_items);
                
                return $prod_test_vers;
DELAY
*/
));
			?>
            <br/>

			<?php if ( $stage->read_only_mode() ) { ?>
            	<h4>Actions</h4>
				Diff   Entire PRODUCTION Repository from PROD_TEST<br>
				Tag Entire PRODUCTION Repository as PROD_TEST<br>
			<?php } else { ?>
        	    <h4>Actions</h4>
        	    <a href="actions/entire_repo_diff.php?diff=PROD_TEST">Diff   Entire PRODUCTION Repository from PROD_TEST</a><br>
        	    <a href="javascript: confirmAction('TAG','actions/entire_repo_tag.php?tag=PROD_TEST')"    >Tag Entire PRODUCTION Repository as PROD_TEST</a><br>
        	<?php } ?>
		<?php } ?>
	
	</td>
</table>

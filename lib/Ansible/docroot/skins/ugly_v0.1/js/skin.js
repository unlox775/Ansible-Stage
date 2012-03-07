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

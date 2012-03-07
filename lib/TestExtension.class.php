<?php

class TestExtension {

	public function __construct() {
	}

	public function hook_ansible($extend) {
		$extend->hook('header', array( $this, 'ansible_header_hook'));
	}

	public function ansible_header_hook($scope) {
		require( $scope->import_scope() );

		echo "<h2>Test Header</h2>";
	}
}
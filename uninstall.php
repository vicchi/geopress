<?php

if (defined('WP_UNINSTALL_PLUGIN')) {
	delete_option ('geopress_settings');
	
	global $wpdb;
	$table = $wpdb->prefix . 'geopress';
	
	$sql = "DROP TABLE $table";
	$wpdb->get_results ($sql);
}

else {
	exit ();
}
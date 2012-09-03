<?php
/*
Plugin Name: GeoPress
Plugin URI: http://georss.org/geopress/
Description: GeoPress adds geographic tagging of your posts and blog. You can enter an address, points on a map, upload a GPX log, or enter latitude & longitude. You can then embed Maps, location tags, and ground tracks in your site and your blog entries. Makes your feeds GeoRSS compatible and adds KML output. (http://georss.org/geopress)
Version: 3.0.0
Author: Andrew Turner, Mikel Maron, Gary Gale
Author URI: http://www.garygale.com/
License: GPL2
Text Domain: geopress
*/

define ('GEOPRESS_PATH', plugin_dir_path (__FILE__));
define ('GEOPRESS_URL', plugin_dir_url (__FILE__));
define ('GEOPRESS_DEFAULT_MARKER', GEOPRESS_URL . 'images/flag.png');
define ('GEOPRESS_TABLE_NAME', 'geopress');

require_once (GEOPRESS_PATH . '/wp-plugin-base/wp-plugin-base.php');
require_once (GEOPRESS_PATH . '/wp-mxn-helper/wp-mxn-helper.php');

if (!class_exists ('GeoPress')) {
	class GeoPress extends WP_PluginBase {
		private static $instance;
		
		private $mxn;
		
		static $tab_names;
		static $feed_types;
		static $zoom_levels;
		static $view_types;
		static $control_size;
		static $geocoders;
		
		const OPTIONS = 'geopress_settings';
		const VERSION = '300';
		const DISPLAY_VERSION = 'v3.0.0';
		const META_NONCE = 'geopress-meta-nonce';

		/**
		 * Class constructor
		 */
		
		private function __construct () {
			$this->mxn = new WP_MXNHelper;
			$this->mxn->register_callback ('cloudmade', array ($this, 'cloudmade_auth_callback'));
			$this->mxn->register_callback ('nokia', array ($this, 'nokia_auth_callback'));
			$this->mxn->register_callback ('googlev3', array ($this, 'googlev3_auth_callback'));
			
			self::$tab_names = array (
				'maps' => 'Maps',
				'locations' => 'Locations',
				'geocoding' => 'Geocoding',
				'feeds' => 'Feeds',
				'defaults' => 'Defaults',
				'help' => 'Help',
				'colophon' => 'Colophon'
				);
				
			self::$feed_types = array (
				'simple' => 'Simple <georss:point>',
				'gml' => 'GML <gml:pos>',
				'w3c' => 'W3C <geo:lat>'
				);
				
			self::$zoom_levels = array (
				'18' => 'Zoomed In',
				'17' => 'Single Block',
				'16' => 'Neighbourhood',
				'15' => '15',
				'14' => 'Several Blocks',
				'13' => '13',
				'12' => '12',
				'11' => 'City',
				'10' => '10',
				'9' => '9',
				'8' => '8',
				'7' => 'Region',
				'6' => '6',
				'5' => '5',
				'4' => '4',
				'3' => 'Continent',
				'2' => '2',
				'1' => 'Zoomed Out'
				);
			
			self::$view_types = array (
				'road' => 'Road',
				'satellite' => 'Satellite',
				'hybrid' => 'Hybrid'
				);
				
			self::$control_size = array (
				'false' => 'None',
				'small' => 'Small',
				'large' => 'Large'
				);
				
			self::$geocoders = array (
				'bing' => array (
					'name' => 'Bing Maps v7.0',
					'config' => 'microsoft7_key'
					),
				'cloudmade' => array (
					'name' => 'CloudMade',
					'config' => 'cloudmade_key'
					),
				'googlev3' => array (
					'name' => 'Google Maps v3',
					'config' => 'google_key'
					),
				'yahoo' => array (
					'name' => 'Yahoo! Placefinder',
					'config' => 'yahoo_key'
					)
				);
				
			register_activation_hook (__FILE__, array ($this, 'add_settings'));
			$this->hook ('plugins_loaded');
		}

		public static function get_instance () {
			if (!isset (self::$instance)) {
				$c = __CLASS__;
				self::$instance = new $c ();
			}

			return self::$instance;
		}

		/**
		 * "plugins_loaded" action hook; called after all active plugins and pluggable functions
		 * are loaded.
		 *
		 * Adds front-end display actions, shortcode support and admin actions.
		 */

		function plugins_loaded () {
			//register_activation_hook (__FILE__, array ($this, 'add_settings'));
			$this->hook ('init');
			
			if (is_admin ()) {
				$this->hook ('admin_init');
				$this->hook ('admin_menu');
				$this->hook ('admin_print_scripts');
				$this->hook ('admin_print_styles');
				$this->hook ('add_meta_boxes', 'admin_add_meta_boxes');
				$this->hook ('save_post', 'admin_save_meta_boxes');
			}
		}

		/**
		 * Queries the back-end database for GeoPress settings and options.
		 *
		 * @param string $key Optional settings/options key name; if specified only the value
		 * for the key will be returned, if the key exists, if omitted all settings/options
		 * will be returned.
		 * @return mixed If $key is specified, a string containing the key's settings/option 
		 * value is returned, if the key exists, else an empty string is returned. If $key is
		 * omitted, an array containing all settings/options will be returned.
		 */

		function get_option () {
			$num_args = func_num_args ();
			$options = get_option (self::OPTIONS);

			if ($num_args > 0) {
				$args = func_get_args ();
				$key = $args[0];
				$value = "";
				if (isset ($options[$key])) {
					$value = $options[$key];
				}
				return $value;
			}

			else {
				return $options;
			}
		}

		/**
		 * Adds/updates a settings/option key and value in the back-end database.
		 *
		 * @param string key Settings/option key to be created/updated.
		 * @param string value Value to be associated with the specified settings/option key
		 */

		function set_option ($key , $value) {
			$options = get_option (self::OPTIONS);
			$options[$key] = $value;
			update_option (self::OPTIONS , $options);
		}
		
		/**
		 * plugin activation / "activate_pluginname" action hook; called when the plugin is
		 * first activated.
		 *
		 * Defines and sets up the default settings and options for the plugin. The default set
		 * of options are configurable, at activation time, via the
		 * 'geopress_default_settings' filter hook.
		 */
		
		function add_settings () {
			$settings = $this->get_option ();
			if (!is_array ($settings)) {
				$settings = apply_filters ('geopress_default_settings',
					array (
						'installed' => 'on',
						'version' => self::VERSION,
						'map_width' => '400',
						'map_height' => '200',
						'marker' => GEOPRESS_DEFAULT_MARKER,
						'rss_enable' => 'on',
						'rss_format' => 'simple',
						'map_format' => 'openlayers',
						'map_type' => 'hybrid',
						'controls_pan' => 'on',
						'controls_map_type' => 'on',
						'controls_zoom' => 'small',
						'controls_overview' => '',
						'controls_scale' => 'on',
						'default_add_map' => 0,
						'default_zoom_level' => '11',
						'nokia_app_id' => '',
						'nokia_app_token' => '',
						'google_key' => '',
						'cloudmade_key' => '',
						'microsoft7_key' => '',
						'yahoo_key' => '',
						'geocoder' => 'yahoo'
					)
				);
				update_option (self::OPTIONS, $settings);
				$ping_sites = get_option ('ping_sites');
				if (!preg_match ('/mapufacture/', $ping_sites, $matches)) { 
					update_option ('ping_sites', $ping_sites . "\n" . 'http://mapufacture.com/georss/ping/api');
		    	}
				$this->admin_check_database ();
				$this->admin_upgrade_database ();
			}
		}
		
		/**
		 * "init" action hook; called to initialise the plugin
		 */

		function init () {
			$lang_dir = basename (dirname (__FILE__)) . DIRECTORY_SEPARATOR . 'lang';
			load_plugin_textdomain ('geopress', false, $lang_dir);
		}

		function cloudmade_auth_callback () {
			$key = $this->get_option ('cloudmade_key');
			if (isset ($key)) {
				return array ('key' => $key);
			}
		}
		
		function nokia_auth_callback () {
			$id = $this->get_option ('nokia_app_id');
			$token = $this->get_option ('nokia_app_token');
			if (isset ($id) && isset ($token)) {
				return array ('app-id' => $id, 'app-token' => $token);
			}
		}

		function googlev3_auth_callback () {
			$key = $this->get_option ('google_key');
			if (isset ($key)) {
				$sensor = 'false';
				return array ('key' => $key, 'sensor' => $sensor);
			}
		}
		
		/****************************************************************************
 	 	 * Map display functions ...
 	 	 */

		// MIGRATION HINT
		// v3.0+ - map_select
		// v2.4.3 - geopress_map_select

		function map_select ($height=250, $width=400) {
			$format = $this->get_option ('map_format');
			$type = $this->get_option ('map_type');
			$content = array ();
			
			$content[] = '<div id="geopress_map" class="mapstraction" style="width:' . $width . 'px; height:' . $height .'px; float: left"></div>';
			$content[] = '<!-- Map by GeoPress ' . self::DISPLAY_VERSION . ' -->';
			$content[] = '<script type="text/javascript">';
			$content[] = '//<![CDATA[';
			$content[] = '(function ($) {';
			$content[] = '$(window).load (function(){';
			$content[] = '});';
			$content[] = '})(jQuery);';
			$content[] = '// ]]>';
			$content[] = '</script>';
			$content[] = '<!-- End of map by GeoPress ' . self::DISPLAY_VERSION . ' -->';

			return implode (PHP_EOL, $content);
		}
		
		// MIGRATION HINT
		// v3.0+ - map_saved_locations
		// v2.4.3 - map_saved_locations

		function map_saved_locations ($locations) {
			if (!isset ($locations) && empty ($locations)) {
				$locations = $this->get_saved_locations ();
			}
		}
	
		/****************************************************************************
 	 	 * GeoPress locations functions  ...
 	 	 */

		function get_saved_locations () {
			global $wpdb;

			$table = $wpdb->prefix . GEOPRESS_TABLE_NAME;
			$sql = "SELECT * FROM $table;";
			$res = $wpdb->get_results ($sql);
			
			return $res;
		}

		// MIGRATION HINT
		// v3.0+ - get_location_by_post_id
		// v2.4.3 - get_geo

		function get_location_by_post_id ($post_id) {
			global $wpdb;
			
			$table = $wpdb->prefix . GEOPRESS_TABLE_NAME;
			$id = get_post_meta ($post_id, 'geopress_id', true);
			if ($id) {
				$sql = array ();

			    $sql[] = "SELECT * FROM $table, $wpdb->postmeta";
			    $sql[] = " INNER JOIN $wpdb->posts ON $wpdb->posts.id = $wpdb->postmeta.post_id";
			    $sql[] = " WHERE $wpdb->postmeta.meta_key = 'geopress_id'";
			    $sql[] = " AND $wpdb->postmeta.meta_value = $geopress_table.geopress_id";
			    $sql[] = " AND $wpdb->postmeta.post_id = $id AND $table.geopress_id = $id;";

			    $res = $wpdb->get_results (implode ('', $sql));
			    return $res[0];
			}
		}

		// MIGRATION HINT
		// v3.0+ - get_location_by_id
		// v2.4.3 - get_location
		
		function get_location_by_id ($id) {
		  	if ($id) {
		    	global $wpdb;

				$table = $wpdb->prefix . GEOPRESS_TABLE_NAME;
		    	$sql = "SELECT * FROM $table WHERE geopress_id = $id";
		    	$res = $wpdb->get_row ($sql);
		    	return $res;
			}
		}

		/****************************************************************************
 	 	 * Admin functions ...
 	 	 */

		/**
		 * "admin_init" action hook; called after the admin panel is initialised.
		 */

		function admin_init () {
			$this->admin_upgrade ();
			$current_providers = $this->mxn->get_supported_providers ();
			$providers = array ();
			foreach ($current_providers as $provider => $chars) {
				$providers[] = $provider;
			}
			$this->mxn->set_admin_providers ($providers);
		}

		/**
		 * Checks for the presence of an old (pre v3) style option and, if present, migrates
		 * it to a settings/options key and value and removes the old style option.
		 *
		 * @param array settings Array containing the current set of settings/options
		 * @param string key Settings/options key
		 * @param string option Pre v3 option name
		 */

		function admin_migrate_option (&$settings, $key, $option) {
			$optname = '_geopress_' . $option;
			$optval = get_option ($optname);
			if ($optval !== false) {
				if ($optval === true || $optval === 'true') {
					$optval = 'on';
				}
				$settings[$key] = $optval;
				delete_option ($optname);
			}
		}

		/**
		 * Checks for the presence of a settings/options key and if not present, adds the
		 * key and its associated value.
		 *
		 * @param array settings Array containing the current set of settings/options
		 * @param string key Settings/options key
		 * @param stirng key Settings/options value for key
		 */

		function admin_upgrade_option (&$options, $key, $value) {
			if (!isset ($options[$key])) {
				$options[$key] = $value;
			}
		}

		/**
		 * Called in response to the "admin_init" action hook; checks the current set of
		 * settings/options and upgrades them according to the new version of the plugin.
		 */

		function admin_upgrade () {
			$settings = null;
			$upgrade_settings = false;
			$current_plugin_version = null;
			
			/*
			 * Even if the plugin has only just been installed, the activation hook should have
			 * fired *before* the admin_init action so therefore we /should/ already have the
			 * plugin's configuration options defined in the database, but there's no harm in checking
			 * just to make sure ...
			 */

			$settings = $this->get_option ();

			/*
			 * Bale out early if there's no need to check for the need to upgrade the configuration
			 * settings ...
			 */

			if (is_array ($settings)) {
				/*
				 * Versions of GeoPress prior to v3.0.0 used individual settings fields rather
				 * than array elements of 'geopress_settings' (see self::OPTIONS). If one
				 * of these individual settings fields exist then set the version number
				 * to v0.0.0 to force an upgrde of them ...
				 */
				
				$pre_v3_check = get_option ('_geopress_map_format');
				if ($pre_v3_check !== false) {
					$settings['version'] = '000';
				}
				
				if (isset ($settings['version']) && $settings['version'] === self::VERSION) {
					return;
				}
			}

			if (!is_array ($settings)) {
				/*
				 * Something odd is going on, so define the default set of config settings ...
				 */
				$this->add_settings ();
			}

			else {
				if (isset ($settings['version'])) {
					$current_plugin_version = $settings['version'];
				}
				else {
					$current_plugin_version = '000';
				}
				
				/*
				 * v0.0.0 configuration settings (effectively any version before v3.0.0)
				 * ... these settings are stored as individual fields in the wp_options table
				 *		_geopress_mapwidth = "400"
				 *		_geopress_mapheight = "200"
				 *		_geopress_marker = GEOPRESS_URL . "/flag.png"
				 *		_geopress_rss_enable = "true"
				 *		_geopress_rss_format = "simple"
				 *		_geopress_map_format = "openlayers"
				 * 		_geopress_map_type = 'hybrid'
				 *		_geopress_controls_pan = true;
				 * 		_geopress_controls_map_type = true
				 *		_geopress_controls_zoom = "small"
				 *		_geopress_controls_overview = false
				 *		_geopress_controls_scale = true
				 *		_geopress_default_add_map = 0
				 *		_geopress_default_zoom_level = "11"
				 *
				 * v3.0.0 added configuration settings ...
				 *		installed = 'on'
				 *		version = '300'
				 *		map_width = '400'
				 *		map_height = '200'
				 *		marker = GEOPRESS_DEFAULT_MARKER
				 *		rss_enable = 'on'
				 *		rss_format = 'simple'
				 *		map_format = 'openlayers'
				 *		map_type = 'hybrid'
				 *		controls_pan = 'on'
				 *		controls_map_type = 'on'
				 *		controls_zoom = 'small'
				 *		controls_overview = ''
				 *		controls_scale = 'on'
				 *		default_add_map = 0
				 *		default_zoom_level = '11'
				 *		nokia_app_id = ''
				 *		nokia_app_token = ''
				 *		google_key = ''
				 *		cloudmade_key = ''
				 * v3.0.0 removed configuration settings ...
				 *		_geopress_mapwidth (replaced by map_width)
				 *		_geopress_mapheight (replaced by map_height)
				 *		_geopress_marker (replaced by marker)
				 *		_geopress_rss_enable (replaced by rss_enable)
				 *		_geopress_rss_format (replaced by rss_format)
				 *		_geopress_map_format (replaced by map_format)
				 * 		_geopress_map_type (replaced by map_type)
				 *		_geopress_controls_pan (replaced by controls_pan)
				 * 		_geopress_controls_map_type (replaced by controls_map_type)
				 *		_geopress_controls_zoom (replaced by controls_zoom)
				 *		_geopress_controls_overview (replaced by controls_overview)
				 *		_geopress_controls_scale (replaced by controls_scale)
				 *		_geopress_default_add_map (replaced by default_add_map)
				 *		_geopress_default_zoom_level (replaced by default_zoom_level)				 
				 */
				
				switch ($current_plugin_version) {
					case '000':
						$this->admin_migrate_option ($settings, 'map_width', 'mapwidth');
						$this->admin_migrate_option ($settings, 'map_height', 'mapheight');
						$this->admin_migrate_option ($settings, 'marker', 'marker');
						$this->admin_migrate_option ($settings, 'rss_enable', 'rss_enable');
						$this->admin_migrate_option ($settings, 'rss_format', 'rss_format');
						$this->admin_migrate_option ($settings, 'map_format', 'map_format');
						$this->admin_migrate_option ($settings, 'map_type', 'map_type');
						$this->admin_migrate_option ($settings, 'controls_pan', 'controls_pan');
						$this->admin_migrate_option ($settings, 'controls_map_type', 'controls_map_type');
						$this->admin_migrate_option ($settings, 'controls_overview', 'controls_overview');
						$this->admin_migrate_option ($settings, 'controls_zoom', 'controls_zoom');
						$this->admin_migrate_option ($settings, 'controls_scale', 'controls_scale');
						$this->admin_migrate_option ($settings, 'default_add_map', 'default_add_map');
						$this->admin_migrate_option ($settings, 'default_zoom_level', 'default_zoom_level');
						$this->admin_migrate_option ($settings, 'google_apikey', 'google_key');
						$this->admin_migrate_option ($settings, 'yahoo_appid', 'yahoo_key');
						
					case '300':
						$this->admin_upgrade_option ($settings, 'nokia_app_id', '');
						$this->admin_upgrade_option ($settings, 'nokia_app_token', '');
						$this->admin_upgrade_option ($settings, 'cloudmade_key', '');
						$this->admin_upgrade_option ($settings, 'microsoft7_key', '');
						$this->admin_upgrade_option ($settings, 'geocoder', 'yahoo');

						$settings['version'] = self::VERSION;
						$upgrade_settings = true;
						$upgrade_database = true;
						
					default:
						break;
				}	// end-switch ()
				
				
				if ($upgrade_settings) {
					update_option (self::OPTIONS, $settings);
				}
				
				if ($upgrade_database) {
					$this->admin_check_database ();
					$this->admin_upgrade_database ();
				}
			}
		}
		
		/**
		 * Called in response to the "admin_init" action hook; checks the current database
		 * table structure and upgrades it according to the new version of the plugin.
		 */
		
		function admin_upgrade_database () {
			global $wpdb;

			$upgrade_id = false;
			$table = $wpdb->prefix . GEOPRESS_TABLE_NAME;
			$sql = "DESCRIBE $table;";
			$fields = $wpdb->get_results ($sql);
			
			foreach ($fields as $field) {
				if (strtolower ($field->Field) == 'id') {
					$upgrade_id = true;
					break;
				}
			}
			
			if ($upgrade_id) {
				$sql = "ALTER TABLE $table CHANGE id geopress_id int(11) NOT NULL auto_increment;";
				$res = $wpdb->get_results ($sql);
			}
		}
		
		/**
		 * Called in response to the "admin_init" action hook; creates/updates the current
		 * database structure.
		 */

		function admin_check_database () {
			global $wpdb;

			$table = $wpdb->prefix . GEOPRESS_TABLE_NAME;
			$sql = "CREATE TABLE $table (
				geopress_id int(11) NOT NULL AUTO_INCREMENT,
				name tinytext NOT NULL,
				loc tinytext,
				warn tinytext,
				mapurl tinytext,
				coord text NOT NULL,
				geom varchar(16) NOT NULL,
				relationshiptag tinytext,
				featuretypetag tinytext,
				elev float,
				floor float,
				radius float,
				visible tinyint(4) DEFAULT 1,
				map_format tinytext DEFAULT '',
				map_zoom tinyint(4) DEFAULT 0,
				map_type tinytext DEFAULT '',
				UNIQUE KEY id (geopress_id)
				);";

			require_once (ABSPATH . 'wp-admin/includes/upgrade.php');

			dbDelta ($sql);
		}

		/**
		 * "admin_print_scripts" action hook; called to enqueue admin specific scripts.
		 */

		function admin_print_scripts () {
			global $pagenow;

			if ($pagenow == 'options-general.php' &&
					isset ($_GET['page']) &&
					strstr ($_GET['page'], "geopress")) {
				wp_enqueue_script ('postbox');
				wp_enqueue_script ('dashboard');
				wp_enqueue_script ('geopress-admin-script', GEOPRESS_URL . 'js/geopress-admin.js');
				//wp_enqueue_script ('geopress-admin-script', GEOPRESS_URL . 'js/geopress-admin.min.js');
			}
		}

		/**
		 * "admin_print_styles" action hook; called to enqueue admin specific CSS.
		 */

		function admin_print_styles () {
			global $pagenow;

			if ($pagenow == 'options-general.php' &&
					isset ($_GET['page']) &&
					strstr ($_GET['page'], "geopress")) {
				wp_enqueue_style ('dashboard');
				wp_enqueue_style ('global');
				wp_enqueue_style ('wp-admin');
				//wp_enqueue_style ('geopress-admin', GEOPRESS_URL . 'css/geopress-admin.min.css');	
				wp_enqueue_style ('geopress-admin', GEOPRESS_URL . 'css/geopress-admin.css');	
			}
		}

		/**
		 * "admin_menu" action hook; called after the basic admin panel menu structure is in
		 * place.
		 */

		function admin_menu () {
			if (function_exists ('add_options_page')) {
				$page_title = __('GeoPress', 'geopress');
				$menu_title = __('GeoPress', 'geopress');
				add_options_page ($page_title, $menu_title, 'manage_options', __FILE__,
					array ($this, 'admin_display_settings'));
			}
		}

		/**
		 * add_options_page() callback function; called to emit the plugin's settings/options
		 * page.
		 */

		function admin_display_settings () {
			$settings = $this->admin_save_settings ();
			$locn_settings = array ();
			$feed_settings = array ();
			$geo_settings = array ();
			$microsoft7_geo = array ();
			$cloudmade_geo = array ();
			$googlev3_geo = array ();
			$yahoo_geo = array ();
			$maps_settings = array ();
			$nokia_maps = array ();
			$googlev3_maps = array ();
			$cloudmade_maps = array ();
			$leaflet_maps = array ();
			$openlayers_maps = array ();
			$openmq_maps = array ();
			$microsoft7_maps = array ();
			$openspace_maps = array ();
			
			$defaults_settings = array ();
			$help_settings = array ();
			$colophon_settings = array ();
			$wrapped_content = array ();

			$tab = $this->admin_validate_tab ();
			
			switch ($tab) {
				case 'locations':
					$locn_settings[] = '<table class="widefat">';
					$locn_settings[] = '<thead>';
					$locn_settings[] = '<tr>';
					$locn_settings[] = '<th>' . __('Show', 'geopress') . '</th>';
					$locn_settings[] = '<th>' . __('Name', 'geopress') . '</th>';
					$locn_settings[] = '<th>' . __('Address', 'geopress') . '</th>';
					$locn_settings[] = '<th>' . __('Geometry', 'geopress') . '</th>';
					$locn_settings[] = '</tr>';
					$locn_settings[] = '</thead>';
					$locn_settings[] = '<tfoot>';
					$locn_settings[] = '<tr>';
					$locn_settings[] = '<th>' . __('Show', 'geopress') . '</th>';
					$locn_settings[] = '<th>' . __('Name', 'geopress') . '</th>';
					$locn_settings[] = '<th>' . __('Address', 'geopress') . '</th>';
					$locn_settings[] = '<th>' . __('Geometry', 'geopress') . '</th>';
					$locn_settings[] = '</tr>';
					$locn_settings[] = '</tfoot>';
					$locn_settings[] = '<tbody>';
					
					$locations = $this->get_saved_locations ();
					
					$locn_settings[] = '</tbody>';
					$locn_settings[] = '</table>';
					
					$this->map_saved_locations ($locations);
					break;
					
				case 'geocoding':
					$geo_settings[] = '<p><strong>' . __('Geocoders', 'geopress') . '</strong><br />
					<select name="geopress-geocoder-type" id="geopress-geocoder-type">';
					foreach (self::$geocoders as $id => $chars) {
						$geo_settings[] = '<option value="' . $id . '"' . selected ($settings['geocoder'], $id, false) . '>' . $chars['name'] . '</option>';
					}	// end-foreach
					$geo_settings[] = '';
					$geo_settings[] = '</select><br /><small>Select which geocoder GeoPress should use</small></p>';
					
					$cloudmade_geo[] = '<p><em>'
						. __('You\'ve selected CloudMade\'s geocoder; to use this you\'ll need an API key; you can get one from the <a href="http://developers.cloudmade.com/projects" target="_blank">CloudMade Developer Zone</a>.', 'geopress')
						. '</em></p>';
					$cloudmade_geo[] = '<p><strong>' . __("CloudMade API Key", 'geopress') . '</strong><br />
						<input type="text" name="geopress_cloudmade_key" id="geopress-cloudmade-key" size="40" value="'
						. $settings["cloudmade_key"]
						. '" /><br />
						<small>' . __('Enter your CloudMade API key', 'geopress') . '</small></p>';

					$googlev3_geo[] = '<p><em>'
						. __('You\'ve selected Google\'s geocoder; that\'s all there is. No settings, no API key, no options.', 'geopress')
						. '</em></p>';

					$microsoft7_geo[] = '<p><em>'
						. __('You\'ve selected Bing\'s geocoder; to use this you\'ll need an API key; you can get one from the <a href="http://www.bingmapsportal.com/" target="_blank">Bing Maps Account Center</a>.', 'geopress')
						. '</em></p>';
					$microsoft7_geo[] = '<p><strong>' . __("Bing API Key", 'geopress') . '</strong><br />
						<input type="text" name="geopress_bing_key" id="geopress-binf-key" size="40" value="'
						. $settings["microsoft7_key"]
						. '" /><br />
						<small>' . __('Enter your Bing API key', 'geopress') . '</small></p>';

					$yahoo_geo[] = '<p><em>'
						. __('You\'ve selected Yahoo\'s geocoder; to use this you\'ll need an API key; you can get one from the <a href="https://developer.apps.yahoo.com/dashboard/createKey.html" target="_blank">Yahoo! Developer Network</a>.', 'geopress')
						. '</em></p>';
					$yahoo_geo[] = '<p><strong>' . __("Yahoo API Key", 'geopress') . '</strong><br />
						<input type="text" name="geopress_bing_key" id="geopress-binf-key" size="40" value="'
						. $settings["microsoft7_key"]
						. '" /><br />
						<small>' . __('Enter your Bing API key', 'geopress') . '</small></p>';
					break;
					
				case 'feeds':
					$feed_settings[] = '<p><strong>' . __('GeoRSS Support', 'geopress') . '</strong><br />
						<input type="checkbox" name="geopress_rss_enable"' . checked ($settings['rss_enable'], 'on', false). '/>
						<small>' . __('Enable GeoRSS in your RSS feeds', 'geopress') . '</small></p>';
					$feed_settings[] = '<p><strong>' . __('GeoRSS Type', 'geopress') . '</strong><br />
						<select name="geopress_rss_format" id="geopress-rss-format">';
					foreach (self::$feed_types as $name => $descr) {
						$feed_settings[] = '<option value="' . $name . '"' . selected ($settings['rss_format'], $name, false) . '>' . htmlspecialchars ($descr) . '</option>';
					}	// end-foreach
					$feed_settings[] = '</select>';
					break;
				
				case 'defaults':
					break;
					
				case 'help':
					/****************************************************************************
				 	 * Help tab content
				 	 */
					
					$help_settings[] = '<p><em>'
						. __('GeoPress is a tool to help you embed geographic locations into your blog posts, and also include this information in your RSS/Atom syndicated feeds using the <a href="http://georss.org" title="GeoRSS.org website">GeoRSS</a> standard.', 'geopress')
						. '</p></em>';
					$help_settings[] = '<p>'
						. __('To begin using GeoPress, write a new article and enter a location name a geographic address in the appropriate fields. Press <em>enter</em>, or click the <em>Geocode</em> button to verify on the map that this is the appropriate location. Additionally, you can click on the map to set a location. Once you save your post, the geographic location is stored with the post entry. If you want to just enter latitude and longitude, then enter <code>[latitude, longitude]</code> into the address field.', 'geopress')
						. '</p>';
					$help_settings[] = '<p>'
						. __('Notice to users of WordPress 2.1+: there are now default privacy settings that prevent your blog from pinging Blog aggregators like <a href="http://technorati.com/">Technorati</a> or <a href="http://mapufacture.com/">Mapufacture</a>, or being searched by <a href="http://www.google.com/">Google</a>. To change your privacy settings, go to "Options" -> "Privacy" and allow your blog to be visible by anyone. This will let aggregators and search engines allow users and readers to find your blog.', 'geopress')
						. '</p>';

					$help_settings[] = '<h3>'
						. __('Adding To Your Posts', 'geopress')
						. '</h3>';
						
					$help_settings[] = '<p>'
						. __('You can insert a dynamic map into your post automatically by selecting "Automatically add a map after any post" in the GeoPress options tab. This map will be inserted at the end of any post that has a location set for it.', 'geopress')
						. '</p>';
					$help_settings[] = '<p>'
						. __('Alternatively, you can manually insert a map by putting <code>INSERT_MAP</code> anywhere in your post text. The map will use the default map size as sent in your GeoPress options. You can override this size by passing in INSERT_MAP(height,width), where height and width are the size of the map, in pixels.', 'geopress')
						. '</p>';
					$help_settings[] = '<p>'
						. __('You can also insert the geographic coordinates, or address of the post by using <code>INSERT_COORDS</code>, and <code>INSERT_ADDRESS</code>, respectively. These will be output using <a href="http://microformats.org" title="Microformats homepage">Microformat</a> styling.', 'geopress')
						. '</p>';
					$help_settings[] = '<p>'
						. __('INSERT_LOCATION will put in the stored name of the location into a post.', 'geopress')
						. '</p>';
					$help_settings[] = '<p>'
						. __('INSERT_MAP(height,width,url) will add a map for the post and also a KML or GeoRSS Overlay from the URL.', 'geopress')
						. '</p>';
					$help_settings[] = '<p>'
						. __('A map of all your geotagged posts can be inserted by using INSERT_GEOPRESS_MAP(height,width).', 'geopress')
						. '</p>';
					$help_settings[] = '<p>'
						. __('You can set the location of a post within the body of the post itself by using <code>GEOPRESS_LOCATION(Location String)</code>, where <em>Location String</em> can be any text that normally works in the GeoPress location box. For example address, city, region, country, etc. You can also post coordinates by doing <code>GEOPRESS_LOCATION([latitude,longitude])</code>. An alternative is to use machine-tags in the Post, like <code>tags: geo:long=24.9419260025024 geo:lat=60.1587851399795</code>, for example. These two mechanisms make it easy to add GeoPress locations when using an offline blog client, or when posting by email or SMS.', 'geopress')
						. '</p>';

					$help_settings[] = '<h4>' . __('Limitations', 'geopress') . '</h3>';

					$help_settings[] = '<p>'
						. __('Currently, GeoPress only supports a single geographic coordinate. In the future it will support lines, polygons, and multiple points.', 'geopress')
						. '</p>';

					$help_settings[] = '<h3>' . __('Template Functions', 'geopress') . '</h3>';

					$help_settings[] = '<p>'
						. __('These functions are available from GeoPress to further customize embedding geographic information into your blog. The <strong>Post</strong> functions return information about a specific post, or entry, and should be placed within the <em>the_post()</em> section of your templates. <strong>General</strong> functions can be used anywhere in your blog template and will return information pertaining to all of your geographic locations (such as maps, lists, links to locations).', 'geopress')
						. '</p>';

					$help_settings[] = '<h4>' . __('General Functions', 'geopress') . '</h4>';
					$help_settings[] = '<p>'
						. __('The following functions <em>return</em> the output. This allows you to perform any processing on the return text that you may want. To finally place the result in your template, use <code>echo</code>. For example, to output the stored address: <code>&lt;?php echo the_address(); ?&gt;</code>', 'geopress')
						. '</p>'
						. '<ul>'
						. '<li>' . __('<code>geopress_map(height, width, num_locs)</code>: returns a GeoPress map of the last <code>num_locs</code> number of locations. If no value is set for <code>num_locs</code>, then all locations are plotted. <em>caution</em>: plotting all locations could slow down/prevent people viewing your blog.', 'geopress') . '</li>'
						. '</ul>';
					$help_settings[] = '<h4>' . __('Post Functions', 'geopress') . '</h4>'
						. '<ul>'
						. '<li>' . __('<code>has_location()</code>: returns \'true\' if the post has a location, \'false\' if no location was set', 'geopress') . '</li>'
						. '<li>' . __('<code>geopress_post_map(height, width, controls)</code>: returns a GeoPress map of the current post\'s location. <code>height, width</code> sets the map size in pixels. <code>controls</code> is boolean if you want controls or no controls', 'geopress') . '</li>'
						. '<li>' . __('<code>the_coord()</code>: returns the coordinates for the post as an array, latitude, longitude', 'geopress') . '</li>'
						. '<li>' . __('<code>the_address()</code>: returns the address for the post', 'geopress') . '</li>'
						. '<li>' . __('<code>the_location_name()</code>: returns the saved name for the post\'s location', 'geopress') . '</li>'
						. '<li>' . __('<code>the_geo_mf()</code>: returns the coordinates of the post in <a href="http://microformats.org/wiki/geo" title="Microformats Wiki: geo">Microformat geo</a> format ', 'geopress') . '</li>'
						. '<li>' . __('<code>the_adr_mf()</code>: returns the address of the post in <a href="http://microformats.org/wiki/adr" title="Microformats Wiki: adr">Microformat adr</a> format', 'geopress') . '</li>'
						. '<li>' . __('<code>the_loc_mf()</code>: returns the location name of the post in <a href="http://microformats.org/wiki/hcard" title="Microformats Wiki: hCard">Microformat hCard</a> format', 'geopress') . '</li>'
						. '</ul>';
					$help_settings[] = '<h4>' . __('Template Functions', 'geopress') . '</h4>';
					$help_settings[] = '<p>'
						. __('GeoPress provides the ability to view all the posts at a specific location by putting "location=#" in the url, where # is the id number of the location, or "location=savedname", where <code>savedname</code> is the the name of the location (e.g. Home or Trafalgar Square)', 'geopress')
						. '</p>'
						. '<ul>'
						. '<li>' . __('<code>geopress_location_name()</code>: prints out the name of the location if it is passed in by the url..', 'geopress')
						. '<li>' . __('<code>geopress_locations_list()</code>: prints out an unordered list of locations and links to display posts at that location.', 'geopress')
						. '</ul>';
					break;
					
				case 'colophon':
					break;
					
				case 'maps':
				default:
					$maps_settings[] = '<p><strong>' . __('Map Width', 'geopress') . '</strong><br />
						<input type="text" name="geopress_map_width" id="geopress-map-width" value="' . $settings['map_width'] . '" /><br />
						<small>' . __('Enter the map width, specified in px', 'geopress') . '</small></p>';
					$maps_settings[] = '<p><strong>' . __('Map Height', 'geopress') . '</strong><br />
						<input type="text" name="geopress_map_height" id="geopress-map-height" value="' . $settings['map_height'] . '" /><br />
						<small>' . __('Enter the map height, specified in px', 'geopress') . '</small></p>';
					$maps_settings[] = '<p><strong>' . __('Map Zoom Level', 'geopress') . '</strong><br />
						<select name="geopress_zoom_level" id="geopress-zoom-level">';
						foreach (self::$zoom_levels as $name => $descr) {
							$maps_settings[] = '<option value="' . $name . '"' . selected ($settings['default_zoom_level'], $name, false) . '>' . htmlspecialchars ($descr) . '</option>';
						}	// end-foreach
					$maps_settings[] = '</select><br />' . __('Select which zoom level you want your map to be displayed at.', 'geopress') . '<small></small></p>';
					$maps_settings[] = '<p><strong>' . __('Map View Type', 'geopress') . '</strong><br />
						<select name="geopress_view_type" id="geopress-view-type">';
						foreach (self::$view_types as $name => $descr) {
							$maps_settings[] = '<option value="' . $name . '"' . selected ($settings['map_type'], $name, false) . '>' . htmlspecialchars ($descr) . '</option>';
						}	// end-foreach
					$maps_settings[] = '</select><br /><small>' . __('Select which view type you want your map displayed with.', 'geopress') . '</small></p>';
					
					$providers = $this->mxn->get_supported_providers ();
					$maps_settings[] = '<p><strong>' . __('Map Provider', 'geopress') . '</strong><br />
						<select name="geopress_map_format" id="geopress-map-format">';
					foreach ($providers as $provider => $chars) {
						$maps_settings[] = '<option value="' . $provider . '"' . selected ($settings['map_format'], $provider, false) . '>' . $chars['description'] . '</option>';
					}	// end-foreach
					$maps_settings[] = '</select><br /><small>' . __('Select which Maps provider you want to display your map with.', 'geopress') . '</small></p>';

					$maps_settings[] = '<p><strong>' . __('Map Controls', 'geopress') . '</strong><br />
						<select name="geopress_control_size" id="geopress-control-size">';
						foreach (self::$control_size as $name => $descr) {
							$maps_settings[] = '<option value="' . $name . '"' . selected ($settings['controls_zoom'], $name, false) . '>' . htmlspecialchars ($descr) . '</option>';
						}	// end-foreach
					$maps_settings[] = '</select><br /><small>' . __('Select which type of map controls you want displayed on your map.', 'geopress') . '</small></p>';
					
					$maps_settings[] = '<p><strong>' . __('Map Pan Control', 'geopress') . '</strong><br />
						<input type="checkbox" name="geopress_control_pan" id="geopress-control-pan" ' . checked ($settings['controls_pan'], 'on', false) . ' />
						<small>' . __('Show map Pan control (not all map providers support this).', 'geopress'). '</small></p>';
					$maps_settings[] = '<p><strong>' . __('Map Type Control', 'geopress') . '</strong><br />
						<input type="checkbox" name="geopress_control_type" id="geopress-control-type" ' . checked ($settings['controls_map_type'], 'on', false) . ' />
						<small>' . __('Show map Type control (not all map providers support this).', 'geopress'). '</small></p>';
					$maps_settings[] = '<p><strong>' . __('Map Overview Control', 'geopress') . '</strong><br />
						<input type="checkbox" name="geopress_control_overview" id="geopress-control-overview" ' . checked ($settings['controls_overview'], 'on', false) . ' />
						<small>' . __('Show map Overview control (not all map providers support this).', 'geopress'). '</small></p>';
					$maps_settings[] = '<p><strong>' . __('Map Scale Control', 'geopress') . '</strong><br />
						<input type="checkbox" name="geopress_control_scale" id="geopress-control-scale" ' . checked ($settings['controls_scale'], 'on', false) . ' />
						<small>' . __('Show map Scale control (not all map providers support this).', 'geopress'). '</small></p>';
						
					$nokia_maps[] = '<p>'
						. sprintf (__('You\'ve selected Nokia Maps. To use Nokia Maps, you\'ll need API keys; you get get them from the <a href="%s" target="_blank">Nokia Developer site</a>.', 'geopress'), 'http://api.developer.nokia.com/')
						. '</p>';
					$nokia_maps[] = '<p><strong>' . __('App ID', 'geopress') . '</strong><br />
						<input type="text" name="geopress_nokia_app_id" id="geopress-nokia-app-id" value="' . $settings['nokia_app_id'] . '" size="35" /><br />
						<small>' . __('Enter your registered Nokia Location API App ID') . '</small></p>';
					$nokia_maps[] = '<p><strong>' . __('Token / App Code', 'geopress') . '</strong><br />
						<input type="text" name="geopress_nokia_app_token" id="geopress-nokia-app-token" value="' . $settings['nokia_app_token'] . '" size="35" /><br />
						<small>' . __('Enter your registered Nokia Location API Token / App Code') . '</small></p>';
					
					$googlev3_maps[] = '<p><em>'
						. __('You\'ve selected Google Maps. To use Google Maps, you\'ll need an API key; you can get one from the <a href="https://code.google.com/apis/console/" target="_blank">Google Code APIs Console</a>.', 'geopress')
						. '</em></p>';
					$googlev3_maps[] = '<p><strong>' . __("Google Maps API Key", 'geopress') . '</strong><br />
						<input type="text" name="geopress_google_key" id="geopress-google-key" size="40" value="'
						. $settings["google_key"]
						. '" /><br />
						<small>' . __('Enter your Google Maps API key', 'geopress') . '</small></p>';

					$leaflet_maps[] = '<p><em>'
						. __('You\'ve selected Leaflet Maps. That\'s all there is. No settings, no API key, no options.', 'geopress')
						. '</em></p>';

					$cloudmade_maps[] = '<p><em>'
						. __('You\'ve selected CloudMade Maps. To use CloudMade Maps, you\'ll need an API key; you can get one from the <a href="http://developers.cloudmade.com/projects" target="_blank">CloudMade Developer Zone</a>.', 'geopress')
						. '</em></p>';
					$cloudmade_maps[] = '<p><strong>' . __("CloudMade API Key", 'geopress') . '</strong><br />
						<input type="text" name="geopress_cloudmade_key" id="geopress-cloudmade-key" size="40" value="'
						. $settings["cloudmade_key"]
						. '" /><br />
						<small>' . __('Enter your CloudMade API key', 'geopress') . '</small></p>';

					$openlayers_maps[] = '<p><em>'
						. __('You\'ve selected OpenLayers Maps. That\'s all there is. No settings, no API key, no options.', 'geopress')
						. '</em></p>';

					$openmq_maps[] = '<p><em>'
						. __('You\'ve selected MapQuest Open Maps. That\'s all there is. No settings, no API key, no options.', 'geopress')
						. '</em></p>';

					$microsoft7_maps[] = '<p><em>'
						. __('You\'ve selected Bing Maps. That\'s all there is. No settings, no API key, no options.', 'geopress')
						. '</em></p>';

					$openspace_maps[] = '<p><em>'
						. __('You\'ve selected OS OpenSpace Maps. That\'s all there is. No settings, no API key, no options.', 'geopress')
						. '</em></p>';

					break;
			}	// end-switch ($tab)
			
			if (function_exists ('wp_nonce_field')) {
				$wrapped_content[] = wp_nonce_field (
					'geopress-update-options',
					'_wpnonce',
					true,
					false);
			}

			$tab = $this->admin_validate_tab ();
			switch ($tab) {
				case 'locations':
					$wrapped_content[] = $this->admin_postbox ('geopress-locations-settings',
						__('Locations Settings', 'geopress'),
						implode ('', $locn_settings));
					break;

				case 'geocoding':
					$wrapped_content[] = $this->admin_postbox ('geopress-geocoding-settings',
						__('Geocoding Settings', 'geopress'),
						implode ('', $geo_settings));
					break;

				case 'feeds':
					$wrapped_content[] = $this->admin_postbox ('geopress-feeds-settings',
						__('GeoRSS Feeds Settings', 'geopress'),
						implode ('', $feed_settings));
					break;
					
				case 'defaults':
					$wrapped_content[] = $this->admin_postbox ('geopress-defaults-settings',
						__('Reset GeoPress', 'geopress'),
						implode ('', $defaults_settings));
						break;

				case 'help':
					$wrapped_content[] = $this->admin_postbox ('geopress-help',
						__('Help', 'geopress'),
						implode ('', $help_settings));
					break;

				case 'colophon':
					$wrapped_content[] = $this->admin_postbox ('geopress-colophon',
						__('Colophon', 'geopress'),
						implode ('', $colophon_settings));
					break;

				case 'maps':
				default:
					$wrapped_content[] = $this->admin_postbox ('geopress-maps-settings',
						__('Maps Settings', 'geopress'),
						implode ('', $maps_settings));

					$providers = $this->mxn->get_supported_providers ();
					$current_provider = $settings['map_format'];
					foreach ($providers as $provider => $chars) {
						$id = 'geopress-' . $provider . '-settings';
						$title = $chars['description'] . ' Settings';
						$hidden = ($current_provider != $provider);
						$block = $provider . '_maps';
						$wrapped_content[] = $this->admin_postbox ($id, $title, implode ('', $$block), $hidden);
					}	// end-foreach

					break;
			}	// end-switch ($tab)
			
			$this->admin_wrap ($tab,
				sprintf (__('GeoPress %s - Settings And Options',
					'geopress'), self::DISPLAY_VERSION),
				implode ('', $wrapped_content));
			
		}

		/**
		 * Extracts a specific settings/option field from the $_POST array.
		 *
		 * @param string field Field name.
		 * @return string Contents of the field parameter if present, else an empty string.
		 */

		function admin_option ($field) {
			return (isset ($_POST[$field]) ? $_POST[$field] : "");
		}

		/**
		 * Verifies and saves the plugin's settings/options to the back-end database.
		 */

		function admin_save_settings () {
			$settings = $this->get_option ();

			if (!empty ($_POST['geopress_option_submitted'])) {
				if (strstr ($_GET['page'], "geopress") &&
				 		check_admin_referer ('geopress-update-options')) {
					$tab = $this->admin_validate_tab ();
					$update_options = true;
					$reset_options = false;
					$update_msg = self::$tab_names[$tab];
					$action_msg = __('Updated', 'wp-biographia');

					switch ($tab) {
						case 'locations':
							$update_options = false;
							break;
							
						case 'feeds':
							$settings['rss_enable'] = $this->admin_option ('geopress_rss_enable');
							$settings['rss_format'] = $this->admin_option ('geopress_rss_format');
							break;
							
						case 'defaults':
							$update_options = false;
							break;
							
						case 'help':
							$update_options = false;
							break;
							
						case 'colophon':
							$update_options = false;
							break;
							
						case 'maps':
						default:
							$settings['map_width'] = $this->admin_option ('geopress_map_width');
							$settings['map_height'] = $this->admin_option ('geopress_map_height');
							$settings['default_zoom_level'] = $this->admin_option ('geopress_zoom_level');
							$settings['map_type'] = $this->admin_option ('geopress_view_type');
							$settings['map_format'] = $this->admin_option ('geopress_map_format');
							$settings['controls_zoom'] = $this->admin_option ('geopress_control_size');
							$settings['controls_pan'] = $this->admin_option ('geopress_control_pan');
							$settings['controls_type'] = $this->admin_option ('geopress_control_type');
							$settings['controls_overview'] = $this->admin_option ('geopress_control_overview');
							$settings['controls_scale'] = $this->admin_option ('geopress_control_scale');
							$settings['nokia_app_id'] = html_entity_decode ($this->admin_option ('geopress_nokia_app_id'));
							$settings['nokia_app_token'] = html_entity_decode ($this->admin_option ('geopress_nokia_app_token'));
							$settings['google_key'] = html_entity_decode ($this->admin_option ('geopress_google_key'));
							$settings['cloudmade_key'] = html_entity_decode ($this->admin_option ('geopress_cloudmade_key'));
							break;
					}	// end-switch
					
					if ($update_options) {
						update_option (self::OPTIONS, $settings);
					}

					if ($update_options || $reset_options) {
						echo "<div id=\"updatemessage\" class=\"updated fade\"><p>";
						echo sprintf (__('%s Settings And Options %s', 'geopress'),
							$update_msg, $action_msg);
						echo "</p></div>\n";
						echo "<script 	type=\"text/javascript\">setTimeout(function(){jQuery('#updatemessage').hide('slow');}, 3000);</script>";
					}
				}
			}
			
			$settings = $this->get_option ();
			return $settings;
		}
		
		/**
		 * Creates a postbox entry for the plugin's admin settings/options page.
		 *
		 * @param string id CSS id for this postbox
		 * @param string title Title string for this postbox
		 * @param string content HTML content for this postbox
		 * @return string Wrapped postbox content.
		 */

		function admin_postbox ($id, $title, $content, $hidden=false) {
			$handle_title = __('Click to toggle', 'geopress');
			$wrapper = array ();

			$wrapper[] = '<div id="' . $id . '" class="postbox"';
			if ($hidden) {
				$wrapper[] = ' style="display:none;"';
			}
			$wrapper[] = '>';
			$wrapper[] = '<div class="handlediv" title="'
				. $handle_title
				. '"><br /></div>';
			$wrapper[] = '<h3 class="hndle"><span>' . $title . '</span></h3>';
			$wrapper[] = '<div class="inside">' . $content . '</div></div>';

			return implode ('', $wrapper);
		}	

		/**
		 * Wrap up all the constituent components of the plugin's admin settings/options page.
		 *
		 * @param string tab Settings/options tab context name
		 * @param string title Title for the plugin's admin settings/options page.
		 * @param string content HTML content for the plugin's admin settings/options page.
		 * @return string Wrapped HTML content
		 */

		function admin_wrap ($tab, $title, $content) {
			$action = admin_url ('options-general.php');
			$action .= '?page=geopress/geopress.php&tab=' . $tab;
		?>
		    <div class="wrap">
		        <h2><?php echo $title; ?></h2>
				<?php
				echo $this->admin_tabs ($tab);

				?>
		        <form method="post" action="<?php echo $action; ?>">
		            <div class="postbox-container geopress-postbox-settings">
		                <div class="metabox-holder">	
		                    <div class="meta-box-sortables">
		                    <?php
		                        echo $content;
								echo $this->admin_submit ($tab);
		                    ?>
		                    <br /><br />
		                    </div>
		                  </div>
		                </div>
		                <div class="postbox-container geopress-postbox-sidebar">
		                  <div class="metabox-holder">	
		                    <div class="meta-box-sortables">
		                    <?php
								echo $this->admin_help_and_support ();
								echo $this->admin_acknowledgements ();
		                    ?>
		                    </div>
		                </div>
		            </div>
		        </form>
		    </div>
		<?php
		}

		/**
		 * Emits the plugin's help/support side-box for the plugin's admin settings/options page.
		 */

		function admin_help_and_support () {
			$email_address = antispambot ("gary@vicchi.org");
			$restart_url = admin_url ('options-general.php');
			$restart_url .= '?page=wp-biographia/wp-biographia.php&tab=display&wp_biographia_restart_tour';
			$restart_url = wp_nonce_url ($restart_url, 'wp-biographia-restart-tour');

			$content = array ();

			$content[] = '<p>';
			$content[] =  __('For help and support with WP Biographia, here\'s what you can do:', 'wp-biographia');
			$content[] = '<ul><li>';
			$content[] = __('Ask a question on the <a href="http://wordpress.org/tags/wp-biographia?forum_id=10">WordPress support forum</a>; this is by far the best way so that other users can follow the conversation.', 'wp-biographia');
			$content[] = '</li><li>';
			$content[] = __('Ask me a question on Twitter; I\'m <a href="http://twitter.com/vicchi">@vicchi</a>.', 'wp-biographia');
			$content[] = '</li><li>';
			$content[] = sprintf (__('Drop me an <a href="mailto:%s">email </a>instead.', 'wp-biographia'), $email_address);
			$content[] = '</li></ul></p><p>';
			$content[] = __('But help and support is a two way street; here\'s what you might want to do:', 'wp-biographia');
			$content[] = '<ul><li>';
			$content[] = sprintf (__('If you like this plugin and use it on your WordPress site, or if you write about it online, <a href="http://www.vicchi.org/codeage/wp-biographia/">link to the plugin</a> and drop me an <a href="mailto:%s">email</a> telling me about this.', 'wp-biographia'), $email_address);
			$content[] = '</li><li>';
			$content[] = __('Rate the plugin on the <a href="http://wordpress.org/extend/plugins/wp-biographia/">WordPress plugin repository</a>.', 'wp-biographia');
			$content[] = '</li><li>';
			$content[] = __('WP Biographia is both free as in speech and free as in beer. No donations are required; <a href="http://www.vicchi.org/codeage/donate/">here\'s why</a>.', 'wp-biographia');
			$content[] = '</li></ul></p>';
			$content[] = sprintf (__('<p>Find out what\'s new and get an overview of WP Biographia; <a href="%s">restart the plugin tour</a>.</p>', 'wp-biographia'), $restart_url);

			return $this->admin_postbox ('geopress-support',
				__('Help &amp; Support', 'geopress'),
				implode ('', $content));
		}

		/**
		 * Emits the plugin's acknowledgements side-box for the plugin's admin settings/options
		 * page.
		 */

		function admin_acknowledgements () {
			$email_address = antispambot ("gary@vicchi.org");
			$content = array ();

			$content[] = '<p>';
			$content[] = __('The fact that you\'re reading this wouldn\'t have been possible without the help, bug fixing, beta testing, gentle prodding and overall general warmth and continued support of <a href="https://twitter.com/#!/wp_smith">Travis Smith</a> and <a href="https://twitter.com/#!/webendev">Bruce Munson</a>. Travis and Bruce ... you\'re awesome. Thank you.', 'wp-biographia');
			$content[] = '</p><p>';
			$content[] = __('WP Biographia has supported translation and internationalisation for a while now. Thanks go out to <a href="https://twitter.com/#!/KazancExpert">Hakan Er</a> for the Turkish translation and to <a href="http://wordpress.org/support/profile/kubitomakita">Jakub Mikita</a> for the Polish translation. If you\'d like to see WP Biographia translated into your language and want to help with the process, then please drop me an <a href="mailto:%s">email</a>.', 'wp-biographia');
			$content[] = '</p><p>';
			$content[] = __('The v1.x and v2.x releases of WP Biographia were inspired and based on <a href="http://www.jonbishop.com">Jon Bishop\'s</a> <a href="http://wordpress.org/extend/plugins/wp-about-author/">WP About Author</a> plugin. WP Biographia has come a long way since v1.0, including a total rewrite in v3.0, but thanks and kudos must go to Jon for writing a well structured, working WordPress plugin released under a software license that enables other plugins such as this one to be written or derived in the first place.', 'wp-biographia');
			$content[] = '</p>';

			return $this->admin_postbox ('geopress-acknowledgements',
				__('Acknowledgements', 'geopress'),
				implode ('', $content));
		}

		/**
		 * Emit a WordPress standard set of tab headers as part of saving the plugin's
		 * settings/options.
		 *
		 * @param string current Currently selected settings/options tab context name
		 * @return string Tab headers HTML
		 */

		function admin_tabs ($current='maps') {
			$content = array ();

			$content[] = '<div id="icon-tools" class="icon32"><br /></div>';
			$content[] = '<h2 class="nav-tab-wrapper">';

			foreach (self::$tab_names as $tab => $name) {
				$class = ($tab == $current) ? ' nav-tab-active' : '';
				$content[] = "<a class='nav-tab$class' id='geopress-tab-$tab' href='options-general.php?page=geopress/geopress.php&tab=$tab'>$name</a>";
			}	// end-foreach (...)

			$content[] = '</h2>';

			return implode ('', $content);
		}

		/**
		 * Check and validate the tab parameter passed as part of the settings/options URL.
		 */

		function admin_validate_tab () {
			$tab = filter_input (INPUT_GET, 'tab', FILTER_SANITIZE_STRING);
			if ($tab !== FALSE && $tab !== null) {
				if (array_key_exists ($tab, self::$tab_names)) {
					return $tab;
				}
			}

			$tab = 'maps';
			return $tab;
		}

		/**
		 * Emit a tab specific submit button for saving the plugin's settings/options.
		 *
		 * @param string tab Settings/options tab context name
		 * @return string Submit button HTML
		 */

		function admin_submit ($tab) {
			$content = array ();

			switch ($tab) {
				case 'locations':
				case 'geocoding':
				case 'feeds':
				case 'maps':
				case 'defaults':
	            	$content[] = '<p class="submit">';
					$content[] = '<input type="submit" name="geopress_option_submitted" class="button-primary" value="';
					$content[] = sprintf (__('Save %s Settings', 'geopress'), self::$tab_names[$tab]);
					$content[] = '" />';
					$content[] = '</p>';
					return implode ('', $content);
					break;

				case 'help':
				case 'colophon':
				default:
					break;
			}	// end-switch ($tab)
		}

		// MIGRATION HINTS
		// v3.0+ - admin_add_meta_boxes, admin_display_meta_box
		// v2.4.3 - location_edit_form, geopress_new_location_form
		//
		// v2.4.3 - update_post
		// v3.0+ - admin_save_meta_boxes
		
		/**
		 * "add_meta_boxes" action hook; adds the "Geopress Locations" meta box to the admin
		 * edit screens for posts, custom post types and pages.
		 */
		
		function admin_add_meta_boxes () {
			error_log ('admin_add_meta_boxes++');
			// Get all currently defined post types (post/custom post/page) ...
			$pts = get_post_types (array (), 'objects');
			
			// Add a meta box for each post type ...
			foreach ($pts as $pt) {
				$id = sprintf ('geopress-%s-meta', $pt->name);
				$title = sprintf (__('Geotag This %s', 'geopress'), $pt->labels->singular_name);
				add_meta_box ($id, $title, array ($this, 'admin_display_meta_box'), $pt->name);
			}	// end-foreach
		}
		
		/**
		 * add_meta_box() callback; displays the "Geopress Locations" meta box.
		 */
		
		function admin_display_meta_box ($post) {
			error_log ('admin_display_meta_box++');

			$locations = $this->get_location_by_post_id ($post->ID);

			$content = array ();
			
			$content[] = wp_nonce_field (basename (__FILE__), self::META_NONCE);
			
			$locn_settings[] = '<table class="widefat">';
			$locn_settings[] = '<thead>';
			$locn_settings[] = '<tr>';
			$locn_settings[] = '<th>' . __('Show', 'geopress') . '</th>';
			$locn_settings[] = '<th>' . __('Name', 'geopress') . '</th>';
			$locn_settings[] = '<th>' . __('Address', 'geopress') . '</th>';
			$locn_settings[] = '<th>' . __('Geometry', 'geopress') . '</th>';
			$locn_settings[] = '</tr>';
			$locn_settings[] = '</thead>';
			$locn_settings[] = '<tfoot>';
			$locn_settings[] = '<tr>';
			$locn_settings[] = '<th>' . __('Show', 'geopress') . '</th>';
			$locn_settings[] = '<th>' . __('Name', 'geopress') . '</th>';
			$locn_settings[] = '<th>' . __('Address', 'geopress') . '</th>';
			$locn_settings[] = '<th>' . __('Geometry', 'geopress') . '</th>';
			$locn_settings[] = '</tr>';
			$locn_settings[] = '</tfoot>';
			$locn_settings[] = '<tbody>';
			
			$locations = $this->get_saved_locations ();
			
			$locn_settings[] = '</tbody>';
			$locn_settings[] = '</table>';
			

			echo implode (PHP_EOL, $content);
		}
		
		/**
		 * "save_post" action hook; save the location information, if present, for the current
		 * post, custom post type or page.
		 */
		
		function admin_save_meta_boxes ($post_id, $post) {
			error_log ('admin_save_meta_boxes++');
			
			// CODE HEALTH WARNING!
			//
			// The "save_post" hook is a bit of a misnomer and isn't particularly well named.
			// It's not just called on the saving of a post, but during post creation, during
			// post autosave, during the creation of a post revision, in fact during anything
			// that changes the disposition of the post. Now repeat all of the above and apply
			// it also to custom post types and to pages.
			//
			// Which is why there's a whole lot of checking and validation going on here before
			// we even start to look at the custom meta box options ...
			
			// Bale out if this is an autosave of a post/custom post type/page ...
			if (defined ('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
				return $post_id;
			}
			
			// Bale out if we're saving a revision ...
			$parent_id = wp_is_post_revision ($post_id);
			if ($parent_id) {
				return $post_id;
			}
			
			// Bale out if the current user can't edit the current post/custom post/page ...
			$post_type = get_post_type_object ($post->post_type);
			if (!current_user_can ($post_type->cap->edit_post, $post_id)) {
				return $post_id;
			}
			
			// Bale out if the status of the post/custom post/page isn't one of draft,
			// pending or published ...
			switch ($post->post_status) {
				case 'draft':
				case 'pending':
				case 'publish':
					break;
					
				default:
					return $post_id;
			}
			
			// Bale out if there's no nonce defined or if the nonce didn't originate from
			// the admin screen ...
			if (!isset ($_POST[self::META_NONCE]) || !check_admin_referer (basename (__FILE__), self::META_NONCE)) {
				return $post_id;
			}
			
			// We're good to go ...
		}
		
	} // end-class GeoPress
	
	GeoPress::get_instance ();
}

?>
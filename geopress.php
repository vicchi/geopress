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

if (!class_exists ('GeoPress')) {
	class GeoPress extends WP_PluginBase {
		const OPTIONS = 'geopress_settings';
		const VERSION = '300';
		const DISPLAY_VERSION = 'v3.0.0';

		/**
		 * Class constructor
		 */
		
		function __construct () {
			error_log ('GeoPress::__construct++');

			register_activation_hook (__FILE__, array ($this, 'add_settings'));
			$this->hook ('plugins_loaded');

			error_log ('GeoPress::__construct--');
		}
		
		/**
		 * "plugins_loaded" action hook; called after all active plugins and pluggable functions
		 * are loaded.
		 *
		 * Adds front-end display actions, shortcode support and admin actions.
		 */

		function plugins_loaded () {
			error_log ('GeoPress::plugins_loaded++');

			//register_activation_hook (__FILE__, array ($this, 'add_settings'));
			
			$this->hook ('init');
			
			if (is_admin ()) {
				$this->hook ('admin_init');
			}
			error_log ('GeoPress::plugins_loaded--');
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
			error_log ('GeoPress::add_settings++');
			error_log ('marker: ' . GEOPRESS_DEFAULT_MARKER);
			$settings = $this->get_option ();
			error_log ('geopress_settings type: ' . gettype ($settings));
			if (!is_array ($settings)) {
				error_log ('geopress_settings does not exist, creatting');
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
						'default_zoom_level' => '11'
					)
				);
				update_option (self::OPTIONS, $settings);
				$ping_sites = get_option ('ping_sites');
				if (!preg_match ('/mapufacture/', $ping_sites, $matches)) { 
					update_option ('ping_sites', $ping_sites . "\n" . 'http://mapufacture.com/georss/ping/api');
		    	}
				error_log ('Upgrading database table');
				$this->admin_check_database ();
				$this->admin_upgrade_database ();
			}
			error_log ('GeoPress::add_settings--');
		}
		
		/**
		 * "init" action hook; called to initialise the plugin
		 */

		function init () {
			error_log ('GeoPress::init++');
			$lang_dir = basename (dirname (__FILE__)) . DIRECTORY_SEPARATOR . 'lang';
			load_plugin_textdomain ('geopress', false, $lang_dir);
			error_log ('GeoPress::init--');
		}

		/**
		 * "admin_init" action hook; called after the admin panel is initialised.
		 */

		function admin_init () {
			error_log ('GeoPress::admin_init++');
			$this->admin_upgrade ();
			error_log ('GeoPress::admin_init--');
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
		 * Called in response to the "admin_init" action hook; checks the current set of
		 * settings/options and upgrades them according to the new version of the plugin.
		 */

		function admin_upgrade () {
			error_log ('GeoPress::admin_upgrade++');
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
				error_log ('pre_v3_check: ' . $pre_v3_check);
				if ($pre_v3_check !== false) {
					error_log ('Pre V3 settings found');
					$settings['version'] = '000';
				}
				
				if (isset ($settings['version']) && $settings['version'] === self::VERSION) {
					error_log ('At current version, no upgrade needed');
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
						error_log ('Migrating pre v3 options');
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
						
					case '300':
						$settings['version'] = self::VERSION;
						$upgrade_settings = true;
						$upgrade_database = true;
						
					default:
						break;
				}	// end-switch ()
				
				
				if ($upgrade_settings) {
					error_log ('Upgrading v3 options');
					update_option (self::OPTIONS, $settings);
				}
				
				if ($upgrade_database) {
					error_log ('Upgrading database table');
					$this->admin_check_database ();
					$this->admin_upgrade_database ();
				}
			}

			error_log ('GeoPress::admin_upgrade--');
		}
		
		/**
		 * Called in response to the "admin_init" action hook; checks the current database
		 * table structure and upgrades it according to the new version of the plugin.
		 */
		
		function admin_upgrade_database () {
			error_log ('GeoPress::admin_upgrade_database++');
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
				//$sql = "ALTER TABLE $table ADD KEY geopress_id (geopress_id);";
				//$res = $wpdb->get_results ($sql);
				//$sql = "ALTER TABLE $table DROP KEY id";
				//$res = $wpdb->get_results ($sql);
			}
			
			error_log ('GeoPress::admin_upgrade_database--');
		}
		
		/**
		 * Called in response to the "admin_init" action hook; creates/updates the current
		 * database structure.
		 */

		function admin_check_database () {
			error_log ('admin_check_database++');
			
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

			error_log ('admin_check_database--');
		}
	} // end-class GeoPress
	
	$__geopress_instance = new GeoPress;
}

?>
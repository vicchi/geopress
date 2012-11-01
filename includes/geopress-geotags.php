<?php

if (!class_exists ('WP_List_Table')) {
	require_once (ABSPATH . 'wp-admin/includes/class-wp-list-table.php');
}

if (!class_exists ('GeoPressGeotags')) {
	class GeoPressGeotags extends WP_List_Table {
		function __construct () {
			parent::__construct (array (
				'singular' => 'wp_list_text_link',
				'plural' => 'wp_list_text_links',
				'ajax' => false
			));
		}
		
		function get_columns () {
			return $columns = array (
				'visible' => __('Show'),
				'name' => __('Name'),
				'loc' => __('Details')
			);
		}
		
		function get_sortable_columns () {
			return $sortable = array (
				'visible' => array ('visible', false),
				'name' => array ('name', false)
			);
		}

		function column_default ($item, $column_name) {
			switch ($column_name) {
				case 'visible':
				case 'name':
				case 'loc':
					return $item[$column_name];
					break;
				default:
					break;
			}	// end-switch
		}
		function column_name ($item) {
			$actions = array (
				'edit' => '<a class="geopress-edit-geotag">Edit</a>',
				'delete' => '<a class="geopress-delete-geotag">Delete</a>'
			);
			
			return sprintf ('%1$s %2$s', $item['name'], $this->row_actions ($actions));
		}
		
		// TODO : need to merge the code in prepare_items() into GeoPress::get_all_geotags()
		
		function prepare_items () {
			global $wpdb;
			global $_wp_column_headers;
			$screen = get_current_screen ();
			$table = $wpdb->prefix . GEOPRESS_TABLE_NAME;
			$sql = array ();
			
			$sql[] = "SELECT * FROM $table";
			
			// Ordering
			$orderby = !empty ($_GET['orderby']) ? mysql_real_escape_string ($_GET['orderby']) : 'ASC';
			$order = !empty ($_GET['order']) ? mysql_real_escape_string ($_GET['order']) : '';
			if (!empty ($orderby) && !empty ($order)) {
				$sql[] = " ORDER BY $orderby $order";
			}
			
			// Pagination
			$count = $wpdb->query (implode ('', $sql));
			$perpage = 10;
			$paged = !empty ($_GET['paged']) ? mysql_real_escape_string ($_GET['[paged]']) : '';
			if (!empty ($paged) || !is_numeric ($paged) || $paged <= 0) {
				$paged = 1;
			}
			$total = ceil ($count / $perpage);
			if (!empty ($paged) && !empty ($perpage)) {
				$offset = ($paged - 1) * $perpage;
				$sql[] = ' LIMIT ' . (int) $offset . ',' . (int) $perpage;
			}
			
			$this->set_pagination_args (array (
				'total_items' => $count,
				'total_pages' => $total,
				'per_page' => $perpage
				));
				
			// Columns
			$columns = $this->get_columns ();
			$hidden = array ();
			$sortable = $this->get_sortable_columns ();
			//$_wp_column_headers[$screen->id] = $columns;
			$this->_column_headers = array ($columns, $hidden, $sortable);
			
			// Make it so
			$this->items = $wpdb->get_results (implode ('', $sql));
		}
		
		function display_rows () {
			$records = $this->items;
			list ($columns, $hidden) = $this->get_column_info ();
			
			if (!empty ($records)) {
				foreach ($records as $rec) {
					$class = 'geopress-list-' . $rec->geopress_id;
					$id = 'geopress-list-' . $rec->geopress_id;
					
					echo '<tr id="' . $id . '" class="' . $class . '">';
					foreach ($columns as $column_name => $display_name) {
						$class = "class='$column_name column-$column_name'";
						$style = '';
						if (in_array ($column_name, $hidden)) {
							$style = 'style="display: none;"';
						}
						$attributes = $class . $style;
						$editlink = '/wp-admin/'; 	// TODO
						
						switch ($column_name) {
							case 'visible':
								echo '<td ' . $attributes . '><input type="checkbox" disabled="disabled" ' . checked ($rec->visible, true, false) . ' /></td>';
								break;
							case 'name':
								echo '<td ' . $attributes . '>' . $rec->name . '</td>';
								break;
							case 'loc':
								echo '<td ' . $attributes . '>' . $rec->loc . '</td>';
								break;
						}	// end-switch (...)
					}	// end-foreach
					echo '</tr>';
				}	// end-foreach
			}
		}
		
	}	// end-class GeoPressGeotags
}
?>
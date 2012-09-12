<?php

if (!class_exists ('GeoPressGeocoder')) {
	class GeoPressGeocoder {
		
		private static $instance;
		private static $providers;
		
		const BING_URL = 'http://dev.virtualearth.net/REST/v1/Locations?query=%1$s&key=%2$s';
		const YAHOO_URL = 'http://where.yahooapis.com/geocode?q=%1$s&flags=J&appid=%2$s';
		const CLOUDMADE_URL = 'http://geocoding.cloudmade.com/%1$s/geocoding/v2/find.js?query=%2$s';
		const GOOGLE_URL = 'http://maps.googleapis.com/maps/api/geocode/json?address=%s&sensor=false';

		/**
		 * Class constructor
		 */
		
		private function __construct () {
			self::$providers = array (
				'microsoft7' => array (
					'handler' => 'bing_geocode',
					'has_key' => true
				),
				'cloudmade' => array (
					'handler' => 'cloudmade_geocode',
					'has_key' => true
				),
				'googlev3' => array (
					'handler' => 'googlev3_geocode',
					'has_key' => false
				),
				'yahoo' => array (
					'handler' => 'yahoo_geocode',
					'has_key' => true
				)
			);
		}

		public static function get_instance () {
			if (!isset (self::$instance)) {
				$c = __CLASS__;
				self::$instance = new $c ();
			}

			return self::$instance;
		}
		
		public function geocode ($provider, $query, $key) {
			if (!$this->is_valid_provider ($provider)) {
				return array ('status' => 'bad-provider');
			}
			
			if (!isset ($query) || empty ($query)) {
				return array ('status' => 'empty-query');
			}
			
			$meta = self::$providers[$provider];
			if ($meta['has_key'] && (!isset ($key) || empty ($key))) {
				return array ('status' => 'empty-key');
			}

			$query = urlencode ($query);
			$handler = $meta['handler'];

			return call_user_func (array ($this, $handler), $query, $key);
		}
		
		private function is_valid_provider ($provider) {
			if (isset ($provider) && !empty ($provider)) {
				return array_key_exists ($provider, self::$providers);
			}
			
			else {
				return false;
			}
		}
		
		private function bing_geocode ($query, $key) {
			$status = 'failed';
			$http_code = '';
			$service_code = '';
			$lat = '';
			$lon = '';
			$url = sprintf (self::BING_URL, $query, $key);
			
			$res = wp_remote_get ($url);
			if (isset ($res) && !empty ($res) && !is_wp_error ($res)) {
				$http_code = $res['response']['code'];

				if ($http_code == '200') {
					$json = json_decode ($res['body']);
					$service_code = $json->statusDescription;

					if ($service_code == 'OK') {
						$status = 'ok';
						$resource = $json->resourceSets[0]->resources[0];
						$lat = $resource->point->coordinates[0];
						$lon = $resource->point->coordinates[1];
					}
				}
			}

			return array (
				'status' => $status,
				'http-code' => $http_code,
				'service-code' => $service_code,
				'lat' => $lat,
				'lon' => $lon);
		}
		
		private function yahoo_geocode ($query, $key) {
			$status = 'failed';
			$http_code = '';
			$service_code = '';
			$lat = '';
			$lon = '';
			$url = sprintf (self::YAHOO_URL, $query, $key);
			
			$res = wp_remote_get ($url);
			if (isset ($res) && !empty ($res) && !is_wp_error ($res)) {
				$http_code = $res['response']['code'];

				if ($http_code == '200') {
					$json = json_decode ($res['body']);
					$service_code = $json->ResultSet->Error;

					if ($service_code == '0') {
						$status = 'ok';
						$result = $json->ResultSet->Results[0];
						$lat = $result->latitude;
						$lon = $result->longitude;
					}
				}
			}
			
			return array (
				'status' => $status,
				'http-code' => $http_code,
				'service-code' => $service_code,
				'lat' => $lat,
				'lon' => $lon);
		}

		private function googlev3_geocode ($query, $key) {
			$status = 'failed';
			$http_code = '';
			$service_code = '';
			$lat = '';
			$lon = '';
			$url = sprintf (self::GOOGLE_URL, $query);

			$res = wp_remote_get ($url);
			if (isset ($res) && !empty ($res) && !is_wp_error ($res)) {
				$http_code = $res['response']['code'];

				if ($http_code == '200') {
					$json = json_decode ($res['body']);
					$service_code = $json->status;

					if ($service_code == 'OK') {
						$status = 'ok';
						$result = $json->results[0];
						$lat = $result->geometry->location->lat;
						$lon = $result->geometry->location->lng;
					}
				}
			}

			return array (
				'status' => $status,
				'http-code' => $http_code,
				'service-code' => $service_code,
				'lat' => $lat,
				'lon' => $lon);
		}

		private function cloudmade_geocode ($query, $key) {
			$status = 'failed';
			$http_code = '';
			$service_code = '';
			$lat = '';
			$lon = '';
			$url = sprintf (self::CLOUDMADE_URL, $key, $query);
			
			$res = wp_remote_get ($url);
			if (isset ($res) && !empty ($res) && !is_wp_error ($res)) {
				$http_code = $res['response']['code'];

				if ($http_code == '200') {
					$json = json_decode ($res['body']);
					$service_code = $json->found;

					if ($service_code == '1') {
						$status = 'ok';
						$features = $json->features[0];
						$lat = $features->centroid->coordinates[0];
						$lon = $features->centroid->coordinates[1];
					}
				}
			}

			return array (
				'status' => $status,
				'http-code' => $http_code,
				'service-code' => $service_code,
				'lat' => $lat,
				'lon' => $lon);
		}
		
	}	// end-class GeoPressGeocoder
}
?>
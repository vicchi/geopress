(function($) {
	$().ready(function() {
		$('#geopress-location-geocode').click (function() {
			var source_address = $('#geopress-location-details').val ();
			if (source_address) {
				jQuery.post (
					GeoPressAjax.ajaxurl,
					{
						action: 'geopress-geocode',
						geocodeAddress: source_address
					},
					function (response) {
						if (response.status == 'ok') {
							var coords = response.lat + "," + response.lon;
							$('#geopress-location-lat').val (response.lat);
							$('#geopress-location-lon').val (response.lon);
							$('#geopress-location-coords').val (coords);
							$('#geopress-geocode-success').show ();
							$('#geopress-geocode-failure').hide ();
						}
						
						else {
							$('#geopress-geocode-failure').text ('Oh no! Something went horribly wrong geocoding your geotag details.');
							$('#geopress-geocode-failure').show ();
							$('#geopress-geocode-success').hide ();
						}
					}
				);
			}
			
			return false;
		});		
	});
})(jQuery);
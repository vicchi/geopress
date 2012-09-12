(function($) {
	$().ready(function() {
		$('#geopress-map-format').focus (function () {
			$(this).data ('prev-format', $(this).val ());
		});
		$('#geopress-map-format').change (function () {
			var prev_id = $('#geopress-' + $(this).data ('prev-format') + '-settings');
			var curr_id = $('#geopress-' + $(this).val () + '-settings');
			prev_id.toggle ();
			curr_id.toggle ();
			$(this).data ('prev-format', $(this).val ());
		});
		
		$('#geopress-geocoder-type').focus (function () {
			$(this).data ('prev-type', $(this).val ());
		});
		$('#geopress-geocoder-type').change (function () {
			var prev_id = $('#geopress-' + $(this).data ('prev-type') + '-geocoder-settings');
			var curr_id = $('#geopress-' + $(this).val () + '-geocoder-settings');
			prev_id.toggle ();
			curr_id.toggle ();
			$(this).data ('prev-type', $(this).val ());
		});
	});
})(jQuery);
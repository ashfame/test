(function($){
	$(document).ready(function(){
		$('.grabconversions-widget').submit(function(){
			var form = $(this);
			var data = form.serializeArray();
			$.ajax({
				type: 'POST',
				url: window.grabconversions.ajax_url,
				data: data,
				success: function(response){
					if (response.success) {
						alert( 'Thank you!' );
					} else {
						alert( response.data.reason ? response.data.reason : 'Something went wrong! Please try again.' );
					}
				}
			});
			console.log(  );
			return false;
		});
	});
})(jQuery);
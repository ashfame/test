(function ($) {
	$(document).ready(function () {
		$('#submit').click(function () {
			$.ajax({
				type: 'POST',
				url: ajaxurl,
				data: $('#gc-settings').serializeArray(),
				dataType: 'json',
				success: function (response) {
					alert('Saved!');
					window.location.reload();
				},
				fail: function (response) {
					alert('Something went wrong! Please try again!');
				}
			});

			return false;
		});
	});
})(jQuery);
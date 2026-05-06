$(document).ready(function () {
	$(".form-one, .form-two").submit(function (e) {
		e.preventDefault();

		var self = this;
		var data = $(this).serialize();
		$.ajax({
			type: "POST",
			data: data,
			url: "php/mail.php",
			success: function (data) {
				console.log(data);
				$(self).trigger('reset');
				$('.thanks').magnificPopup('open');
			}
		});
		/*
				var ct_site_id = 32462;
				var ct_data = {
					fio: name,
					phoneNumber: phone,
					subject: 'Заявка с сайта',
					sessionId: window.ct('calltracking_params', '2yzpjwz7').sessionId,
					modId: '2yzpjwz7'
				};

				$.ajax({
					url: 'https://api-node15.calltouch.ru/calls-service/RestAPI/requests/' + ct_site_id + '/register/',
					dataType: 'json',
					type: 'POST',
					data: ct_data,
					success: function (data) {
						console.log(data);
					}
				});*/
	});
	$('#modal-thanks').magnificPopup({
		type: 'inline'
	});
	$('.thanks').magnificPopup({
		type: 'inline',
		removalDelay: 300,
		mainClass: 'my-mfp-zoom-in',
		//closeBtnInside: false
	});
	// $('.popup-with-zoom-anim').magnificPopup('open');

});
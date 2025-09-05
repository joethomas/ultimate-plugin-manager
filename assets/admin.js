(function($){
	$(function(){

		// Live search
		var $search = $('#upm-search');
		var $rows   = $('#upm-plugin-rows tr');
		$search.on('input', function(){
			var q = $(this).val().toLowerCase().trim();
			if(!q){
				$rows.show();
				return;
			}
			$rows.each(function(){
				var name = $(this).attr('data-name') || '';
				$(this).toggle(name.indexOf(q) !== -1);
			});
		});

		// Mutual exclusivity between ON and OFF checkboxes per row
		$('#upm-plugin-rows').on('change', '.upm-on, .upm-off', function(){
			var $row = $(this).closest('tr');
			if($(this).hasClass('upm-on') && this.checked){
				$row.find('.upm-off').prop('checked', false);
			}
			if($(this).hasClass('upm-off') && this.checked){
				$row.find('.upm-on').prop('checked', false);
			}
		});

	});
})(jQuery);

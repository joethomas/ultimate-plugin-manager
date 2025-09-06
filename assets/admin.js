(function($){
	$(function(){

		// Tabs
		$('.nav-tab-wrapper .nav-tab').on('click', function(e){
			e.preventDefault();
			$('.nav-tab').removeClass('nav-tab-active');
			$(this).addClass('nav-tab-active');
			var tab = $(this).data('upm-tab');
			$('.upm-tab').hide();
			$('.upm-tab-'+tab).show();
		});

		// Search
		var $search = $('#upm-search');
		var $rows   = $('#upm-plugin-rows tr');
		$search.on('input', function(){
			var q = $(this).val().toLowerCase().trim();
			if(!q){ $rows.show(); return; }
			$rows.each(function(){
				var name = ($(this).attr('data-name') || '');
				$(this).toggle(name.indexOf(q) !== -1);
			});
		});

		// Lock ON/OFF mutual exclusivity
		$('#upm-plugin-rows').on('change', '.upm-on, .upm-off', function(){
			var $row = $(this).closest('tr');
			if($(this).hasClass('upm-on') && this.checked){ $row.find('.upm-off').prop('checked', false); }
			if($(this).hasClass('upm-off') && this.checked){ $row.find('.upm-on').prop('checked', false); }
		});

		// Rollback dialog (simple prompt → version or ZIP)
		$('.upm-rollback').on('click', function(){
			var file = $(this).data('file');
			var v = prompt('Enter wp.org version (e.g., 3.2.1) or a direct ZIP URL (GitHub tag ZIP supported):');
			if(!v) return;
			$.post(UPMAdmin.ajax, {action:'upm_rollback', _wpnonce: UPMAdmin.nonce, file:file, version:v}, function(r){
				if(r && r.success){ alert('Rollback requested.'); location.reload(); }
				else { alert('Rollback failed: '+ (r && r.data ? r.data : 'Unknown error')); }
			});
		});

		// Install favorite
		$('#upm-fav-list').on('click', '.upm-install-fav', function(){
			var fav = $(this).data('fav');
			$.post(UPMAdmin.ajax, {action:'upm_install_favorite', _wpnonce: UPMAdmin.nonce, fav: JSON.stringify(fav)}, function(r){
				if(r && r.success){ alert('Installed/updated. Activate in Plugins if needed.'); }
				else { alert('Install failed: '+ (r && r.data ? r.data : 'Unknown error')); }
			});
		});

		// Test GitHub API
		$('#upm-gh-test').on('click', function(){
			var $btn = $(this);
			var $out = $('#upm-gh-test-status');
			var token = $('#upm_gh_token').val().trim(); // empty means "use stored"

			$btn.prop('disabled', true);
			$out
				.removeClass('upm-ok upm-bad')
				.addClass('upm-wait')
				.html('<span class="dashicons dashicons-update-alt"></span> Testing…');

			$.post(UPMAdmin.ajax, {
				action: 'upm_test_github',
				_wpnonce: UPMAdmin.nonce,
				token: token
			}, function(r){
				$btn.prop('disabled', false);
				$out.removeClass('upm-wait');

				if (r && r.success && r.data) {
					var limit = r.data.limit || 0;
					var rem   = r.data.remaining || 0;
					var when  = r.data.reset ? new Date(r.data.reset * 1000) : null;
					var msg   = 'Valid. ' + rem + ' of ' + limit + ' remaining' + (when ? (' (resets ' + when.toLocaleString() + ')') : '') + '.';
					$out.addClass('upm-ok').html('<span class="dashicons dashicons-yes"></span> ' + msg);
				} else {
					var err = (r && r.data && r.data.message) ? r.data.message : 'Test failed.';
					$out.addClass('upm-bad').html('<span class="dashicons dashicons-warning"></span> ' + err);
				}
			}).fail(function(){
				$btn.prop('disabled', false);
				$out.removeClass('upm-wait').addClass('upm-bad').html('<span class="dashicons dashicons-warning"></span> Network error.');
			});
		});

	});
})(jQuery);

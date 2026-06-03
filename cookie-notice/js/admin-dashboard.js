( function( $ ) {

	// ready event
	$( function() {
		// set usage-bar widths from data-pct (kept out of inline styles for wp_kses safety)
		document.querySelectorAll( '#cn_dashboard_stats .cn-card__bar[data-pct]' ).forEach( function( bar ) {
			var pct = parseFloat( bar.getAttribute( 'data-pct' ) );

			if ( isNaN( pct ) )
				pct = 0;

			bar.style.width = Math.max( 0, Math.min( 100, pct ) ) + '%';
		} );

		// charts — only when Chart.js and chart data are present (connected states)
		if ( typeof Chart === 'undefined' || typeof cnDashboardArgs === 'undefined' || ! cnDashboardArgs.charts )
			return;

		var charts = cnDashboardArgs.charts;

		if ( Object.entries( charts ).length > 0 ) {
			for ( const [key, config] of Object.entries( charts ) ) {
				// create canvas
				var canvas = document.getElementById( 'cn-' + key + '-chart' );

				if ( canvas )
					new Chart( canvas, config );
			}
		}
	} );

} )( jQuery );

/* Bit Audit — dashboard interactions: async family switch, skeleton loader, count-up, filter */
( function () {
	'use strict';

	var cfg = window.bitAudit || {};
	var reduce = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

	function $( sel, ctx ) { return ( ctx || document ).querySelector( sel ); }
	function $all( sel, ctx ) { return Array.prototype.slice.call( ( ctx || document ).querySelectorAll( sel ) ); }

	function initFilter() {
		var input = $( '#ba-filter' );
		var table = $( '#ba-per-integration' );
		var empty = $( '#ba-filter-empty' );
		if ( ! input || ! table ) { return; }
		var rows = $all( 'tbody tr', table );
		input.addEventListener( 'input', function () {
			var q = input.value.toLowerCase().trim();
			var shown = 0;
			rows.forEach( function ( row ) {
				var name = ( row.querySelector( '.ba-name' ) || row.cells[1] || row.cells[0] ).textContent || '';
				var match = name.toLowerCase().indexOf( q ) !== -1;
				row.style.display = match ? '' : 'none';
				if ( match ) {
					shown++;
					var sn = row.querySelector( '.ba-sn' );
					if ( sn ) { sn.textContent = shown.toLocaleString(); }
				}
			} );
			if ( empty ) { empty.hidden = shown !== 0; }
		} );
	}

	function bindRows() {
		$all( '.ba-clickable tbody tr[data-href]' ).forEach( function ( row ) {
			var go = function () { window.location.href = row.getAttribute( 'data-href' ); };
			row.addEventListener( 'click', function ( e ) {
				if ( e.target.closest( 'a' ) ) { return; } // let the real link handle it
				go();
			} );
			row.addEventListener( 'keydown', function ( e ) {
				if ( 'Enter' === e.key || ' ' === e.key ) { e.preventDefault(); go(); }
			} );
		} );
	}

	function countUp() {
		$all( '.ba-card-value[data-count]' ).forEach( function ( el ) {
			var target = parseFloat( el.getAttribute( 'data-count' ) );
			if ( isNaN( target ) ) { return; }
			if ( reduce || target === 0 ) { el.textContent = target.toLocaleString(); return; }
			var dur = 650, start = null;
			function step( ts ) {
				if ( ! start ) { start = ts; }
				var p = Math.min( ( ts - start ) / dur, 1 );
				var eased = 1 - Math.pow( 1 - p, 3 );
				el.textContent = Math.round( target * eased ).toLocaleString();
				if ( p < 1 ) { requestAnimationFrame( step ); }
			}
			requestAnimationFrame( step );
		} );
	}

	function updateExports( family ) {
		[ 'json', 'csv' ].forEach( function ( fmt ) {
			var a = $( '#ba-export-' + fmt );
			if ( ! a ) { return; }
			a.href = cfg.exportBase + '?action=bit_audit_export&family=' + encodeURIComponent( family ) +
				'&format=' + fmt + '&_wpnonce=' + encodeURIComponent( cfg.exportNonce );
		} );
	}

	function setLoading( on ) {
		var report = $( '#ba-report' );
		var skeleton = $( '#ba-skeleton' );
		if ( report ) { report.style.display = on ? 'none' : ''; }
		if ( skeleton ) { skeleton.hidden = ! on; }
	}

	function setActivePill( family ) {
		$all( '.ba-pill' ).forEach( function ( p ) {
			var active = p.getAttribute( 'data-family' ) === family;
			p.classList.toggle( 'is-active', active );
			p.setAttribute( 'aria-selected', active ? 'true' : 'false' );
		} );
	}

	function setStatus( presence ) {
		var dot = $( '.ba-dot' );
		var label = $( '#ba-active-label' );
		var active = !! ( presence && presence.active );
		if ( dot ) { dot.className = 'ba-dot ' + ( active ? 'live' : 'idle' ); }
		if ( label ) { label.textContent = active ? ( cfg.activeLabel || 'Active' ) : ( cfg.inactiveLabel || 'Inactive' ); }
	}

	function loadFamily( family ) {
		if ( ! cfg.ajaxUrl ) { return; }
		setActivePill( family );
		setLoading( true );

		var body = new URLSearchParams();
		body.set( 'action', 'bit_audit_report' );
		body.set( 'nonce', cfg.nonce );
		body.set( 'family', family );

		fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} )
		.then( function ( r ) { return r.json(); } )
		.then( function ( res ) {
			if ( ! res || ! res.success ) { throw new Error( 'bad response' ); }
			var report = $( '#ba-report' );
			report.innerHTML = res.data.html;
			updateExports( res.data.family );
			setStatus( res.data.presence );
			setLoading( false );
			report.classList.remove( 'is-fresh' );
			void report.offsetWidth; // reflow to restart animation
			report.classList.add( 'is-fresh' );
			initFilter();
			bindRows();
			countUp();
			if ( window.history && history.replaceState ) {
				var url = new URL( window.location.href );
				url.searchParams.set( 'family', res.data.family );
				history.replaceState( {}, '', url.toString() );
			}
		} )
		.catch( function () {
			setLoading( false );
			var report = $( '#ba-report' );
			if ( report ) {
				var msg = cfg.errorMessage || 'Could not load this report. Please try again.';
				report.innerHTML = '<div class="ba-banner ba-banner-warn"><span class="dashicons dashicons-warning"></span></div>';
				report.firstChild.appendChild( document.createTextNode( msg ) );
			}
		} );
	}

	function bindPills() {
		$all( '.ba-pill' ).forEach( function ( pill ) {
			pill.addEventListener( 'click', function () {
				var family = pill.getAttribute( 'data-family' );
				if ( pill.classList.contains( 'is-active' ) ) { return; }
				loadFamily( family );
			} );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		bindPills();
		initFilter();
		bindRows();
		countUp();
	} );
} )();

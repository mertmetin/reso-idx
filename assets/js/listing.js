/**
 * Listing page: photo gallery and location map.
 */
( function () {
	'use strict';

	function gallery( root ) {
		const track = root.querySelector( '.reso-idx-gallery__track' );
		const counter = root.querySelector( '.reso-idx-gallery__counter' );
		const count = parseInt( root.dataset.count, 10 ) || 1;
		let index = 0;

		function go( next ) {
			index = ( next + count ) % count;
			track.scrollTo( { left: track.clientWidth * index, behavior: 'smooth' } );
		}

		const prev = root.querySelector( '.reso-idx-gallery__nav--prev' );
		const nxt = root.querySelector( '.reso-idx-gallery__nav--next' );
		if ( prev && nxt ) {
			prev.addEventListener( 'click', () => go( index - 1 ) );
			nxt.addEventListener( 'click', () => go( index + 1 ) );
		}
		root.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'ArrowLeft' ) {
				go( index - 1 );
			} else if ( e.key === 'ArrowRight' ) {
				go( index + 1 );
			}
		} );
		// Keep the counter right when the visitor swipes instead of using the buttons.
		track.addEventListener( 'scroll', () => {
			index = Math.round( track.scrollLeft / Math.max( 1, track.clientWidth ) );
			if ( counter ) {
				counter.textContent = ( index + 1 ) + ' / ' + count;
			}
		}, { passive: true } );
	}

	function locationMap( el ) {
		if ( ! window.L ) {
			return;
		}
		const pos = [ parseFloat( el.dataset.lat ), parseFloat( el.dataset.lng ) ];
		const map = window.L.map( el, { scrollWheelZoom: false } ).setView( pos, 15 );
		window.L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19,
			attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
		} ).addTo( map );
		window.L.marker( pos, {
			icon: window.L.divIcon( { className: 'reso-idx-pin reso-idx-pin--dot', html: '<span></span>', iconSize: null } ),
		} ).addTo( map );
	}

	function boot() {
		document.querySelectorAll( '.reso-idx-gallery' ).forEach( gallery );
		document.querySelectorAll( '.reso-idx-listing__map' ).forEach( locationMap );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );

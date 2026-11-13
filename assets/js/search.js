/**
 * Listing Search: filter in place, sync the map, keep the URL shareable.
 *
 * Progressive enhancement: without this script the form submits normally and
 * the server renders the results.
 */
( function () {
	'use strict';

	// Front-end field names → REST API parameters.
	const PARAM_MAP = { ptype: 'type', pg: 'page' };
	const FRONT_PARAMS = [ 'q', 'city', 'ptype', 'min_price', 'max_price', 'beds', 'baths', 'sort', 'pg' ];

	function init( root ) {
		const config = JSON.parse( root.dataset.config || '{}' );
		const form = root.querySelector( '.reso-idx-filters' );
		const results = root.querySelector( '.reso-idx-results' );
		const mapEl = root.querySelector( '.reso-idx-map' );
		const follow = root.querySelector( '.reso-idx-map__follow' );
		let controller = null;
		let map = null;
		let layer = null;
		let markers = {};
		let ignoreMove = false;

		function formParams( page ) {
			const data = new FormData( form );
			const params = new URLSearchParams();
			for ( const [ name, value ] of data.entries() ) {
				if ( value !== '' && ! ( name === 'sort' && value === 'newest' ) ) {
					params.set( name, value );
				}
			}
			if ( page && page > 1 ) {
				params.set( 'pg', String( page ) );
			}
			return params;
		}

		function apiParams( front ) {
			const api = new URLSearchParams();
			for ( const [ name, value ] of front.entries() ) {
				api.set( PARAM_MAP[ name ] || name, value );
			}
			api.set( 'per_page', String( config.perPage || 12 ) );
			api.set( 'render', '1' );
			api.set( 'base', window.location.origin + window.location.pathname );
			if ( map && follow && follow.checked ) {
				const b = map.getBounds();
				api.set( 'bounds', [ b.getSouth(), b.getWest(), b.getNorth(), b.getEast() ].map( ( n ) => n.toFixed( 5 ) ).join( ',' ) );
			}
			return api;
		}

		function updateUrl( front ) {
			const url = new URL( window.location.href );
			FRONT_PARAMS.forEach( ( p ) => url.searchParams.delete( p ) );
			for ( const [ name, value ] of front.entries() ) {
				if ( ! ( config.fixed && name in config.fixed ) ) {
					url.searchParams.set( name, value );
				}
			}
			window.history.replaceState( null, '', url );
		}

		async function load( page, fitMap ) {
			const front = formParams( page );
			if ( controller ) {
				controller.abort();
			}
			controller = new AbortController();
			root.classList.add( 'is-loading' );
			try {
				const sep = config.endpoint.includes( '?' ) ? '&' : '?';
				const res = await fetch( config.endpoint + sep + apiParams( front ).toString(), {
					signal: controller.signal,
					headers: { Accept: 'application/json' },
				} );
				if ( ! res.ok ) {
					throw new Error( 'HTTP ' + res.status );
				}
				const data = await res.json();
				results.innerHTML = data.html;
				drawPins( data.pins || [], fitMap );
				updateUrl( front );
				bindCards();
			} catch ( err ) {
				if ( err.name !== 'AbortError' ) {
					// Fall back to a normal page load so the visitor still gets results.
					form.submit();
				}
			} finally {
				root.classList.remove( 'is-loading' );
			}
		}

		function shortPrice( price ) {
			if ( price === null ) {
				return '';
			}
			return price >= 1e6 ? '$' + ( Math.round( price / 1e4 ) / 100 ) + 'M' : '$' + Math.round( price / 1e3 ) + 'K';
		}

		function drawPins( pins, fit ) {
			if ( ! map ) {
				return;
			}
			layer.clearLayers();
			markers = {};
			pins.forEach( ( pin ) => {
				const icon = window.L.divIcon( {
					className: 'reso-idx-pin',
					html: '<span>' + shortPrice( pin.price ) + '</span>',
					iconSize: null,
				} );
				const marker = window.L.marker( [ pin.lat, pin.lng ], { icon, keyboard: true, title: shortPrice( pin.price ) } );
				marker.on( 'click', () => {
					window.location.href = config.listings + encodeURIComponent( pin.key ) + '/';
				} );
				marker.on( 'mouseover', () => highlight( pin.key, true ) );
				marker.on( 'mouseout', () => highlight( pin.key, false ) );
				marker.addTo( layer );
				markers[ pin.key ] = marker;
			} );
			if ( fit && pins.length ) {
				ignoreMove = true;
				map.fitBounds( layer.getBounds(), { padding: [ 30, 30 ], maxZoom: 15 } );
			}
		}

		function highlight( key, on ) {
			const card = results.querySelector( '.reso-idx-card[data-key="' + CSS.escape( key ) + '"]' );
			if ( card ) {
				card.classList.toggle( 'is-active', on );
			}
			const marker = markers[ key ];
			if ( marker && marker.getElement() ) {
				marker.getElement().classList.toggle( 'is-active', on );
				marker.setZIndexOffset( on ? 1000 : 0 );
			}
		}

		function bindCards() {
			results.querySelectorAll( '.reso-idx-card' ).forEach( ( card ) => {
				card.addEventListener( 'mouseenter', () => highlight( card.dataset.key, true ) );
				card.addEventListener( 'mouseleave', () => highlight( card.dataset.key, false ) );
			} );
		}

		function initMap() {
			if ( ! mapEl || ! window.L ) {
				return;
			}
			map = window.L.map( mapEl, { scrollWheelZoom: false } );
			window.L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				maxZoom: 19,
				attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
			} ).addTo( map );
			layer = window.L.featureGroup().addTo( map );
			const pins = JSON.parse( mapEl.dataset.pins || '[]' );
			if ( pins.length ) {
				drawPins( pins, true );
			} else {
				map.setView( [ 45.4215, -75.6972 ], 11 );
			}
			map.on( 'moveend', () => {
				if ( ignoreMove ) {
					ignoreMove = false;
					return;
				}
				if ( follow && follow.checked ) {
					load( 1, false );
				}
			} );
		}

		form.addEventListener( 'submit', ( e ) => {
			e.preventDefault();
			load( 1, true );
		} );
		form.addEventListener( 'change', ( e ) => {
			if ( e.target.tagName === 'SELECT' ) {
				load( 1, true );
			}
		} );
		results.addEventListener( 'click', ( e ) => {
			const link = e.target.closest( '.reso-idx-pagination a[data-page]' );
			if ( link ) {
				e.preventDefault();
				load( parseInt( link.dataset.page, 10 ), false );
				root.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			}
		} );
		if ( follow ) {
			follow.addEventListener( 'change', () => load( 1, ! follow.checked ) );
		}

		initMap();
		bindCards();
	}

	function boot() {
		document.querySelectorAll( '.reso-idx-search[data-config]' ).forEach( init );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
}() );

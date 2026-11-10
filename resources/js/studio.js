/**
 * Post Image Creator — design studio.
 *
 * The studio paints the *render plan* the server produces (the same structure a
 * renderer receives), so the preview and the saved PNG cannot drift apart.
 * Bengali shaping is done by the browser, which is the whole reason the canvas
 * lives here rather than in PHP.
 *
 * Structure (top to bottom):
 *   1. helpers, icons, REST client, toasts, dialogs
 *   2. document model + history
 *   3. fonts, image cache, plan painting
 *   4. viewport: zoom, pan, rulers, overlay, guides
 *   5. canvas interaction: select, move, resize, rotate, marquee
 *   6. layer manager
 *   7. properties inspector
 *   8. design / elements panel
 *   9. save, export, publish, versions
 *  10. keyboard shortcuts and boot
 *
 * Globals: only `window.CPICStudio` (the block editor renders through it).
 */
( function () {
	'use strict';

	var CONFIG = window.CPIC || {};

	/* ═══ 1. Constants, helpers, icons, REST, UI chrome ═══════════ */

	var STORE_PREFS = 'cpicStudioPrefs';

	var state = {
		document: null,
		plan: null,
		selection: [],
		format: 'square',
		postId: 0,
		templateId: 0,
		templateName: '',
		revision: 0,
		brandId: 0,
		dirty: false,
		saving: false,
		pendingSave: false,
		lastSavedAt: 0,
		imageCache: new Map(),
		fontsReady: false,
		fontsFailed: [],
		zoom: 1,
		panX: 0,
		panY: 0,
		fitMode: true,
		tool: 'select',
		showGrid: false,
		showRulers: true,
		showSafe: true,
		snap: true,
		editingText: null,
		planSeq: 0,
		planPending: null,
		history: { past: [], future: [], limit: 100, lastKey: '', lastAt: 0 },
		clipboard: null,
		interaction: null,
		spacePanning: false,
		menuTarget: null,
		prefs: { autosave: false, onboardingSeen: false },
		meta: { templates: [], starters: [], posts: [], fonts: [], destinations: [], formats: {}, can: {} },
		strings: ( CONFIG.strings || {} ),
	};

	var els = {};
	var refs = {};

	/* ── Tiny helpers ─────────────────────────────────────────────── */

	function str( key, fallback ) {
		var table = state.strings || {};
		return table[ key ] ? table[ key ] : fallback;
	}

	function clamp( value, min, max ) {
		return Math.min( max, Math.max( min, value ) );
	}

	function round( value, places ) {
		var factor = Math.pow( 10, places === undefined ? 0 : places );
		return Math.round( value * factor ) / factor;
	}

	function clone( value ) {
		return JSON.parse( JSON.stringify( value ) );
	}

	function num( value, fallback ) {
		var parsed = typeof value === 'number' ? value : parseFloat( value );
		return isFinite( parsed ) ? parsed : ( fallback === undefined ? 0 : fallback );
	}

	function uid( prefix ) {
		var alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
		var suffix = '';

		for ( var i = 0; i < 4; i++ ) {
			suffix += alphabet.charAt( Math.floor( Math.random() * alphabet.length ) );
		}

		return ( prefix || 'element' ).replace( /[^a-zA-Z0-9_\-]/g, '' ) + '_' + suffix;
	}

	function el( tag, attributes, children ) {
		var node = document.createElement( tag );

		Object.keys( attributes || {} ).forEach( function ( key ) {
			var value = attributes[ key ];

			if ( value === null || value === undefined ) {
				return;
			}

			if ( key === 'text' ) {
				node.textContent = String( value );
			} else if ( key === 'class' ) {
				node.className = String( value );
			} else if ( key === 'html' ) {
				node.innerHTML = String( value ); // Only ever called with our own SVG strings.
			} else if ( key === 'dataset' ) {
				Object.keys( value ).forEach( function ( dataKey ) {
					node.setAttribute( 'data-' + dataKey, String( value[ dataKey ] ) );
				} );
			} else if ( key.indexOf( 'on' ) === 0 && typeof value === 'function' ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), value );
			} else if ( key === 'value' ) {
				node.value = value;
			} else if ( key === 'checked' || key === 'disabled' || key === 'hidden' || key === 'selected' ) {
				node[ key ] = !! value;
			} else {
				node.setAttribute( key, String( value ) );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( child === null || child === undefined || child === false ) {
				return;
			}
			node.appendChild( child instanceof window.Node ? child : document.createTextNode( String( child ) ) );
		} );

		return node;
	}

	function clear( node ) {
		while ( node && node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function raf( fn ) {
		if ( window.requestAnimationFrame ) {
			return window.requestAnimationFrame( fn );
		}
		return window.setTimeout( fn, 16 );
	}

	function debounce( fn, wait ) {
		var timer = null;
		var wrapped = function () {
			var args = arguments;
			var self = this;
			window.clearTimeout( timer );
			timer = window.setTimeout( function () {
				timer = null;
				fn.apply( self, args );
			}, wait );
		};
		wrapped.cancel = function () {
			window.clearTimeout( timer );
			timer = null;
		};
		return wrapped;
	}

	function isEditable( node ) {
		if ( ! node ) {
			return false;
		}
		var tag = ( node.tagName || '' ).toLowerCase();
		return node.isContentEditable || tag === 'input' || tag === 'textarea' || tag === 'select';
	}

	function isMac() {
		return /Mac|iPhone|iPad/.test( navigator.platform || '' );
	}

	function modKey( event ) {
		return isMac() ? event.metaKey : event.ctrlKey;
	}

	function shortcut( command ) {
		var map = {
			undo: 'Ctrl Z',
			redo: 'Ctrl Shift Z',
			save: 'Ctrl S',
			duplicate: 'Ctrl D',
			delete: 'Del',
			selectAll: 'Ctrl A',
			escape: 'Esc',
			pan: 'Space',
			zoom: 'Ctrl Wheel',
			nudge: 'Arrow keys',
			nudgeBig: 'Shift Arrow',
		};
		var text = map[ command ] || '';
		return isMac() ? text.replace( /Ctrl/g, '⌘' ).replace( /Shift/g, '⇧' ).replace( /Del/g, '⌫' ) : text;
	}

	/* ── Icons ────────────────────────────────────────────────────── */

	var ICON_PATHS = {
		logo: '<rect x="3" y="3" width="18" height="18" rx="4"/><path d="M7.5 15.5l3-4 2.2 2.8 1.8-2.2 2 3.4z"/><circle cx="9" cy="8.6" r="1.4"/>',
		'panel-left': '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M9.5 4v16"/>',
		'panel-right': '<rect x="3" y="4" width="18" height="16" rx="2.5"/><path d="M14.5 4v16"/>',
		undo: '<path d="M9 14L4 9l5-5"/><path d="M4 9h9.5A5.5 5.5 0 0 1 19 14.5v.5"/>',
		redo: '<path d="M15 14l5-5-5-5"/><path d="M20 9h-9.5A5.5 5.5 0 0 0 5 14.5v.5"/>',
		eye: '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
		'eye-off': '<path d="M4 4l16 16"/><path d="M9.9 5.9A9.7 9.7 0 0 1 12 5.5c6 0 9.5 6.5 9.5 6.5a17 17 0 0 1-2.7 3.6"/><path d="M6.3 7.9A17 17 0 0 0 2.5 12S6 18.5 12 18.5c1.2 0 2.3-.2 3.3-.6"/><path d="M9.9 10.1a3 3 0 0 0 4 4"/>',
		save: '<path d="M5 4h11l4 4v12H5z"/><path d="M8 4v5h7V4"/><rect x="8" y="13" width="8" height="7"/>',
		download: '<path d="M12 3v12"/><path d="M7.5 10.5L12 15l4.5-4.5"/><path d="M4 19h16"/>',
		'chevron-down': '<path d="M6 9l6 6 6-6"/>',
		'chevron-right': '<path d="M9 6l6 6-6 6"/>',
		'chevron-left': '<path d="M15 6l-6 6 6 6"/>',
		send: '<path d="M21 3L3 10.5l7 2.8L12.8 21z"/><path d="M10 13l11-10"/>',
		more: '<circle cx="5" cy="12" r="1.6"/><circle cx="12" cy="12" r="1.6"/><circle cx="19" cy="12" r="1.6"/>',
		template: '<rect x="3.5" y="3.5" width="17" height="17" rx="2.5"/><path d="M3.5 9h17"/><path d="M9 9v11.5"/>',
		shapes: '<rect x="3.5" y="3.5" width="9" height="9" rx="2"/><circle cx="16.5" cy="16.5" r="4.5"/>',
		layers: '<path d="M12 3l9 5-9 5-9-5z"/><path d="M3 13l9 5 9-5"/>',
		search: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l4.5 4.5"/>',
		plus: '<path d="M12 5v14"/><path d="M5 12h14"/>',
		minus: '<path d="M5 12h14"/>',
		'arrow-up': '<path d="M12 19V5"/><path d="M6 11l6-6 6 6"/>',
		'arrow-down': '<path d="M12 5v14"/><path d="M6 13l6 6 6-6"/>',
		'to-front': '<rect x="4" y="4" width="11" height="11" rx="2"/><path d="M9 20h11V9"/>',
		'to-back': '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M15 4H4v11"/>',
		copy: '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M15 6V5a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h1"/>',
		trash: '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="M6 7l1 13h10l1-13"/>',
		lock: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
		unlock: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 7.5-2"/>',
		cursor: '<path d="M6 3l12 8-5.2 1.6L10 18z"/>',
		hand: '<path d="M8 12V5.5a1.5 1.5 0 0 1 3 0V11"/><path d="M11 11V4.5a1.5 1.5 0 0 1 3 0V11"/><path d="M14 11V6a1.5 1.5 0 0 1 3 0v7"/><path d="M8 12V9.5a1.5 1.5 0 0 0-3 0V15a6 6 0 0 0 6 6h2a6 6 0 0 0 6-6v-2"/>',
		fit: '<path d="M4 9V4h5"/><path d="M20 15v5h-5"/><path d="M15 4h5v5"/><path d="M9 20H4v-5"/>',
		'one-to-one': '<path d="M4 8V4h4"/><path d="M20 16v4h-4"/><path d="M8 20H4v-4"/><path d="M16 4h4v4"/><path d="M9 12h6"/>',
		grid: '<path d="M3 9h18"/><path d="M3 15h18"/><path d="M9 3v18"/><path d="M15 3v18"/>',
		ruler: '<rect x="2.5" y="8" width="19" height="8" rx="1.5"/><path d="M7 8v3"/><path d="M11 8v4"/><path d="M15 8v3"/><path d="M19 8v4"/>',
		safe: '<rect x="3" y="3" width="18" height="18" rx="2.5"/><rect x="7" y="7" width="10" height="10" rx="1.5" stroke-dasharray="2.5 2.5"/>',
		magnet: '<path d="M5 4h4v7a3 3 0 0 0 6 0V4h4v7a7 7 0 0 1-14 0z"/><path d="M5 8h4"/><path d="M15 8h4"/>',
		keyboard: '<rect x="2.5" y="6" width="19" height="12" rx="2"/><path d="M6 10h.01M9.5 10h.01M13 10h.01M16.5 10h.01M6 13.5h.01M9.5 13.5h.01M13 13.5h.01M16.5 13.5h.01M8 16.5h8"/>',
		close: '<path d="M6 6l12 12"/><path d="M18 6L6 18"/>',
		move: '<path d="M12 3v18"/><path d="M3 12h18"/><path d="M9 6l3-3 3 3"/><path d="M9 18l3 3 3-3"/><path d="M6 9l-3 3 3 3"/><path d="M18 9l3 3-3 3"/>',
		sliders: '<path d="M4 7h10"/><path d="M18 7h2"/><path d="M4 17h4"/><path d="M12 17h8"/><circle cx="16" cy="7" r="2"/><circle cx="10" cy="17" r="2"/>',
		type: '<path d="M4 6h16"/><path d="M12 6v14"/><path d="M9 20h6"/>',
		heading: '<path d="M5 5v14"/><path d="M17 5v14"/><path d="M5 12h12"/>',
		image: '<rect x="3" y="4" width="18" height="16" rx="2.5"/><circle cx="8.5" cy="9.5" r="1.6"/><path d="M4.5 18l5-5.5 3.5 3.5 2.5-2.5 4 4.5"/>',
		rect: '<rect x="3.5" y="5.5" width="17" height="13" rx="2"/>',
		circle: '<circle cx="12" cy="12" r="8.5"/>',
		ellipse: '<ellipse cx="12" cy="12" rx="9" ry="6"/>',
		line: '<path d="M4 17L20 7"/>',
		gradient: '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><path d="M3.5 16l17-8" opacity=".55"/><path d="M3.5 19.5l17-8" opacity=".35"/>',
		watermark: '<path d="M12 3s6 5.2 6 9a6 6 0 0 1-12 0c0-3.8 6-9 6-9z"/><path d="M9.5 13.5a2.5 2.5 0 0 0 2.5 2.5"/>',
		upload: '<path d="M12 16V4"/><path d="M7.5 8.5L12 4l4.5 4.5"/><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>',
		align: '<path d="M4 6h16"/><path d="M4 12h10"/><path d="M4 18h13"/>',
		history: '<path d="M3.5 12a8.5 8.5 0 1 0 3-6.5"/><path d="M3 4v5h5"/><path d="M12 8v4.5l3 1.8"/>',
		settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 9 19.4a1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1A1.7 1.7 0 0 0 4.6 9a1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
		crop: '<path d="M6 2v14a2 2 0 0 0 2 2h14"/><path d="M2 6h14a2 2 0 0 1 2 2v14"/>',
		rotate: '<path d="M21 12a9 9 0 1 1-3-6.7"/><path d="M21 3v6h-6"/>',
		star: '<path d="M12 3l2.8 5.9 6.2.8-4.6 4.3 1.2 6.3L12 17.4 6.4 20.3l1.2-6.3L3 9.7l6.2-.8z"/>',
		info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.6h.01"/>',
		check: '<path d="M4.5 12.5l5 5 10-11"/>',
		warning: '<path d="M12 3l9 17H3z"/><path d="M12 9v5"/><path d="M12 17h.01"/>',
		retry: '<path d="M3.5 12a8.5 8.5 0 0 1 14.6-5.9L21 9"/><path d="M21 4v5h-5"/><path d="M20.5 12a8.5 8.5 0 0 1-14.6 5.9L3 15"/><path d="M3 20v-5h5"/>',
		external: '<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
		alignLeft: '<path d="M4 5v14"/><path d="M8 8h12"/><path d="M8 16h8"/>',
		alignCenterH: '<path d="M12 4v16"/><path d="M5 8h14"/><path d="M7 16h10"/>',
		alignRight: '<path d="M20 5v14"/><path d="M4 8h12"/><path d="M8 16h8"/>',
		alignTop: '<path d="M5 4h14"/><path d="M8 8v12"/><path d="M16 8v8"/>',
		alignMiddle: '<path d="M4 12h16"/><path d="M8 5v14"/><path d="M16 7v10"/>',
		alignBottom: '<path d="M5 20h14"/><path d="M8 4v12"/><path d="M16 8v8"/>',
	};

	/**
	 * Replace `<span class="cpic-svg" data-icon="name">` placeholders with the
	 * inline SVG. One sprite-free approach keeps the templates readable and
	 * avoids any external icon dependency.
	 *
	 * @param {Element} root Scope.
	 * @return {void}
	 */
	function hydrateIcons( root ) {
		var scope = root || document;
		var nodes = scope.querySelectorAll( '.cpic-svg[data-icon]' );

		Array.prototype.forEach.call( nodes, function ( node ) {
			var name = node.getAttribute( 'data-icon' );
			var paths = ICON_PATHS[ name ];
			var size = node.getAttribute( 'data-size' ) || '18';

			if ( ! paths || node.getAttribute( 'data-icon-ready' ) === '1' ) {
				return;
			}

			node.innerHTML =
				'<svg viewBox="0 0 24 24" width="' + size + '" height="' + size + '" fill="none" stroke="currentColor" ' +
				'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' + paths + '</svg>';
			node.setAttribute( 'data-icon-ready', '1' );
		} );
	}

	function iconNode( name, size ) {
		var span = el( 'span', { class: 'cpic-svg', 'data-icon': name, 'aria-hidden': 'true' } );
		if ( size ) {
			span.setAttribute( 'data-size', String( size ) );
		}
		hydrateIcons( span.parentNode || span );
		var paths = ICON_PATHS[ name ];
		if ( paths && ! span.firstChild ) {
			span.innerHTML = '<svg viewBox="0 0 24 24" width="' + ( size || 18 ) + '" height="' + ( size || 18 ) + '" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + paths + '</svg>';
		}
		return span;
	}

	/* ── Status + toasts ──────────────────────────────────────────── */

	function setSaveState( key, text ) {
		if ( ! els.saveState ) {
			return;
		}

		els.saveState.setAttribute( 'data-state', key );
		var label = els.saveState.querySelector( '.cpic-save-state__text' );
		if ( label ) {
			label.textContent = text;
		}
	}

	function refreshSaveState() {
		if ( state.saving ) {
			setSaveState( 'saving', str( 'saving', 'Saving…' ) );
			return;
		}

		if ( state.dirty ) {
			setSaveState( 'unsaved', str( 'unsaved', 'Unsaved changes' ) );
			return;
		}

		if ( state.templateId > 0 ) {
			setSaveState( 'saved', str( 'saved', 'Saved' ) );
		} else {
			setSaveState( 'unsaved', str( 'notSaved', 'Not saved yet' ) );
		}
	}

	function toast( message, kind, options ) {
		options = options || {};

		if ( ! els.toasts ) {
			return;
		}

		var tone = kind || 'info';
		var node = el( 'div', { class: 'cpic-toast is-' + tone, role: 'status' }, [
			iconNode( tone === 'error' || tone === 'warning' ? 'warning' : tone === 'success' ? 'check' : 'info', 16 ),
			el( 'span', { class: 'cpic-toast__text', text: message } ),
		] );

		if ( options.action && options.action.label ) {
			node.appendChild(
				el( 'button', {
					type: 'button',
					class: 'cpic-toast__action',
					text: options.action.label,
					onClick: function () {
						options.action.run();
						close();
					},
				} )
			);
		}

		var timer = window.setTimeout( close, options.duration || ( tone === 'error' ? 8000 : 3600 ) );

		function close() {
			window.clearTimeout( timer );
			if ( node.parentNode ) {
				node.classList.add( 'is-leaving' );
				window.setTimeout( function () {
					if ( node.parentNode ) {
						node.parentNode.removeChild( node );
					}
				}, 180 );
			}
		}

		node.addEventListener( 'click', function ( event ) {
			if ( ! event.target.closest( '.cpic-toast__action' ) ) {
				close();
			}
		} );

		els.toasts.appendChild( node );
	}

	/* ── REST client ──────────────────────────────────────────────── */

	async function api( path, options ) {
		options = options || {};

		if ( ! CONFIG.restRoot ) {
			throw new Error( str( 'restMissing', 'The REST API is unavailable on this screen.' ) );
		}

		var url = CONFIG.restRoot.replace( /\/$/, '' ) + ( path.charAt( 0 ) === '/' ? path : '/' + path );

		// A request that never answers must not freeze the editor: WordPress
		// hosts stall for all sorts of reasons, and a spinner with no message
		// is worse than an error the user can act on.
		var timeout = options.timeout || num( CONFIG.requestTimeout, 20000 );
		var controller = typeof AbortController === 'function' ? new AbortController() : null;
		var timer = null;

		if ( controller && ! options.signal ) {
			timer = window.setTimeout( function () {
				controller.abort();
			}, timeout );
		}

		var response;

		try {
			response = await fetch( url, {
				method: options.method || 'GET',
				credentials: 'same-origin',
				headers: Object.assign(
					{
						'Content-Type': 'application/json',
						'X-WP-Nonce': CONFIG.nonce || '',
					},
					options.headers || {}
				),
				body: options.body ? JSON.stringify( options.body ) : undefined,
				signal: options.signal || ( controller ? controller.signal : undefined ),
			} );
		} catch ( error ) {
			if ( timer ) {
				window.clearTimeout( timer );
			}

			var network = new Error(
				error && error.name === 'AbortError'
					? str( 'timedOut', 'The server did not respond in time. Check your connection and try again.' )
					: str( 'offline', 'The server could not be reached. Check your connection and try again.' )
			);
			network.code = error && error.name === 'AbortError' ? 'timeout' : 'network';
			network.timeout = timeout;
			throw network;
		}

		if ( timer ) {
			window.clearTimeout( timer );
		}

		var payload = null;
		try {
			payload = await response.json();
		} catch ( error ) {
			payload = null;
		}

		if ( ! response.ok ) {
			var message = payload && payload.message ? payload.message : str( 'renderFailed', 'Request failed' );
			var failure = new Error( message );
			failure.code = payload && payload.code ? payload.code : 'http_' + response.status;
			failure.status = response.status;
			throw failure;
		}

		if ( null === payload ) {
			// A 200 with a body the editor cannot read (a PHP notice, a login
			// page) looks like success but carries nothing usable.
			var unreadable = new Error( str( 'badResponse', 'The server sent a response the editor could not read. Reload the page and try again.' ) );
			unreadable.code = 'bad_payload';
			unreadable.status = response.status;
			throw unreadable;
		}

		return payload;
	}

	/* ── Menus ────────────────────────────────────────────────────── */

	function closeMenus() {
		if ( els.menu ) {
			els.menu.hidden = true;
			clear( els.menu );
		}
		if ( els.contextMenu ) {
			els.contextMenu.hidden = true;
			clear( els.contextMenu );
		}

		Array.prototype.forEach.call( document.querySelectorAll( '.cpic-menu-wrap .cpic-btn[aria-expanded], .cpic-menu-wrap .cpic-icon-btn[aria-expanded]' ), function ( button ) {
			button.setAttribute( 'aria-expanded', 'false' );
		} );
	}

	/**
	 * Open a dropdown menu anchored to a button.
	 *
	 * @param {Element} anchor   Button.
	 * @param {Array}   items    { label, icon, hint, run, disabled, danger, separator }.
	 * @param {Object}  options  { align: 'start'|'end' }.
	 * @return {void}
	 */
	function openMenu( anchor, items, options ) {
		options = options || {};
		closeMenus();

		var menu = els.menu;
		menu.hidden = false;
		clear( menu );

		items.forEach( function ( item ) {
			if ( ! item ) {
				return;
			}

			if ( item.separator ) {
				menu.appendChild( el( 'div', { class: 'cpic-menu__sep', role: 'separator' } ) );
				return;
			}

			if ( item.heading ) {
				menu.appendChild( el( 'div', { class: 'cpic-menu__heading', text: item.heading } ) );
				return;
			}

			var button = el(
				'button',
				{
					type: 'button',
					class: 'cpic-menu__item' + ( item.danger ? ' is-danger' : '' ) + ( item.active ? ' is-active' : '' ),
					role: 'menuitem',
					disabled: item.disabled ? 'disabled' : null,
					onClick: function () {
						if ( item.keepOpen ) {
							item.run();
							return;
						}
						closeMenus();
						item.run();
					},
				},
				[
					item.icon ? iconNode( item.icon, 16 ) : el( 'span', { class: 'cpic-svg' } ),
					el( 'span', { class: 'cpic-menu__label', text: item.label } ),
					item.hint ? el( 'span', { class: 'cpic-menu__hint', text: item.hint } ) : ( item.active ? iconNode( 'check', 14 ) : null ),
				]
			);

			menu.appendChild( button );
		} );

		var rect = anchor.getBoundingClientRect();
		menu.style.visibility = 'hidden';
		menu.style.top = '0px';
		menu.style.left = '0px';

		var menuRect = menu.getBoundingClientRect();
		var left = options.align === 'start' ? rect.left : rect.right - menuRect.width;
		left = clamp( left, 8, window.innerWidth - menuRect.width - 8 );
		var top = rect.bottom + 6;

		if ( top + menuRect.height > window.innerHeight - 8 ) {
			top = Math.max( 8, rect.top - menuRect.height - 6 );
		}

		menu.style.left = left + 'px';
		menu.style.top = top + 'px';
		menu.style.visibility = '';

		anchor.setAttribute( 'aria-expanded', 'true' );
		var first = menu.querySelector( 'button' );
		if ( first ) {
			first.focus();
		}
	}

	/* ── Dialogs ──────────────────────────────────────────────────── */

	var dialogState = { onClose: null, lastFocus: null };

	function openDialog( options ) {
		options = options || {};

		dialogState.lastFocus = document.activeElement;
		dialogState.onClose = options.onClose || null;

		els.modal.hidden = false;
		els.modalTitle.textContent = options.title || '';

		clear( els.modalBody );
		clear( els.modalFoot );

		if ( options.body ) {
			els.modalBody.appendChild( options.body );
		}

		( options.actions || [] ).forEach( function ( action ) {
			els.modalFoot.appendChild(
				el( 'button', {
					type: 'button',
					class: 'cpic-btn' + ( action.primary ? ' cpic-btn--primary' : '' ) + ( action.danger ? ' cpic-btn--danger' : '' ),
					text: action.label,
					onClick: function () {
						if ( action.run && false === action.run() ) {
							return;
						}
						if ( action.keepOpen ) {
							return;
						}
						closeDialog();
					},
				} )
			);
		} );

		els.modalFoot.hidden = ! ( options.actions && options.actions.length );

		hydrateIcons( els.modal );
		window.setTimeout( function () {
			var focusable = els.modal.querySelector( 'input, textarea, select, button.cpic-btn--primary, button' );
			if ( focusable ) {
				focusable.focus();
			}
		}, 20 );
	}

	function closeDialog() {
		if ( els.modal.hidden ) {
			return;
		}

		els.modal.hidden = true;
		clear( els.modalBody );
		clear( els.modalFoot );

		if ( dialogState.lastFocus && dialogState.lastFocus.focus ) {
			dialogState.lastFocus.focus();
		}

		var callback = dialogState.onClose;
		dialogState.onClose = null;

		if ( callback ) {
			callback();
		}
	}

	function confirmDialog( options ) {
		return new Promise( function ( resolve ) {
			var settled = false;

			openDialog( {
				title: options.title,
				body: el( 'div', { class: 'cpic-dialog-text' }, [
					el( 'p', { text: options.message } ),
					options.detail ? el( 'p', { class: 'cpic-muted', text: options.detail } ) : null,
				] ),
				actions: [
					{
						label: options.cancelLabel || str( 'cancel', 'Cancel' ),
						run: function () {
							settled = true;
							resolve( false );
						},
					},
					{
						label: options.confirmLabel || str( 'confirm', 'Continue' ),
						primary: ! options.danger,
						danger: !! options.danger,
						run: function () {
							settled = true;
							resolve( true );
						},
					},
				],
				onClose: function () {
					if ( ! settled ) {
						resolve( false );
					}
				},
			} );
		} );
	}

	/* ═══ 2. Document model, history ═════════════════════════════ */

	/**
	 * The base element map. `document.elements` keeps the original order and
	 * ids; z-order is a property, never an array position, so reordering layers
	 * can never re-index or rename an element.
	 *
	 * @return {Object} Map of id => element.
	 */
	function elements() {
		if ( ! state.document ) {
			return {};
		}
		if ( ! state.document.elements || typeof state.document.elements !== 'object' ) {
			state.document.elements = {};
		}
		return state.document.elements;
	}

	function elementFor( id ) {
		if ( ! id ) {
			return null;
		}
		return elements()[ id ] || null;
	}

	/**
	 * Render-plan layer for an element id (already format-merged by the server).
	 *
	 * @param {string} id Element id.
	 * @return {Object|null} Layer.
	 */
	function layerFor( id ) {
		if ( ! state.plan || ! state.plan.layers ) {
			return null;
		}
		var layers = state.plan.layers;
		for ( var i = 0; i < layers.length; i++ ) {
			if ( layers[ i ].id === id ) {
				return layers[ i ];
			}
		}
		return null;
	}

	/**
	 * Effective geometry for the active format: the format override wins over
	 * the base element, matching TemplateSchema::elements_for_format().
	 *
	 * @param {string} id Element id.
	 * @return {Object|null} { x, y, width, height, rotation, z, opacity }.
	 */
	function geometry( id ) {
		var layer = layerFor( id );
		if ( layer ) {
			return {
				x: num( layer.x ),
				y: num( layer.y ),
				width: num( layer.width ),
				// An auto-height layer (text, natural-ratio logo) reports the box
				// the editor should manipulate, not the plan's placeholder 0.
				height: num( layer.height ) > 0 ? num( layer.height ) : hitHeight( layer ),
				rotation: num( layer.rotation ),
				z: num( layer.z ),
				opacity: num( layer.opacity, 1 ),
				locked: !! layer.locked,
				text: layer.text || null,
			};
		}

		var element = elementFor( id );
		if ( ! element ) {
			return null;
		}

		return {
			x: num( element.x ),
			y: num( element.y ),
			width: num( element.width ),
			height: num( element.height ),
			rotation: num( element.rotation ),
			z: num( element.z ),
			opacity: num( element.opacity, 1 ),
			locked: !! element.locked,
			text: null,
		};
	}

	/**
	 * Effective, non-geometric property: format override, then base element.
	 *
	 * @param {string} id  Element id.
	 * @param {string} key Property.
	 * @return {*} Value.
	 */
	function prop( id, key ) {
		var layout = state.document && state.document.layout && state.document.layout[ state.format ];
		var overrides = layout && layout.elements ? layout.elements[ id ] : null;

		if ( overrides && Object.prototype.hasOwnProperty.call( overrides, key ) ) {
			return overrides[ key ];
		}

		var element = elementFor( id );
		return element ? element[ key ] : undefined;
	}

	/**
	 * Which geometry keys are supplied by the format override for this format.
	 * Editing one of these must write into the override, not the base element,
	 * or the change would silently disappear on reload.
	 *
	 * @param {string} id Element id.
	 * @return {Object} Set of keys.
	 */
	function overriddenKeys( id ) {
		var layout = state.document && state.document.layout && state.document.layout[ state.format ];
		var overrides = layout && layout.elements ? layout.elements[ id ] : null;
		var out = {};

		if ( overrides ) {
			Object.keys( overrides ).forEach( function ( key ) {
				out[ key ] = true;
			} );
		}

		return out;
	}

	/**
	 * Write one property for an element in the active format.
	 *
	 * Geometry is normally written to the format override when the active format
	 * is not the document's base format (so other formats keep their layouts);
	 * the base format writes to the element itself. Text/style properties are
	 * shared unless the element already overrides them per format.
	 *
	 * @param {string}  id    Element id.
	 * @param {string}  key   Property.
	 * @param {*}       value Value.
	 * @param {Object}  opts  { toBase: bool }.
	 * @return {void}
	 */
	function writeProp( id, key, value, opts ) {
		opts = opts || {};

		var element = elementFor( id );
		if ( ! element ) {
			return;
		}

		var geometryKeys = { x: 1, y: 1, width: 1, height: 1, rotation: 1, z: 1 };
		var baseFormat = state.document && state.document.canvas ? String( state.document.canvas.format || 'square' ) : 'square';
		var isGeometry = !! geometryKeys[ key ];
		var target = 'element';

		if ( isGeometry && ! opts.toBase && state.format !== baseFormat ) {
			target = 'override';
		} else if ( ! isGeometry && overriddenKeys( id )[ key ] ) {
			target = 'override';
		}

		if ( target === 'override' ) {
			if ( ! state.document.layout ) {
				state.document.layout = {};
			}
			if ( ! state.document.layout[ state.format ] ) {
				state.document.layout[ state.format ] = { elements: {} };
			}
			if ( ! state.document.layout[ state.format ].elements ) {
				state.document.layout[ state.format ].elements = {};
			}
			if ( ! state.document.layout[ state.format ].elements[ id ] ) {
				state.document.layout[ state.format ].elements[ id ] = {};
			}
			state.document.layout[ state.format ].elements[ id ][ key ] = value;
		} else {
			element[ key ] = value;
		}

		// Keep the painted plan in step for local (non-server) updates.
		var layer = layerFor( id );
		if ( layer ) {
			if ( isGeometry || key === 'opacity' ) {
				layer[ key ] = value;
			} else if ( key === 'locked' ) {
				layer.locked = !! value;
			} else if ( layer.text && Object.prototype.hasOwnProperty.call( layer.text, key ) ) {
				layer.text[ key ] = value;
			}
		}

		markDirty();
	}

	function writeProps( id, patch, opts ) {
		Object.keys( patch ).forEach( function ( key ) {
			writeProp( id, key, patch[ key ], opts );
		} );
	}

	function markDirty() {
		state.dirty = true;
		refreshSaveState();
	}

	/* ── History ──────────────────────────────────────────────────── */

	function snapshot() {
		return {
			document: clone( state.document ),
			selection: state.selection.slice(),
			format: state.format,
			templateId: state.templateId,
			templateName: state.templateName,
		};
	}

	/**
	 * Record a history entry.
	 *
	 * `key` coalesces bursts of the same edit (typing in a field, nudging with
	 * the arrow keys) into one entry; a drag calls this once, on commit.
	 *
	 * @param {string} label Human label, used in the history menu.
	 * @param {string} key   Optional coalescing key.
	 * @return {void}
	 */
	function pushHistory( label, key ) {
		var now = Date.now();
		var history = state.history;

		if ( key && key === history.lastKey && now - history.lastAt < 1200 ) {
			history.lastAt = now;
			updateHistoryButtons();
			return;
		}

		history.past.push( { label: label, snapshot: snapshot(), at: now } );

		if ( history.past.length > history.limit ) {
			history.past.shift();
		}

		history.future.length = 0;
		history.lastKey = key || '';
		history.lastAt = now;

		updateHistoryButtons();
	}

	function restoreSnapshot( entry ) {
		state.document = clone( entry.snapshot.document );
		state.format = entry.snapshot.format;
		state.selection = ( entry.snapshot.selection || [] ).filter( function ( id ) {
			return !! elementFor( id );
		} );
		state.templateId = entry.snapshot.templateId;
		state.templateName = entry.snapshot.templateName;
		state.dirty = true;
	}

	function undo() {
		var history = state.history;

		if ( ! history.past.length ) {
			return;
		}

		var entry = history.past.pop();
		history.future.push( { label: entry.label, snapshot: snapshot(), at: Date.now() } );
		restoreSnapshot( entry );
		history.lastKey = '';

		afterHistoryMove( entry.label ? str( 'undoOf', 'Undid' ) + ': ' + entry.label : str( 'undone', 'Undone' ) );
	}

	function redo() {
		var history = state.history;

		if ( ! history.future.length ) {
			return;
		}

		var entry = history.future.pop();
		history.past.push( { label: entry.label, snapshot: snapshot(), at: Date.now() } );
		restoreSnapshot( entry );
		history.lastKey = '';

		afterHistoryMove( entry.label ? str( 'redoOf', 'Redid' ) + ': ' + entry.label : str( 'redone', 'Redone' ) );
	}

	function afterHistoryMove( message ) {
		updateHistoryButtons();
		refreshSaveState();
		syncFormatButtons();
		syncDocName();
		requestPlan( true ).then( function () {
			renderAll();
		} );
		if ( message ) {
			toast( message, 'info', { duration: 1800 } );
		}
	}

	function updateHistoryButtons() {
		if ( els.undo ) {
			els.undo.disabled = ! state.history.past.length;
		}
		if ( els.redo ) {
			els.redo.disabled = ! state.history.future.length;
		}
	}

	/* ── Element factories (schema-valid) ─────────────────────────── */

	function defaultText( overrides ) {
		return Object.assign(
			{
				id: '',
				type: 'text',
				name: 'Text',
				text: '',
				source: 'post_title',
				dynamic_key: '',
				fallback: '',
				x: 64,
				y: 640,
				width: Math.max( 120, canvasSize().width - 128 ),
				height: 0,
				rotation: 0,
				opacity: 1,
				visible: true,
				locked: false,
				z: 30,
				font_family: 'Noto Sans Bengali',
				font_size: 52,
				min_font_size: 18,
				max_font_size: 96,
				font_weight: 700,
				font_style: 'normal',
				line_height: 1.35,
				letter_spacing: 0,
				align: 'left',
				vertical_align: 'top',
				color: '#FFFFFF',
				background: '',
				padding_x: 0,
				padding_y: 0,
				border_radius: 0,
				border_width: 0,
				border_color: '#FFFFFF',
				shadow_color: 'rgba(0,0,0,0.45)',
				shadow_blur: 10,
				shadow_offset_x: 0,
				shadow_offset_y: 2,
				stroke_color: '',
				stroke_width: 0,
				max_lines: 4,
				auto_scale: true,
				ellipsis: true,
				uppercase: false,
				trim_words: 0,
				date_format: '',
				transform: 'none',
				conditions: [],
			},
			overrides || {}
		);
	}

	function defaultImage( overrides ) {
		return Object.assign(
			{
				id: '',
				type: 'image',
				name: 'Image',
				source: 'featured_image',
				url: '',
				asset_id: 0,
				x: 0,
				y: 0,
				width: canvasSize().width,
				height: Math.round( canvasSize().height * 0.55 ),
				rotation: 0,
				opacity: 1,
				visible: true,
				locked: false,
				z: 10,
				fit: 'cover',
				focus_x: 0.5,
				focus_y: 0.5,
				border_radius: 0,
				border_width: 0,
				border_color: '#FFFFFF',
				shadow_blur: 0,
				shadow_color: 'rgba(0,0,0,0.35)',
				fallback: 'gradient',
				natural_ratio: true,
				conditions: [],
			},
			overrides || {}
		);
	}

	function defaultShape( overrides ) {
		return Object.assign(
			{
				id: '',
				type: 'shape',
				name: 'Shape',
				shape: 'rect',
				x: 64,
				y: 520,
				width: 240,
				height: 8,
				rotation: 0,
				opacity: 1,
				visible: true,
				locked: false,
				z: 20,
				fill: '#E11D48',
				gradient_from: '',
				gradient_to: '',
				gradient_angle: 90,
				stroke_color: '',
				stroke_width: 0,
				border_radius: 4,
				conditions: [],
			},
			overrides || {}
		);
	}

	function canvasSize() {
		var canvas = state.document && state.document.canvas ? state.document.canvas : null;
		var preset = ( state.meta.formats || {} )[ state.format ] || {};

		if ( canvas && state.format === String( canvas.format ) ) {
			return { width: num( canvas.width, 1080 ), height: num( canvas.height, 1080 ) };
		}

		return {
			width: num( preset.width, canvas ? num( canvas.width, 1080 ) : 1080 ),
			height: num( preset.height, canvas ? num( canvas.height, 1080 ) : 1080 ),
		};
	}

	function safeArea() {
		var canvas = state.document && state.document.canvas ? state.document.canvas : {};
		var preset = ( state.meta.formats || {} )[ state.format ] || {};
		var safe = state.format === String( canvas.format ) ? canvas.safe_area : preset.safe;

		if ( ! safe ) {
			safe = preset.safe || { top: 64, right: 64, bottom: 64, left: 64 };
		}

		return {
			top: num( safe.top, 64 ),
			right: num( safe.right, 64 ),
			bottom: num( safe.bottom, 64 ),
			left: num( safe.left, 64 ),
		};
	}

	/* ═══ 3. Fonts, images, plan painting ════════════════════════ */

	/**
	 * Wait for the @font-face rules printed by the admin header. A canvas drawn
	 * before Bengali faces load silently falls back to a Latin face, which
	 * produces broken conjuncts — so this is a correctness step, not polish.
	 *
	 * @param {Array<string>} families Families to force-load.
	 * @return {Promise<void>} Resolves once fonts are ready (or after a timeout).
	 */
	async function ensureFonts( families ) {
		var list = families && families.length
			? families
			: [ 'Noto Sans Bengali', 'Noto Serif Bengali', 'Hind Siliguri' ];

		if ( ! document.fonts || ! document.fonts.load ) {
			state.fontsReady = true;
			return;
		}

		await Promise.race( [
			Promise.all(
				list.map( function ( family ) {
					return document.fonts.load( '400 32px "' + family + '"', 'বাংলা' ).catch( function () {
						if ( state.fontsFailed.indexOf( family ) === -1 ) {
							state.fontsFailed.push( family );
						}
						return null;
					} );
				} )
			),
			new Promise( function ( resolve ) {
				window.setTimeout( resolve, 4000 );
			} ),
		] );

		state.fontsReady = true;
	}

	function loadImage( url ) {
		if ( ! url ) {
			return Promise.resolve( null );
		}

		if ( state.imageCache.has( url ) ) {
			return state.imageCache.get( url );
		}

		var promise = new Promise( function ( resolve ) {
			var image = new Image();
			image.crossOrigin = 'anonymous';
			image.onload = function () {
				promise.__loaded = image;
				resolve( image );
			};
			image.onerror = function () {
				promise.__loaded = null;
				resolve( null );
			};
			image.src = url;
		} );

		state.imageCache.set( url, promise );

		return promise;
	}

	async function preloadPlanImages( plan ) {
		var urls = [];

		if ( plan.canvas && plan.canvas.background_image ) {
			urls.push( plan.canvas.background_image );
		}

		( plan.layers || [] ).forEach( function ( layer ) {
			if ( layer.image && layer.image.url ) {
				urls.push( layer.image.url );
			}
		} );

		await Promise.all( urls.map( loadImage ) );
	}

	/* ── Painting ─────────────────────────────────────────────────── */

	function paintCanvasBackground( ctx, plan ) {
		var config = plan.canvas || {};
		var width = plan.width;
		var height = plan.height;
		var hasContent = false;

		ctx.save();
		ctx.clearRect( 0, 0, width, height );

		// A design must never export with transparent pixels: paint the base
		// colour first, then whatever the background type asks for on top.
		var baseColour = isColour( config.background_color ) ? config.background_color : '#0F172A';

		applyColour( ctx, baseColour, '#0F172A' );
		ctx.fillRect( 0, 0, width, height );

		if ( config.background_type === 'image' && config.background_image ) {
			var cached = state.imageCache.get( config.background_image );
			var paintBackgroundImage = function ( image ) {
				if ( ! image ) {
					return;
				}
				if ( config.background_fit === 'contain' ) {
					drawContain( ctx, image, 0, 0, width, height );
				} else if ( config.background_fit === 'stretch' ) {
					ctx.drawImage( image, 0, 0, width, height );
				} else {
					drawCover( ctx, image, 0, 0, width, height );
				}
			};

			if ( cached && cached.__loaded !== undefined ) {
				paintBackgroundImage( cached.__loaded );
				hasContent = true;
			} else if ( cached ) {
				cached.then( function ( image ) {
					cached.__loaded = image || null;
					paintBackgroundImage( image );
					if ( els.canvas && state.plan && ! els.headless ) {
						scheduleRepaint();
					}
				} );
				hasContent = true;
			}
			if ( ! hasContent ) {
				applyColour( ctx, baseColour, '#0F172A' );
				ctx.fillRect( 0, 0, width, height );
			}
		} else if ( config.background_type === 'gradient' || ( ! config.background_type && config.gradient_from ) ) {
			var gradient = makeGradient(
				ctx,
				{ x: 0, y: 0, width: width, height: height },
				config.gradient_angle,
				config.gradient_from,
				config.gradient_to
			);

			// An unusable gradient keeps the base colour instead of going blank.
			if ( gradient ) {
				ctx.fillStyle = gradient;
				ctx.fillRect( 0, 0, width, height );
			}
			hasContent = true;
		}

		// Overlay: a real design element of the document, so it must export too.
		var overlay = config.overlay || {};
		if ( overlay.enabled && ( overlay.from || overlay.to ) ) {
			var overlayGradient = makeGradient(
				ctx,
				{ x: 0, y: 0, width: width, height: height },
				overlay.angle,
				overlay.from,
				overlay.to
			);

			if ( overlayGradient ) {
				ctx.fillStyle = overlayGradient;
				ctx.fillRect( 0, 0, width, height );
			}
		}

		ctx.restore();
	}

	function drawCover( ctx, image, x, y, width, height, focusX, focusY ) {
		var scale = Math.max( width / image.width, height / image.height );
		var drawWidth = image.width * scale;
		var drawHeight = image.height * scale;
		var offsetX = x + ( width - drawWidth ) * ( typeof focusX === 'number' ? focusX : 0.5 );
		var offsetY = y + ( height - drawHeight ) * ( typeof focusY === 'number' ? focusY : 0.5 );

		ctx.drawImage( image, offsetX, offsetY, drawWidth, drawHeight );
	}

	function drawContain( ctx, image, x, y, width, height ) {
		var scale = Math.min( width / image.width, height / image.height );
		var drawWidth = image.width * scale;
		var drawHeight = image.height * scale;

		ctx.drawImage( image, x + ( width - drawWidth ) / 2, y + ( height - drawHeight ) / 2, drawWidth, drawHeight );
	}

	function shapePath( ctx, kind, x, y, width, height ) {
		var w = Math.max( 0, width );
		var h = Math.max( 0, height );

		ctx.beginPath();

		if ( kind === 'circle' || kind === 'ellipse' ) {
			var rx = kind === 'circle' ? Math.min( w, h ) / 2 : w / 2;
			var ry = kind === 'circle' ? Math.min( w, h ) / 2 : h / 2;
			ctx.ellipse( x + w / 2, y + h / 2, Math.max( 0.5, rx ), Math.max( 0.5, ry ), 0, 0, Math.PI * 2 );
			return;
		}

		if ( kind === 'line' ) {
			ctx.moveTo( x, y + h / 2 );
			ctx.lineTo( x + w, y + h / 2 );
			return;
		}

		roundedRect( ctx, x, y, w, h, 0 );
	}

	function roundedRect( ctx, x, y, width, height, radius ) {
		var r = Math.min( radius || 0, width / 2, height / 2 );
		var w = Math.max( 0, width );
		var h = Math.max( 0, height );

		ctx.beginPath();
		ctx.moveTo( x + r, y );
		ctx.arcTo( x + w, y, x + w, y + h, r );
		ctx.arcTo( x + w, y + h, x, y + h, r );
		ctx.arcTo( x, y + h, x, y, r );
		ctx.arcTo( x, y, x + w, y, r );
		ctx.closePath();
	}

	/**
	 * Break text into lines that fit `maxWidth`.
	 *
	 * Bengali has no inter-word requirement for shaping, so wrapping on spaces
	 * is correct for headlines and much cheaper than measuring clusters.
	 *
	 * @param {CanvasRenderingContext2D} ctx      Context.
	 * @param {string}                   text     Text.
	 * @param {number}                   maxWidth Width budget.
	 * @param {number}                   maxLines Limit (0 = unlimited).
	 * @return {Array<string>} Lines.
	 */
	function wrapText( ctx, text, maxWidth, maxLines ) {
		var paragraphs = String( text ).split( /\r?\n/ );
		var lines = [];

		/**
		 * Break one word that is wider than the box.
		 *
		 * Bengali and URLs both produce long unspaced runs; without this a single
		 * word spills out of the safe area and can leave the design entirely.
		 *
		 * @param {string} word Unbreakable token.
		 * @return {string[]} Pieces that each fit.
		 */
		var breakWord = function ( word ) {
			var pieces = [];
			var piece = '';

			Array.from( word ).forEach( function ( character ) {
				var candidate = piece + character;

				if ( piece && measureLine( ctx, candidate ) > maxWidth ) {
					pieces.push( piece );
					piece = character;
					return;
				}

				piece = candidate;
			} );

			if ( piece ) {
				pieces.push( piece );
			}

			return pieces.length ? pieces : [ word ];
		};

		paragraphs.forEach( function ( paragraph ) {
			var words = paragraph.split( /\s+/ ).filter( Boolean );
			var current = '';

			if ( ! words.length ) {
				lines.push( '' );
				return;
			}

			words.forEach( function ( word ) {
				var candidate = current ? current + ' ' + word : word;

				if ( measureLine( ctx, candidate ) <= maxWidth ) {
					current = candidate;
					return;
				}

				if ( ! current ) {
					// The word cannot even start a line: break it by character.
					var pieces = breakWord( word );

					pieces.slice( 0, -1 ).forEach( function ( piece ) {
						lines.push( piece );
					} );
					current = pieces[ pieces.length - 1 ];
					return;
				}

				lines.push( current );

				if ( measureLine( ctx, word ) > maxWidth ) {
					var halves = breakWord( word );

					halves.slice( 0, -1 ).forEach( function ( piece ) {
						lines.push( piece );
					} );
					current = halves[ halves.length - 1 ];
					return;
				}

				current = word;
			} );

			if ( current ) {
				lines.push( current );
			}
		} );

		if ( maxLines > 0 && lines.length > maxLines ) {
			lines = lines.slice( 0, maxLines );
			lines[ lines.length - 1 ] = lines[ lines.length - 1 ].replace( /\s+\S*$/, '' ) + '…';
		}

		return lines;
	}

	/**
	 * Whether a value is a colour the canvas will accept.
	 *
	 * An invalid `fillStyle` is *silently ignored* by browsers, which leaves the
	 * previous style in place — so one bad value can paint an entire background
	 * grey. An invalid `addColorStop` throws instead, which would abort the whole
	 * paint. Every colour therefore goes through here first.
	 *
	 * @param {*} value Candidate colour.
	 * @return {boolean} True when the canvas can parse it.
	 */
	var colourCache = new Map();

	function isColour( value ) {
		var text = String( value === undefined || value === null ? '' : value ).trim();

		if ( '' === text ) {
			return false;
		}

		if ( colourCache.has( text ) ) {
			return colourCache.get( text );
		}

		var valid = false;

		try {
			// `CSS.supports` is the browser's own parser; fall back to assigning
			// to a throwaway context when it is unavailable.
			if ( window.CSS && typeof window.CSS.supports === 'function' ) {
				valid = window.CSS.supports( 'color', text );
			} else {
				// No CSS.supports: ask the canvas itself, twice with different
				// sentinels. An invalid assignment leaves the sentinel in place,
				// and "kept both sentinels" cannot be a real colour.
				var probe = document.createElement( 'canvas' ).getContext( '2d' );
				probe.fillStyle = '#010203';
				probe.fillStyle = text;
				var first = String( probe.fillStyle );
				probe.fillStyle = '#040506';
				probe.fillStyle = text;
				var second = String( probe.fillStyle );

				valid = ! ( first === '#010203' && second === '#040506' );
			}
		} catch ( error ) {
			valid = false;
		}

		// Gradients and patterns are not "colours", but they are valid fills.
		if ( ! valid && typeof value === 'object' ) {
			valid = true;
		}

		colourCache.set( text, valid );

		if ( colourCache.size > 500 ) {
			colourCache.clear();
		}

		return valid;
	}

	/**
	 * Use a colour only if the canvas can parse it.
	 *
	 * @param {CanvasRenderingContext2D} ctx        Context.
	 * @param {*}                        value      Candidate colour.
	 * @param {string}                   fallback   Colour to keep when invalid.
	 * @param {string}                   property   'fillStyle' | 'strokeStyle'.
	 * @return {boolean} True when the value was applied.
	 */
	function applyColour( ctx, value, fallback, property ) {
		var key = property || 'fillStyle';

		if ( isColour( value ) ) {
			ctx[ key ] = value;
			return true;
		}

		if ( fallback ) {
			ctx[ key ] = fallback;
		}

		return false;
	}

	/**
	 * Add a gradient stop only when the colour parses.
	 *
	 * @param {CanvasGradient} gradient Gradient.
	 * @param {number}         offset   Stop offset.
	 * @param {*}              colour   Stop colour.
	 * @param {string}         fallback Fallback colour.
	 * @return {boolean} True when the stop was added.
	 */
	function addStop( gradient, offset, colour, fallback ) {
		var value = isColour( colour ) ? colour : fallback;

		if ( ! isColour( value ) ) {
			return false;
		}

		try {
			gradient.addColorStop( Math.min( 1, Math.max( 0, num( offset ) ) ), value );
			return true;
		} catch ( error ) {
			return false;
		}
	}

	/**
	 * CSS gradient geometry for a box.
	 *
	 * CSS angles run clockwise from "to top"; canvas coordinates grow downwards.
	 * The line is clipped to the box the way CSS does it, so a 135° gradient
	 * looks the same in the preview and in the image.
	 *
	 * @param {string} angle CSS angle in degrees.
	 * @param {number} x     Box x.
	 * @param {number} y     Box y.
	 * @param {number} width Box width.
	 * @param {number} height Box height.
	 * @return {Object} { x0, y0, x1, y1 }.
	 */
	function gradientLine( angle, x, y, width, height ) {
		var degrees = num( angle, 180 );
		var radians = ( degrees * Math.PI ) / 180;
		var cx = x + width / 2;
		var cy = y + height / 2;
		var dx = Math.sin( radians );
		var dy = -Math.cos( radians );
		var length = Math.abs( width * dx ) + Math.abs( height * dy );
		var half = Math.max( 1, length ) / 2;

		return {
			x0: cx - dx * half,
			y0: cy - dy * half,
			x1: cx + dx * half,
			y1: cy + dy * half,
		};
	}

	/**
	 * Build a linear gradient for a box, or null when both stops are unusable.
	 *
	 * @param {CanvasRenderingContext2D} ctx    Context.
	 * @param {Object}                   box    { x, y, width, height }.
	 * @param {string}                   angle  CSS angle.
	 * @param {*}                        from   First colour.
	 * @param {*}                        to     Last colour.
	 * @return {CanvasGradient|null} Gradient.
	 */
	function makeGradient( ctx, box, angle, from, to ) {
		var line = gradientLine( angle, box.x, box.y, box.width, box.height );
		var gradient;

		try {
			gradient = ctx.createLinearGradient( line.x0, line.y0, line.x1, line.y1 );
		} catch ( error ) {
			return null;
		}

		var added = addStop( gradient, 0, from, '#000000' ) | addStop( gradient, 1, to, '#000000' );

		// `|` on booleans is intentional: both stops are attempted.
		return added ? gradient : null;
	}

	/**
	 * Extra stroke width applied when a requested weight has no real face.
	 *
	 * Synthetic bold drawn by the browser is inconsistent (some engines widen
	 * the glyphs, others ignore the request entirely), so the painter applies
	 * its own, and measures it, instead of hoping.
	 */
	var fauxBold = 0;

	/**
	 * Weights a family actually ships.
	 *
	 * @param {string} family Family name.
	 * @return {Array<number>} Sorted weights.
	 */
	function fontWeightsFor( family ) {
		var rows = ( state.meta && state.meta.fonts ) || ( typeof CPIC !== 'undefined' && CPIC.fonts ) || [];
		var weights = [];

		rows.forEach( function ( font ) {
			if ( font.family === family && font.weight ) {
				weights.push( num( font.weight ) );
			}
		} );

		weights = weights.filter( function ( weight, index ) {
			return weights.indexOf( weight ) === index;
		} ).sort( function ( a, b ) {
			return a - b;
		} );

		return weights.length ? weights : [ 400 ];
	}

	/**
	 * Nearest weight the family really ships.
	 *
	 * @param {string} family   Family name.
	 * @param {number} requested Requested weight.
	 * @return {number} Usable weight.
	 */
	function resolveWeight( family, requested ) {
		var weights = fontWeightsFor( family );
		var wanted = num( requested, 400 );
		var best = weights[ 0 ];

		weights.forEach( function ( weight ) {
			if ( Math.abs( weight - wanted ) < Math.abs( best - wanted ) ) {
				best = weight;
			}
		} );

		return best;
	}

	/**
	 * Width of one measured line, including any synthetic bold overhang.
	 *
	 * @param {CanvasRenderingContext2D} ctx  Context.
	 * @param {string}                   line Line.
	 * @return {number} Width in pixels.
	 */
	function measureLine( ctx, line ) {
		return ctx.measureText( line ).width + ( fauxBold ? fauxBold * 2 : 0 );
	}

	function applyTextFont( ctx, text, size ) {
		var family = text.font_family || 'Noto Sans Bengali';
		var requested = num( text.font_weight, 400 );
		var weight = resolveWeight( family, requested );

		ctx.font =
			( text.font_style || 'normal' ) + ' ' +
			weight + ' ' +
			size + 'px "' + family + '", "Noto Sans Bengali", sans-serif';
		ctx.letterSpacing = num( text.letter_spacing, 0 ) ? num( text.letter_spacing, 0 ) + 'px' : '0px';

		// Emulate the weight the family does not ship, so "Bold" always shows.
		fauxBold = requested >= 600 && weight < 600 ? Math.max( 0.5, size * 0.028 ) : 0;
	}

	/**
	 * Paint one text layer, shrinking type until the block fits its box.
	 *
	 * @param {CanvasRenderingContext2D} ctx   Context.
	 * @param {Object}                   layer Layer.
	 * @return {void}
	 */
	/**
	 * What the last paint actually did, per layer.
	 *
	 * Diagnostics ("nothing is rendering") need facts, not guesses: this
	 * records the type size finally used, the lines drawn, whether a block
	 * overflowed its box, and the colours that could not be parsed. It is reset
	 * by every `paint()` and exposed on `CPICStudio.lastReport`.
	 *
	 * @type {Object}
	 */
	var paintReport = { width: 0, height: 0, layers: [], warnings: [], startedAt: 0, ms: 0 };

	function reportLayer( layer, extra ) {
		var row = {
			id: layer.id,
			type: layer.type,
			x: num( layer.x ),
			y: num( layer.y ),
			width: num( layer.width ),
			height: num( layer.height ),
			z: num( layer.z ),
			opacity: num( layer.opacity, 1 ),
		};

		Object.keys( extra || {} ).forEach( function ( key ) {
			row[ key ] = extra[ key ];
		} );

		paintReport.layers.push( row );

		return row;
	}

	/**
	 * Surface anything the last live paint could not draw faithfully.
	 *
	 * Silent problems are the worst kind: a missing featured image or type that
	 * had to shrink to nothing should be visible before the user exports.
	 *
	 * @return {void}
	 */
	function showRenderWarnings() {
		if ( els.headless || ! els.canvasMeta ) {
			return;
		}

		var host = els.canvasMeta.closest( '.cpic-workspace__status' ) || els.canvasMeta;
		var existing = document.getElementById( 'cpic-render-warnings' );

		if ( ! paintReport.warnings.length ) {
			if ( existing ) {
				existing.remove();
			}
			els.canvasMeta.classList.remove( 'is-warning' );
			return;
		}

		if ( ! existing ) {
			existing = document.createElement( 'span' );
			existing.id = 'cpic-render-warnings';
			existing.className = 'cpic-render-warnings';
			host.appendChild( existing );
		}

		existing.textContent = paintReport.warnings.length === 1
			? paintReport.warnings[ 0 ]
			: paintReport.warnings.length + ' layers need attention: ' + paintReport.warnings[ paintReport.warnings.length - 1 ];

		existing.title = paintReport.warnings.join( '\n' );
		els.canvasMeta.classList.add( 'is-warning' );
	}

	function warnRender( message ) {
		if ( paintReport.warnings.indexOf( message ) === -1 ) {
			paintReport.warnings.push( message );
		}
	}

	function paintText( ctx, layer ) {
		var text = layer.text || {};
		var value = String( text.value || '' ).trim();

		if ( ! value ) {
			reportLayer( layer, { drawn: false, reason: 'empty' } );
			return;
		}

		if ( text.uppercase ) {
			value = value.toUpperCase();
		}

		var paddingX = num( text.padding_x );
		var paddingY = num( text.padding_y );
		var boxWidth = Math.max( 1, num( layer.width ) - paddingX * 2 );
		var boxHeight = num( layer.height ) > 0 ? num( layer.height ) : 1e6;
		var maxLines = num( text.max_lines );

		var size = num( text.font_size, 40 );
		var minSize = Math.max( 6, num( text.min_font_size, Math.max( 10, size * 0.4 ) ) );
		var maxSize = Math.max( size, num( text.max_font_size, size ) );
		var lineHeightFactor = num( text.line_height, 1.3 );
		var lines;
		var lineHeight;
		var totalHeight;

		/**
		 * How well the text fits its box at the current size.
		 *
		 * Both failures matter: a block taller than the box is clipped, and a
		 * block that needs more lines than `max_lines` is truncated.
		 *
		 * @return {Object} { lines, height, overflow, overLines }.
		 */
		var fitAt = function ( candidate ) {
			applyTextFont( ctx, text, candidate );

			var candidateLines = wrapText( ctx, value, boxWidth, maxLines );
			var candidateHeight = candidateLines.length * lineHeightFactor * candidate;
			var widest = 0;

			candidateLines.forEach( function ( line ) {
				widest = Math.max( widest, measureLine( ctx, line ) );
			} );

			return {
				lines: candidateLines,
				height: candidateHeight,
				widest: widest,
				// A single word wider than the box cannot be wrapped.
				tooWide: widest > boxWidth + 1 && candidate > minSize,
				overflow: candidateHeight > boxHeight + 1,
			};
		};

		var fit = fitAt( size );

		// Auto-scale: grow while the block still fits its box (width included).
		// Without a box there is nothing to grow *into*: an auto-height layer
		// keeps the size it was authored at, otherwise a 23px date would be
		// blown up to max_font_size and pushed off the canvas.
		if ( text.auto_scale && boxHeight !== 1e6 && boxHeight !== Infinity && size < maxSize ) {
			var grown = size;

			for ( var candidateSize = size + 2; candidateSize <= maxSize; candidateSize += 2 ) {
				var trial = fitAt( candidateSize );

				if ( trial.overflow || trial.tooWide ) {
					break;
				}

				grown = candidateSize;
			}

			if ( grown !== size ) {
				size = grown;
				fit = fitAt( size );
			}
		}

		// Shrink until the block fits its box, down to the minimum size.
		while ( size > minSize && ( fit.overflow || fit.tooWide ) ) {
			size = Math.max( minSize, size - 2 );
			fit = fitAt( size );
		}

		applyTextFont( ctx, text, size );
		lines = fit.lines;
		lineHeight = lineHeightFactor * size;
		totalHeight = lines.length * lineHeight;

		var boxX = num( layer.x );
		var boxY = num( layer.y );
		var radius = num( text.border_radius );

		// Vertical alignment inside the element box.
		var innerTop = boxY;
		if ( num( layer.height ) > totalHeight + paddingY * 2 ) {
			if ( text.vertical_align === 'middle' || text.vertical_align === 'center' ) {
				innerTop = boxY + ( num( layer.height ) - totalHeight ) / 2;
			} else if ( text.vertical_align === 'bottom' ) {
				innerTop = boxY + num( layer.height ) - totalHeight - paddingY;
			}
		}

		var plateWidth = boxWidth + paddingX * 2;
		var plateHeight = totalHeight + paddingY * 2;

		if ( text.background || text.background_gradient_from ) {
			ctx.save();

			var plate = makeGradient(
				ctx,
				{ x: boxX, y: innerTop - paddingY, width: plateWidth, height: plateHeight },
				text.background_gradient_angle,
				text.background_gradient_from,
				text.background_gradient_to
			);

			// "linear-gradient(...)" typed into a plain colour field degrades to
			// a single colour rather than to nothing.
			if ( plate ) {
				ctx.fillStyle = plate;
			} else {
				applyColour( ctx, text.background, 'rgba(0,0,0,0)' );
			}

			roundedRect( ctx, boxX, innerTop - paddingY, plateWidth, plateHeight, radius );
			ctx.fill();
			ctx.restore();
		}

		if ( num( text.border_width ) > 0 && isColour( text.border_color ) ) {
			ctx.save();
			ctx.strokeStyle = text.border_color;
			ctx.lineWidth = num( text.border_width );
			roundedRect( ctx, boxX, innerTop - paddingY, plateWidth, plateHeight, radius );
			ctx.stroke();
			ctx.restore();
		}

		ctx.save();
		ctx.textBaseline = 'top';

		var textX = boxX + paddingX;

		if ( text.align === 'center' ) {
			ctx.textAlign = 'center';
			textX = boxX + boxWidth / 2;
		} else if ( text.align === 'right' ) {
			ctx.textAlign = 'right';
			textX = boxX + boxWidth;
		} else {
			ctx.textAlign = 'left';
		}

		if ( isColour( text.shadow_color ) && ( num( text.shadow_blur ) > 0 || num( text.shadow_offset_x ) || num( text.shadow_offset_y ) ) ) {
			ctx.shadowColor = text.shadow_color;
			ctx.shadowBlur = num( text.shadow_blur );
			ctx.shadowOffsetX = num( text.shadow_offset_x );
			ctx.shadowOffsetY = num( text.shadow_offset_y );
		}

		lines.forEach( function ( line, index ) {
			var y = innerTop + index * lineHeight;

			// The stroke sits behind the fill so counters stay legible.
			if ( num( text.stroke_width ) > 0 && isColour( text.stroke_color ) ) {
				ctx.lineWidth = num( text.stroke_width );
				ctx.strokeStyle = text.stroke_color;
				ctx.lineJoin = 'round';
				ctx.strokeText( line, textX, y );
			}

			applyColour( ctx, text.color, '#FFFFFF' );

			if ( fauxBold ) {
				ctx.strokeStyle = ctx.fillStyle;
				ctx.lineWidth = fauxBold * 2;
				ctx.lineJoin = 'round';
				ctx.strokeText( line, textX, y );
			}

			ctx.fillText( line, textX, y );
		} );

		var overflowY = num( layer.height ) > 0 ? innerTop + totalHeight + paddingY - ( num( layer.y ) + num( layer.height ) ) : 0;

		reportLayer( layer, {
			drawn: true,
			lines: lines.length,
			fontSize: size,
			requestedSize: num( text.font_size, 40 ),
			lineHeight: lineHeight,
			blockHeight: totalHeight,
			overflowY: Math.max( 0, overflowY ),
			shrunk: size < num( text.font_size, 40 ),
			bold: fauxBold > 0,
			widest: fit.widest || 0,
			aligned: text.align || 'left',
			textBoxWidth: boxWidth,
			bottom: innerTop + totalHeight,
			truncated: Boolean( text.max_lines && fit.lines.length >= num( text.max_lines ) ),
		} );

		ctx.restore();
	}

	var scheduleRepaint = debounce( function () {
		if ( els.canvas && state.plan && ! els.headless ) {
			raf( function () {
				paint( state.plan );
			} );
		}
	}, 60 );

	function paintShape( ctx, layer ) {
		var shape = layer.shape || {};
		var kind = shape.shape || 'rect';
		var x = num( layer.x );
		var y = num( layer.y );
		var width = Math.max( 0, num( layer.width ) );
		var height = Math.max( 0, num( layer.height ) );

		ctx.save();

		if ( shape.shadow_blur > 0 && shape.shadow_color ) {
			ctx.shadowColor = shape.shadow_color;
			ctx.shadowBlur = num( shape.shadow_blur );
		}

		if ( kind === 'line' ) {
			// A rule can be defined by thickness or by the box height.
			var thickness = Math.max( 1, num( shape.stroke_width ) || height || 1 );

			reportLayer( layer, { drawn: true, shapeKind: 'line', thickness: thickness } );

			applyColour( ctx, shape.stroke_color || shape.fill, '#FFFFFF', 'strokeStyle' );
			ctx.lineWidth = thickness;
			ctx.lineCap = 'butt';
			ctx.beginPath();
			ctx.moveTo( x, y + Math.max( height, thickness ) / 2 );
			ctx.lineTo( x + width, y + Math.max( height, thickness ) / 2 );
			ctx.stroke();
			ctx.restore();
			return;
		}

		if ( kind === 'circle' || kind === 'ellipse' ) {
			ctx.beginPath();
			var rx = ( kind === 'circle' ? Math.min( width, height ) : width ) / 2;
			var ry = ( kind === 'circle' ? Math.min( width, height ) : height ) / 2;
			ctx.ellipse( x + width / 2, y + height / 2, Math.max( 0.5, rx ), Math.max( 0.5, ry ), 0, 0, Math.PI * 2 );
		} else {
			roundedRect( ctx, x, y, width, height, num( shape.border_radius ) );
		}

		var gradient = null;

		if ( shape.gradient_from || shape.gradient_to ) {
			gradient = makeGradient(
				ctx,
				{ x: x, y: y, width: width, height: height },
				shape.gradient_angle,
				shape.gradient_from,
				shape.gradient_to
			);
		}

		if ( gradient ) {
			ctx.fillStyle = gradient;
		} else {
			applyColour( ctx, shape.fill, '#FFFFFF' );
		}

		ctx.fill();

		if ( num( shape.stroke_width ) > 0 ) {
			applyColour( ctx, shape.stroke_color, '#FFFFFF', 'strokeStyle' );
			ctx.lineWidth = num( shape.stroke_width );
			ctx.stroke();
		}

		reportLayer( layer, { drawn: true, shapeKind: kind, gradient: Boolean( gradient ) } );

		ctx.restore();
	}

	function paintPlaceholder( ctx, layer, image ) {
		if ( image.fallback === 'hide' ) {
			return;
		}

		ctx.save();

		var x = num( layer.x );
		var y = num( layer.y );
		var width = Math.max( 1, num( layer.width ) );
		// An auto-height logo box has no height until the image is known.
		var height = num( layer.height ) > 0 ? num( layer.height ) : Math.max( 24, width * 0.4 );

		if ( image.fallback === 'blur' ) {
			ctx.fillStyle = 'rgba(15, 23, 42, 0.55)';
			roundedRect( ctx, x, y, width, height, num( image.border_radius ) );
			ctx.fill();
			ctx.restore();
			return;
		}

		var gradient = ctx.createLinearGradient( x, y, x + width, y + height );
		gradient.addColorStop( 0, 'rgba(148, 163, 184, 0.55)' );
		gradient.addColorStop( 1, 'rgba(71, 85, 105, 0.35)' );
		ctx.fillStyle = gradient;
		roundedRect( ctx, x, y, width, height, num( image.border_radius ) );
		ctx.fill();

		// The dashed outline is editor chrome, not artwork: it marks "no image
		// yet" while you are working, and must never appear in the exported
		// PNG that gets published.
		if ( ! els.headless ) {
			ctx.strokeStyle = 'rgba(255, 255, 255, 0.35)';
			ctx.lineWidth = Math.max( 1, width / 300 );
			ctx.setLineDash( [ 12, 10 ] );
			roundedRect( ctx, x + 1, y + 1, Math.max( 0, width - 2 ), Math.max( 0, height - 2 ), num( image.border_radius ) );
			ctx.stroke();
			ctx.setLineDash( [] );
		}

		ctx.restore();

		reportLayer( layer, { drawn: true, placeholder: true, chrome: ! els.headless } );
		warnRender( 'Image layer “' + ( layer.name || layer.id ) + '” has no image; a fallback was exported.' );
	}

	/**
	 * Paint an image layer.
	 *
	 * When the image is already decoded it is drawn synchronously, so an export
	 * can never capture a half-painted canvas. A cold cache falls back to a
	 * promise and one repaint when it resolves.
	 *
	 * @param {CanvasRenderingContext2D} ctx   Context.
	 * @param {Object}                   layer Layer.
	 * @return {boolean} True when the image was drawn immediately.
	 */
	function paintImage( ctx, layer ) {
		var image = layer.image || {};
		var cached = image.url ? state.imageCache.get( image.url ) : null;

		if ( ! cached ) {
			paintPlaceholder( ctx, layer, image );
			return false;
		}

		var drawn = false;
		var x = num( layer.x );
		var y = num( layer.y );
		var width = Math.max( 1, num( layer.width ) );
		// An auto-height box (`natural_ratio`) is sized from the image itself.
		var height = num( layer.height ) > 0
			? num( layer.height )
			: ( cached && cached.__loaded ? width * ( cached.__loaded.height / Math.max( 1, cached.__loaded.width ) ) : width );

		var radius = num( image.border_radius );
		// A border must not be clipped away by its own clip path.
		var borderWidth = num( image.border_width );
		var inset = borderWidth > 0 ? borderWidth / 2 : 0;
		var stroke = function () {
			if ( borderWidth > 0 && isColour( image.border_color ) ) {
				ctx.save();
				ctx.strokeStyle = image.border_color;
				ctx.lineWidth = borderWidth;
				roundedRect( ctx, x + inset, y + inset, Math.max( 0, width - inset * 2 ), Math.max( 0, height - inset * 2 ), Math.max( 0, radius - inset ) );
				ctx.stroke();
				ctx.restore();
			}
		};

		var draw = function ( loaded ) {
			if ( ! loaded ) {
				return;
			}

			ctx.save();

			// Re-apply the clip: a promise may resolve after the context state
			// has been restored by the caller.
			roundedRect( ctx, x, y, width, height, radius );
			ctx.clip();

			if ( num( image.shadow_blur ) > 0 && isColour( image.shadow_color ) ) {
				ctx.shadowColor = image.shadow_color;
				ctx.shadowBlur = num( image.shadow_blur );
			}

			if ( image.fit === 'contain' ) {
				drawContain( ctx, loaded, x, y, width, height );
			} else if ( image.fit === 'fill' ) {
				ctx.drawImage( loaded, x, y, width, height );
			} else if ( image.fit === 'none' ) {
				ctx.drawImage( loaded, x, y, loaded.width, loaded.height );
			} else {
				drawCover( ctx, loaded, x, y, width, height, num( image.focus_x, 0.5 ), num( image.focus_y, 0.5 ) );
			}

			ctx.restore();
		};

		if ( cached.__loaded !== undefined ) {
			draw( cached.__loaded );
			drawn = true;
		} else {
			cached.then( function ( loaded ) {
				cached.__loaded = loaded || null;

				if ( ! loaded ) {
					return;
				}

				draw( loaded );

				// Interactive canvases repaint once the image arrives; an export
				// awaits preloadPlanImages() first, so it never needs this.
				if ( els.canvas && state.plan && ! els.headless ) {
					scheduleRepaint();
				}
			} );
		}

		stroke();

		if ( drawn ) {
			reportLayer( layer, { drawn: true, image: true, fit: image.fit || 'cover', boxHeight: height } );
		} else {
			// The image is still loading; in an export this means it never
			// arrived in time.
			reportLayer( layer, { drawn: false, reason: 'image-pending' } );
			warnRender( 'Image layer “' + ( layer.name || layer.id ) + '” had not finished loading when the design was drawn.' );
		}

		return drawn;
	}

	/**
	 * Paint a render plan into the design canvas at full document resolution.
	 *
	 * The canvas is always the document's pixel size (never the on-screen
	 * size); the viewport scales it with CSS, so the preview and the exported
	 * PNG are produced by identical drawing code.
	 *
	 * @param {Object} plan Render plan.
	 * @return {Promise<void>}
	 */
	async function paint( plan ) {
		var canvas = els.canvas;

		if ( ! canvas || ! plan ) {
			return;
		}

		// Interactive use re-syncs the backing store; a headless call (export,
		// block editor) has already sized its own canvas.
		if ( ! els.headless && ( canvas.width !== num( plan.width ) || canvas.height !== num( plan.height ) ) ) {
			canvas.width = num( plan.width, 1080 );
			canvas.height = num( plan.height, 1080 );
		}

		var ctx = canvas.getContext( '2d' );

		ctx.setTransform( 1, 0, 0, 1, 0, 0 );
		ctx.clearRect( 0, 0, canvas.width, canvas.height );

		await ensureFonts( plan.fonts );
		await preloadPlanImages( plan );

		paintReport = {
			width: num( plan.width, canvas.width ),
			height: num( plan.height, canvas.height ),
			layers: [],
			warnings: [],
			headless: Boolean( els.headless ),
			startedAt: Date.now(),
			ms: 0,
		};

		paintCanvasBackground( ctx, plan );

		var layers = ( plan.layers || [] ).slice().sort( function ( a, b ) {
			return num( a.z ) - num( b.z );
		} );

		for ( var index = 0; index < layers.length; index++ ) {
			var layer = layers[ index ];

			// Hidden layers never reach the plan; the editor draws a skipped
			// layer only when the user explicitly asked for hidden layers.
			ctx.save();
			ctx.globalAlpha = clamp( num( layer.opacity, 1 ), 0, 1 );

			if ( num( layer.rotation ) ) {
				var cx = num( layer.x ) + num( layer.width ) / 2;
				var cy = num( layer.y ) + num( layer.height ) / 2;
				ctx.translate( cx, cy );
				ctx.rotate( ( num( layer.rotation ) * Math.PI ) / 180 );
				ctx.translate( -cx, -cy );
			}

			try {
				if ( layer.type === 'text' ) {
					paintText( ctx, layer );
				} else if ( layer.type === 'shape' || layer.type === 'gradient' || layer.type === 'pattern' ) {
					paintShape( ctx, layer );
				} else if ( layer.image ) {
					paintImage( ctx, layer );
				}
			} catch ( error ) {
				// One broken layer must not blank the whole canvas.
				window.console && window.console.warn( 'CPIC: layer failed', layer.id, error );
				reportLayer( layer, { drawn: false, reason: 'error', error: String( error && error.message ) } );
				warnRender( 'A layer could not be painted: ' + layer.id );
			}

			ctx.restore();
		}

		paintReport.ms = Date.now() - paintReport.startedAt;



		if ( els.canvasMeta ) {
			els.canvasMeta.textContent = plan.width + ' × ' + plan.height + ' px · ' + state.format;
		}

		showRenderWarnings();
	}

	/**
	 * Paint hidden layers faintly so the editor can still see what it is
	 * missing, without affecting the exported PNG (which uses the plan).
	 *
	 * @param {CanvasRenderingContext2D} ctx Overlay context.
	 * @return {void}
	 */
	function paintHiddenLayers( ctx ) {
		var hidden = Object.keys( elements() ).filter( function ( id ) {
			return elementFor( id ) && elementFor( id ).visible === false;
		} );

		if ( ! hidden.length ) {
			return;
		}

		hidden.forEach( function ( id ) {
			var geo = geometry( id );
			if ( ! geo ) {
				return;
			}

			ctx.save();
			ctx.globalAlpha = 0.35;
			ctx.setLineDash( [ 6, 6 ] );
			ctx.strokeStyle = 'rgba(100, 116, 139, 0.75)';
			ctx.lineWidth = 1.5 / state.zoom;
			ctx.strokeRect( geo.x, geo.y, Math.max( geo.width, 6 ), Math.max( geo.height, 6 ) );
			ctx.restore();
		} );
	}

	/* ═══ 4. Viewport: zoom, pan, rulers, overlay ════════════════ */

	var HANDLE_SIZE = 9;
	var RULER_SIZE = 22;
	var overlayFrame = null;
	var guides = [];

	function zoomLimits() {
		return { min: 0.05, max: 4 };
	}

	function setZoom( next, options ) {
		options = options || {};

		var limits = zoomLimits();
		var previous = state.zoom;
		var zoom = clamp( next, limits.min, limits.max );

		if ( Math.abs( zoom - previous ) < 0.0001 ) {
			return;
		}

		state.zoom = zoom;
		state.fitMode = false;

		// Keep the anchor point (canvas coordinate) under the same screen point.
		if ( options.anchor && els.viewport ) {
			var viewportRect = els.viewport.getBoundingClientRect();
			var ax = options.anchor.x - viewportRect.left;
			var ay = options.anchor.y - viewportRect.top;
			state.panX = ax - ( ax - state.panX ) * ( zoom / previous );
			state.panY = ay - ( ay - state.panY ) * ( zoom / previous );
		}

		applyViewportTransform();
		scheduleOverlay();
		syncZoomLabel();
	}

	function fitToScreen() {
		if ( ! els.viewport ) {
			return;
		}

		var rect = els.viewport.getBoundingClientRect();
		var size = canvasSize();
		var padding = state.showRulers ? 56 : 40;
		var scale = Math.min(
			( rect.width - padding * 2 ) / Math.max( 1, size.width ),
			( rect.height - padding * 2 ) / Math.max( 1, size.height )
		);

		state.zoom = clamp( scale, zoomLimits().min, zoomLimits().max );
		state.fitMode = true;
		state.panX = 0;
		state.panY = 0;

		applyViewportTransform();
		scheduleOverlay();
		syncZoomLabel();
	}

	function actualSize() {
		state.fitMode = false;
		state.panX = 0;
		state.panY = 0;
		state.zoom = 1;
		applyViewportTransform();
		scheduleOverlay();
		syncZoomLabel();
	}

	/**
	 * Apply zoom and pan.
	 *
	 * The canvas keeps its document pixel size and is scaled with one CSS
	 * transform on the scaler wrapper, so zooming never re-rasterises (and
	 * never changes) the rendered design.
	 *
	 * @return {void}
	 */
	function applyViewportTransform() {
		if ( ! els.canvas ) {
			return;
		}

		var size = canvasSize();

		els.canvas.style.width = size.width + 'px';
		els.canvas.style.height = size.height + 'px';

		if ( refs.scaler ) {
			refs.scaler.style.width = size.width + 'px';
			refs.scaler.style.height = size.height + 'px';
			refs.scaler.style.transform =
				'translate(' + round( state.panX, 2 ) + 'px,' + round( state.panY, 2 ) + 'px) scale(' + state.zoom + ')';
		}

		if ( els.frame ) {
			els.frame.style.setProperty( '--cpic-inv-zoom', String( 1 / Math.max( 0.05, state.zoom ) ) );
		}

		if ( els.selectionToolbar ) {
			els.selectionToolbar.style.setProperty( '--cpic-inv-zoom', String( 1 / Math.max( 0.05, state.zoom ) ) );
		}

		var overlay = document.getElementById( 'cpic-overlay-canvas' );
		if ( overlay ) {
			var dpr = window.devicePixelRatio || 1;
			overlay.style.width = size.width + 'px';
			overlay.style.height = size.height + 'px';
			overlay.width = Math.round( size.width * dpr );
			overlay.height = Math.round( size.height * dpr );
		}
	}

	function syncZoomLabel() {
		if ( els.zoomValue ) {
			els.zoomValue.textContent = Math.round( state.zoom * 100 ) + '%';
		}
	}

	/**
	 * Convert a pointer event into document (canvas pixel) coordinates.
	 *
	 * @param {Event}  event Pointer/mouse event.
	 * @param {Object} opts  { clampToCanvas: bool }.
	 * @return {Object} { x, y }.
	 */
	function toCanvas( event, opts ) {
		opts = opts || {};
		var rect = els.canvas.getBoundingClientRect();
		var size = canvasSize();
		var x = ( ( event.clientX - rect.left ) / Math.max( 1, rect.width ) ) * size.width;
		var y = ( ( event.clientY - rect.top ) / Math.max( 1, rect.height ) ) * size.height;

		if ( opts.clampToCanvas ) {
			x = clamp( x, 0, size.width );
			y = clamp( y, 0, size.height );
		}

		return { x: x, y: y };
	}

	/* ── Rulers ───────────────────────────────────────────────────── */

	function drawRulers() {
		if ( ! state.showRulers || ! refs.rulerH || ! refs.rulerV ) {
			return;
		}

		var size = canvasSize();
		var dpr = window.devicePixelRatio || 1;
		var rect = els.viewport.getBoundingClientRect();
		var canvasRect = els.canvas.getBoundingClientRect();

		[
			[ refs.rulerH, rect.width - RULER_SIZE, RULER_SIZE, size.width, 'h' ],
			[ refs.rulerV, RULER_SIZE, rect.height - RULER_SIZE, size.height, 'v' ],
		].forEach( function ( setup ) {
			var canvas = setup[ 0 ];
			var width = Math.max( 1, setup[ 1 ] );
			var height = Math.max( 1, setup[ 2 ] );
			var span = setup[ 3 ];
			var axis = setup[ 4 ];
			var offset = axis === 'h' ? canvasRect.left - rect.left - RULER_SIZE : canvasRect.top - rect.top - RULER_SIZE;
			var visible = axis === 'h' ? canvasRect.width : canvasRect.height;
			var scale = visible / Math.max( 1, span );

			canvas.width = Math.round( width * dpr );
			canvas.height = Math.round( height * dpr );
			canvas.style.width = width + 'px';
			canvas.style.height = height + 'px';

			var ctx = canvas.getContext( '2d' );
			ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
			ctx.clearRect( 0, 0, width, height );

			ctx.fillStyle = '#f8fafc';
			ctx.fillRect( 0, 0, width, height );

			// Choose a tick step that keeps labels readable.
			var candidates = [ 10, 25, 50, 100, 200, 250, 500, 1000 ];
			var step = candidates[ candidates.length - 1 ];
			for ( var c = 0; c < candidates.length; c++ ) {
				if ( candidates[ c ] * scale >= 56 ) {
					step = candidates[ c ];
					break;
				}
			}

			ctx.strokeStyle = '#cbd5e1';
			ctx.fillStyle = '#64748b';
			ctx.font = '10px system-ui, -apple-system, Segoe UI, sans-serif';
			ctx.lineWidth = 1;
			ctx.textBaseline = 'top';

			var docLength = span;
			for ( var value = 0; value <= docLength + 0.5; value += step / 2 ) {
				var pos = offset + value * scale;

				if ( pos < -20 || pos > ( axis === 'h' ? width : height ) ) {
					continue;
				}

				var major = Math.abs( value % step ) < 0.001;

				ctx.beginPath();

				if ( axis === 'h' ) {
					ctx.moveTo( Math.round( pos ) + 0.5, major ? height - 9 : height - 5 );
					ctx.lineTo( Math.round( pos ) + 0.5, height );
					ctx.stroke();

					if ( major ) {
						ctx.fillText( String( Math.round( value ) ), Math.round( pos ) + 3, 3 );
					}
				} else {
					ctx.moveTo( major ? width - 9 : width - 5, Math.round( pos ) + 0.5 );
					ctx.lineTo( width, Math.round( pos ) + 0.5 );
					ctx.stroke();

					if ( major ) {
						ctx.save();
						ctx.translate( 3, Math.round( pos ) + 3 );
						ctx.rotate( Math.PI / 2 );
						ctx.textBaseline = 'top';
						ctx.fillText( String( Math.round( value ) ), 0, 0 );
						ctx.restore();
					}
				}
			}

			ctx.strokeStyle = '#e2e8f0';
			ctx.beginPath();
			if ( axis === 'h' ) {
				ctx.moveTo( 0, height - 0.5 );
				ctx.lineTo( width, height - 0.5 );
			} else {
				ctx.moveTo( width - 0.5, 0 );
				ctx.lineTo( width - 0.5, height );
			}
			ctx.stroke();
		} );
	}

	/* ── Overlay (selection, handles, guides, grid) ───────────────── */

	var scheduleOverlay = debounce( function () {
		if ( overlayFrame ) {
			window.cancelAnimationFrame( overlayFrame );
		}
		overlayFrame = raf( drawOverlay );
	}, 8 );

	function selectedGeometryList() {
		return state.selection
			.map( function ( id ) {
				var geo = geometry( id );
				if ( ! geo ) {
					return null;
				}
				return Object.assign( { id: id }, geo );
			} )
			.filter( Boolean );
	}

	function unionBounds( list ) {
		if ( ! list.length ) {
			return null;
		}

		var minX = Infinity;
		var minY = Infinity;
		var maxX = -Infinity;
		var maxY = -Infinity;

		list.forEach( function ( geo ) {
			minX = Math.min( minX, geo.x );
			minY = Math.min( minY, geo.y );
			maxX = Math.max( maxX, geo.x + Math.max( geo.width, 1 ) );
			maxY = Math.max( maxY, geo.y + Math.max( geo.height, 1 ) );
		} );

		return { x: minX, y: minY, width: maxX - minX, height: maxY - minY };
	}

	/**
	 * Handles for the current selection, in document coordinates.
	 *
	 * @param {Object} bounds Bounding box.
	 * @return {Array<Object>} Handles.
	 */
	function handlesFor( bounds ) {
		var list = [
			[ 'nw', bounds.x, bounds.y ],
			[ 'n', bounds.x + bounds.width / 2, bounds.y ],
			[ 'ne', bounds.x + bounds.width, bounds.y ],
			[ 'e', bounds.x + bounds.width, bounds.y + bounds.height / 2 ],
			[ 'se', bounds.x + bounds.width, bounds.y + bounds.height ],
			[ 's', bounds.x + bounds.width / 2, bounds.y + bounds.height ],
			[ 'sw', bounds.x, bounds.y + bounds.height ],
			[ 'w', bounds.x, bounds.y + bounds.height / 2 ],
		];

		return list.map( function ( item ) {
			return { name: item[ 0 ], x: item[ 1 ], y: item[ 2 ] };
		} );
	}

	function rotationHandleFor( bounds ) {
		return { name: 'rotate', x: bounds.x + bounds.width / 2, y: bounds.y - 26 / Math.max( 0.35, state.zoom ) };
	}

	function drawOverlay() {
		overlayFrame = null;

		var overlay = document.getElementById( 'cpic-overlay-canvas' );
		if ( ! overlay || ! state.plan ) {
			return;
		}

		var size = canvasSize();
		var dpr = window.devicePixelRatio || 1;
		var ctx = overlay.getContext( '2d' );

		if ( overlay.width !== Math.round( size.width * dpr ) ) {
			overlay.width = Math.round( size.width * dpr );
			overlay.height = Math.round( size.height * dpr );
		}

		ctx.setTransform( dpr, 0, 0, dpr, 0, 0 );
		ctx.clearRect( 0, 0, size.width, size.height );

		var px = 1 / Math.max( 0.0001, state.zoom ); // one screen pixel in document units
		var accent = '#4f46e5';

		// Grid
		if ( state.showGrid ) {
			var step = 1080 / 12;
			for ( var gx = 0; gx <= size.width + 0.5; gx += step ) {
				ctx.fillStyle = gx % ( step * 2 ) === 0 ? 'rgba(79,70,229,0.20)' : 'rgba(79,70,229,0.10)';
				ctx.fillRect( gx, 0, Math.max( px, 1 ), size.height );
			}
			for ( var gy = 0; gy <= size.height + 0.5; gy += step ) {
				ctx.fillStyle = gy % ( step * 2 ) === 0 ? 'rgba(79,70,229,0.20)' : 'rgba(79,70,229,0.10)';
				ctx.fillRect( 0, gy, size.width, Math.max( px, 1 ) );
			}
		}

		// Safe area (editor-only guide)
		if ( state.showSafe ) {
			var safe = safeArea();
			ctx.save();
			ctx.setLineDash( [ 8 * px, 6 * px ] );
			ctx.strokeStyle = 'rgba(16, 185, 129, 0.85)';
			ctx.lineWidth = Math.max( px, 1 );
			ctx.strokeRect(
				safe.left,
				safe.top,
				Math.max( 0, size.width - safe.left - safe.right ),
				Math.max( 0, size.height - safe.top - safe.bottom )
			);
			ctx.restore();

			// Dim the region outside the safe area, subtly.
			ctx.save();
			ctx.fillStyle = 'rgba(15, 23, 42, 0.06)';
			ctx.fillRect( 0, 0, size.width, safe.top );
			ctx.fillRect( 0, size.height - safe.bottom, size.width, safe.bottom );
			ctx.fillRect( 0, safe.top, safe.left, Math.max( 0, size.height - safe.top - safe.bottom ) );
			ctx.fillRect( size.width - safe.right, safe.top, safe.right, Math.max( 0, size.height - safe.top - safe.bottom ) );
			ctx.restore();
		}

		paintHiddenLayers( ctx );

		if ( state.interaction && state.interaction.type === 'marquee' ) {
			var a = state.interaction.start;
			var b = state.interaction.current || state.interaction.start;
			var boxX = Math.min( a.x, b.x );
			var boxY = Math.min( a.y, b.y );

			ctx.save();
			ctx.fillStyle = 'rgba(79, 70, 229, 0.10)';
			ctx.strokeStyle = 'rgba(79, 70, 229, 0.85)';
			ctx.lineWidth = Math.max( px, 1 );
			ctx.fillRect( boxX, boxY, Math.abs( b.x - a.x ), Math.abs( b.y - a.y ) );
			ctx.strokeRect( boxX, boxY, Math.abs( b.x - a.x ), Math.abs( b.y - a.y ) );
			ctx.restore();
		}

		var list = selectedGeometryList();
		var bounds = unionBounds( list );

		if ( ! list.length ) {
			drawGuides( ctx, px );
			return;
		}

		// Individual outlines for each selected element, plus one shared box.
		list.forEach( function ( geo ) {
			ctx.save();
			ctx.strokeStyle = accent;
			ctx.lineWidth = Math.max( px * 1.5, 1.5 );
			ctx.strokeRect( geo.x, geo.y, Math.max( geo.width, 1 ), Math.max( geo.height, 1 ) );
			ctx.restore();
		} );

		if ( list.length > 1 ) {
			ctx.save();
			ctx.setLineDash( [ 6 * px, 4 * px ] );
			ctx.strokeStyle = 'rgba(79,70,229,0.9)';
			ctx.lineWidth = Math.max( px, 1 );
			ctx.strokeRect( bounds.x, bounds.y, Math.max( bounds.width, 1 ), Math.max( bounds.height, 1 ) );
			ctx.restore();
		}

		var locked = list.some( function ( geo ) {
			return geo.locked;
		} );

		var handleSize = clamp( HANDLE_SIZE / state.zoom, 6 / state.zoom, 200 );
		var handleFill = locked ? '#94a3b8' : '#ffffff';

		if ( ! locked ) {
			handlesFor( bounds ).forEach( function ( handle ) {
				drawHandle( ctx, handle.x, handle.y, handleSize, handleFill, accent, px );
			} );

			var rotate = rotationHandleFor( bounds );
			ctx.save();
			ctx.strokeStyle = accent;
			ctx.lineWidth = Math.max( px, 1 );
			ctx.beginPath();
			ctx.moveTo( bounds.x + bounds.width / 2, bounds.y );
			ctx.lineTo( rotate.x, rotate.y + handleSize );
			ctx.stroke();

			ctx.beginPath();
			ctx.arc( rotate.x, rotate.y + handleSize / 2, handleSize * 0.62, 0, Math.PI * 2 );
			ctx.fillStyle = '#ffffff';
			ctx.fill();
			ctx.stroke();
			ctx.restore();

			ctx.save();
			ctx.fillStyle = accent;
			ctx.beginPath();
			ctx.arc( rotate.x, rotate.y + handleSize / 2, handleSize * 0.22, 0, Math.PI * 2 );
			ctx.fill();
			ctx.restore();
		} else {
			ctx.save();
			ctx.setLineDash( [ 5 * px, 4 * px ] );
			ctx.strokeStyle = '#94a3b8';
			ctx.lineWidth = Math.max( px, 1 );
			ctx.strokeRect( bounds.x - 4 * px, bounds.y - 4 * px, bounds.width + 8 * px, bounds.height + 8 * px );
			ctx.restore();
		}

		// Selection size label.
		var label = Math.round( bounds.width ) + ' × ' + Math.round( bounds.height );
		ctx.save();
		ctx.font = '600 ' + Math.max( 10, 11 * px ) + 'px system-ui, -apple-system, Segoe UI, sans-serif';
		var textWidth = ctx.measureText( label ).width;
		var labelX = bounds.x + bounds.width / 2 - textWidth / 2 - 6 * px;
		var labelY = bounds.y + bounds.height + 8 * px;
		ctx.fillStyle = accent;
		roundedRect( ctx, labelX, labelY, textWidth + 12 * px, 20 * px, 4 * px );
		ctx.fill();
		ctx.fillStyle = '#ffffff';
		ctx.textBaseline = 'middle';
		ctx.fillText( label, labelX + 6 * px, labelY + 10 * px );
		ctx.restore();

		drawGuides( ctx, px );
	}

	function drawHandle( ctx, x, y, size, fill, stroke, px ) {
		ctx.save();
		ctx.fillStyle = fill;
		ctx.strokeStyle = stroke;
		ctx.lineWidth = Math.max( px * 1.5, 1.5 );
		ctx.beginPath();
		ctx.rect( x - size / 2, y - size / 2, size, size );
		ctx.fill();
		ctx.stroke();
		ctx.restore();
	}

	function drawGuides( ctx, px ) {
		if ( ! guides.length ) {
			return;
		}

		ctx.save();
		ctx.strokeStyle = '#ec4899';
		ctx.lineWidth = Math.max( px, 1 );
		ctx.setLineDash( [ 5 * px, 4 * px ] );

		guides.forEach( function ( guide ) {
			ctx.beginPath();

			if ( guide.axis === 'x' ) {
				ctx.moveTo( guide.at, guide.from );
				ctx.lineTo( guide.at, guide.to );
			} else {
				ctx.moveTo( guide.from, guide.at );
				ctx.lineTo( guide.to, guide.at );
			}

			ctx.stroke();
		} );

		ctx.restore();
	}

	/* ── Panning ──────────────────────────────────────────────────── */

	function beginPan( event ) {
		if ( state.interaction ) {
			return;
		}

		state.interaction = {
			type: 'pan',
			startX: event.clientX,
			startY: event.clientY,
			panX: state.panX,
			panY: state.panY,
		};

		els.viewport.classList.add( 'is-panning' );
	}

	function updatePan( event ) {
		var interaction = state.interaction;
		if ( ! interaction || interaction.type !== 'pan' ) {
			return;
		}

		state.panX = interaction.panX + ( event.clientX - interaction.startX );
		state.panY = interaction.panY + ( event.clientY - interaction.startY );
		state.fitMode = false;

		raf( function () {
			applyViewportTransform();
			scheduleOverlay();
		} );
	}

	function endPan() {
		if ( state.interaction && state.interaction.type === 'pan' ) {
			state.interaction = null;
		}
		if ( els.viewport ) {
			els.viewport.classList.remove( 'is-panning' );
		}
		scheduleOverlay();
	}

	function bindViewport() {
		if ( ! els.viewport ) {
			return;
		}

		// Ctrl/⌘ + wheel zooms around the pointer; plain wheel pans.
		els.viewport.addEventListener(
			'wheel',
			function ( event ) {
				if ( event.ctrlKey || event.metaKey ) {
					event.preventDefault();
					var factor = Math.exp( -event.deltaY * 0.0025 );
					setZoom( state.zoom * factor, { anchor: { x: event.clientX, y: event.clientY } } );
					return;
				}

				// Plain wheel pans the workspace. Shift already arrives as deltaX
				// from most browsers; fall back to the vertical delta.
				event.preventDefault();
				state.panX -= event.deltaX || ( event.shiftKey ? event.deltaY : 0 );
				state.panY -= event.shiftKey ? 0 : event.deltaY;
				state.fitMode = false;
				applyViewportTransform();
				scheduleOverlay();
			},
			{ passive: false }
		);

		// Middle mouse button pans.
		els.viewport.addEventListener( 'pointerdown', function ( event ) {
			if ( event.button === 1 ) {
				event.preventDefault();
				beginPan( event );
			}
		} );

		window.addEventListener( 'pointermove', function ( event ) {
			if ( state.interaction && ( state.interaction.type === 'pan' ) ) {
				updatePan( event );
			}
		} );

		window.addEventListener( 'pointerup', function () {
			endPan();
		} );

		window.addEventListener( 'resize', debounce( function () {
			if ( state.fitMode ) {
				fitToScreen();
			} else {
				applyViewportTransform();
				scheduleOverlay();
			}
			drawRulers();
		}, 120 ) );
	}

	/* ═══ 5. Canvas interaction ══════════════════════════════════ */

	function elementIsLocked( id ) {
		var element = elementFor( id );
		return ! element || !! element.locked;
	}

	function elementIsHidden( id ) {
		var element = elementFor( id );
		return ! element || element.visible === false;
	}

	/**
	 * Plan layers, topmost first. Hidden elements are not in the plan, so a
	 * hidden layer can never be picked up by a canvas click.
	 *
	 * @return {Array<Object>} Layers.
	 */
	function hitCandidates() {
		return ( ( state.plan && state.plan.layers ) || [] )
			.slice()
			.sort( function ( a, b ) {
				return num( b.z ) - num( a.z );
			} );
	}

	/**
	 * A layer's clickable height.
	 *
	 * Auto-height text layers carry `height: 0` in the plan (the renderer sizes
	 * them from the glyphs), so the editor needs a usable box or such a layer
	 * can never be clicked, dragged or double-clicked.
	 *
	 * @param {Object} layer Plan layer.
	 * @return {number} Height in document pixels.
	 */
	function hitHeight( layer ) {
		var height = num( layer.height );

		if ( height > 0 ) {
			return height;
		}

		var text = layer.text || {};
		var fontSize = num( text.font_size, 40 );
		var lines = Math.max( 1, Math.min( 12, num( text.max_lines, 3 ) ) );
		var padding = num( text.padding_y, 0 ) * 2;

		return Math.max( 24, fontSize * num( text.line_height, 1.35 ) * lines + padding );
	}

	function pointInLayer( point, layer ) {
		var x = num( layer.x );
		var y = num( layer.y );
		var w = Math.max( num( layer.width ), 6 );
		var h = Math.max( hitHeight( layer ), 6 );

		if ( num( layer.rotation ) ) {
			var cx = x + w / 2;
			var cy = y + h / 2;
			var angle = ( -num( layer.rotation ) * Math.PI ) / 180;
			var dx = point.x - cx;
			var dy = point.y - cy;
			var rx = dx * Math.cos( angle ) - dy * Math.sin( angle );
			var ry = dx * Math.sin( angle ) + dy * Math.cos( angle );
			return Math.abs( rx ) <= w / 2 && Math.abs( ry ) <= h / 2;
		}

		return point.x >= x && point.x <= x + w && point.y >= y && point.y <= y + h;
	}

	function hitTest( point ) {
		var candidates = hitCandidates();

		for ( var i = 0; i < candidates.length; i++ ) {
			if ( pointInLayer( point, candidates[ i ] ) && ! elementIsLocked( candidates[ i ].id ) ) {
				return candidates[ i ];
			}
		}

		return null;
	}

	/**
	 * Which handle (if any) is under a document-space point.
	 *
	 * @param {Object} point Document point.
	 * @return {string|null} Handle name.
	 */
	function handleAt( point ) {
		var bounds = unionBounds( selectedGeometryList() );

		if ( ! bounds || state.selection.some( elementIsLocked ) ) {
			return null;
		}

		var tolerance = clamp( 9 / state.zoom, 3, 400 );

		var rotate = rotationHandleFor( bounds );
		if ( Math.abs( point.x - rotate.x ) <= tolerance && Math.abs( point.y - ( rotate.y + tolerance * 0.6 ) ) <= tolerance ) {
			return 'rotate';
		}

		var handles = handlesFor( bounds );
		for ( var i = 0; i < handles.length; i++ ) {
			if ( Math.abs( point.x - handles[ i ].x ) <= tolerance && Math.abs( point.y - handles[ i ].y ) <= tolerance ) {
				return handles[ i ].name;
			}
		}

		return null;
	}

	function cursorForHandle( handle ) {
		var map = {
			nw: 'nwse-resize',
			se: 'nwse-resize',
			ne: 'nesw-resize',
			sw: 'nesw-resize',
			n: 'ns-resize',
			s: 'ns-resize',
			e: 'ew-resize',
			w: 'ew-resize',
			rotate: 'grab',
		};

		return map[ handle ] || 'default';
	}

	/* ── Snapping ─────────────────────────────────────────────────── */

	function snapTargets( excludeIds ) {
		var size = canvasSize();
		var safe = safeArea();
		var vertical = [ 0, size.width / 2, size.width, safe.left, size.width - safe.right ];
		var horizontal = [ 0, size.height / 2, size.height, safe.top, size.height - safe.bottom ];

		Object.keys( elements() ).forEach( function ( id ) {
			if ( excludeIds.indexOf( id ) !== -1 || elementIsHidden( id ) ) {
				return;
			}

			var geo = geometry( id );
			if ( ! geo ) {
				return;
			}

			vertical.push( geo.x, geo.x + geo.width / 2, geo.x + geo.width );
			horizontal.push( geo.y, geo.y + geo.height / 2, geo.y + geo.height );
		} );

		if ( state.showGrid ) {
			var step = size.width / 12;
			for ( var i = 1; i < 12; i++ ) {
				vertical.push( step * i );
			}
			var stepY = size.height / 12;
			for ( var j = 1; j < 12; j++ ) {
				horizontal.push( stepY * j );
			}
		}

		return { vertical: vertical, horizontal: horizontal };
	}

	/**
	 * Snap a moving bounding box.
	 *
	 * @param {Object} box      { x, y, width, height }.
	 * @param {Array}  exclude  Selected ids.
	 * @param {number} threshold In document units.
	 * @return {Object} { dx, dy, guides }.
	 */
	function snapBox( box, exclude, threshold ) {
		var targets = snapTargets( exclude );
		var edgesX = [ { kind: 'left', at: box.x }, { kind: 'center', at: box.x + box.width / 2 }, { kind: 'right', at: box.x + box.width } ];
		var edgesY = [ { kind: 'top', at: box.y }, { kind: 'middle', at: box.y + box.height / 2 }, { kind: 'bottom', at: box.y + box.height } ];
		var bestX = null;
		var bestY = null;
		var out = { dx: 0, dy: 0, guides: [] };

		edgesX.forEach( function ( edge ) {
			targets.vertical.forEach( function ( target ) {
				var delta = target - edge.at;
				if ( Math.abs( delta ) <= threshold && ( ! bestX || Math.abs( delta ) < Math.abs( bestX.delta ) ) ) {
					bestX = { delta: delta, at: target };
				}
			} );
		} );

		edgesY.forEach( function ( edge ) {
			targets.horizontal.forEach( function ( target ) {
				var delta = target - edge.at;
				if ( Math.abs( delta ) <= threshold && ( ! bestY || Math.abs( delta ) < Math.abs( bestY.delta ) ) ) {
					bestY = { delta: delta, at: target };
				}
			} );
		} );

		var size = canvasSize();

		if ( bestX ) {
			out.dx = bestX.delta;
			out.guides.push( { axis: 'x', at: bestX.at, from: 0, to: size.height } );
		}

		if ( bestY ) {
			out.dy = bestY.delta;
			out.guides.push( { axis: 'y', at: bestY.at, from: 0, to: size.width } );
		}

		return out;
	}

	/* ── Commit helpers ───────────────────────────────────────────── */

	/**
	 * Write a geometry patch for one element into the correct storage slot.
	 *
	 * @param {string} id    Element id.
	 * @param {Object} patch Geometry patch.
	 * @return {void}
	 */
	function commitGeometry( id, patch, options ) {
		options = options || {};

		if ( ! options.gesture ) {
			pushHistory( options.label || str( 'editGeometry', 'Edit position' ), 'geom-' + id );
		}

		Object.keys( patch ).forEach( function ( key ) {
			var value = patch[ key ];
			if ( key === 'x' || key === 'y' || key === 'width' || key === 'height' ) {
				value = Math.max( 0, Math.min( 6000, round( value, 2 ) ) );
			} else if ( key === 'rotation' ) {
				value = round( clamp( value, -360, 360 ), 2 );
			} else if ( key === 'z' ) {
				value = clamp( Math.round( value ), 0, 999 );
			}

			writeProp( id, key, value );
		} );

		// A gesture commits several elements at once: the caller owns the
		// single history entry, repaint and plan request (see finishGesture).
		if ( options.gesture ) {
			return;
		}

		// Repaint from the document immediately (no network wait), then ask the
		// server for the authoritative plan exactly once.
		repaintPreview( serializeDocument() );
		scheduleOverlay();
		positionSelectionToolbar();

		requestPlan( true ).then( function () {
			renderLayers();
			refreshLiveGeometry();
		} );
	}

	function geometryOfElement( element ) {
		var id = element.id;
		var geo = geometry( id ) || { x: 0, y: 0, width: 0, height: 0, z: 0 };

		if ( geo.width <= 0 ) {
			geo.width = 200;
		}
		if ( geo.height <= 0 ) {
			geo.height = 100;
		}

		return geo;
	}

	/* ── Pointer interaction on the canvas ────────────────────────── */

	/**
	 * Pinch-to-zoom and two-finger pan for touch/pen-first devices.
	 *
	 * Single-finger drawing is left to the pointer handlers; this only claims
	 * gestures once a second finger lands.
	 *
	 * @param {HTMLElement} canvas Canvas element.
	 * @return {void}
	 */
	function bindTouchGestures( canvas ) {
		if ( ! canvas || ! window.PointerEvent ) {
			return;
		}

		var pointers = {};
		var gesture = null;

		var distance = function ( a, b ) {
			return Math.hypot( a.x - b.x, a.y - b.y );
		};

		canvas.addEventListener( 'pointerdown', function ( event ) {
			if ( event.pointerType === 'mouse' ) {
				return;
			}

			pointers[ event.pointerId ] = { x: event.clientX, y: event.clientY };

			var ids = Object.keys( pointers );

			if ( ids.length === 2 ) {
				var a = pointers[ ids[ 0 ] ];
				var b = pointers[ ids[ 1 ] ];
				gesture = {
					startDistance: Math.max( 1, distance( a, b ) ),
					startZoom: state.zoom,
					startPanX: state.panX,
					startPanY: state.panY,
					midX: ( a.x + b.x ) / 2,
					midY: ( a.y + b.y ) / 2,
				};

				// A pinch cancels whatever single-finger gesture was starting.
				state.interaction = null;
				els.viewport.classList.add( 'is-panning' );
			}
		} );

		canvas.addEventListener( 'pointermove', function ( event ) {
			if ( event.pointerType === 'mouse' || ! pointers[ event.pointerId ] ) {
				return;
			}

			pointers[ event.pointerId ] = { x: event.clientX, y: event.clientY };

			var ids = Object.keys( pointers );

			if ( ! gesture || ids.length !== 2 ) {
				return;
			}

			var a = pointers[ ids[ 0 ] ];
			var b = pointers[ ids[ 1 ] ];
			var midX = ( a.x + b.x ) / 2;
			var midY = ( a.y + b.y ) / 2;

			state.zoom = clamp( gesture.startZoom * ( distance( a, b ) / gesture.startDistance ), zoomLimits().min, zoomLimits().max );
			state.panX = gesture.startPanX + ( midX - gesture.midX );
			state.panY = gesture.startPanY + ( midY - gesture.midY );
			state.fitMode = false;

			raf( function () {
				applyViewportTransform();
				scheduleOverlay();
				syncZoomLabel();
			} );
		} );

		var release = function ( event ) {
			delete pointers[ event.pointerId ];

			if ( Object.keys( pointers ).length < 2 ) {
				gesture = null;
				els.viewport.classList.remove( 'is-panning' );
			}
		};

		canvas.addEventListener( 'pointerup', release );
		canvas.addEventListener( 'pointercancel', release );
	}

	function bindCanvasInteraction() {
		var canvas = els.canvas;
		if ( ! canvas ) {
			return;
		}

		canvas.addEventListener( 'pointerdown', onCanvasPointerDown );
		canvas.addEventListener( 'dblclick', onCanvasDoubleClick );
		canvas.addEventListener( 'contextmenu', onCanvasContextMenu );

		window.addEventListener( 'pointermove', onGlobalPointerMove );
		window.addEventListener( 'pointerup', onGlobalPointerUp );

		// Hover cursor feedback for handles.
		canvas.addEventListener( 'pointermove', function ( event ) {
			if ( state.interaction || state.tool === 'pan' || state.spacePanning ) {
				return;
			}

			var handle = handleAt( toCanvas( event ) );
			canvas.style.cursor = handle ? cursorForHandle( handle ) : ( hitTest( toCanvas( event ) ) ? 'move' : 'default' );
		} );

		bindTouchGestures( canvas );
	}

	function onCanvasPointerDown( event ) {
		if ( event.button === 2 ) {
			return;
		}

		closeMenus();

		var point = toCanvas( event );

		// Pan: the tool, the space bar, or the middle button.
		if ( state.spacePanning || state.tool === 'pan' ) {
			event.preventDefault();
			beginPan( event );
			return;
		}

		var handle = handleAt( point );

		if ( handle ) {
			startTransform( handle, point, event );
			return;
		}

		var layer = hitTest( point );

		if ( ! layer ) {
			if ( ! event.shiftKey ) {
				state.selection = [];
				renderSelectionState();
			}
			startMarquee( point, event );
			return;
		}

		var additive = event.shiftKey || event.metaKey || event.ctrlKey;

		if ( additive ) {
			toggleSelection( layer.id );
		} else if ( state.selection.indexOf( layer.id ) === -1 ) {
			state.selection = [ layer.id ];
		}

		renderSelectionState();

		if ( state.selection.indexOf( layer.id ) === -1 ) {
			return;
		}

		startMove( point, event );
	}

	function startMove( point, event ) {
		var list = state.selection
			.map( function ( id ) {
				var geo = geometry( id );
				return geo && ! elementIsLocked( id ) ? { id: id, start: { x: geo.x, y: geo.y } } : null;
			} )
			.filter( Boolean );

		if ( ! list.length ) {
			return;
		}

		var bounds = unionBounds( list.map( function ( item ) {
			var geo = geometry( item.id );
			return Object.assign( { id: item.id }, geo );
		} ) );

		state.interaction = {
			type: 'move',
			start: point,
			items: list,
			bounds: bounds,
			moved: false,
			lastSnap: { dx: 0, dy: 0 },
			event: event,
			historyPushed: false,
		};

		try {
			els.canvas.setPointerCapture( event.pointerId );
		} catch ( error ) {
			// Pointer capture is a nicety; the window listeners still work.
		}
	}

	function startTransform( handle, point, event ) {
		var list = selectedGeometryList().filter( function ( geo ) {
			return ! geo.locked;
		} );

		if ( ! list.length ) {
			return;
		}

		var bounds = unionBounds( list );

		state.interaction = {
			type: handle === 'rotate' ? 'rotate' : 'resize',
			handle: handle,
			start: point,
			bounds: bounds,
			items: list.map( function ( geo ) {
				var cx = bounds.x + bounds.width / 2;
				var cy = bounds.y + bounds.height / 2;
				return {
					id: geo.id,
					x: geo.x,
					y: geo.y,
					width: geo.width,
					height: geo.height,
					rotation: geo.rotation,
					relX: geo.x - cx,
					relY: geo.y - cy,
					ratio: geo.width / Math.max( 1, geo.height ),
				};
			} ),
			moved: false,
			event: event,
			center: { x: bounds.x + bounds.width / 2, y: bounds.y + bounds.height / 2 },
			startAngle: 0,
		};

		if ( handle === 'rotate' ) {
			state.interaction.startAngle = Math.atan2( point.y - state.interaction.center.y, point.x - state.interaction.center.x ) * ( 180 / Math.PI );
		}

		try {
			els.canvas.setPointerCapture( event.pointerId );
		} catch ( error ) {
			// Ignored.
		}
	}

	function startMarquee( point, event ) {
		state.interaction = {
			type: 'marquee',
			start: point,
			current: point,
			event: event,
			baseSelection: ( event.shiftKey || event.metaKey ? state.selection.slice() : [] ),
		};
	}

	function onGlobalPointerMove( event ) {
		var interaction = state.interaction;

		if ( ! interaction || interaction.type === 'pan' ) {
			return;
		}

		var point = toCanvas( event, { clampToCanvas: false } );

		if ( interaction.type === 'move' ) {
			updateMove( point, event );
		} else if ( interaction.type === 'resize' || interaction.type === 'rotate' ) {
			updateTransform( point, event );
		} else if ( interaction.type === 'marquee' ) {
			interaction.current = point;
			scheduleOverlay();
			drawMarquee();
		}
	}

	function updateMove( point, event ) {
		var interaction = state.interaction;
		var dx = point.x - interaction.start.x;
		var dy = point.y - interaction.start.y;

		if ( event.shiftKey && Math.abs( dx ) > Math.abs( dy ) ) {
			dy = 0;
		} else if ( event.shiftKey ) {
			dx = 0;
		}

		if ( Math.abs( dx ) > 0.4 || Math.abs( dy ) > 0.4 ) {
			if ( ! interaction.moved ) {
				pushHistory( str( 'move', 'Move' ), 'move' );
				interaction.historyPushed = true;
			}
			interaction.moved = true;
		}

		guides = [];

		if ( state.snap ) {
			var box = {
				x: interaction.bounds.x + dx,
				y: interaction.bounds.y + dy,
				width: interaction.bounds.width,
				height: interaction.bounds.height,
			};
			var snap = snapBox( box, state.selection, 7 / state.zoom );
			dx += snap.dx;
			dy += snap.dy;
			guides = snap.guides;
		}

		interaction.lastMove = { dx: dx, dy: dy };

		raf( function () {
			interaction.items.forEach( function ( item ) {
				var nx = item.start.x + dx;
				var ny = item.start.y + dy;

				var layer = layerFor( item.id );
				if ( layer ) {
					layer.x = nx;
					layer.y = ny;
				}

				// The document is written once on commit, so a drag produces
				// exactly one history entry and one plan request.
			} );

			repaintPreview();
			refreshLiveGeometry();
		} );
	}

	function updateTransform( point, event ) {
		var interaction = state.interaction;

		if ( ! interaction.moved ) {
			pushHistory( interaction.type === 'rotate' ? str( 'rotate', 'Rotate' ) : str( 'resize', 'Resize' ), interaction.type );
			interaction.moved = true;
		}

		if ( interaction.type === 'rotate' ) {
			var angle = Math.atan2( point.y - interaction.center.y, point.x - interaction.center.x ) * ( 180 / Math.PI );
			var rotation = angle - interaction.startAngle;
			var primary = interaction.items[ 0 ];
			var base = primary.rotation || 0;
			var next = base + rotation;

			if ( event.shiftKey || state.snap ) {
				var step = 15;
				var snapped = Math.round( next / step ) * step;
				if ( Math.abs( snapped - next ) < 4 ) {
					next = snapped;
				}
			}

			interaction.lastRotation = next;

			raf( function () {
				interaction.items.forEach( function ( item ) {
					// Rotate as a group: every member keeps its relative place
					// by rotating around the shared centre.
					var theta = ( rotation * Math.PI ) / 180;
					var rx = item.relX * Math.cos( theta ) - item.relY * Math.sin( theta );
					var ry = item.relX * Math.sin( theta ) + item.relY * Math.cos( theta );
					var geo = { x: 0, y: 0 };
					geo.x = interaction.center.x + rx - item.width / 2;
					geo.y = interaction.center.y + ry - item.height / 2;

					var layer = layerFor( item.id );
					if ( layer ) {
						layer.x = geo.x;
						layer.y = geo.y;
						layer.rotation = ( item.rotation || 0 ) + rotation;
					}
				} );

				repaintPreview();
			} );

			return;
		}

		var handle = interaction.handle;
		var dx = point.x - interaction.start.x;
		var dy = point.y - interaction.start.y;
		var bounds = interaction.bounds;
		var keepRatio = event.shiftKey;
		var next = { x: bounds.x, y: bounds.y, width: bounds.width, height: bounds.height };

		if ( handle.indexOf( 'e' ) !== -1 ) {
			next.width = Math.max( 8, bounds.width + dx );
		}
		if ( handle.indexOf( 's' ) !== -1 ) {
			next.height = Math.max( 8, bounds.height + dy );
		}
		if ( handle.indexOf( 'w' ) !== -1 ) {
			next.width = Math.max( 8, bounds.width - dx );
			next.x = bounds.x + ( bounds.width - next.width );
		}
		if ( handle.indexOf( 'n' ) !== -1 ) {
			next.height = Math.max( 8, bounds.height - dy );
			next.y = bounds.y + ( bounds.height - next.height );
		}

		// Corner handles preserve the aspect ratio with Shift.
		if ( keepRatio && handle.length === 2 && bounds.width > 0 && bounds.height > 0 ) {
			var ratio = bounds.width / bounds.height;
			if ( Math.abs( next.width - bounds.width ) >= Math.abs( next.height - bounds.height ) ) {
				next.height = next.width / ratio;
			} else {
				next.width = next.height * ratio;
			}
			if ( handle.indexOf( 'w' ) !== -1 ) {
				next.x = bounds.x + bounds.width - next.width;
			}
			if ( handle.indexOf( 'n' ) !== -1 ) {
				next.y = bounds.y + bounds.height - next.height;
			}
		}

		next.width = clamp( next.width, 8, canvasSize().width * 2 );
		next.height = clamp( next.height, 8, canvasSize().height * 2 );
		next.x = clamp( next.x, -next.width, canvasSize().width );
		next.y = clamp( next.y, -next.height, canvasSize().height );

		interaction.lastResize = next;

		var scaleX = bounds.width > 0 ? next.width / bounds.width : 1;
		var scaleY = bounds.height > 0 ? next.height / bounds.height : 1;

		raf( function () {
			interaction.items.forEach( function ( item ) {
				var layer = layerFor( item.id );
				if ( ! layer ) {
					return;
				}

				var nx = next.x + ( item.x - bounds.x ) * scaleX;
				var ny = next.y + ( item.y - bounds.y ) * scaleY;
				var nw = Math.max( 8, item.width * scaleX );
				var nh = Math.max( 1, item.height * scaleY );

				layer.x = nx;
				layer.y = ny;
				layer.width = nw;
				layer.height = nh;

				if ( item.id === interaction.items[ 0 ].id && layer.text ) {
					// Scale type with the box so a resized headline stays
					// proportional, exactly like the plan would on re-request.
					layer.text.font_size = Math.max( 6, num( layer.text.font_size ) * scaleY );
					layer.text.max_font_size = Math.max( layer.text.font_size, num( layer.text.max_font_size ) * scaleY );
				}
			} );

			repaintPreview();
			refreshLiveGeometry();
		} );
	}

	function drawMarquee() {
		// The marquee is drawn by drawOverlay(); this only requests a frame.
		scheduleOverlay();
	}

	function onGlobalPointerUp( event ) {
		var interaction = state.interaction;

		if ( interaction && interaction.type === 'pan' ) {
			endPan();
			return;
		}

		if ( ! interaction ) {
			return;
		}

		state.interaction = null;
		guides = [];

		try {
			if ( els.canvas.releasePointerCapture && event && els.canvas.hasPointerCapture && els.canvas.hasPointerCapture( event.pointerId ) ) {
				els.canvas.releasePointerCapture( event.pointerId );
			}
		} catch ( error ) {
			// Ignored.
		}

		if ( interaction.type === 'move' && interaction.moved ) {
			var move = interaction.lastMove || { dx: 0, dy: 0 };

			interaction.items.forEach( function ( item ) {
				commitGeometry( item.id, { x: item.start.x + move.dx, y: item.start.y + move.dy }, { gesture: true } );
			} );

			finishGesture( str( 'move', 'Move' ) );
			return;
		}

		if ( interaction.type === 'resize' && interaction.lastResize ) {
			var next = interaction.lastResize;
			var bounds = interaction.bounds;
			var scaleX = bounds.width > 0 ? next.width / bounds.width : 1;
			var scaleY = bounds.height > 0 ? next.height / bounds.height : 1;
			var primary = interaction.items[ 0 ];

			interaction.items.forEach( function ( item ) {
				commitGeometry(
					item.id,
					{
						x: next.x + ( item.x - bounds.x ) * scaleX,
						y: next.y + ( item.y - bounds.y ) * scaleY,
						width: Math.max( 8, item.width * scaleX ),
						height: Math.max( 1, item.height * scaleY ),
					},
					{ gesture: true }
				);
			} );

			var element = elementFor( primary.id );
			if ( element && element.type === 'text' ) {
				var baseSize = num( element.font_size, 40 );
				var baseMax = num( element.max_font_size, baseSize );
				writeProps( primary.id, {
					font_size: Math.max( 8, Math.round( baseSize * scaleY ) ),
					max_font_size: Math.max( 8, Math.round( baseMax * scaleY ) ),
				} );
			}

			finishGesture( str( 'resize', 'Resize' ) );
			return;
		}

		if ( interaction.type === 'rotate' && typeof interaction.lastRotation === 'number' ) {
			var rotation = interaction.lastRotation;
			var base = interaction.items[ 0 ].rotation || 0;
			var delta = rotation - base;

			interaction.items.forEach( function ( item ) {
				var theta = ( delta * Math.PI ) / 180;
				var rx = item.relX * Math.cos( theta ) - item.relY * Math.sin( theta );
				var ry = item.relX * Math.sin( theta ) + item.relY * Math.cos( theta );

				commitGeometry(
					item.id,
					{
						x: interaction.center.x + rx - item.width / 2,
						y: interaction.center.y + ry - item.height / 2,
						rotation: ( item.rotation || 0 ) + delta,
					},
					{ gesture: true }
				);
			} );

			finishGesture( str( 'rotate', 'Rotate' ) );
			return;
		}

		if ( interaction.type === 'marquee' ) {
			var a = interaction.start;
			var b = interaction.current || interaction.start;
			var box = {
				x: Math.min( a.x, b.x ),
				y: Math.min( a.y, b.y ),
				width: Math.abs( b.x - a.x ),
				height: Math.abs( b.y - a.y ),
			};
			var picked = interaction.baseSelection.slice();

			if ( box.width > 3 || box.height > 3 ) {
				hitCandidates().forEach( function ( layer ) {
					if ( elementIsLocked( layer.id ) ) {
						return;
					}
					var geo = geometry( layer.id );
					if ( ! geo ) {
						return;
					}
					if (
						geo.x < box.x + box.width &&
						geo.x + geo.width > box.x &&
						geo.y < box.y + box.height &&
						geo.y + geo.height > box.y &&
						picked.indexOf( layer.id ) === -1
					) {
						picked.push( layer.id );
					}
				} );
			}

			state.selection = picked;
			renderSelectionState();
			return;
		}

		renderSelectionState();
	}

	/**
	 * After a gesture commits, clear the live-preview flags and re-request the
	 * authoritative plan once.
	 *
	 * @param {string} label History label (already pushed).
	 * @return {void}
	 */
	function finishGesture( label ) {
		Object.keys( elements() ).forEach( function ( id ) {
			if ( elements()[ id ].__preview ) {
				delete elements()[ id ].__preview;
			}
		} );

		// Paint the committed geometry now, then confirm against the server once.
		repaintPreview( serializeDocument() );
		scheduleOverlay();

		requestPlan( true ).then( function () {
			renderAll();
			refreshLiveGeometry();
		} );
	}

	/**
	 * Paint the last plan, with a local patch for the live document.
	 *
	 * The plan is authoritative about geometry after a gesture, but while a
	 * field is being edited the document is one step ahead of the network, so
	 * the local geometry is folded in. Layers are matched by id, which keeps
	 * the server's ordering, sanitising and dropped-layer decisions intact.
	 *
	 * @param {Object} liveDocument Optional current document.
	 * @return {void}
	 */
	function repaintPreview( liveDocument ) {
		if ( ! state.plan ) {
			return;
		}

		var patched = state.plan;

		if ( liveDocument && liveDocument.elements ) {
			var baseFormat = String( ( liveDocument.canvas && liveDocument.canvas.format ) || 'square' );
			var overrides = ( ( liveDocument.layout || {} )[ state.format ] || {} ).elements || {};

			patched = Object.assign( {}, state.plan, {
				layers: ( state.plan.layers || [] ).map( function ( layer ) {
					var source = liveDocument.elements[ layer.id ];

					if ( ! source ) {
						return layer;
					}

					var merged = Object.assign( {}, layer );
					var override = overrides[ layer.id ] || {};
					var geometry = [ 'x', 'y', 'width', 'height', 'rotation', 'z' ];

					geometry.forEach( function ( key ) {
						if ( override[ key ] !== undefined ) {
							merged[ key ] = override[ key ];
						} else if ( state.format === baseFormat && source[ key ] !== undefined ) {
							merged[ key ] = source[ key ];
						}
					} );

					if ( source.opacity !== undefined ) {
						merged.opacity = source.opacity;
					}

					if ( layer.text && source.text ) {
						merged.text = Object.assign( {}, layer.text, source.text );
					}

					return merged;
				} ),
			} );
		}

		raf( function () {
			paint( patched );
			scheduleOverlay();
		} );
	}

	/* ── Selection helpers ────────────────────────────────────────── */

	function selectOnly( id ) {
		state.selection = id ? [ id ] : [];
		renderSelectionState();
	}

	function toggleSelection( id ) {
		var index = state.selection.indexOf( id );

		if ( index === -1 ) {
			state.selection.push( id );
		} else {
			state.selection.splice( index, 1 );
		}
	}

	function selectAll() {
		state.selection = hitCandidates()
			.filter( function ( layer ) {
				return ! elementIsLocked( layer.id );
			} )
			.map( function ( layer ) {
				return layer.id;
			} );

		renderSelectionState();
		toast( state.selection.length + ' ' + str( 'selectedCount', 'layers selected' ), 'info', { duration: 1600 } );
	}

	function renderSelectionState() {
		renderLayers();
		renderInspector();
		scheduleOverlay();
		positionSelectionToolbar();
		refreshLiveGeometry();
	}

	/* ── Context menu ─────────────────────────────────────────────── */

	var contextMenuState = { point: { x: 0, y: 0 } };

	function onCanvasContextMenu( event ) {
		event.preventDefault();

		var point = toCanvas( event );
		var layer = hitTest( point );

		if ( layer && state.selection.indexOf( layer.id ) === -1 ) {
			state.selection = [ layer.id ];
			renderSelectionState();
		}

		contextMenuState.point = point;
		buildContextMenu( event.clientX, event.clientY );
	}

	/**
	 * Build and show the element / canvas context menu.
	 *
	 * @param {number} clientX Screen x.
	 * @param {number} clientY Screen y.
	 * @return {void}
	 */
	function buildContextMenu( clientX, clientY ) {
		var menu = els.contextMenu;
		var selection = state.selection.slice();
		var single = selection.length === 1;
		var element = single ? elementFor( selection[ 0 ] ) : null;
		var hasSelection = selection.length > 0;

		closeMenus();
		clear( menu );
		menu.hidden = false;

		var items = [];

		if ( hasSelection ) {
			items = items.concat( [
				{ label: str( 'duplicate', 'Duplicate' ), icon: 'copy', hint: shortcut( 'duplicate' ), run: duplicateSelection },
				{ label: str( 'copy', 'Copy' ), icon: 'copy', hint: 'Ctrl C', run: copySelection },
				{ label: str( 'paste', 'Paste' ), icon: 'copy', hint: 'Ctrl V', disabled: ! state.clipboard, run: pasteClipboard },
				{ separator: true },
				{ label: str( 'bringForward', 'Bring forward' ), icon: 'arrow-up', hint: 'Ctrl ]', run: function () { reorderSelection( 'forward' ); } },
				{ label: str( 'sendBackward', 'Send backward' ), icon: 'arrow-down', hint: 'Ctrl [', run: function () { reorderSelection( 'backward' ); } },
				{ label: str( 'bringFront', 'Bring to front' ), icon: 'to-front', run: function () { reorderSelection( 'front' ); } },
				{ label: str( 'sendBack', 'Send to back' ), icon: 'to-back', run: function () { reorderSelection( 'back' ); } },
				{ separator: true },
				{ label: str( 'lockLayer', 'Lock' ), icon: 'lock', run: function () { toggleLockSelection(); } },
				{ label: str( 'hideLayer', 'Hide' ), icon: 'eye-off', run: function () { hideSelection(); } },
			] );

			if ( element && element.type === 'text' ) {
				items = items.concat( [
					{ separator: true },
					{ label: str( 'editText', 'Edit text' ), icon: 'type', run: function () { startTextEdit( selection[ 0 ] ); } },
					{ label: str( 'switchSource', 'Use static text' ), icon: 'sliders', active: element.source === 'static', run: function () {
						writeProp( selection[ 0 ], 'source', 'static' );
						requestPlan( true ).then( renderAll );
					} },
				] );
			}

			if ( selection.length > 1 ) {
				items = items.concat( [
					{ separator: true },
					{ heading: str( 'alignHeading', 'Align' ) },
					{ label: str( 'alignLeft', 'Align left edges' ), icon: 'alignLeft', run: function () { alignSelection( 'left' ); } },
					{ label: str( 'alignCenterH', 'Align horizontal centres' ), icon: 'alignCenterH', run: function () { alignSelection( 'center' ); } },
					{ label: str( 'alignRight', 'Align right edges' ), icon: 'alignRight', run: function () { alignSelection( 'right' ); } },
					{ label: str( 'alignTop', 'Align top edges' ), icon: 'alignTop', run: function () { alignSelection( 'top' ); } },
					{ label: str( 'alignMiddle', 'Align vertical centres' ), icon: 'alignMiddle', run: function () { alignSelection( 'middle' ); } },
					{ label: str( 'alignBottom', 'Align bottom edges' ), icon: 'alignBottom', run: function () { alignSelection( 'bottom' ); } },
					{ label: str( 'distribute', 'Distribute horizontally' ), icon: 'align', run: function () { distributeSelection( 'x' ); } },
					{ label: str( 'distributeV', 'Distribute vertically' ), icon: 'align', run: function () { distributeSelection( 'y' ); } },
				] );
			} else if ( selection.length === 1 ) {
				items = items.concat( [
					{ separator: true },
					{ heading: str( 'alignHeading', 'Align to canvas' ) },
					{ label: str( 'centreH', 'Centre horizontally' ), icon: 'alignCenterH', run: function () { alignToCanvas( 'center' ); } },
					{ label: str( 'centreV', 'Centre vertically' ), icon: 'alignMiddle', run: function () { alignToCanvas( 'middle' ); } },
				] );
			}

			items = items.concat( [
				{ separator: true },
				{ label: str( 'delete', 'Delete' ), icon: 'trash', hint: shortcut( 'delete' ), danger: true, run: deleteSelection },
			] );
		} else {
			items = [
				{ label: str( 'paste', 'Paste' ), icon: 'copy', hint: 'Ctrl V', disabled: ! state.clipboard, run: pasteClipboard },
				{ label: str( 'selectAll', 'Select all' ), icon: 'cursor', hint: shortcut( 'selectAll' ), run: selectAll },
				{ separator: true },
				{ label: str( 'fitScreen', 'Fit to screen' ), icon: 'fit', run: fitToScreen },
				{ label: str( 'actualSize', 'Actual size' ), icon: 'one-to-one', run: actualSize },
				{ separator: true },
				{ label: ( state.showGrid ? '✓ ' : '' ) + str( 'showGrid', 'Show grid' ), icon: 'grid', run: toggleGrid },
				{ label: ( state.showRulers ? '✓ ' : '' ) + str( 'showRulers', 'Show rulers' ), icon: 'ruler', run: toggleRulers },
				{ label: ( state.snap ? '✓ ' : '' ) + str( 'snapGuides', 'Snap to guides' ), icon: 'magnet', run: toggleSnap },
				{ separator: true },
				{ label: str( 'canvasSettings', 'Canvas settings' ), icon: 'settings', run: function () { state.selection = []; renderSelectionState(); } },
			];
		}

		items.forEach( function ( item ) {
			if ( item.separator ) {
				menu.appendChild( el( 'div', { class: 'cpic-menu__sep', role: 'separator' } ) );
				return;
			}
			if ( item.heading ) {
				menu.appendChild( el( 'div', { class: 'cpic-menu__heading', text: item.heading } ) );
				return;
			}

			menu.appendChild(
				el( 'button', {
					type: 'button',
					class: 'cpic-menu__item' + ( item.danger ? ' is-danger' : '' ),
					role: 'menuitem',
					disabled: item.disabled ? 'disabled' : null,
					onClick: function () {
						closeMenus();
						item.run();
					},
				}, [
					item.icon ? iconNode( item.icon, 15 ) : el( 'span', { class: 'cpic-svg' } ),
					el( 'span', { class: 'cpic-menu__label', text: item.label } ),
					item.hint ? el( 'span', { class: 'cpic-menu__hint', text: item.hint } ) : null,
				] )
			);
		} );

		hydrateIcons( menu );

		var rect = menu.getBoundingClientRect();
		menu.style.left = clamp( clientX, 8, window.innerWidth - rect.width - 8 ) + 'px';
		menu.style.top = clamp( clientY, 8, window.innerHeight - rect.height - 8 ) + 'px';
	}

	/* ── Double click: inline text editing ────────────────────────── */

	function onCanvasDoubleClick( event ) {
		var layer = hitTest( toCanvas( event ) );

		if ( ! layer ) {
			focusDocName();
			return;
		}

		if ( layer.type === 'text' ) {
			startTextEdit( layer.id );
		} else if ( layer.image ) {
			state.selection = [ layer.id ];
			renderSelectionState();
			openMediaFor( layer.id );
		}
	}

	/**
	 * Inline text editing.
	 *
	 * The overlay `<textarea>` is positioned and sized to the element's box and
	 * styled with the same family, size, weight and colour, so what the user
	 * types is what the canvas will paint. The element's own text is moved into
	 * the document on commit, not per keystroke.
	 *
	 * @param {string} id Element id.
	 * @return {void}
	 */
	function startTextEdit( id ) {
		var element = elementFor( id );
		var layer = layerFor( id );
		var geo = geometry( id );

		if ( ! element || element.type !== 'text' || ! geo || element.locked ) {
			return;
		}

		endTextEdit( false );

		// One history entry for the whole editing session (typing included).
		pushHistory( str( 'editText', 'Edit text' ), 'text-' + id );

		// A dynamic source has no literal text; switch it to static so the
		// user's edit is what renders (and tell them why).
		if ( element.source !== 'static' ) {
			writeProp( id, 'source', 'static' );
			writeProp( id, 'text', layer && layer.text ? layer.text.value : element.text || '' );
			toast( str( 'staticNotice', 'This layer followed the post. It is now static text.' ), 'info' );
		}

		var text = layer && layer.text ? layer.text : {};
		var size = canvasSize();
		var rect = els.canvas.getBoundingClientRect();
		var scale = rect.width / Math.max( 1, size.width );
		var area = els.canvasArea.getBoundingClientRect();

		var editor = el( 'textarea', {
			class: 'cpic-text-editor',
			spellcheck: 'false',
			'aria-label': str( 'editText', 'Edit text' ),
			'data-element': id,
		} );

		editor.value = element.text || ( layer && layer.text ? layer.text.value : '' );

		var left = rect.left - area.left + geo.x * scale;
		var top = rect.top - area.top + geo.y * scale;
		var width = Math.max( 60, geo.width * scale );
		var height = Math.max( 28, ( geo.height > 0 ? geo.height : Math.max( 80, num( text.font_size, 40 ) * 2.4 ) ) * scale );

		editor.style.left = left + 'px';
		editor.style.top = top + 'px';
		editor.style.width = width + 'px';
		editor.style.height = height + 'px';
		editor.style.fontFamily = '"' + ( element.font_family || 'Noto Sans Bengali' ) + '", "Noto Sans Bengali", sans-serif';
		editor.style.fontSize = Math.max( 8, num( element.font_size, 40 ) * scale ) + 'px';
		editor.style.fontWeight = String( num( element.font_weight, 700 ) );
		editor.style.lineHeight = String( num( element.line_height, 1.35 ) );
		editor.style.color = element.color || '#111827';
		editor.style.textAlign = element.align || 'left';

		els.canvasArea.appendChild( editor );
		editor.focus();
		editor.setSelectionRange( editor.value.length, editor.value.length );

		// `before` is captured after the source switch above, so Escape restores
		// the text the user started editing — not the source they came from.
		state.editingText = { id: id, node: editor, before: clone( element ), beforeText: element.text };

		editor.addEventListener( 'input', function () {
			writeProp( id, 'text', editor.value );

			// Repaint from the document so the text appears without waiting for
			// the server; the plan is requested once when editing ends.
			repaintPreview( serializeDocument() );
		} );

		editor.addEventListener( 'keydown', function ( event ) {
			if ( event.key === 'Escape' ) {
				// Cancel: put back the text the user started with.
				event.preventDefault();
				event.stopPropagation();
				endTextEdit( false );
				return;
			}

			if ( event.key === 'Enter' && ( event.metaKey || event.ctrlKey ) ) {
				event.preventDefault();
				endTextEdit( true );
			}

			event.stopPropagation();
		} );

		editor.addEventListener( 'blur', function () {
			window.setTimeout( function () {
				if ( state.editingText && state.editingText.node === editor ) {
					endTextEdit( true );
				}
			}, 60 );
		} );
	}

	function endTextEdit( commit ) {
		var editing = state.editingText;

		if ( ! editing ) {
			return;
		}

		state.editingText = null;

		if ( editing.node && editing.node.parentNode ) {
			editing.node.parentNode.removeChild( editing.node );
		}

		if ( commit ) {
			// Persist the text as static content, then confirm with the server.
			var element = elementFor( editing.id );

			if ( element && element.source !== 'static' ) {
				writeProp( editing.id, 'source', 'static' );
			}

			repaintPreview( serializeDocument() );
			requestPlan( true ).then( renderAll );
		} else {
			// Escape restores the text the edit started with.
			var current = elementFor( editing.id );

			if ( current && editing.beforeText !== undefined ) {
				current.text = editing.beforeText;
				markDirty();
				repaintPreview( serializeDocument() );
				requestPlan( true ).then( renderAll );
			}
		}

		scheduleOverlay();
	}

	/* ═══ Layer operations shared by panel, canvas and menus ══════ */

	function uniqueElementId( base ) {
		var candidate = ( base || 'element' ).replace( /[^a-zA-Z0-9_\-]/g, '' );
		var index = 1;

		while ( elements()[ candidate ] ) {
			candidate = ( base || 'element' ).replace( /[^a-zA-Z0-9_\-]/g, '' ) + '_' + index;
			index++;
		}

		return candidate;
	}

	function topZ() {
		var max = 0;
		Object.keys( elements() ).forEach( function ( id ) {
			max = Math.max( max, num( elements()[ id ].z ) );
		} );
		return max;
	}

	function addElement( factory, kind ) {
		var element = factory();
		element.id = uniqueElementId( kind || factory().name.toLowerCase().replace( /[^a-z0-9]/g, '' ) || 'element' );
		element.z = Math.min( 999, topZ() + 5 );
		element.visible = true;
		element.locked = false;

		// Stagger new elements so they never land exactly on top of each other.
		var count = Object.keys( elements() ).length;
		element.x = clamp( element.x + ( count % 5 ) * 12, 0, Math.max( 0, canvasSize().width - 40 ) );
		element.y = clamp( element.y + ( count % 5 ) * 12, 0, Math.max( 0, canvasSize().height - 40 ) );

		pushHistory( str( 'addLayer', 'Add layer' ) + ': ' + element.name );
		elements()[ element.id ] = element;
		state.selection = [ element.id ];
		markDirty();

		requestPlan( true ).then( function () {
			renderAll();
			toast( element.name + ' ' + str( 'added', 'added' ), 'success', { duration: 1800 } );
		} );

		return element.id;
	}

	function deleteSelection() {
		var ids = state.selection.slice();

		if ( ! ids.length ) {
			return;
		}

		var names = ids.map( function ( id ) {
			var element = elementFor( id );
			return element ? element.name : id;
		} );

		pushHistory( str( 'delete', 'Delete' ) + ': ' + names.join( ', ' ) );

		ids.forEach( function ( id ) {
			delete elements()[ id ];
			if ( state.document.layout ) {
				Object.keys( state.document.layout ).forEach( function ( variant ) {
					var layout = state.document.layout[ variant ];
					if ( layout && layout.elements && layout.elements[ id ] ) {
						delete layout.elements[ id ];
					}
				} );
			}
			if ( state.plan && state.plan.layers ) {
				state.plan.layers = state.plan.layers.filter( function ( layer ) {
					return layer.id !== id;
				} );
			}
		} );

		state.selection = [];
		markDirty();

		requestPlan( true ).then( function () {
			renderAll();
			toast(
				ids.length > 1
					? ids.length + ' ' + str( 'layersDeleted', 'layers deleted' )
					: str( 'layerDeleted', 'Layer deleted' ),
				'success',
				{
					duration: 5000,
					action: {
						label: str( 'undo', 'Undo' ),
						run: undo,
					},
				}
			);
		} );
	}

	function duplicateSelection() {
		var ids = state.selection.slice();

		if ( ! ids.length ) {
			return;
		}

		pushHistory( str( 'duplicate', 'Duplicate' ) );

		var newIds = [];
		ids.forEach( function ( id ) {
			var element = elementFor( id );
			var geo = geometry( id );

			if ( ! element || ! geo ) {
				return;
			}

			var copy = clone( element );
			copy.id = uniqueElementId( id );
			copy.name = element.name + ' copy';
			copy.z = Math.min( 999, topZ() + 5 );
			copy.locked = false;
			delete copy.__preview;

			elements()[ copy.id ] = copy;

			// Position the copy in the active format (offset so it is visible).
			writeProp( copy.id, 'x', clamp( geo.x + 24, 0, canvasSize().width ), { toBase: true } );
			writeProp( copy.id, 'y', clamp( geo.y + 24, 0, canvasSize().height ), { toBase: true } );

			newIds.push( copy.id );
		} );

		state.selection = newIds;
		markDirty();

		requestPlan( true ).then( function () {
			renderAll();
			toast( newIds.length > 1 ? newIds.length + ' ' + str( 'layersDuplicated', 'layers duplicated' ) : str( 'layerDuplicated', 'Layer duplicated' ), 'success', { duration: 1800 } );
		} );
	}

	function copySelection() {
		if ( ! state.selection.length ) {
			return;
		}

		state.clipboard = state.selection.map( function ( id ) {
			return clone( elements()[ id ] );
		} ).filter( Boolean );

		toast( state.clipboard.length + ' ' + str( 'copied', 'copied to the clipboard' ), 'info', { duration: 1800 } );
	}

	function pasteClipboard() {
		if ( ! state.clipboard || ! state.clipboard.length ) {
			return;
		}

		pushHistory( str( 'paste', 'Paste' ) );

		var newIds = state.clipboard.map( function ( source, index ) {
			var copy = clone( source );
			copy.id = uniqueElementId( source.id );
			copy.name = source.name;
			copy.z = Math.min( 999, topZ() + 5 + index );
			copy.x = clamp( num( source.x ) + 24, 0, canvasSize().width );
			copy.y = clamp( num( source.y ) + 24, 0, canvasSize().height );
			delete copy.__preview;
			elements()[ copy.id ] = copy;
			return copy.id;
		} );

		state.selection = newIds;
		markDirty();

		requestPlan( true ).then( function () {
			renderAll();
			toast( newIds.length + ' ' + str( 'pasted', 'pasted' ), 'success', { duration: 1800 } );
		} );
	}

	/**
	 * Reorder the selection. Operates on `z` (the renderer's ordering key),
	 * never on the elements array, so ids and object order are preserved.
	 *
	 * @param {string} mode forward|backward|front|back.
	 * @return {void}
	 */
	function reorderSelection( mode ) {
		var ids = state.selection.slice();

		if ( ! ids.length ) {
			return;
		}

		pushHistory( str( 'reorder', 'Reorder' ) );

		var all = Object.keys( elements() ).sort( function ( a, b ) {
			return num( elements()[ a ].z ) - num( elements()[ b ].z );
		} );

		if ( mode === 'front' || mode === 'back' ) {
			var targets = all.filter( function ( id ) {
				return ids.indexOf( id ) === -1;
			} );
			var moving = all.filter( function ( id ) {
				return ids.indexOf( id ) !== -1;
			} );
			var ordered = mode === 'front' ? targets.concat( moving ) : moving.concat( targets );
			ordered.forEach( function ( id, index ) {
				elements()[ id ].z = clamp( index * 5, 0, 999 );
			} );
		} else {
			var step = mode === 'forward' ? 1 : -1;
			var orderedIds = mode === 'forward' ? ids.slice().reverse() : ids;

			orderedIds.forEach( function ( id ) {
				var index = all.indexOf( id );
				var swapWith = index + step;

				if ( swapWith < 0 || swapWith >= all.length ) {
					return;
				}

				if ( ids.indexOf( all[ swapWith ] ) !== -1 ) {
					return;
				}

				var zA = num( elements()[ id ].z );
				var zB = num( elements()[ all[ swapWith ] ].z );
				elements()[ id ].z = zB;
				elements()[ all[ swapWith ] ].z = zA;

				// Re-read order for the next item in the same pass.
				all.sort( function ( a, b ) {
					return num( elements()[ a ].z ) - num( elements()[ b ].z );
				} );
			} );
		}

		markDirty();
		requestPlan( true ).then( renderAll );
	}

	function toggleLockSelection() {
		var ids = state.selection.slice();
		if ( ! ids.length ) {
			return;
		}

		pushHistory( str( 'lock', 'Lock' ) );
		var lock = ids.some( function ( id ) {
			return ! elementIsLocked( id );
		} );

		ids.forEach( function ( id ) {
			writeProp( id, 'locked', lock );
		} );

		markDirty();
		scheduleOverlay();
		renderLayers();
		renderInspector();
		toast( lock ? str( 'locked', 'Layer locked' ) : str( 'unlocked', 'Layer unlocked' ), 'info', { duration: 1600 } );
	}

	function hideSelection() {
		var ids = state.selection.slice();
		if ( ! ids.length ) {
			return;
		}

		pushHistory( str( 'hide', 'Hide' ) );
		ids.forEach( function ( id ) {
			writeProp( id, 'visible', false );
		} );

		state.selection = [];
		markDirty();
		requestPlan( true ).then( renderAll );
	}

	function toggleVisibility( id ) {
		var element = elementFor( id );

		if ( ! element ) {
			return;
		}

		pushHistory( str( 'visibility', 'Visibility' ) );
		writeProp( id, 'visible', element.visible === false );
		markDirty();
		requestPlan( true ).then( renderAll );
	}

	function alignSelection( mode ) {
		var list = selectedGeometryList().filter( function ( geo ) {
			return ! geo.locked;
		} );

		if ( list.length < 2 ) {
			return;
		}

		pushHistory( str( 'align', 'Align' ) );
		var bounds = unionBounds( list );

		list.forEach( function ( geo ) {
			var patch = {};

			if ( mode === 'left' ) {
				patch.x = bounds.x;
			} else if ( mode === 'right' ) {
				patch.x = bounds.x + bounds.width - geo.width;
			} else if ( mode === 'center' ) {
				patch.x = bounds.x + ( bounds.width - geo.width ) / 2;
			} else if ( mode === 'top' ) {
				patch.y = bounds.y;
			} else if ( mode === 'bottom' ) {
				patch.y = bounds.y + bounds.height - geo.height;
			} else if ( mode === 'middle' ) {
				patch.y = bounds.y + ( bounds.height - geo.height ) / 2;
			}

			commitGeometry( geo.id, patch );
		} );

		markDirty();
		requestPlan( true ).then( renderAll );
	}

	function alignToCanvas( mode ) {
		var list = selectedGeometryList().filter( function ( geo ) {
			return ! geo.locked;
		} );

		if ( ! list.length ) {
			return;
		}

		pushHistory( str( 'align', 'Align' ) );
		var size = canvasSize();

		list.forEach( function ( geo ) {
			if ( mode === 'center' ) {
				commitGeometry( geo.id, { x: ( size.width - geo.width ) / 2 } );
			} else if ( mode === 'middle' ) {
				commitGeometry( geo.id, { y: ( size.height - geo.height ) / 2 } );
			}
		} );

		markDirty();
		requestPlan( true ).then( renderAll );
	}

	function distributeSelection( axis ) {
		var list = selectedGeometryList().filter( function ( geo ) {
			return ! geo.locked;
		} );

		if ( list.length < 3 ) {
			return;
		}

		pushHistory( str( 'distribute', 'Distribute' ) );

		var sorted = list.slice().sort( function ( a, b ) {
			return axis === 'x' ? a.x - b.x : a.y - b.y;
		} );

		var first = sorted[ 0 ];
		var last = sorted[ sorted.length - 1 ];
		var start = axis === 'x' ? first.x + first.width : first.y + first.height;
		var end = axis === 'x' ? last.x : last.y;
		var total = sorted.slice( 1, -1 ).reduce( function ( sum, geo ) {
			return sum + ( axis === 'x' ? geo.width : geo.height );
		}, 0 );
		var gap = ( end - start - total ) / ( sorted.length - 1 );
		var cursor = start;

		sorted.slice( 1, -1 ).forEach( function ( geo ) {
			var patch = {};
			if ( axis === 'x' ) {
				patch.x = cursor + gap;
				cursor += geo.width + gap;
			} else {
				patch.y = cursor + gap;
				cursor += geo.height + gap;
			}
			commitGeometry( geo.id, patch );
		} );

		markDirty();
		requestPlan( true ).then( renderAll );
	}

	function nudgeSelection( dx, dy ) {
		var list = selectedGeometryList().filter( function ( geo ) {
			return ! geo.locked;
		} );

		if ( ! list.length ) {
			return;
		}

		pushHistory( str( 'nudge', 'Nudge' ), 'nudge' );

		list.forEach( function ( geo ) {
			commitGeometry( geo.id, {
				x: clamp( geo.x + dx, -geo.width + 8, canvasSize().width - 8 ),
				y: clamp( geo.y + dy, -geo.height + 8, canvasSize().height - 8 ),
			} );
		} );

		scheduleOverlay();
		refreshLiveGeometry();
		debouncedNudgePlan();
	}

	var debouncedNudgePlan = debounce( function () {
		requestPlan( true ).then( function () {
			renderLayers();
			renderInspectorThrottled();
		} );
	}, 220 );

	var renderInspectorThrottled = debounce( function () {
		renderInspector();
	}, 40 );

	function refreshLiveGeometry() {
		// Keeps the inspector's X/Y/W/H fields in step with a live drag or nudge.
		var fields = document.querySelectorAll( '[data-live-geometry]' );

		if ( ! fields.length || state.selection.length !== 1 ) {
			return;
		}

		var geo = geometry( state.selection[ 0 ] );
		if ( ! geo ) {
			return;
		}

		Array.prototype.forEach.call( fields, function ( field ) {
			var key = field.getAttribute( 'data-live-geometry' );
			if ( document.activeElement === field ) {
				return;
			}
			field.value = String( Math.round( geo[ key ] ) );
		} );
	}

	/* ═══ 6. Layer manager ═══════════════════════════════════════ */

	var LAYER_ICONS = {
		text: 'type',
		image: 'image',
		logo: 'star',
		shape: 'rect',
		gradient: 'gradient',
		pattern: 'grid',
		watermark: 'watermark',
		svg: 'image',
		group: 'layers',
	};

	function layerIcon( element ) {
		if ( element.type === 'shape' ) {
			if ( element.shape === 'circle' ) {
				return 'circle';
			}
			if ( element.shape === 'ellipse' ) {
				return 'ellipse';
			}
			if ( element.shape === 'line' ) {
				return 'line';
			}
			return 'rect';
		}

		if ( element.type === 'text' ) {
			return num( element.font_size ) >= 60 ? 'heading' : 'type';
		}

		return LAYER_ICONS[ element.type ] || 'layers';
	}

	function layerLabel( element ) {
		var name = element.name || element.type;

		if ( element.type === 'text' ) {
			var preview = ( element.source === 'static' ? element.text : '' ) || '';
			if ( preview && preview.trim() ) {
				return name;
			}
		}

		return name;
	}

	/**
	 * Layers, topmost first. Hidden layers stay in the list (they are missing
	 * from the plan on purpose), so the manager reads from the document and
	 * falls back to the plan for nothing.
	 *
	 * @return {Array<Object>} Elements with effective z.
	 */
	function layerList() {
		return Object.keys( elements() )
			.map( function ( id ) {
				var element = elements()[ id ];
				return {
					id: id,
					element: element,
					z: num( element.z ),
					name: layerLabel( element ),
				};
			} )
			.sort( function ( a, b ) {
				if ( a.z === b.z ) {
					return a.id < b.id ? -1 : 1;
				}
				return b.z - a.z;
			} );
	}

	function renderLayers() {
		if ( ! els.layers ) {
			return;
		}

		clear( els.layers );

		var list = layerList();

		if ( ! list.length ) {
			els.layers.appendChild(
				el( 'li', { class: 'cpic-empty cpic-empty--list' }, [
					iconNode( 'layers', 22 ),
					el( 'p', { text: str( 'noLayers', 'This design has no layers yet. Add an element to begin.' ) } ),
				] )
			);
			return;
		}

		list.forEach( function ( row ) {
			var element = row.element;
			var selected = state.selection.indexOf( row.id ) !== -1;

			var item = el(
				'li',
				{
					class:
						'cpic-layer' +
						( selected ? ' is-selected' : '' ) +
						( element.visible === false ? ' is-hidden' : '' ) +
						( element.locked ? ' is-locked' : '' ),
					'data-layer': row.id,
					tabindex: '0',
					role: 'option',
					'aria-selected': String( selected ),
					draggable: 'true',
					onClick: function ( event ) {
						if ( event.shiftKey || event.metaKey || event.ctrlKey ) {
							toggleSelection( row.id );
						} else {
							state.selection = [ row.id ];
						}
						renderSelectionState();
					},
					onKeydown: function ( event ) {
						if ( event.key === 'Enter' || event.key === ' ' ) {
							event.preventDefault();
							state.selection = [ row.id ];
							renderSelectionState();
						}
						if ( event.key === 'F2' ) {
							event.preventDefault();
							renameLayerInline( row.id, item );
						}
					},
					onDragstart: function ( event ) {
						event.dataTransfer.setData( 'text/plain', row.id );
						event.dataTransfer.effectAllowed = 'move';
						item.classList.add( 'is-dragging' );
					},
					onDragend: function () {
						item.classList.remove( 'is-dragging' );
						Array.prototype.forEach.call( els.layers.querySelectorAll( '.cpic-layer' ), function ( node ) {
							node.classList.remove( 'is-drop-before', 'is-drop-after' );
						} );
					},
					onDragover: function ( event ) {
						event.preventDefault();
						var rect = item.getBoundingClientRect();
						var before = event.clientY < rect.top + rect.height / 2;
						item.classList.toggle( 'is-drop-before', before );
						item.classList.toggle( 'is-drop-after', ! before );
					},
					onDragleave: function () {
						item.classList.remove( 'is-drop-before', 'is-drop-after' );
					},
					onDrop: function ( event ) {
						event.preventDefault();
						var sourceId = event.dataTransfer.getData( 'text/plain' );
						var rect = item.getBoundingClientRect();
						var before = event.clientY < rect.top + rect.height / 2;
						dropLayer( sourceId, row.id, before );
					},
				},
				[
					el( 'span', { class: 'cpic-layer__thumb', 'data-thumb-for': row.id }, [ iconNode( layerIcon( element ), 15 ) ] ),
					el( 'span', { class: 'cpic-layer__text' }, [
						el( 'span', {
							class: 'cpic-layer__name',
							text: row.name,
							onDblclick: function ( event ) {
								event.stopPropagation();
								renameLayerInline( row.id, item );
							},
						} ),
						el( 'span', { class: 'cpic-layer__meta', text: layerMeta( element ) } ),
					] ),
					el( 'button', {
						type: 'button',
						class: 'cpic-layer__btn' + ( element.visible === false ? ' is-off' : '' ),
						title: element.visible === false ? str( 'showLayer', 'Show layer' ) : str( 'hideLayerButton', 'Hide layer' ),
						'aria-label': element.visible === false ? str( 'showLayer', 'Show layer' ) : str( 'hideLayerButton', 'Hide layer' ),
						onClick: function ( event ) {
							event.stopPropagation();
							toggleVisibility( row.id );
						},
					}, [ iconNode( element.visible === false ? 'eye-off' : 'eye', 15 ) ] ),
					el( 'button', {
						type: 'button',
						class: 'cpic-layer__btn' + ( element.locked ? ' is-on' : '' ),
						title: element.locked ? str( 'unlockLayer', 'Unlock layer' ) : str( 'lockLayerButton', 'Lock layer' ),
						'aria-label': element.locked ? str( 'unlockLayer', 'Unlock layer' ) : str( 'lockLayerButton', 'Lock layer' ),
						onClick: function ( event ) {
							event.stopPropagation();
							pushHistory( str( 'lock', 'Lock' ) );
							writeProp( row.id, 'locked', ! element.locked );
							renderLayers();
							renderInspector();
							scheduleOverlay();
						},
					}, [ iconNode( element.locked ? 'lock' : 'unlock', 15 ) ] ),
					el( 'button', {
						type: 'button',
						class: 'cpic-layer__btn cpic-layer__btn--menu',
						title: str( 'moreActions', 'More actions' ),
						'aria-label': str( 'moreActions', 'More actions' ),
						onClick: function ( event ) {
							event.stopPropagation();
							state.selection = [ row.id ];
							renderSelectionState();
							openMenu( event.currentTarget, layerMenuItems( row.id ), { align: 'end' } );
						},
					}, [ iconNode( 'more', 15 ) ] ),
				]
			);

			els.layers.appendChild( item );
		} );

		hydrateIcons( els.layers );
		applyLayerThumbnails();
	}

	function layerMeta( element ) {
		if ( element.type === 'text' ) {
			var family = ( element.font_family || 'Noto Sans Bengali' ).replace( /^Noto /, '' ).replace( / Bengali$/, '' );
			return family + ' · ' + Math.round( num( element.font_size, 40 ) ) + 'px';
		}

		if ( element.type === 'shape' ) {
			return ( element.shape || 'rect' ) + ' · ' + Math.round( num( element.width ) ) + '×' + Math.round( num( element.height ) );
		}

		if ( element.image || element.url !== undefined ) {
			return ( element.source || 'image' ).replace( /_/g, ' ' );
		}

		return element.type;
	}

	function layerMenuItems( id ) {
		var element = elementFor( id );

		if ( ! element ) {
			return [];
		}

		return [
			{
				label: str( 'rename', 'Rename' ),
				icon: 'type',
				run: function () {
					var node = els.layers.querySelector( '[data-layer="' + id + '"] .cpic-layer__name' );
					renameLayerInline( id, node ? node.closest( '.cpic-layer' ) : null );
				},
			},
			{ label: str( 'duplicateLayer', 'Duplicate' ), icon: 'copy', run: function () { state.selection = [ id ]; duplicateSelection(); } },
			{ separator: true },
			{ label: str( 'bringForward', 'Bring forward' ), icon: 'arrow-up', run: function () { state.selection = [ id ]; reorderSelection( 'forward' ); } },
			{ label: str( 'sendBackward', 'Send backward' ), icon: 'arrow-down', run: function () { state.selection = [ id ]; reorderSelection( 'backward' ); } },
			{ label: str( 'bringFront', 'Bring to front' ), icon: 'to-front', run: function () { state.selection = [ id ]; reorderSelection( 'front' ); } },
			{ label: str( 'sendBack', 'Send to back' ), icon: 'to-back', run: function () { state.selection = [ id ]; reorderSelection( 'back' ); } },
			{ separator: true },
			{
				label: element.locked ? str( 'unlock', 'Unlock' ) : str( 'lock', 'Lock' ),
				icon: element.locked ? 'unlock' : 'lock',
				run: function () { state.selection = [ id ]; toggleLockSelection(); },
			},
			{
				label: element.visible === false ? str( 'show', 'Show' ) : str( 'hide', 'Hide' ),
				icon: element.visible === false ? 'eye' : 'eye-off',
				run: function () { toggleVisibility( id ); },
			},
			{ separator: true },
			{
				label: str( 'deleteLayer', 'Delete' ),
				icon: 'trash',
				danger: true,
				run: function () {
					state.selection = [ id ];
					deleteSelection();
				},
			},
		];
	}

	function renameLayerInline( id, item ) {
		var element = elementFor( id );

		if ( ! element || ! item ) {
			return;
		}

		var nameNode = item.querySelector( '.cpic-layer__name' );
		if ( ! nameNode ) {
			return;
		}

		var input = el( 'input', {
			type: 'text',
			class: 'cpic-layer__rename',
			value: element.name || element.type,
			'aria-label': str( 'rename', 'Rename layer' ),
		} );

		nameNode.replaceWith( input );
		input.focus();
		input.select();

		var finish = function ( commit ) {
			if ( ! input.parentNode ) {
				return;
			}

			if ( commit ) {
				var value = input.value.trim();
				if ( value && value !== element.name ) {
					pushHistory( str( 'renameLayer', 'Rename' ) );
					element.name = value.slice( 0, 80 );
					markDirty();
					renderInspector();
				}
			}

			renderLayers();
		};

		input.addEventListener( 'keydown', function ( event ) {
			event.stopPropagation();
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				finish( true );
			} else if ( event.key === 'Escape' ) {
				event.preventDefault();
				finish( false );
			}
		} );

		input.addEventListener( 'blur', function () {
			finish( true );
		} );
	}

	/**
	 * Drag-and-drop reordering. Dropping above an item means "in front of it".
	 *
	 * @param {string}  sourceId Dragged layer.
	 * @param {string}  targetId Drop target.
	 * @param {boolean} before   True when dropped in the top half.
	 * @return {void}
	 */
	function dropLayer( sourceId, targetId, before ) {
		if ( ! sourceId || ! targetId || sourceId === targetId ) {
			return;
		}

		var order = layerList().map( function ( row ) {
			return row.id;
		} );

		var from = order.indexOf( sourceId );
		if ( from === -1 ) {
			return;
		}

		pushHistory( str( 'reorder', 'Reorder layers' ) );

		order.splice( from, 1 );
		var to = order.indexOf( targetId );

		if ( to === -1 ) {
			return;
		}

		order.splice( before ? to : to + 1, 0, sourceId );

		// The list is topmost-first; z must ascend with the reversed order.
		order.slice().reverse().forEach( function ( id, index ) {
			elements()[ id ].z = clamp( ( index + 1 ) * 5, 0, 999 );
		} );

		state.selection = [ sourceId ];
		markDirty();

		requestPlan( true ).then( renderAll );
	}

	/**
	 * Draw real layer thumbnails from the design canvas. Cheap because the
	 * canvas is already painted; each thumb is one `drawImage` of the layer
	 * box, clipped to a small square.
	 *
	 * @return {void}
	 */
	function applyLayerThumbnails() {
		if ( ! els.canvas || ! state.plan ) {
			return;
		}

		var nodes = els.layers.querySelectorAll( '[data-thumb-for]' );

		Array.prototype.forEach.call( nodes, function ( node ) {
			var id = node.getAttribute( 'data-thumb-for' );
			var geo = geometry( id );

			if ( ! geo || geo.width < 2 || geo.height < 2 ) {
				return;
			}

			var thumb = document.createElement( 'canvas' );
			thumb.width = 30;
			thumb.height = 30;
			var ctx = thumb.getContext( '2d' );
			var scale = Math.min( 30 / geo.width, 30 / geo.height );
			var drawW = Math.max( 1, geo.width * scale );
			var drawH = Math.max( 1, geo.height * scale );

			try {
				ctx.drawImage(
					els.canvas,
					geo.x * ( els.canvas.width / Math.max( 1, canvasSize().width ) ),
					geo.y * ( els.canvas.height / Math.max( 1, canvasSize().height ) ),
					Math.max( 1, geo.width * ( els.canvas.width / Math.max( 1, canvasSize().width ) ) ),
					Math.max( 1, geo.height * ( els.canvas.height / Math.max( 1, canvasSize().height ) ) ),
					( 30 - drawW ) / 2,
					( 30 - drawH ) / 2,
					drawW,
					drawH
				);
			} catch ( error ) {
				return;
			}

			clear( node );
			node.appendChild( thumb );
		} );
	}

	function bindLayerToolbar() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-layer-action]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				if ( ! state.selection.length ) {
					toast( str( 'selectFirst', 'Select a layer first.' ), 'info', { duration: 1800 } );
					return;
				}

				var action = button.getAttribute( 'data-layer-action' );

				if ( action === 'duplicate' ) {
					duplicateSelection();
				} else if ( action === 'delete' ) {
					deleteSelection();
				} else {
					reorderSelection( action );
				}
			} );
		} );
	}

	/* ── Selection toolbar (above the canvas) ─────────────────────── */

	function positionSelectionToolbar() {
		var toolbar = els.selectionToolbar;

		if ( ! toolbar ) {
			return;
		}

		var bounds = unionBounds( selectedGeometryList() );

		if ( ! bounds || state.editingText ) {
			toolbar.hidden = true;
			return;
		}

		var rect = els.canvas.getBoundingClientRect();
		var area = els.canvasArea.getBoundingClientRect();
		var scale = rect.width / Math.max( 1, canvasSize().width );
		var left = rect.left - area.left + ( bounds.x + bounds.width / 2 ) * scale;
		var top = rect.top - area.top + bounds.y * scale;

		toolbar.hidden = false;
		toolbar.style.left = clamp( left, 90, area.width - 90 ) + 'px';
		toolbar.style.top = Math.max( 6, top - 46 ) + 'px';
	}

	function bindSelectionToolbar() {
		if ( ! els.selectionToolbar ) {
			return;
		}

		Array.prototype.forEach.call( els.selectionToolbar.querySelectorAll( '[data-sel-action]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var action = button.getAttribute( 'data-sel-action' );

				if ( action === 'duplicate' ) {
					duplicateSelection();
				} else if ( action === 'lock' ) {
					toggleLockSelection();
				} else if ( action === 'hide' ) {
					hideSelection();
				} else if ( action === 'front' || action === 'back' ) {
					reorderSelection( action );
				} else if ( action === 'delete' ) {
					deleteSelection();
				}
			} );
		} );
	}

	/* ═══ 7. Properties inspector ════════════════════════════════ */

	var sectionState = {};

	/* ── Field primitives ─────────────────────────────────────────── */

	function fieldRow( label, control, extraClass, hint ) {
		return el( 'label', { class: 'cpic-field' + ( extraClass ? ' ' + extraClass : '' ) }, [
			el( 'span', { class: 'cpic-field__label', text: label } ),
			control,
			hint ? el( 'span', { class: 'cpic-field__hint', text: hint } ) : null,
		] );
	}

	/**
	 * Numeric input that commits on change/blur and not on every keystroke.
	 *
	 * @param {number}  value    Current value.
	 * @param {Object}  options  { min, max, step, suffix, onChange, live }.
	 * @return {Element} Input.
	 */
	function numberField( value, options ) {
		options = options || {};

		var input = el( 'input', {
			type: 'number',
			class: 'cpic-input',
			value: value === undefined || value === null || value === '' ? '' : String( round( num( value ), 2 ) ),
			step: options.step || 1,
			min: options.min !== undefined ? options.min : null,
			max: options.max !== undefined ? options.max : null,
			'data-live-geometry': options.live || null,
		} );

		var commit = function () {
			var parsed = parseFloat( input.value );
			if ( ! isFinite( parsed ) ) {
				return;
			}
			if ( options.min !== undefined ) {
				parsed = Math.max( options.min, parsed );
			}
			if ( options.max !== undefined ) {
				parsed = Math.min( options.max, parsed );
			}
			options.onChange( parsed );
		};

		input.addEventListener( 'change', commit );
		input.addEventListener( 'keydown', function ( event ) {
			event.stopPropagation();
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				commit();
				input.blur();
			}
		} );

		// Arrow keys inside the input adjust the value; the canvas shortcuts
		// must not also fire (they are guarded by isEditable()).
		input.addEventListener( 'keyup', function ( event ) {
			if ( event.key !== 'ArrowUp' && event.key !== 'ArrowDown' ) {
				return;
			}
			var next = num( input.value ) + ( event.key === 'ArrowUp' ? 1 : -1 ) * ( options.step || 1 );
			input.value = String( round( next, 2 ) );
			commit();
		} );

		return options.suffix
			? el( 'span', { class: 'cpic-input-affix' }, [ input, el( 'span', { class: 'cpic-input-suffix', text: options.suffix } ) ] )
			: input;
	}

	function textField( value, onChange, options ) {
		options = options || {};
		var input = el( 'input', {
			type: 'text',
			class: 'cpic-input',
			value: value === undefined || value === null ? '' : String( value ),
			placeholder: options.placeholder || '',
		} );

		input.addEventListener( 'change', function () {
			onChange( input.value );
		} );

		input.addEventListener( 'input', function () {
			if ( options.immediate ) {
				onChange( input.value );
			}
		} );

		input.addEventListener( 'keydown', function ( event ) {
			event.stopPropagation();
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				onChange( input.value );
				input.blur();
			}
		} );

		return input;
	}

	function textareaField( value, onChange, rows ) {
		var area = el( 'textarea', {
			class: 'cpic-textarea',
			rows: String( rows || 3 ),
			value: value === undefined || value === null ? '' : String( value ),
		} );

		var commit = debounce( function () {
			onChange( area.value );
		}, 350 );

		area.addEventListener( 'input', commit );
		area.addEventListener( 'blur', function () {
			onChange( area.value );
		} );
		area.addEventListener( 'keydown', function ( event ) {
			event.stopPropagation();
		} );

		return area;
	}

	function selectField( value, options, onChange ) {
		var select = el( 'select', { class: 'cpic-select' } );

		options.forEach( function ( option ) {
			select.appendChild(
				el( 'option', {
					value: String( option.value ),
					text: option.label,
					selected: String( option.value ) === String( value ),
				} )
			);
		} );

		select.addEventListener( 'change', function () {
			onChange( select.value );
		} );

		return select;
	}

	function segmentedField( value, options, onChange ) {
		var group = el( 'div', { class: 'cpic-segmented', role: 'group' } );

		options.forEach( function ( option ) {
			var active = String( option.value ) === String( value );
			var button = el( 'button', {
				type: 'button',
				class: 'cpic-segmented__item' + ( active ? ' is-active' : '' ),
				'aria-pressed': String( active ),
				title: option.label,
				'aria-label': option.label,
				'data-value': String( option.value ),
				onClick: function () {
					onChange( option.value );
				},
			}, [
				option.icon ? iconNode( option.icon, 16 ) : el( 'span', { text: option.text || option.label }),
			] );

			group.appendChild( button );
		} );

		hydrateIcons( group );
		return group;
	}

	function toggleField( value, label, onChange ) {
		var input = el( 'input', { type: 'checkbox', class: 'cpic-switch__input', checked: !! value } );
		input.addEventListener( 'change', function () {
			onChange( input.checked );
		} );

		return el( 'label', { class: 'cpic-switch' }, [
			input,
			el( 'span', { class: 'cpic-switch__track', 'aria-hidden': 'true' } ),
			el( 'span', { class: 'cpic-switch__label', text: label } ),
		] );
	}

	function sliderField( value, options, onChange ) {
		var input = el( 'input', {
			type: 'range',
			class: 'cpic-range',
			min: String( options.min !== undefined ? options.min : 0 ),
			max: String( options.max !== undefined ? options.max : 100 ),
			step: String( options.step || 1 ),
			value: String( num( value, options.min || 0 ) ),
		} );

		var output = el( 'span', { class: 'cpic-range__value', text: String( round( num( value ), 2 ) ) + ( options.suffix || '' ) } );

		input.addEventListener( 'input', function () {
			output.textContent = String( round( num( input.value ), 2 ) ) + ( options.suffix || '' );
			onChange( num( input.value ) );
		} );

		return el( 'div', { class: 'cpic-range-wrap' }, [ input, output ] );
	}

	/**
	 * Colour field: a native colour swatch plus a free-text value so rgba(),
	 * transparent and gradient stops stay editable.
	 */
	function colourField( value, onChange, options ) {
		options = options || {};

		var current = String( value || '' );
		var hex = toHex( current );
		var swatch = el( 'input', {
			type: 'color',
			class: 'cpic-colour__swatch',
			value: hex || '#000000',
			'aria-label': options.label || 'Colour',
		} );

		var text = el( 'input', {
			type: 'text',
			class: 'cpic-input cpic-colour__text',
			value: current,
			placeholder: options.placeholder || '#RRGGBB',
		} );

		swatch.addEventListener( 'input', function () {
			text.value = swatch.value;
			onChange( swatch.value );
		} );

		var commit = function () {
			var next = text.value.trim();
			var nextHex = toHex( next );
			if ( nextHex ) {
				swatch.value = nextHex;
			}
			onChange( next );
		};

		text.addEventListener( 'change', commit );
		text.addEventListener( 'keydown', function ( event ) {
			event.stopPropagation();
			if ( event.key === 'Enter' ) {
				event.preventDefault();
				commit();
			}
		} );

		var wrap = el( 'div', { class: 'cpic-colour' }, [ swatch, text ] );

		if ( options.clearable ) {
			wrap.appendChild(
				el( 'button', {
					type: 'button',
					class: 'cpic-colour__clear',
					title: str( 'clear', 'Clear' ),
					'aria-label': str( 'clear', 'Clear' ),
					onClick: function () {
						text.value = '';
						onChange( '' );
					},
				}, [ iconNode( 'close', 12 ) ] )
			);
		}

		return wrap;
	}

	function toHex( value ) {
		var text = String( value || '' ).trim();

		if ( /^#[0-9a-f]{3}$/i.test( text ) ) {
			return '#' + text[ 1 ] + text[ 1 ] + text[ 2 ] + text[ 2 ] + text[ 3 ] + text[ 3 ];
		}

		if ( /^#[0-9a-f]{6}$/i.test( text ) ) {
			return text.toLowerCase();
		}

		if ( /^#[0-9a-f]{8}$/i.test( text ) ) {
			return text.slice( 0, 7 ).toLowerCase();
		}

		var rgba = text.match( /^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i );
		if ( rgba ) {
			return (
				'#' +
				( '0' + parseInt( rgba[ 1 ], 10 ).toString( 16 ) ).slice( -2 ) +
				( '0' + parseInt( rgba[ 2 ], 10 ).toString( 16 ) ).slice( -2 ) +
				( '0' + parseInt( rgba[ 3 ], 10 ).toString( 16 ) ).slice( -2 )
			);
		}

		return '';
	}

	function section( id, title, children, options ) {
		options = options || {};

		var key = id + ':' + ( options.scope || '' );
		var open = sectionState[ key ] === undefined ? options.open !== false : sectionState[ key ];
		var body = el( 'div', { class: 'cpic-section__body' }, children );
		body.hidden = ! open;

		var header = el( 'button', {
			type: 'button',
			class: 'cpic-section__toggle' + ( open ? ' is-open' : '' ),
			'aria-expanded': String( open ),
			onClick: function () {
				var next = body.hidden;
				body.hidden = ! next;
				header.setAttribute( 'aria-expanded', String( next ) );
				header.classList.toggle( 'is-open', next );
				sectionState[ key ] = next;
			},
		}, [
			iconNode( 'chevron-right', 14 ),
			el( 'span', { text: title } ),
			options.badge ? el( 'span', { class: 'cpic-section__badge', text: options.badge } ) : null,
		] );

		return el( 'section', { class: 'cpic-section', 'data-section': id }, [ header, body ] );
	}

	function pair( a, b, third ) {
		return el( 'div', { class: 'cpic-grid cpic-grid--' + ( third ? '3' : '2' ) }, [ a, b, third || null ] );
	}

	/* ── Inspector: canvas settings ───────────────────────────────── */

	function renderInspector() {
		if ( ! els.inspector ) {
			return;
		}

		// A rebuild must not steal focus from the field the user is typing in
		// (the thumbnail/inspector refresh runs right after each commit).
		var active = document.activeElement;
		var keptFocus =
			active && els.inspector.contains( active )
				? {
						tag: active.tagName,
						className: active.className,
						value: active.value,
						start: active.selectionStart,
						end: active.selectionEnd,
				  }
				: null;

		clear( els.inspector );

		if ( state.selection.length === 0 ) {
			renderCanvasInspector();
		} else if ( state.selection.length > 1 ) {
			renderMultiInspector();
		} else {
			renderElementInspector( state.selection[ 0 ] );
		}

		if ( ! keptFocus ) {
			return;
		}

		var restored = Array.prototype.find.call( els.inspector.querySelectorAll( 'input, select, textarea' ), function ( node ) {
			return node.tagName === keptFocus.tag && node.className === keptFocus.className;
		} );

		if ( ! restored ) {
			return;
		}

		restored.focus();

		try {
			// A rebuilt field shows the committed value; the caret goes back to
			// where it was so typing can continue.
			if ( keptFocus.start !== null && keptFocus.start !== undefined ) {
				restored.setSelectionRange( keptFocus.start, keptFocus.end );
			}
		} catch ( error ) {
			// Input types such as number/colour do not support selections.
		}
	}

	function setInspectorHead( title, badge ) {
		if ( els.inspectorTitle ) {
			els.inspectorTitle.textContent = title;
		}
		if ( els.inspectorBadge ) {
			els.inspectorBadge.textContent = badge || '';
			els.inspectorBadge.hidden = ! badge;
		}
	}

	function renderMultiInspector() {
		setInspectorHead( str( 'multiSelection', 'Multiple layers' ), state.selection.length + '' );

		var list = selectedGeometryList();

		els.inspector.appendChild(
			section( 'multi', str( 'selection', 'Selection' ), [
				el( 'p', { class: 'cpic-muted', text: state.selection.length + ' ' + str( 'layersSelected', 'layers selected.' ) } ),
				el( 'div', { class: 'cpic-btn-row' }, [
					el( 'button', { type: 'button', class: 'cpic-btn cpic-btn--sm', text: str( 'alignLeft', 'Left' ), onClick: function () { alignSelection( 'left' ); } } ),
					el( 'button', { type: 'button', class: 'cpic-btn cpic-btn--sm', text: str( 'centreH', 'Centre' ), onClick: function () { alignSelection( 'center' ); } } ),
					el( 'button', { type: 'button', class: 'cpic-btn cpic-btn--sm', text: str( 'alignRight', 'Right' ), onClick: function () { alignSelection( 'right' ); } } ),
				] ),
				el( 'div', { class: 'cpic-btn-row' }, [
					el( 'button', { type: 'button', class: 'cpic-btn cpic-btn--sm', text: str( 'alignTop', 'Top' ), onClick: function () { alignSelection( 'top' ); } } ),
					el( 'button', { type: 'button', class: 'cpic-btn cpic-btn--sm', text: str( 'alignMiddle', 'Middle' ), onClick: function () { alignSelection( 'middle' ); } } ),
					el( 'button', { type: 'button', class: 'cpic-btn cpic-btn--sm', text: str( 'alignBottom', 'Bottom' ), onClick: function () { alignSelection( 'bottom' ); } } ),
				] ),
			] )
		);

		els.inspector.appendChild(
			section( 'multi-actions', str( 'actions', 'Actions' ), [
				el( 'div', { class: 'cpic-btn-row' }, [
					el( 'button', { type: 'button', class: 'cpic-btn cpic-btn--sm', text: str( 'duplicate', 'Duplicate' ), onClick: duplicateSelection } ),
					el( 'button', { type: 'button', class: 'cpic-btn cpic-btn--sm', text: str( 'lock', 'Lock / unlock' ), onClick: toggleLockSelection } ),
					el( 'button', { type: 'button', class: 'cpic-btn cpic-btn--sm cpic-btn--danger', text: str( 'delete', 'Delete' ), onClick: deleteSelection } ),
				] ),
			] )
		);

		els.inspector.appendChild(
			section( 'multi-list', str( 'layers', 'Layers' ), list.map( function ( geo ) {
				var element = elementFor( geo.id );
				return el( 'div', { class: 'cpic-mini-row' }, [
					iconNode( layerIcon( element ), 14 ),
					el( 'span', { class: 'cpic-mini-row__label', text: element.name }),
					el( 'button', {
						type: 'button',
						class: 'cpic-mini-row__x',
						'aria-label': str( 'deselect', 'Remove from selection' ),
						onClick: function () {
							toggleSelection( geo.id );
							renderSelectionState();
						},
					}, [ iconNode( 'close', 12 ) ] ),
				] );
			} ) )
		);

		hydrateIcons( els.inspector );
	}

	function renderCanvasInspector() {
		var canvas = state.document.canvas || {};
		var size = canvasSize();
		var preset = ( state.meta.formats || {} )[ state.format ] || {};

		setInspectorHead( str( 'canvasSettings', 'Canvas' ), state.format );

		els.inspector.appendChild(
			section( 'canvas-format', str( 'format', 'Format' ), [
				el( 'p', { class: 'cpic-muted', text: ( preset.label || state.format ) + ' · ' + size.width + ' × ' + size.height + ' px' } ),
				el( 'div', { class: 'cpic-format-grid' }, Object.keys( state.meta.formats || {} ).map( function ( key ) {
					var format = state.meta.formats[ key ];
					return el( 'button', {
						type: 'button',
						class: 'cpic-format-card' + ( key === state.format ? ' is-active' : '' ),
						onClick: function () {
							switchFormat( key );
						},
					}, [
						el( 'span', { class: 'cpic-format-card__shape', 'data-ratio': format.ratio, 'aria-hidden': 'true' } ),
						el( 'span', { class: 'cpic-format-card__label', text: format.label } ),
						el( 'span', { class: 'cpic-format-card__dims', text: format.width + '×' + format.height } ),
					] );
				} ) ),
			] )
		);

		var backgroundChildren = [
			fieldRow( str( 'backgroundType', 'Type' ), segmentedField( canvas.background_type || 'gradient', [
				{ value: 'color', label: str( 'solid', 'Solid' ), text: str( 'solid', 'Solid' ) },
				{ value: 'gradient', label: str( 'gradient', 'Gradient' ), text: str( 'gradient', 'Gradient' ) },
				{ value: 'image', label: str( 'image', 'Image' ), text: str( 'image', 'Image' ) },
			], function ( value ) {
				pushHistory( str( 'background', 'Background' ), 'canvas-bg' );
				canvas.background_type = value;
				markDirty();
				requestPlan( true ).then( renderAll );
			} ) ),
		];

		if ( 'color' === ( canvas.background_type || 'gradient' ) ) {
			backgroundChildren.push(
				fieldRow( str( 'colour', 'Colour' ), colourField( canvas.background_color, function ( value ) {
					pushHistory( str( 'background', 'Background' ), 'canvas-colour' );
					canvas.background_color = value;
					markDirty();
					debouncedCanvasPlan();
				} ) )
			);
		}

		if ( 'gradient' === ( canvas.background_type || '' ) ) {
			backgroundChildren.push(
				pair(
					fieldRow( str( 'from', 'From' ), colourField( canvas.gradient_from, function ( value ) {
						pushHistory( str( 'background', 'Background' ), 'canvas-gradient' );
						canvas.gradient_from = value;
						markDirty();
						debouncedCanvasPlan();
					} ) ),
					fieldRow( str( 'to', 'To' ), colourField( canvas.gradient_to, function ( value ) {
						pushHistory( str( 'background', 'Background' ), 'canvas-gradient' );
						canvas.gradient_to = value;
						markDirty();
						debouncedCanvasPlan();
					} ) )
				),
				fieldRow( str( 'angle', 'Angle' ), sliderField( canvas.gradient_angle, { min: 0, max: 360, suffix: '°' }, function ( value ) {
					canvas.gradient_angle = value;
					markDirty();
					debouncedCanvasPlan();
				} ) )
			);
		}

		if ( 'image' === canvas.background_type ) {
			backgroundChildren.push(
				el( 'div', { class: 'cpic-field' }, [
					el( 'span', { class: 'cpic-field__label', text: str( 'backgroundImage', 'Background image' ) } ),
					el( 'div', { class: 'cpic-media-row' }, [
						canvas.background_image
							? el( 'div', { class: 'cpic-media-preview' }, [ el( 'img', { src: canvas.background_image, alt: '' } ) ] )
							: el( 'div', { class: 'cpic-media-empty', text: str( 'noImage', 'No image chosen' ) } ),
						el( 'div', { class: 'cpic-media-actions' }, [
							el( 'button', {
								type: 'button',
								class: 'cpic-btn cpic-btn--sm',
								text: str( 'chooseImage', 'Choose' ),
								onClick: function () {
									openMedia( function ( attachment ) {
										pushHistory( str( 'background', 'Background' ) );
										canvas.background_type = 'image';
										canvas.background_image = attachment.url;
										markDirty();
										requestPlan( true ).then( renderAll );
									} );
								},
							} ),
							canvas.background_image
								? el( 'button', {
									type: 'button',
									class: 'cpic-btn cpic-btn--sm cpic-btn--ghost',
									text: str( 'remove', 'Remove' ),
									onClick: function () {
										pushHistory( str( 'background', 'Background' ) );
										canvas.background_image = '';
										markDirty();
										requestPlan( true ).then( renderAll );
									},
								} )
								: null,
						] ),
					] ),
				] ),
				fieldRow( str( 'imageFit', 'Fit' ), selectField( canvas.background_fit || 'cover', [
					{ value: 'cover', label: str( 'cover', 'Cover' ) },
					{ value: 'contain', label: str( 'contain', 'Contain' ) },
					{ value: 'stretch', label: str( 'stretch', 'Stretch' ) },
				], function ( value ) {
					pushHistory( str( 'background', 'Background' ) );
					canvas.background_fit = value;
					markDirty();
					requestPlan( true ).then( renderAll );
				} ) )
			);
		}

		els.inspector.appendChild( section( 'canvas-bg', str( 'background', 'Background' ), backgroundChildren ) );

		var overlay = canvas.overlay || {};
		els.inspector.appendChild(
			section( 'canvas-overlay', str( 'overlay', 'Overlay' ), [
				toggleField( overlay.enabled, str( 'enableOverlay', 'Dim the background for readable text' ), function ( value ) {
					pushHistory( str( 'overlay', 'Overlay' ) );
					canvas.overlay = Object.assign( { enabled: value, from: 'rgba(9,12,24,0.05)', to: 'rgba(9,12,24,0.82)', angle: 90 }, overlay, { enabled: value } );
					markDirty();
					requestPlan( true ).then( renderAll );
				} ),
				overlay.enabled
					? pair(
						fieldRow( str( 'overlayFrom', 'From' ), colourField( overlay.from || 'rgba(9,12,24,0.05)', function ( value ) {
							canvas.overlay = Object.assign( {}, overlay, { from: value } );
							markDirty();
							debouncedCanvasPlan();
						}, { placeholder: 'rgba(0,0,0,0.5)' } ) ),
						fieldRow( str( 'overlayTo', 'To' ), colourField( overlay.to || 'rgba(9,12,24,0.82)', function ( value ) {
							canvas.overlay = Object.assign( {}, overlay, { to: value } );
							markDirty();
							debouncedCanvasPlan();
						}, { placeholder: 'rgba(0,0,0,0.8)' } ) )
					)
					: null,
			] )
		);

		els.inspector.appendChild(
			section( 'canvas-guides', str( 'guides', 'Guides and grid' ), [
				toggleField( state.showGrid, str( 'showGrid', 'Grid' ), function ( value ) {
					state.showGrid = value;
					els.toggleGrid && els.toggleGrid.setAttribute( 'aria-pressed', String( value ) );
					els.toggleGrid && els.toggleGrid.classList.toggle( 'is-active', value );
					scheduleOverlay();
				} ),
				toggleField( state.showRulers, str( 'showRulers', 'Rulers' ), function ( value ) {
					toggleRulers( value );
				} ),
				toggleField( state.showSafe, str( 'showSafeArea', 'Safe area' ), function ( value ) {
					state.showSafe = value;
					els.toggleSafe && els.toggleSafe.setAttribute( 'aria-pressed', String( value ) );
					els.toggleSafe && els.toggleSafe.classList.toggle( 'is-active', value );
					scheduleOverlay();
				} ),
				toggleField( state.snap, str( 'snapGuides', 'Snap to guides' ), function ( value ) {
					state.snap = value;
					els.toggleSnap && els.toggleSnap.setAttribute( 'aria-pressed', String( value ) );
					els.toggleSnap && els.toggleSnap.classList.toggle( 'is-active', value );
				} ),
				el( 'div', { class: 'cpic-grid cpic-grid--4' }, [ 'top', 'right', 'bottom', 'left' ].map( function ( side ) {
					return el( 'label', { class: 'cpic-field cpic-field--compact' }, [
						el( 'span', { class: 'cpic-field__label', text: side.charAt( 0 ).toUpperCase() } ),
						numberField( ( canvas.safe_area || {} )[ side ], {
							min: 0,
							max: 600,
							onChange: function ( value ) {
								pushHistory( str( 'safeArea', 'Safe area' ), 'safe' );
								canvas.safe_area = Object.assign( { top: 64, right: 64, bottom: 64, left: 64 }, canvas.safe_area || {}, { [ side ]: value } );
								markDirty();
								scheduleOverlay();
								debouncedCanvasPlan();
							},
						} ),
					] );
				} ) ),
			] )
		);

		els.inspector.appendChild(
			section( 'canvas-doc', str( 'document', 'Document' ), [
				el( 'div', { class: 'cpic-kv' }, [
					el( 'span', { text: str( 'name', 'Name' ) } ),
					el( 'strong', { text: state.templateName || str( 'untitled', 'Untitled design' ) } ),
				] ),
				el( 'div', { class: 'cpic-kv' }, [
					el( 'span', { text: str( 'savedAs', 'Template id' ) } ),
					el( 'strong', { text: state.templateId ? '#' + state.templateId : str( 'unsaved', 'Not saved yet' ) } ),
				] ),
				el( 'div', { class: 'cpic-kv' }, [
					el( 'span', { text: str( 'revision', 'Revision' ) } ),
					el( 'strong', { text: state.revision ? String( state.revision ) : '—' } ),
				] ),
				el( 'div', { class: 'cpic-kv' }, [
					el( 'span', { text: str( 'layers', 'Layers' ) } ),
					el( 'strong', { text: String( Object.keys( elements() ).length ) } ),
				] ),
				state.templateId
					? el( 'button', {
						type: 'button',
						class: 'cpic-btn cpic-btn--sm cpic-btn--block',
						onClick: openVersionHistory,
						text: str( 'versionHistory', 'Version history' ),
					} )
					: el( 'p', { class: 'cpic-panel__hint', text: str( 'versionHint', 'Save the design to start recording versions.' ) } ),
			] )
		);

		hydrateIcons( els.inspector );
	}

	var debouncedCanvasPlan = debounce( function () {
		requestPlan( true ).then( renderLayers );
	}, 220 );

	/* ── Inspector: element ───────────────────────────────────────── */

	function renderElementInspector( id ) {
		var element = elementFor( id );

		if ( ! element ) {
			state.selection = [];
			renderCanvasInspector();
			return;
		}

		setInspectorHead( element.name || element.type, element.type );

		els.inspector.appendChild( buildHeaderBlock( id, element ) );
		els.inspector.appendChild( buildTransformSection( id, element ) );

		if ( element.type === 'text' ) {
			els.inspector.appendChild( buildTextSection( id, element ) );
			els.inspector.appendChild( buildTypographySection( id, element ) );
			els.inspector.appendChild( buildTextAppearanceSection( id, element ) );
		}

		if ( element.type === 'image' || element.type === 'logo' || element.type === 'watermark' || element.type === 'svg' ) {
			els.inspector.appendChild( buildImageSection( id, element ) );
		}

		if ( element.type === 'shape' || element.type === 'gradient' || element.type === 'pattern' ) {
			els.inspector.appendChild( buildShapeSection( id, element ) );
		}

		els.inspector.appendChild( buildEffectsSection( id, element ) );

		if ( element.type === 'text' ) {
			els.inspector.appendChild( buildAdvancedTextSection( id, element ) );
		}

		hydrateIcons( els.inspector );
	}

	function buildHeaderBlock( id, element ) {
		var geo = geometry( id ) || { x: 0, y: 0, width: 0, height: 0 };

		return el( 'div', { class: 'cpic-inspector__hero' }, [
			el( 'span', { class: 'cpic-inspector__hero-icon' }, [ iconNode( layerIcon( element ), 16 ) ] ),
			el( 'div', { class: 'cpic-inspector__hero-text' }, [
				el( 'input', {
					type: 'text',
					class: 'cpic-input cpic-input--title',
					value: element.name || element.type,
					'aria-label': str( 'layerName', 'Layer name' ),
					onChange: function ( event ) {
						var value = event.target.value.trim();
						if ( value && value !== element.name ) {
							pushHistory( str( 'rename', 'Rename' ) );
							element.name = value.slice( 0, 80 );
							markDirty();
							renderLayers();
						}
					},
					onKeydown: function ( event ) {
						event.stopPropagation();
					},
				} ),
				el( 'span', { class: 'cpic-muted cpic-muted--sm', text: Math.round( geo.width ) + ' × ' + Math.round( geo.height ) + ' px · ' + element.type } ),
			] ),
			el( 'div', { class: 'cpic-inspector__hero-actions' }, [
				el( 'button', {
					type: 'button',
					class: 'cpic-icon-btn cpic-icon-btn--sm' + ( element.locked ? ' is-active' : '' ),
					title: element.locked ? str( 'unlock', 'Unlock' ) : str( 'lock', 'Lock' ),
					'aria-label': element.locked ? str( 'unlock', 'Unlock' ) : str( 'lock', 'Lock' ),
					onClick: toggleLockSelection,
				}, [ iconNode( element.locked ? 'lock' : 'unlock', 15 ) ] ),
				el( 'button', {
					type: 'button',
					class: 'cpic-icon-btn cpic-icon-btn--sm' + ( element.visible === false ? ' is-active' : '' ),
					title: element.visible === false ? str( 'show', 'Show' ) : str( 'hide', 'Hide' ),
					'aria-label': element.visible === false ? str( 'show', 'Show' ) : str( 'hide', 'Hide' ),
					onClick: function () {
						toggleVisibility( id );
					},
				}, [ iconNode( element.visible === false ? 'eye-off' : 'eye', 15 ) ] ),
			] ),
		] );
	}

	function buildTransformSection( id, element ) {
		var geo = geometry( id ) || { x: 0, y: 0, width: 0, height: 0, rotation: 0, opacity: 1, z: 0 };
		var overrides = overriddenKeys( id );
		var baseFormat = state.document.canvas.format || 'square';
		var scoped = state.format !== baseFormat && Object.keys( overrides ).length > 0;

		var children = [
			scoped
				? el( 'p', { class: 'cpic-panel__hint cpic-panel__hint--inline' }, [
					iconNode( 'info', 13 ),
					el( 'span', { text: str( 'formatScoped', 'Position is set for this format only. Other formats keep their layout.' ) } ),
				] )
				: null,
			pair(
				fieldRow( 'X', numberField( geo.x, { min: -2000, max: 6000, live: 'x', onChange: function ( value ) { commitGeometry( id, { x: value } ); } } ), 'cpic-field--half' ),
				fieldRow( 'Y', numberField( geo.y, { min: -2000, max: 6000, live: 'y', onChange: function ( value ) { commitGeometry( id, { y: value } ); } } ), 'cpic-field--half' )
			),
			pair(
				fieldRow( str( 'width', 'W' ), numberField( geo.width, { min: 1, max: 6000, live: 'width', onChange: function ( value ) { commitGeometry( id, { width: value } ); } } ), 'cpic-field--half' ),
				fieldRow( str( 'height', 'H' ), numberField( geo.height, { min: 0, max: 6000, live: 'height', onChange: function ( value ) { commitGeometry( id, { height: value } ); } } ), 'cpic-field--half' )
			),
			pair(
				fieldRow( str( 'rotation', 'Rotate' ), numberField( geo.rotation, { min: -360, max: 360, step: 1, suffix: '°', onChange: function ( value ) { commitGeometry( id, { rotation: value } ); } } ) ),
				fieldRow( str( 'opacity', 'Opacity' ), numberField( Math.round( num( geo.opacity, 1 ) * 100 ), { min: 0, max: 100, suffix: '%', onChange: function ( value ) {
					writeProp( id, 'opacity', round( value / 100, 3 ) );
					requestPlan( true ).then( renderAll );
				} } ) )
			),
			el( 'div', { class: 'cpic-field' }, [
				el( 'span', { class: 'cpic-field__label', text: str( 'alignToCanvas', 'Align to canvas' ) } ),
				el( 'div', { class: 'cpic-btn-row cpic-btn-row--tight' }, [
					el( 'button', { type: 'button', class: 'cpic-icon-btn cpic-icon-btn--sm', title: str( 'alignLeft', 'Align left' ), onClick: function () { commitGeometry( id, { x: safeArea().left } ); requestPlan( true ).then( renderAll ); } }, [ iconNode( 'alignLeft', 15 ) ] ),
					el( 'button', { type: 'button', class: 'cpic-icon-btn cpic-icon-btn--sm', title: str( 'centreH', 'Centre horizontally' ), onClick: function () { alignToCanvas( 'center' ); } }, [ iconNode( 'alignCenterH', 15 ) ] ),
					el( 'button', { type: 'button', class: 'cpic-icon-btn cpic-icon-btn--sm', title: str( 'alignRight', 'Align right' ), onClick: function () { commitGeometry( id, { x: canvasSize().width - safeArea().right - geo.width } ); requestPlan( true ).then( renderAll ); } }, [ iconNode( 'alignRight', 15 ) ] ),
					el( 'span', { class: 'cpic-btn-row__sep' } ),
					el( 'button', { type: 'button', class: 'cpic-icon-btn cpic-icon-btn--sm', title: str( 'alignTop', 'Align top' ), onClick: function () { commitGeometry( id, { y: safeArea().top } ); requestPlan( true ).then( renderAll ); } }, [ iconNode( 'alignTop', 15 ) ] ),
					el( 'button', { type: 'button', class: 'cpic-icon-btn cpic-icon-btn--sm', title: str( 'centreV', 'Centre vertically' ), onClick: function () { alignToCanvas( 'middle' ); } }, [ iconNode( 'alignMiddle', 15 ) ] ),
					el( 'button', { type: 'button', class: 'cpic-icon-btn cpic-icon-btn--sm', title: str( 'alignBottom', 'Align bottom' ), onClick: function () { commitGeometry( id, { y: canvasSize().height - safeArea().bottom - geo.height } ); requestPlan( true ).then( renderAll ); } }, [ iconNode( 'alignBottom', 15 ) ] ),
				] ),
			] ),
		];

		// Text-specific height note: a 0 height means "grow to fit".
		if ( element.type === 'text' ) {
			children.push(
				el( 'p', { class: 'cpic-panel__hint', text: str( 'textHeightHint', 'Height 0 lets the text grow to fit its box.' ) } )
			);
		}

		return section( 'transform', str( 'positionSize', 'Position and size' ), children );
	}

	function fontOptions() {
		var families = [];
		var seen = {};

		( state.meta.fonts || [] ).forEach( function ( font ) {
			if ( seen[ font.family ] ) {
				return;
			}
			seen[ font.family ] = true;
			families.push( { value: font.family, label: font.bengali ? font.label + ' (Bengali)' : font.label } );
		} );

		if ( ! families.length ) {
			families = [
				{ value: 'Noto Sans Bengali', label: 'Noto Sans Bengali' },
				{ value: 'Noto Serif Bengali', label: 'Noto Serif Bengali' },
				{ value: 'Hind Siliguri', label: 'Hind Siliguri' },
			];
		}

		// Keep a font the document uses even if it is no longer bundled.
		var current = state.selection.length ? elementFor( state.selection[ 0 ] ) : null;
		if ( current && current.font_family && ! seen[ current.font_family ] ) {
			families.push( { value: current.font_family, label: current.font_family } );
		}

		return families;
	}

	function buildTextSection( id, element ) {
		var sources = [
			{ value: 'post_title', label: str( 'srcPostTitle', 'Post title' ) },
			{ value: 'post_excerpt', label: str( 'srcExcerpt', 'Post excerpt' ) },
			{ value: 'post_author', label: str( 'srcAuthor', 'Post author' ) },
			{ value: 'post_date', label: str( 'srcDate', 'Post date' ) },
			{ value: 'post_category', label: str( 'srcCategory', 'Category' ) },
			{ value: 'site_name', label: str( 'srcSiteName', 'Site name' ) },
			{ value: 'site_url', label: str( 'srcSiteUrl', 'Site URL' ) },
			{ value: 'custom_field', label: str( 'srcCustomField', 'Custom field' ) },
			{ value: 'dynamic', label: str( 'srcDynamic', 'Dynamic tokens' ) },
			{ value: 'static', label: str( 'srcStatic', 'Static text' ) },
			{ value: 'none', label: str( 'srcNone', 'None' ) },
		];

		var current = element.source || 'static';
		var known = sources.some( function ( source ) {
			return source.value === current;
		} );

		if ( ! known ) {
			sources.push( { value: current, label: current } );
		}

		var children = [
			fieldRow( str( 'textSource', 'Source' ), selectField( current, sources, function ( value ) {
				pushHistory( str( 'textSource', 'Text source' ) );
				writeProp( id, 'source', value );
				markDirty();
				requestPlan( true ).then( renderAll );
			} ) ),
		];

		if ( 'static' === current ) {
			children.push(
				fieldRow(
					str( 'text', 'Text' ),
					textareaField( element.text || '', function ( value ) {
						pushHistory( str( 'text', 'Edit text' ), 'text-' + id );
						writeProp( id, 'text', value );
						var layer = layerFor( id );
						if ( layer && layer.text ) {
							layer.text.value = value;
						}
						repaintPreview();
						debouncedTextPlan();
					}, 4 )
				),
				el( 'button', {
					type: 'button',
					class: 'cpic-btn cpic-btn--sm cpic-btn--block',
					onClick: function () {
						startTextEdit( id );
					},
				}, [ iconNode( 'type', 14 ), el( 'span', { text: str( 'editOnCanvas', 'Edit on canvas' ) } ) ] )
			);
		}

		if ( 'custom_field' === current || 'dynamic' === current ) {
			children.push(
				fieldRow(
					'custom_field' === current ? str( 'metaKey', 'Meta key' ) : str( 'token', 'Token' ),
					textField( element.dynamic_key || '', function ( value ) {
						pushHistory( str( 'dynamicKey', 'Dynamic key' ) );
						writeProp( id, 'dynamic_key', value );
						markDirty();
						requestPlan( true ).then( renderAll );
					}, { placeholder: 'reporter' } )
				)
			);
		}

		if ( 'post_date' === current ) {
			children.push(
				fieldRow( str( 'dateFormat', 'Date format' ), selectField( element.date_format || 'bangla_long', [
					{ value: 'bangla_long', label: str( 'dateBangla', 'Bengali long date' ) },
					{ value: 'site', label: str( 'dateSite', 'Site date format' ) },
					{ value: 'iso', label: 'ISO (YYYY-MM-DD)' },
				], function ( value ) {
					pushHistory( str( 'dateFormat', 'Date format' ) );
					writeProp( id, 'date_format', value );
					markDirty();
					requestPlan( true ).then( renderAll );
				} ) )
			);
		}

		if ( 'static' !== current ) {
			children.push(
				fieldRow( str( 'fallback', 'Fallback text' ), textField( element.fallback || '', function ( value ) {
					pushHistory( str( 'fallback', 'Fallback' ), 'fallback-' + id );
					writeProp( id, 'fallback', value );
					markDirty();
					debouncedTextPlan();
				}, { placeholder: str( 'fallbackPlaceholder', 'Shown when the post has no value' ) } ) )
			);
		}

		return section( 'text-source', str( 'content', 'Content' ), children );
	}

	var debouncedTextPlan = debounce( function () {
		// requestPlan() repaints the canvas itself.
		requestPlan( true ).then( renderLayers );
	}, 320 );

	function buildTypographySection( id, element ) {
		var families = fontOptions();
		var shipped = fontWeightsFor( element.font_family || 'Noto Sans Bengali' );
		var weightLabels = {
			400: 'Regular (400)',
			500: 'Medium (500)',
			600: 'Semibold (600)',
			700: 'Bold (700)',
			800: 'Extra bold (800)',
			900: 'Black (900)',
		};
		// Offer every weight, but say which ones the family really ships; the
		// rest are drawn with the painter's own bold, so they always show.
		var weights = [ 400, 500, 600, 700, 800, 900 ].map( function ( value ) {
			return {
				value: value,
				label: weightLabels[ value ] + ( shipped.indexOf( value ) === -1 && value > 400 ? ' · simulated' : '' ),
			};
		} );

		return section(
			'typography',
			str( 'typography', 'Typography' ),
			[
				fieldRow( str( 'fontFamily', 'Font' ), selectField( element.font_family || 'Noto Sans Bengali', families, function ( value ) {
					pushHistory( str( 'font', 'Font' ) );
					writeProp( id, 'font_family', value );
					markDirty();
					debouncedTextPlan();
				} ) ),
				pair(
					fieldRow( str( 'fontSize', 'Size' ), numberField( element.font_size, { min: 8, max: 400, suffix: 'px', onChange: function ( value ) {
						pushHistory( str( 'fontSize', 'Font size' ), 'size-' + id );
						writeProp( id, 'font_size', value );
						markDirty();
						debouncedTextPlan();
					} } ) ),
					fieldRow( str( 'weight', 'Weight' ), selectField( num( element.font_weight, 400 ), weights, function ( value ) {
						pushHistory( str( 'weight', 'Weight' ) );
						writeProp( id, 'font_weight', num( value ) );
						markDirty();
						debouncedTextPlan();
					} ) )
				),
				pair(
					fieldRow( str( 'lineHeight', 'Line height' ), numberField( element.line_height, { min: 0.6, max: 3, step: 0.01, onChange: function ( value ) {
						pushHistory( str( 'lineHeight', 'Line height' ), 'lh-' + id );
						writeProp( id, 'line_height', value );
						markDirty();
						debouncedTextPlan();
					} } ) ),
					fieldRow( str( 'letterSpacing', 'Letter spacing' ), numberField( element.letter_spacing, { min: -20, max: 40, step: 0.5, suffix: 'px', onChange: function ( value ) {
						pushHistory( str( 'letterSpacing', 'Letter spacing' ), 'ls-' + id );
						writeProp( id, 'letter_spacing', value );
						markDirty();
						debouncedTextPlan();
					} } ) )
				),
				fieldRow( str( 'style', 'Style' ), segmentedField( element.font_style || 'normal', [
					{ value: 'normal', label: str( 'regular', 'Regular' ), text: str( 'regular', 'Regular' ) },
					{ value: 'italic', label: str( 'italic', 'Italic' ), text: str( 'italic', 'Italic' ) },
				], function ( value ) {
					pushHistory( str( 'style', 'Style' ) );
					writeProp( id, 'font_style', value );
					markDirty();
					debouncedTextPlan();
				} ) ),
				fieldRow( str( 'align', 'Alignment' ), segmentedField( element.align || 'left', [
					{ value: 'left', label: str( 'left', 'Left' ), icon: 'alignLeft' },
					{ value: 'center', label: str( 'centre', 'Centre' ), icon: 'alignCenterH' },
					{ value: 'right', label: str( 'right', 'Right' ), icon: 'alignRight' },
				], function ( value ) {
					pushHistory( str( 'align', 'Alignment' ) );
					writeProp( id, 'align', value );
					markDirty();
					debouncedTextPlan();
				} ) ),
				fieldRow( str( 'verticalAlign', 'Vertical' ), segmentedField( element.vertical_align || 'top', [
					{ value: 'top', label: str( 'top', 'Top' ), icon: 'alignTop' },
					{ value: 'middle', label: str( 'middle', 'Middle' ), icon: 'alignMiddle' },
					{ value: 'bottom', label: str( 'bottom', 'Bottom' ), icon: 'alignBottom' },
				], function ( value ) {
					pushHistory( str( 'verticalAlign', 'Vertical alignment' ) );
					writeProp( id, 'vertical_align', value );
					markDirty();
					debouncedTextPlan();
				} ) ),
				fieldRow( str( 'colour', 'Colour' ), colourField( element.color, function ( value ) {
					pushHistory( str( 'colour', 'Colour' ), 'color-' + id );
					writeProp( id, 'color', value );
					markDirty();
					debouncedTextPlan();
				} ) ),
			],
			{ open: true }
		);
	}

	function buildTextAppearanceSection( id, element ) {
		return section( 'text-box', str( 'textBox', 'Text box' ), [
			pair(
				fieldRow( str( 'paddingX', 'Padding X' ), numberField( element.padding_x, { min: 0, max: 400, suffix: 'px', onChange: function ( value ) {
					pushHistory( str( 'padding', 'Padding' ), 'px-' + id );
					writeProp( id, 'padding_x', value );
					markDirty();
					debouncedTextPlan();
				} } ) ),
				fieldRow( str( 'paddingY', 'Padding Y' ), numberField( element.padding_y, { min: 0, max: 400, suffix: 'px', onChange: function ( value ) {
					pushHistory( str( 'padding', 'Padding' ), 'py-' + id );
					writeProp( id, 'padding_y', value );
					markDirty();
					debouncedTextPlan();
				} } ) )
			),
			fieldRow( str( 'background', 'Background' ), colourField( element.background || '', function ( value ) {
				pushHistory( str( 'background', 'Text background' ), 'bg-' + id );
				writeProp( id, 'background', value );
				markDirty();
				debouncedTextPlan();
			}, { clearable: true, placeholder: str( 'none', 'None' ) } ) ),
			pair(
				fieldRow( str( 'cornerRadius', 'Corner radius' ), numberField( element.border_radius, { min: 0, max: 400, suffix: 'px', onChange: function ( value ) {
					pushHistory( str( 'cornerRadius', 'Corner radius' ), 'radius-' + id );
					writeProp( id, 'border_radius', value );
					markDirty();
					debouncedTextPlan();
				} } ) ),
				fieldRow( str( 'borderWidth', 'Border' ), numberField( element.border_width, { min: 0, max: 40, suffix: 'px', onChange: function ( value ) {
					pushHistory( str( 'borderWidth', 'Border' ), 'bw-' + id );
					writeProp( id, 'border_width', value );
					markDirty();
					debouncedTextPlan();
				} } ) )
			),
			fieldRow( str( 'borderColour', 'Border colour' ), colourField( element.border_color, function ( value ) {
				pushHistory( str( 'borderColour', 'Border colour' ), 'bc-' + id );
				writeProp( id, 'border_color', value );
				markDirty();
				debouncedTextPlan();
			} ) ),
		] );
	}

	function buildEffectsSection( id, element ) {
		var isText = element.type === 'text';

		var children = [
			fieldRow( str( 'shadowColour', 'Shadow colour' ), colourField( element.shadow_color || '', function ( value ) {
				pushHistory( str( 'shadow', 'Shadow' ), 'sc-' + id );
				writeProp( id, 'shadow_color', value );
				markDirty();
				debouncedTextPlan();
			}, { clearable: true, placeholder: 'rgba(0,0,0,0.45)' } ) ),
			pair(
				fieldRow( str( 'shadowBlur', 'Blur' ), numberField( element.shadow_blur, { min: 0, max: 120, suffix: 'px', onChange: function ( value ) {
					pushHistory( str( 'shadow', 'Shadow' ), 'sb-' + id );
					writeProp( id, 'shadow_blur', value );
					markDirty();
					debouncedTextPlan();
				} } ) ),
				fieldRow( str( 'shadowY', 'Offset Y' ), numberField( element.shadow_offset_y, { min: -80, max: 80, suffix: 'px', onChange: function ( value ) {
					pushHistory( str( 'shadow', 'Shadow' ), 'sy-' + id );
					writeProp( id, 'shadow_offset_y', value );
					markDirty();
					debouncedTextPlan();
				} } ) )
			),
			fieldRow( str( 'shadowX', 'Offset X' ), numberField( element.shadow_offset_x, { min: -80, max: 80, suffix: 'px', onChange: function ( value ) {
				pushHistory( str( 'shadow', 'Shadow' ), 'sx-' + id );
				writeProp( id, 'shadow_offset_x', value );
				markDirty();
				debouncedTextPlan();
			} } ) ),
		];

		if ( isText ) {
			children.push(
				pair(
					fieldRow( str( 'strokeWidth', 'Stroke' ), numberField( element.stroke_width, { min: 0, max: 20, suffix: 'px', onChange: function ( value ) {
						pushHistory( str( 'stroke', 'Stroke' ), 'sw-' + id );
						writeProp( id, 'stroke_width', value );
						markDirty();
						debouncedTextPlan();
					} } ) ),
					fieldRow( str( 'strokeColour', 'Stroke colour' ), colourField( element.stroke_color || '', function ( value ) {
						pushHistory( str( 'stroke', 'Stroke' ), 'sk-' + id );
						writeProp( id, 'stroke_color', value );
						markDirty();
						debouncedTextPlan();
					}, { clearable: true } ) )
				)
			);
		}

		return section( 'effects', str( 'effects', 'Effects' ), children );
	}

	function buildAdvancedTextSection( id, element ) {
		return section( 'advanced-text', str( 'advanced', 'Advanced' ), [
			pair(
				fieldRow( str( 'maxLines', 'Max lines' ), numberField( element.max_lines, { min: 0, max: 20, onChange: function ( value ) {
					pushHistory( str( 'maxLines', 'Max lines' ), 'ml-' + id );
					writeProp( id, 'max_lines', value );
					markDirty();
					debouncedTextPlan();
				} }, 0 ) ),
				fieldRow( str( 'minFontSize', 'Min size' ), numberField( element.min_font_size, { min: 6, max: 400, suffix: 'px', onChange: function ( value ) {
					pushHistory( str( 'minFontSize', 'Minimum size' ), 'mf-' + id );
					writeProp( id, 'min_font_size', value );
					markDirty();
					debouncedTextPlan();
				} } ) )
			),
			fieldRow( str( 'maxFontSize', 'Max size' ), numberField( element.max_font_size, { min: 8, max: 400, suffix: 'px', onChange: function ( value ) {
				pushHistory( str( 'maxFontSize', 'Maximum size' ), 'xf-' + id );
				writeProp( id, 'max_font_size', value );
				markDirty();
				debouncedTextPlan();
			} } ) ),
			toggleField( !! element.auto_scale, str( 'autoScale', 'Auto-scale to fit the box' ), function ( value ) {
				pushHistory( str( 'autoScale', 'Auto scale' ) );
				writeProp( id, 'auto_scale', value );
				markDirty();
				debouncedTextPlan();
			} ),
			toggleField( !! element.ellipsis, str( 'ellipsis', 'Ellipsis when text overflows' ), function ( value ) {
				pushHistory( str( 'ellipsis', 'Ellipsis' ) );
				writeProp( id, 'ellipsis', value );
				markDirty();
				debouncedTextPlan();
			} ),
			toggleField( !! element.uppercase, str( 'uppercase', 'Uppercase' ), function ( value ) {
				pushHistory( str( 'uppercase', 'Uppercase' ) );
				writeProp( id, 'uppercase', value );
				markDirty();
				debouncedTextPlan();
			} ),
			fieldRow( str( 'trimWords', 'Limit words' ), numberField( element.trim_words, { min: 0, max: 200, onChange: function ( value ) {
				pushHistory( str( 'trimWords', 'Trim words' ), 'tw-' + id );
				writeProp( id, 'trim_words', value );
				markDirty();
				requestPlan( true ).then( renderAll );
			} }, 0 ), '0 = no limit' ),
			el( 'p', { class: 'cpic-panel__hint', text: str( 'bengaliHint', 'Bengali conjuncts are shaped by the browser; the PNG uses the same fonts and the same painter.' ) } ),
		] );
	}

	function buildImageSection( id, element ) {
		var url = element.source === 'custom_url' ? element.url : ( layerFor( id ) && layerFor( id ).image ? layerFor( id ).image.url : element.url );

		var children = [
			el( 'div', { class: 'cpic-field' }, [
				el( 'span', { class: 'cpic-field__label', text: str( 'image', 'Image' ) } ),
				el( 'div', { class: 'cpic-media-row' }, [
					url
						? el( 'div', { class: 'cpic-media-preview' }, [ el( 'img', { src: url, alt: '' } ) ] )
						: el( 'div', { class: 'cpic-media-empty' }, [ iconNode( 'image', 18 ), el( 'span', { text: str( 'noImageChosen', 'No image' ) } ) ] ),
					el( 'div', { class: 'cpic-media-actions' }, [
						el( 'button', {
							type: 'button',
							class: 'cpic-btn cpic-btn--sm',
							onClick: function () {
								openMediaFor( id );
							},
						}, [ iconNode( 'upload', 14 ), el( 'span', { text: str( 'chooseImage', 'Choose' ) } ) ] ),
						el( 'button', {
							type: 'button',
							class: 'cpic-btn cpic-btn--sm cpic-btn--ghost',
							onClick: function () {
								pushHistory( str( 'removeImage', 'Remove image' ) );
								writeProps( id, { source: 'none', url: '' } );
								markDirty();
								requestPlan( true ).then( renderAll );
							},
						}, [ iconNode( 'trash', 14 ), el( 'span', { text: str( 'remove', 'Remove' ) } ) ] ),
					] ),
				] ),
			] ),
			fieldRow( str( 'imageSource', 'Source' ), selectField( element.source || 'featured_image', [
				{ value: 'featured_image', label: str( 'srcFeatured', 'Post featured image' ) },
				{ value: 'custom_url', label: str( 'srcCustomUrl', 'Custom URL / media library' ) },
				{ value: 'brand_logo', label: str( 'srcBrandLogo', 'Brand logo' ) },
				{ value: 'brand_logo_light', label: str( 'srcBrandLogoLight', 'Brand logo (light)' ) },
				{ value: 'brand_logo_dark', label: str( 'srcBrandLogoDark', 'Brand logo (dark)' ) },
				{ value: 'brand_watermark', label: str( 'srcWatermark', 'Brand watermark' ) },
				{ value: 'post_meta_image', label: str( 'srcMetaImage', 'Post meta image' ) },
				{ value: 'asset', label: str( 'srcAsset', 'Media asset id' ) },
				{ value: 'none', label: str( 'srcNone', 'None' ) },
			], function ( value ) {
				pushHistory( str( 'imageSource', 'Image source' ) );
				writeProp( id, 'source', value );
				markDirty();
				requestPlan( true ).then( renderAll );
			} ) ),
		];

		if ( 'custom_url' === element.source ) {
			children.push(
				fieldRow( str( 'imageUrl', 'URL' ), textField( element.url || '', function ( value ) {
					pushHistory( str( 'imageUrl', 'Image URL' ), 'url-' + id );
					writeProp( id, 'url', value );
					markDirty();
					requestPlan( true ).then( renderAll );
				}, { placeholder: 'https://…' } ) )
			);
		}

		children.push(
			fieldRow( str( 'fit', 'Fit' ), segmentedField( element.fit || 'cover', [
				{ value: 'cover', label: str( 'cover', 'Cover' ), text: str( 'cover', 'Cover' ) },
				{ value: 'contain', label: str( 'contain', 'Contain' ), text: str( 'contain', 'Contain' ) },
				{ value: 'fill', label: str( 'fill', 'Fill' ), text: str( 'fill', 'Fill' ) },
				{ value: 'none', label: str( 'actual', 'Actual' ), text: str( 'actual', 'Actual' ) },
			], function ( value ) {
				pushHistory( str( 'fit', 'Image fit' ) );
				writeProp( id, 'fit', value );
				markDirty();
				requestPlan( true ).then( renderAll );
			} ) ),
			pair(
				fieldRow( str( 'focusX', 'Focus X' ), sliderField( Math.round( num( element.focus_x, 0.5 ) * 100 ), { min: 0, max: 100, suffix: '%' }, function ( value ) {
					writeProp( id, 'focus_x', round( value / 100, 3 ) );
					debouncedFocus( id );
				} ) ),
				fieldRow( str( 'focusY', 'Focus Y' ), sliderField( Math.round( num( element.focus_y, 0.5 ) * 100 ), { min: 0, max: 100, suffix: '%' }, function ( value ) {
					writeProp( id, 'focus_y', round( value / 100, 3 ) );
					debouncedFocus( id );
				} ) )
			),
			pair(
				fieldRow( str( 'cornerRadius', 'Corner radius' ), numberField( element.border_radius, { min: 0, max: 900, suffix: 'px', onChange: function ( value ) {
					pushHistory( str( 'cornerRadius', 'Corner radius' ), 'ir-' + id );
					writeProp( id, 'border_radius', value );
					markDirty();
					debouncedTextPlan();
				} } ) ),
				fieldRow( str( 'borderWidth', 'Border' ), numberField( element.border_width, { min: 0, max: 60, suffix: 'px', onChange: function ( value ) {
					pushHistory( str( 'borderWidth', 'Border' ), 'ib-' + id );
					writeProp( id, 'border_width', value );
					markDirty();
					debouncedTextPlan();
				} } ) )
			),
			fieldRow( str( 'borderColour', 'Border colour' ), colourField( element.border_color, function ( value ) {
				pushHistory( str( 'borderColour', 'Border colour' ), 'ic-' + id );
				writeProp( id, 'border_color', value );
				markDirty();
				debouncedTextPlan();
			} ) ),
			toggleField( !! element.natural_ratio, str( 'naturalRatio', 'Keep natural aspect ratio' ), function ( value ) {
				pushHistory( str( 'naturalRatio', 'Aspect ratio' ) );
				writeProp( id, 'natural_ratio', value );
				markDirty();
			} ),
			fieldRow( str( 'fallback', 'Missing image' ), selectField( element.fallback || 'gradient', [
				{ value: 'gradient', label: str( 'fallbackGradient', 'Gradient placeholder' ) },
				{ value: 'blur', label: str( 'fallbackBlur', 'Blurred block' ) },
				{ value: 'hide', label: str( 'fallbackHide', 'Hide the layer' ) },
			], function ( value ) {
				pushHistory( str( 'fallback', 'Fallback' ) );
				writeProp( id, 'fallback', value );
				markDirty();
				requestPlan( true ).then( renderAll );
			} ) )
		);

		return section( 'image', str( 'image', 'Image' ), children );
	}

	var debouncedFocus = debounce( function () {
		requestPlan( true );
	}, 200 );

	function buildShapeSection( id, element ) {
		return section( 'shape', str( 'shape', 'Shape' ), [
			fieldRow( str( 'shape', 'Shape' ), segmentedField( element.shape || 'rect', [
				{ value: 'rect', label: str( 'rectangle', 'Rectangle' ), icon: 'rect' },
				{ value: 'circle', label: str( 'circle', 'Circle' ), icon: 'circle' },
				{ value: 'ellipse', label: str( 'ellipse', 'Ellipse' ), icon: 'ellipse' },
				{ value: 'line', label: str( 'line', 'Line' ), icon: 'line' },
			], function ( value ) {
				pushHistory( str( 'shape', 'Shape' ) );
				writeProp( id, 'shape', value );
				markDirty();
				requestPlan( true ).then( renderAll );
			} ) ),
			fieldRow( str( 'fill', 'Fill' ), colourField( element.fill, function ( value ) {
				pushHistory( str( 'fill', 'Fill' ), 'fill-' + id );
				writeProp( id, 'fill', value );
				markDirty();
				debouncedTextPlan();
			} ) ),
			el( 'div', { class: 'cpic-field' }, [
				el( 'span', { class: 'cpic-field__label', text: str( 'gradient', 'Gradient' ) } ),
				pair(
					colourField( element.gradient_from || '', function ( value ) {
						pushHistory( str( 'gradient', 'Gradient' ), 'gf-' + id );
						writeProp( id, 'gradient_from', value );
						markDirty();
						debouncedTextPlan();
					}, { clearable: true, placeholder: str( 'from', 'From' ) } ),
					colourField( element.gradient_to || '', function ( value ) {
						pushHistory( str( 'gradient', 'Gradient' ), 'gt-' + id );
						writeProp( id, 'gradient_to', value );
						markDirty();
						debouncedTextPlan();
					}, { clearable: true, placeholder: str( 'to', 'To' ) } )
				),
			] ),
			fieldRow( str( 'angle', 'Gradient angle' ), sliderField( element.gradient_angle, { min: 0, max: 360, suffix: '°' }, function ( value ) {
				writeProp( id, 'gradient_angle', value );
				debouncedTextPlan();
			} ) ),
			pair(
				fieldRow( str( 'cornerRadius', 'Corner radius' ), numberField( element.border_radius, { min: 0, max: 900, suffix: 'px', onChange: function ( value ) {
					pushHistory( str( 'cornerRadius', 'Corner radius' ), 'sr-' + id );
					writeProp( id, 'border_radius', value );
					markDirty();
					debouncedTextPlan();
				} } ) ),
				fieldRow( str( 'borderWidth', 'Border' ), numberField( element.stroke_width, { min: 0, max: 60, suffix: 'px', onChange: function ( value ) {
					pushHistory( str( 'borderWidth', 'Border' ), 'ssw-' + id );
					writeProp( id, 'stroke_width', value );
					markDirty();
					debouncedTextPlan();
				} } ) )
			),
			fieldRow( str( 'borderColour', 'Border colour' ), colourField( element.stroke_color || '', function ( value ) {
				pushHistory( str( 'borderColour', 'Border colour' ), 'ssc-' + id );
				writeProp( id, 'stroke_color', value );
				markDirty();
				debouncedTextPlan();
			}, { clearable: true } ) ),
		] );
	}

	/* ═══ 8. Design and elements panels ══════════════════════════ */

	function renderDesignPanel() {
		renderNewFormats();
		renderTemplateList();
		renderStarterList();
	}

	function renderNewFormats() {
		var host = document.getElementById( 'cpic-new-formats' );

		if ( ! host ) {
			return;
		}

		clear( host );

		Object.keys( state.meta.formats || {} ).forEach( function ( key ) {
			var format = state.meta.formats[ key ];
			host.appendChild(
				el( 'button', {
					type: 'button',
					class: 'cpic-chip' + ( key === ( refs.newFormat || 'square' ) ? ' is-active' : '' ),
					title: format.label + ' · ' + format.width + '×' + format.height,
					'data-format': key,
					'aria-pressed': String( key === ( refs.newFormat || 'square' ) ),
					onClick: function () {
						refs.newFormat = key;
						renderNewFormats();
					},
				}, [
					el( 'span', { class: 'cpic-chip__shape', 'data-ratio': format.ratio, 'aria-hidden': 'true' } ),
					el( 'span', { text: format.label }),
				] )
			);
		} );
	}

	function renderTemplateList() {
		var host = document.getElementById( 'cpic-design-list' );
		var count = document.getElementById( 'cpic-template-count' );

		if ( ! host ) {
			return;
		}

		var query = ( refs.templateSearch || '' ).toLowerCase();
		var list = ( state.meta.templates || [] ).filter( function ( template ) {
			return ! query || template.name.toLowerCase().indexOf( query ) !== -1;
		} );

		if ( count ) {
			count.textContent = String( ( state.meta.templates || [] ).length );
		}

		clear( host );

		if ( ! list.length ) {
			host.appendChild(
				el( 'li', { class: 'cpic-empty cpic-empty--list' }, [
					iconNode( 'template', 22 ),
					el( 'p', { text: query ? str( 'noMatch', 'No design matches that search.' ) : str( 'noTemplates', 'No saved designs yet. Save this one to start your library.' ) } ),
				] )
			);
			return;
		}

		list.forEach( function ( template ) {
			var active = template.id === state.templateId;
			var preset = ( state.meta.formats || {} )[ template.format ] || { ratio: '1:1' };

			host.appendChild(
				el( 'li', {}, [
					el( 'button', {
						type: 'button',
						class: 'cpic-design-item' + ( active ? ' is-active' : '' ),
						title: template.name,
						onClick: function () {
							openTemplate( template.id );
						},
					}, [
						el( 'span', { class: 'cpic-design-item__thumb' }, [
							el( 'span', { class: 'cpic-design-item__shape', 'data-ratio': preset.ratio, 'aria-hidden': 'true' }),
						] ),
						el( 'span', { class: 'cpic-design-item__text' }, [
							el( 'span', { class: 'cpic-design-item__name', text: template.name }),
							el( 'span', { class: 'cpic-design-item__meta', text: template.width + '×' + template.height + ' · rev ' + template.revision }),
						] ),
						el( 'span', {
							class: 'cpic-design-item__menu',
							role: 'button',
							tabindex: '0',
							title: str( 'moreActions', 'More actions' ),
							onClick: function ( event ) {
								event.stopPropagation();
								openMenu( event.currentTarget, [
									{ label: str( 'openDesign', 'Open' ), icon: 'external', run: function () { openTemplate( template.id ); } },
									{ label: str( 'duplicateDesign', 'Duplicate' ), icon: 'copy', run: function () { duplicateTemplate( template.id ); } },
									{ label: str( 'versionHistory', 'Version history' ), icon: 'history', disabled: ! state.meta.can.save, run: function () {
										if ( state.templateId === template.id ) {
											openVersionHistory();
										} else {
											toast( str( 'openFirst', 'Open that design first to see its versions.' ), 'info' );
										}
									} },
									{ separator: true },
									{ label: str( 'deleteDesign', 'Delete design' ), icon: 'trash', danger: true, run: function () { deleteTemplate( template ); } },
								], { align: 'end' } );
							},
						}, [ iconNode( 'more', 15 ) ] ),
					] ),
				] )
			);
		} );

		hydrateIcons( host );
	}

	function renderStarterList() {
		var host = document.getElementById( 'cpic-starter-list' );

		if ( ! host ) {
			return;
		}

		clear( host );

		( state.meta.starters || [] ).forEach( function ( starter ) {
			var preset = ( state.meta.formats || {} )[ starter.format ] || { ratio: '1:1' };

			host.appendChild(
				el( 'li', {}, [
					el( 'button', {
						type: 'button',
						class: 'cpic-design-item',
						title: starter.description || starter.name,
						onClick: function () {
							openStarter( starter );
						},
					}, [
						el( 'span', { class: 'cpic-design-item__thumb' }, [
							el( 'span', { class: 'cpic-design-item__shape', 'data-ratio': preset.ratio, 'aria-hidden': 'true' }),
						] ),
						el( 'span', { class: 'cpic-design-item__text' }, [
							el( 'span', { class: 'cpic-design-item__name', text: starter.name }),
							el( 'span', { class: 'cpic-design-item__meta', text: starter.description || '' }),
						] ),
					] ),
				] )
			);
		} );

		hydrateIcons( host );
	}

	function renderElementsPanel() {
		var groups = {
			'cpic-elements-text': [
				{
					label: str( 'addText', 'Text' ),
					icon: 'type',
					run: function () {
						addElement( function () {
							return defaultText( { name: str( 'newText', 'Text' ), source: 'static', text: str( 'newTextSample', 'আপনার লেখা এখানে লিখুন' ), font_size: 44, z: 40 } );
						}, 'text' );
					},
				},
				{
					label: str( 'addHeading', 'Heading' ),
					icon: 'heading',
					run: function () {
						addElement( function () {
							return defaultText( { name: str( 'newHeading', 'Heading' ), source: 'post_title', font_size: 64, max_font_size: 96, font_weight: 700, z: 40 } );
						}, 'heading' );
					},
				},
				{
					label: str( 'addSubheading', 'Subheading' ),
					icon: 'type',
					run: function () {
						addElement( function () {
							return defaultText( { name: str( 'newSubheading', 'Subheading' ), source: 'post_excerpt', font_size: 34, max_lines: 3, font_weight: 400, z: 38 } );
						}, 'subheading' );
					},
				},
				{
					label: str( 'addChip', 'Label chip' ),
					icon: 'star',
					run: function () {
						addElement( function () {
							return defaultText( {
								name: str( 'newChip', 'Label' ),
								source: 'post_category',
								font_size: 24,
								max_lines: 1,
								uppercase: true,
								background: '#E11D48',
								padding_x: 18,
								padding_y: 8,
								border_radius: 6,
								width: 320,
								z: 42,
							} );
						}, 'label' );
					},
				},
			],
			'cpic-elements-media': [
				{
					label: str( 'addImage', 'Featured image' ),
					icon: 'image',
					run: function () {
						addElement( function () {
							return defaultImage( { name: str( 'newImage', 'Post image' ), source: 'featured_image' } );
						}, 'image' );
					},
				},
				{
					label: str( 'addLibraryImage', 'Media library' ),
					icon: 'upload',
					run: function () {
						openMedia( function ( attachment ) {
							addElement( function () {
								return defaultImage( {
									name: attachment.title || str( 'newImage', 'Image' ),
									source: 'custom_url',
									url: attachment.url,
									width: Math.round( canvasSize().width * 0.6 ),
									height: Math.round( canvasSize().width * 0.6 ),
									x: Math.round( canvasSize().width * 0.2 ),
									y: Math.round( canvasSize().height * 0.2 ),
								} );
							}, 'image' );
						} );
					},
				},
				{
					label: str( 'addLogo', 'Logo' ),
					icon: 'star',
					run: function () {
						addElement( function () {
							return defaultImage( {
								type: 'logo',
								name: str( 'newLogo', 'Logo' ),
								source: 'brand_logo',
								width: Math.round( canvasSize().width * 0.16 ),
								height: Math.round( canvasSize().width * 0.16 ),
								x: canvasSize().width - Math.round( canvasSize().width * 0.16 ) - safeArea().right,
								y: safeArea().top,
								z: 45,
							} );
						}, 'logo' );
					},
				},
				{
					label: str( 'addWatermark', 'Watermark' ),
					icon: 'watermark',
					run: function () {
						addElement( function () {
							return defaultImage( {
								type: 'watermark',
								name: str( 'newWatermark', 'Watermark' ),
								source: 'brand_watermark',
								width: Math.round( canvasSize().width * 0.3 ),
								height: Math.round( canvasSize().width * 0.3 ),
								opacity: 0.25,
								z: 60,
							} );
						}, 'watermark' );
					},
				},
			],
			'cpic-elements-shapes': [
				{
					label: str( 'addRect', 'Rectangle' ),
					icon: 'rect',
					run: function () {
						addElement( function () {
							return defaultShape( { name: str( 'newRect', 'Rectangle' ), shape: 'rect', width: 240, height: 160, border_radius: 12 } );
						}, 'rect' );
					},
				},
				{
					label: str( 'addCircle', 'Circle' ),
					icon: 'circle',
					run: function () {
						addElement( function () {
							return defaultShape( { name: str( 'newCircle', 'Circle' ), shape: 'circle', width: 200, height: 200 } );
						}, 'circle' );
					},
				},
				{
					label: str( 'addEllipse', 'Ellipse' ),
					icon: 'ellipse',
					run: function () {
						addElement( function () {
							return defaultShape( { name: str( 'newEllipse', 'Ellipse' ), shape: 'ellipse', width: 280, height: 160 } );
						}, 'ellipse' );
					},
				},
				{
					label: str( 'addLine', 'Rule' ),
					icon: 'line',
					run: function () {
						addElement( function () {
							return defaultShape( { name: str( 'newLine', 'Rule' ), shape: 'rect', width: Math.round( canvasSize().width * 0.2 ), height: 8, border_radius: 4 } );
						}, 'rule' );
					},
				},
			],
		};

		Object.keys( groups ).forEach( function ( hostId ) {
			var host = document.getElementById( hostId );
			if ( ! host ) {
				return;
			}

			clear( host );

			groups[ hostId ].forEach( function ( item ) {
				host.appendChild(
					el( 'button', {
						type: 'button',
						class: 'cpic-element-tile',
						title: item.label,
						onClick: item.run,
					}, [
						el( 'span', { class: 'cpic-element-tile__icon' }, [ iconNode( item.icon, 20 ) ] ),
						el( 'span', { class: 'cpic-element-tile__label', text: item.label } ),
					] )
				);
			} );

			hydrateIcons( host );
		} );
	}

	/* ── Media library ────────────────────────────────────────────── */

	function openMedia( callback, options ) {
		options = options || {};

		if ( ! window.wp || ! window.wp.media ) {
			toast( str( 'mediaUnavailable', 'The media library is unavailable on this screen.' ), 'error' );
			return;
		}

		var frame = window.wp.media( {
			title: options.title || str( 'chooseImageTitle', 'Choose an image' ),
			multiple: false,
			library: { type: 'image' },
			button: { text: options.button || str( 'useImage', 'Use this image' ) },
		} );

		frame.on( 'select', function () {
			var selection = frame.state().get( 'selection' ).first();
			var attachment = selection ? selection.toJSON() : null;

			if ( ! attachment || ! attachment.url ) {
				return;
			}

			callback( {
				id: attachment.id,
				url: attachment.url,
				title: attachment.title,
				width: attachment.width,
				height: attachment.height,
			} );
		} );

		frame.open();
	}

	function openMediaFor( id ) {
		var element = elementFor( id );

		if ( ! element ) {
			return;
		}

		openMedia( function ( attachment ) {
			pushHistory( str( 'replaceImage', 'Replace image' ) );
			writeProps( id, {
				source: 'custom_url',
				url: attachment.url,
				asset_id: attachment.id || 0,
			} );

			// Adopt the image's aspect ratio when the element keeps one.
			if ( element.natural_ratio && attachment.width && attachment.height && num( element.width ) > 0 ) {
				writeProp( id, 'height', Math.round( num( element.width ) * ( attachment.height / attachment.width ) ) );
			}

			markDirty();
			requestPlan( true ).then( function () {
				renderAll();
				toast( str( 'imageReplaced', 'Image replaced' ), 'success', { duration: 2000 } );
			} );
		} );
	}

	function bindMediaDragDrop() {
		if ( ! els.canvasArea ) {
			return;
		}

		[ 'dragenter', 'dragover' ].forEach( function ( type ) {
			els.canvasArea.addEventListener( type, function ( event ) {
				if ( event.dataTransfer && Array.prototype.indexOf.call( event.dataTransfer.types, 'Files' ) !== -1 ) {
					event.preventDefault();
					els.canvasArea.classList.add( 'is-dropping' );
				}
			} );
		} );

		els.canvasArea.addEventListener( 'dragleave', function ( event ) {
			if ( event.target === els.canvasArea ) {
				els.canvasArea.classList.remove( 'is-dropping' );
			}
		} );

		els.canvasArea.addEventListener( 'drop', function ( event ) {
			var files = event.dataTransfer ? event.dataTransfer.files : null;

			if ( ! files || ! files.length ) {
				return;
			}

			event.preventDefault();
			els.canvasArea.classList.remove( 'is-dropping' );

			var file = files[ 0 ];

			if ( ! /^image\//.test( file.type ) ) {
				toast( str( 'notAnImage', 'That file is not an image.' ), 'error' );
				return;
			}

			uploadImage( file );
		} );
	}

	/**
	 * Upload a dropped/pasted image through the WordPress media endpoint and
	 * place it as a new image layer. Uses the core REST media route, so the
	 * usual capability checks and sanitising apply.
	 *
	 * @param {File} file Image file.
	 * @return {Promise<void>}
	 */
	async function uploadImage( file ) {
		var toastNode = str( 'uploading', 'Uploading image…' );
		toast( toastNode, 'info', { duration: 2500 } );

		try {
			var response = await fetch( CONFIG.restRoot.replace( /\/$/, '' ) + '/wp/v2/media', {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': CONFIG.nonce || '',
					'Content-Type': file.type,
					'Content-Disposition': 'attachment; filename="' + file.name.replace( /"/g, '' ) + '"',
				},
				body: file,
			} );

			var payload = null;

			try {
				payload = await response.json();
			} catch ( error ) {
				payload = null;
			}

			if ( ! response.ok || ! payload || ! payload.source_url ) {
				throw new Error( payload && payload.message ? payload.message : str( 'uploadFailed', 'The image could not be uploaded.' ) );
			}

			addElement( function () {
				return defaultImage( {
					name: ( payload.title && payload.title.rendered ) || file.name,
					source: 'custom_url',
					url: payload.source_url,
					asset_id: payload.id || 0,
					width: Math.min( canvasSize().width, Math.round( canvasSize().width * 0.6 ) ),
					height: Math.round( canvasSize().width * 0.6 ),
				} );
			}, 'image' );
		} catch ( error ) {
			toast( error.message || str( 'uploadFailed', 'The image could not be uploaded.' ), 'error' );
		}
	}

	/* ═══ 9. Save, export, publish, versions ════════════════════ */

	function serializeDocument() {
		// Drop editor-only scratch fields before the document leaves the page.
		var doc = clone( state.document );

		if ( doc.elements ) {
			Object.keys( doc.elements ).forEach( function ( id ) {
				delete doc.elements[ id ].__preview;
			} );
		}

		return doc;
	}

	/**
	 * Ask the server for a render plan and paint it.
	 *
	 * @param {boolean} silent      Show no indicator.
	 * @param {Object}  [options]   { reportErrors } — report failures the user
	 *                              should see even on a quiet request.
	 * @return {Promise<Object|null>} Plan.
	 */
	async function requestPlan( silent, options ) {
		options = options || {};

		if ( ! state.document ) {
			return null;
		}

		var seq = ++state.planSeq;
		state.planPending = seq;

		// Every visible request owns the indicator while it waits, and always
		// clears it in `finally`. A spinner that never goes away reads as a
		// broken renderer even when the design painted perfectly.
		var visible = ! silent;
		if ( visible ) {
			showCanvasLoading( true );
		}

		try {
			var payload = await api( CONFIG.restPath + 'render/plan', {
				method: 'POST',
				body: {
					document: serializeDocument(),
					format: state.format,
					post_id: state.postId || 0,
					brand_id: state.brandId || 0,
				},
			} );

			var plan = payload && payload.data ? payload.data : payload;

			// Ignore an obsolete response (a newer request is in flight).
			if ( seq !== state.planSeq ) {
				return state.plan;
			}

			if ( plan && plan.document ) {
				// The server returns the sanitised document; adopting it keeps
				// the editor honest about what will actually be saved.
				adoptServerDocument( plan.document );
			}

			state.plan = plan;

			// One place shows the new plan: the document that produced it can be
			// painted locally, so a property edit does not need a second pass,
			// and a gesture cannot render the stale document.
			repaintPreview( serializeDocument() );

			return plan;
		} catch ( error ) {
			// A background repaint may fail quietly, but a re-render the user
			// asked for must never fail silently: that is how a dead server
			// looked like a working editor.
			var shouldReport = options.reportErrors || ! silent;

			if ( seq === state.planSeq && shouldReport ) {
				toast( error.message || str( 'renderFailed', 'Rendering failed' ), 'error' );
			}
			return state.plan;
		} finally {
			// Clear it unconditionally: a superseded request must not leave the
			// indicator on screen on behalf of the one that replaced it.
			if ( visible ) {
				showCanvasLoading( false );
			}
		}
	}

	/**
	 * Adopt the server's sanitised document without discarding the local ids
	 * or the layout overrides the studio owns.
	 *
	 * @param {Object} incoming Sanitised document from the plan endpoint.
	 * @return {void}
	 */
	function adoptServerDocument( incoming ) {
		if ( ! incoming || ! incoming.elements ) {
			return;
		}

		// The plan response is authoritative about defaults/coercions; keep the
		// local object identity so open editors keep working.
		var wantedFormat = state.document.canvas && state.document.canvas.format;

		state.document.canvas = incoming.canvas;

		// The canvas format is the document's *base* format; a plan for another
		// format must not silently re-base the whole document.
		if ( wantedFormat && ! state.document.canvas.format ) {
			state.document.canvas.format = wantedFormat;
		}

		// Plan layers lack the source-level keys (source, conditions, …), so
		// they are never merged into the editable document.
		state.document.layout = incoming.layout;
		state.document.brand = incoming.brand;

		Object.keys( incoming.elements ).forEach( function ( id ) {
			var serverElement = incoming.elements[ id ];

			if ( ! state.document.elements[ id ] ) {
				state.document.elements[ id ] = serverElement;
				return;
			}

			var local = state.document.elements[ id ];

			Object.keys( serverElement ).forEach( function ( key ) {
				if ( key === '__preview' ) {
					return;
				}
				// Geometry being dragged is local by definition.
				if ( local.__preview && ( key === 'x' || key === 'y' ) ) {
					return;
				}
				local[ key ] = serverElement[ key ];
			} );
		} );
	}

	function showCanvasLoading( show ) {
		if ( els.loading ) {
			els.loading.hidden = ! show;
		}
	}

	async function saveDesign( options ) {
		options = options || {};

		if ( state.saving ) {
			// Never fire a second save on top of the first: queue one instead.
			state.pendingSave = true;
			return null;
		}

		if ( ! state.document || state.meta.can.save === false ) {
			if ( state.meta.can.save === false ) {
				toast( str( 'cannotSave', 'You do not have permission to save designs.' ), 'error' );
			}
			return null;
		}

		state.saving = true;
		refreshSaveState();

		try {
			var body = {
				name: state.templateName || str( 'untitled', 'Untitled design' ),
				document: serializeDocument(),
			};

			var payload;

			if ( state.templateId > 0 ) {
				payload = await api( CONFIG.restPath + 'templates/' + state.templateId, { method: 'PUT', body: body } );
			} else {
				// An explicit first save is required: no silent template spam.
				payload = await api( CONFIG.restPath + 'templates', { method: 'POST', body: body } );
			}

			var saved = payload && payload.data ? payload.data : payload;

			if ( saved && saved.id ) {
				state.templateId = saved.id;
				state.revision = saved.revision || state.revision;
				state.templateName = saved.name || state.templateName;
				upsertTemplateRow( saved );
				syncDocName();
			}

			state.dirty = false;
			state.lastSavedAt = Date.now();
			refreshSaveState();
			renderInspector();
			renderTemplateList();

			toast( str( 'designSaved', 'Design saved' ), 'success', { duration: 2200 } );
			return saved;
		} catch ( error ) {
			setSaveState( 'error', str( 'saveFailed', 'Could not save' ) );
			toast( error.message || str( 'saveFailed', 'Could not save' ), 'error' );
			return null;
		} finally {
			state.saving = false;
			refreshSaveState();

			if ( state.pendingSave ) {
				state.pendingSave = false;
				window.setTimeout( function () {
					saveDesign();
				}, 150 );
			}
		}
	}

	function upsertTemplateRow( saved ) {
		var rows = state.meta.templates || [];
		var found = false;

		rows.forEach( function ( row ) {
			if ( row.id === saved.id ) {
				found = true;
				row.name = saved.name || row.name;
				row.revision = saved.revision || row.revision;
				row.updatedAt = saved.updated_at || row.updatedAt;
			}
		} );

		if ( ! found ) {
			var summary = saved.summary || {};
			rows.unshift( {
				id: saved.id,
				name: saved.name,
				format: summary.format || state.format,
				width: summary.width || canvasSize().width,
				height: summary.height || canvasSize().height,
				revision: saved.revision || 1,
				updatedAt: saved.updated_at || '',
			} );
		}

		state.meta.templates = rows;
	}

	/**
	 * Export a PNG of the current plan.
	 *
	 * Paints into an offscreen canvas at the plan's own pixel size, so the file
	 * is never a scaled-up screenshot of the on-screen canvas.
	 *
	 * @return {Promise<{dataUrl: string, width: number, height: number, name: string}>} Result.
	 */
	async function renderPng() {
		var plan = state.plan;

		if ( ! plan ) {
			plan = await requestPlan( true, { reportErrors: true } );
		}

		if ( ! plan ) {
			// Say what actually happened: the editor never received a plan, so
			// "Rendering failed" (which sounds like a broken renderer) is wrong.
			throw new Error( str( 'noPlan', 'The design could not be prepared for export. Check your connection, then reload the page.' ) );
		}

		await ensureFonts( plan.fonts );
		await preloadPlanImages( plan );

		var target = document.createElement( 'canvas' );
		target.width = num( plan.width, 1080 );
		target.height = num( plan.height, 1080 );

		// Reuse the exact painter by temporarily painting into the target.
		var interactive = els.canvas;
		var wasHeadless = els.headless;
		els.canvas = target;
		els.headless = true;

		try {
			await paint( plan );
		} finally {
			els.canvas = interactive;
			els.headless = wasHeadless;
		}

		// The report is ground truth about what was actually drawn: an export
		// that used fallbacks is not a clean export, so the caller must say so
		// rather than promise a perfect PNG.
		return {
			dataUrl: target.toDataURL( 'image/png' ),
			width: target.width,
			height: target.height,
			name: ( state.templateName || 'design' ).replace( /[^a-z0-9\-_ ]/gi, '' ).trim() || 'design',
			problems: paintReport.warnings.slice(),
		};
	}

	function downloadDataUrl( dataUrl, filename ) {
		var link = document.createElement( 'a' );
		link.href = dataUrl;
		link.download = filename;
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
	}

	async function downloadPng() {
		try {
			toast( str( 'exporting', 'Preparing the PNG…' ), 'info', { duration: 2000 } );
			var png = await renderPng();
			downloadDataUrl( png.dataUrl, png.name + '-' + state.format + '.png' );

			if ( png.problems && png.problems.length ) {
				toast( png.problems[ 0 ], 'warning', { duration: 7000 } );
			} else {
				toast( str( 'exported', 'PNG downloaded' ), 'success' );
			}
		} catch ( error ) {
			toast( error.message || str( 'renderFailed', 'Rendering failed' ), 'error' );
		}
	}

	async function storeArtifact( options ) {
		options = options || {};

		var png = await renderPng();

		var payload = await api( CONFIG.restPath + 'artifacts', {
			method: 'POST',
			body: {
				post_id: state.postId || 0,
				template_id: state.templateId || 0,
				format: state.format,
				document: serializeDocument(),
				binary: png.dataUrl,
				attach: !! options.attach,
			},
		} );

		return {
			artifact: payload && payload.data ? payload.data : payload,
			png: png,
		};
	}

	async function renderAndStore( options ) {
		try {
			var result = await storeArtifact( options );
			var artifact = result.artifact || {};

			showResult( artifact, result.png );

			if ( result.png.problems && result.png.problems.length ) {
				toast( result.png.problems[ 0 ], 'warning', { duration: 7000 } );
			}
			// Copy only when the server really stored the file.
			toast(
				artifact && artifact.id
					? str( 'renderStored', 'Image rendered and stored' )
					: str( 'renderOnly', 'Image rendered — it was not stored on the server.' ),
				artifact && artifact.id ? 'success' : 'warning'
			);

			return result;
		} catch ( error ) {
			toast( error.message || str( 'renderFailed', 'Rendering failed' ), 'error' );
			throw error;
		}
	}

	function showResult( artifact, png ) {
		if ( ! els.result || ! els.resultBody ) {
			return;
		}

		els.result.hidden = false;
		clear( els.resultBody );

		var url = artifact && artifact.file_url ? artifact.file_url : ( png ? png.dataUrl : '' );

		if ( url ) {
			els.resultBody.appendChild( el( 'img', { class: 'cpic-result__img', src: url, alt: str( 'renderPreview', 'Rendered design' ) } ) );
		}

		els.resultBody.appendChild(
			el( 'div', { class: 'cpic-result__meta' }, [
				el( 'span', { text: ( artifact.width || ( png && png.width ) ) + ' × ' + ( artifact.height || ( png && png.height ) ) + ' px' }),
				artifact && artifact.format ? el( 'span', { class: 'cpic-badge', text: artifact.format }) : null,
				artifact && artifact.bytes ? el( 'span', { text: Math.round( artifact.bytes / 1024 ) + ' KB' }) : null,
			] )
		);

		var actions = el( 'div', { class: 'cpic-result__actions' } );

		if ( png ) {
			actions.appendChild(
				el( 'button', {
					type: 'button',
					class: 'cpic-btn cpic-btn--sm',
					onClick: function () {
						downloadDataUrl( png.dataUrl, ( png.name || 'design' ) + '-' + state.format + '.png' );
					},
				}, [ iconNode( 'download', 14 ), el( 'span', { text: str( 'downloadPng', 'Download PNG' ) } ) ] )
			);
		}

		if ( artifact && artifact.id ) {
			actions.appendChild(
				el( 'button', {
					type: 'button',
					class: 'cpic-btn cpic-btn--sm',
					onClick: function () {
						attachArtifact( artifact.id );
					},
				}, [ iconNode( 'upload', 14 ), el( 'span', { text: str( 'attachMedia', 'Add to media library' ) } ) ] )
			);

			if ( artifact.attachment_id ) {
				actions.appendChild(
					el( 'a', {
						class: 'cpic-btn cpic-btn--sm cpic-btn--ghost',
						href: CONFIG.adminUrl + 'upload.php?item=' + artifact.attachment_id,
						target: '_blank',
						rel: 'noopener',
					}, [ iconNode( 'external', 14 ), el( 'span', { text: str( 'openMedia', 'Open in media library' ) } ) ] )
				);
			}

			if ( artifact.file_url ) {
				actions.appendChild(
					el( 'a', {
						class: 'cpic-btn cpic-btn--sm cpic-btn--ghost',
						href: artifact.file_url,
						target: '_blank',
						rel: 'noopener',
					}, [ iconNode( 'external', 14 ), el( 'span', { text: str( 'openArtifact', 'Open image' ) } ) ] )
				);
			}
		}

		els.resultBody.appendChild( actions );
		hydrateIcons( els.resultBody );
	}

	async function attachArtifact( id ) {
		try {
			await api( CONFIG.restPath + 'artifacts/' + id + '/attach', { method: 'POST', body: {} } );
			toast( str( 'attached', 'Added to the media library' ), 'success' );
		} catch ( error ) {
			toast( error.message || str( 'attachFailed', 'The image could not be attached.' ), 'error' );
		}
	}

	/* ── Publishing ───────────────────────────────────────────────── */

	async function publishDesign() {
		if ( ! state.postId ) {
			toast( str( 'choosePost', 'Choose a post first — publishing needs one.' ), 'error' );
			openPostPicker();
			return;
		}

		var destinations = state.meta.destinations || [];
		var body = el( 'div', { class: 'cpic-publish' } );

		body.appendChild(
			el( 'div', { class: 'cpic-kv' }, [
				el( 'span', { text: str( 'post', 'Post' ) } ),
				el( 'strong', { text: currentPostTitle() } ),
			] )
		);
		body.appendChild(
			el( 'div', { class: 'cpic-kv' }, [
				el( 'span', { text: str( 'format', 'Format' ) } ),
				el( 'strong', { text: ( ( state.meta.formats || {} )[ state.format ] || {} ).label || state.format } ),
			] )
		);
		body.appendChild(
			el( 'div', { class: 'cpic-kv' }, [
				el( 'span', { text: str( 'design', 'Design' ) } ),
				el( 'strong', { text: state.templateName }),
			] )
		);

		var selected = [];

		destinations.forEach( function ( destination ) {
			var input = el( 'input', { type: 'checkbox', class: 'cpic-checkbox__input', value: String( destination.id ), checked: destination.status === 'connected' } );
			input.addEventListener( 'change', function () {
				var index = selected.indexOf( destination.id );
				if ( input.checked && index === -1 ) {
					selected.push( destination.id );
				} else if ( ! input.checked && index !== -1 ) {
					selected.splice( index, 1 );
				}
			} );

			if ( destination.status === 'connected' ) {
				selected.push( destination.id );
			}

			body.appendChild(
				el( 'label', { class: 'cpic-checkbox' }, [
					input,
					el( 'span', { class: 'cpic-checkbox__text' }, [
						el( 'strong', { text: destination.label || ( '# ' + destination.id ) }),
						el( 'span', { class: 'cpic-muted cpic-muted--sm', text: destination.provider + ' · ' + destination.status }),
					] ),
				] )
			);
		} );

		if ( ! destinations.length ) {
			body.appendChild(
				el( 'p', { class: 'cpic-panel__hint', text: str( 'noDestinations', 'No publishing destinations are connected. Images will still render and be stored; add an account to publish.' ) } )
			);
		}

		body.appendChild(
			el( 'p', { class: 'cpic-panel__hint', text: str( 'publishHint', 'The image is rendered in this browser first, then published through the same endpoint the automations use.' ) } )
		);

		var progress = el( 'div', { class: 'cpic-publish__progress', hidden: true } );

		openDialog( {
			title: str( 'publishImage', 'Publish image' ),
			body: body,
			actions: [
				{ label: str( 'cancel', 'Cancel' ) },
				{
					label: str( 'publish', 'Publish' ),
					primary: true,
					keepOpen: true,
					run: async function ( event ) {
						// `event.currentTarget` is nulled as soon as the handler
						// yields, so capture the button *now*: reading it after an
						// await used to throw inside `finally` and replace the real
						// error message with a TypeError.
						var button = ( event && event.currentTarget ) || els.modalFoot.querySelector( '.cpic-btn--primary' );
						if ( button ) {
							button.disabled = true;
						}

						progress.hidden = false;
						clear( progress );
						progress.appendChild( el( 'span', { class: 'cpic-spinner' } ) );
						progress.appendChild( el( 'span', { text: str( 'publishing', 'Publishing…' ) } ) );
						body.appendChild( progress );

						try {
							var stored = await storeArtifact();
							var artifact = stored.artifact || {};

							var payload = await api( CONFIG.restPath + 'publishing/publish', {
								method: 'POST',
								body: {
									post_id: state.postId,
									format: state.format,
									destination_ids: selected,
									artifact_id: artifact.id || 0,
									overrides: {},
								},
							} );

							var result = payload && payload.data ? payload.data : payload;

							// The run is accepted even when the provider refuses it,
							// so "Published" must be earned: read the per-delivery
							// outcomes before promising anything.
							var outcomes = ( result && result.results ) || [];
							var failed = outcomes.filter( function ( row ) {
								return row && ( row.status === 'failed' || row.status === 'not_claimed' );
							} );
							var waiting = ( result && ( result.waiting_render || result.render_error ) ) || outcomes.some( function ( row ) {
								return row && row.status === 'waiting_for_artifact';
							} );

							clear( progress );

							if ( failed.length ) {
								var reason = failed[ 0 ].error_message || str( 'publishFailed', 'Publishing failed' );
								progress.appendChild( iconNode( 'warning', 16 ) );
								progress.appendChild( el( 'span', { text: reason } ) );
								toast( reason, 'error', { duration: 9000 } );
								state.lastPublishFailed = true;
								return;
							}

							if ( outcomes.length ) {
								progress.appendChild( iconNode( 'check', 16 ) );
								progress.appendChild(
									el( 'span', {
										text: str( 'published', 'Published' ) + ' — ' + outcomes.length + ' ' + str( 'destinations', 'destination(s)' ),
									} )
								);
								toast( str( 'published', 'Published' ), 'success' );
								showResult( artifact, stored.png );
								return;
							}

							// No destinations were chosen: the image is stored and
							// scheduled, but nothing was posted. Saying "Published"
							// here is exactly the misleading success we must avoid.
							progress.appendChild( iconNode( 'warning', 16 ) );
							progress.appendChild(
								el( 'span', {
									text: waiting
										? str( 'publishedWaiting', 'Saved for publishing — the image will be sent when it is ready.' )
										: str( 'publishedStored', 'Image saved. Choose a destination to publish it now.' ),
								} )
							);
							toast(
								waiting ? str( 'publishedWaiting', 'Saved for publishing.') : str( 'publishedStored', 'Image saved — nothing was posted.' ),
								'warning'
							);
						} catch ( error ) {
							clear( progress );
							progress.appendChild( iconNode( 'warning', 16 ) );
							progress.appendChild( el( 'span', { text: error.message || str( 'publishFailed', 'Publishing failed' ) } ) );
							toast( error.message || str( 'publishFailed', 'Publishing failed' ), 'error' );
							state.lastPublishFailed = true;
						} finally {
							if ( button ) {
								button.disabled = false;
							}
						}
					},
				},
			],
		} );
	}

	function currentPostTitle() {
		var found = null;

		( state.meta.posts || [] ).forEach( function ( post ) {
			if ( post.id === state.postId ) {
				found = post;
			}
		} );

		return found ? found.title : str( 'noPost', 'No post selected' );
	}

	function openPostPicker() {
		var body = el( 'div', { class: 'cpic-post-picker' } );
		var search = el( 'input', { type: 'search', class: 'cpic-input', placeholder: str( 'searchPosts', 'Search posts' ) } );
		var list = el( 'div', { class: 'cpic-post-list' } );

		body.appendChild( search );
		body.appendChild( list );

		var renderList = function () {
			var query = search.value.toLowerCase();
			clear( list );

			var posts = ( state.meta.posts || [] ).filter( function ( post ) {
				return ! query || post.title.toLowerCase().indexOf( query ) !== -1;
			} );

			if ( ! posts.length ) {
				list.appendChild( el( 'p', { class: 'cpic-empty', text: str( 'noPosts', 'No posts found.' ) } ) );
				return;
			}

			posts.forEach( function ( post ) {
				list.appendChild(
					el( 'button', {
						type: 'button',
						class: 'cpic-post-item' + ( post.id === state.postId ? ' is-active' : '' ),
						onClick: function () {
							state.postId = post.id;
							closeDialog();
							requestPlan( true ).then( renderAll );
							toast( str( 'postSelected', 'Post selected' ) + ': ' + post.title, 'success', { duration: 2200 } );
						},
					}, [
						post.thumbnail ? el( 'img', { class: 'cpic-post-item__thumb', src: post.thumbnail, alt: '' } ) : el( 'span', { class: 'cpic-post-item__thumb cpic-post-item__thumb--empty' }, [ iconNode( 'image', 14 ) ]),
						el( 'span', { class: 'cpic-post-item__text' }, [
							el( 'span', { class: 'cpic-post-item__title', text: post.title || '#' + post.id }),
							el( 'span', { class: 'cpic-post-item__meta', text: post.date + ' · ' + post.status }),
						] ),
					] )
				);
			} );
		};

		search.addEventListener( 'input', renderList );
		renderList();

		openDialog( {
			title: str( 'choosePost', 'Choose a post' ),
			body: body,
			actions: [
				{ label: str( 'clearPost', 'No post (sample text)' ), run: function () {
					state.postId = 0;
					requestPlan( true ).then( renderAll );
				} },
				{ label: str( 'close', 'Close' ) },
			],
		} );
	}

	/* ── Version history ─────────────────────────────────────────── */

	async function openVersionHistory() {
		if ( ! state.templateId ) {
			toast( str( 'saveFirst', 'Save the design before restoring a version.' ), 'info' );
			return;
		}

		var body = el( 'div', { class: 'cpic-versions' }, [
			el( 'p', { class: 'cpic-muted', text: str( 'versionsLoading', 'Loading versions…' ) } ),
		] );

		openDialog( {
			title: str( 'versionHistory', 'Version history' ),
			body: body,
			actions: [ { label: str( 'close', 'Close' ) } ],
		} );

		try {
			var payload = await api( CONFIG.restPath + 'templates/' + state.templateId + '/versions' );
			var versions = ( payload && payload.data ? payload.data : payload ).versions || [];

			clear( body );

			if ( ! versions.length ) {
				body.appendChild( el( 'p', { class: 'cpic-empty', text: str( 'noVersions', 'No earlier versions yet.' ) } ) );
				return;
			}

			versions.forEach( function ( version ) {
				body.appendChild(
					el( 'div', { class: 'cpic-version' }, [
						el( 'div', { class: 'cpic-version__text' }, [
							el( 'strong', { text: str( 'revision', 'Revision' ) + ' ' + version.revision }),
							el( 'span', { class: 'cpic-muted cpic-muted--sm', text: version.created_at }),
						] ),
						el( 'button', {
							type: 'button',
							class: 'cpic-btn cpic-btn--sm',
							disabled: state.meta.can.save ? null : 'disabled',
							onClick: async function () {
								var confirmed = await confirmDialog( {
									title: str( 'restoreTitle', 'Restore this version?' ),
									message: str( 'restoreMessage', 'The current design is snapshotted first, so this is reversible.' ),
									confirmLabel: str( 'restore', 'Restore' ),
								} );

								if ( ! confirmed ) {
									return;
								}

								try {
									var response = await api( CONFIG.restPath + 'templates/' + state.templateId + '/versions/' + version.id + '/restore', {
										method: 'POST',
										body: {},
									} );
									var restored = response && response.data ? response.data : response;

									pushHistory( str( 'restore', 'Restore version' ) );

									if ( restored && restored.document ) {
										state.document = restored.document;
										state.revision = restored.revision || state.revision;
										state.templateName = restored.name || state.templateName;
										state.selection = [];
										state.dirty = false;
										closeDialog();
										requestPlan( true ).then( function () {
											renderAll();
											syncDocName();
										} );
										toast( str( 'restored', 'Version restored' ), 'success' );
									}
								} catch ( error ) {
									toast( error.message || str( 'restoreFailed', 'That version could not be restored.' ), 'error' );
								}
							},
						}, [ iconNode( 'retry', 14 ), el( 'span', { text: str( 'restore', 'Restore' ) } ) ] ),
					] )
				);
			} );

			hydrateIcons( body );
		} catch ( error ) {
			clear( body );
			body.appendChild( el( 'p', { class: 'cpic-error-text', text: error.message || str( 'versionsFailed', 'Versions could not be loaded.' ) } ) );
		}
	}

	/* ── Templates ────────────────────────────────────────────────── */

	async function openTemplate( id ) {
		if ( id === state.templateId ) {
			return;
		}

		if ( state.dirty ) {
			var proceed = await confirmDialog( {
				title: str( 'unsavedTitle', 'Discard unsaved changes?' ),
				message: str( 'unsavedMessage', 'This design has changes that are not saved yet.' ),
				confirmLabel: str( 'discard', 'Discard and open' ),
				danger: true,
			} );

			if ( ! proceed ) {
				return;
			}
		}

		try {
			var payload = await api( CONFIG.restPath + 'templates/' + id );
			var template = payload && payload.data ? payload.data : payload;

			if ( ! template || ! template.document ) {
				throw new Error( str( 'openFailed', 'That design could not be opened.' ) );
			}

			state.document = template.document;
			state.templateId = template.id;
			state.templateName = template.name;
			state.revision = template.revision || 1;
			state.format = ( template.document.canvas && template.document.canvas.format ) || 'square';
			state.selection = [];
			state.dirty = false;
			state.history.past.length = 0;
			state.history.future.length = 0;

			updateHistoryButtons();
			syncDocName();
			syncFormatButtons();

			await requestPlan( true );
			renderAll();
			fitToScreen();
			toast( str( 'designOpened', 'Design opened' ) + ': ' + template.name, 'success', { duration: 2000 } );
		} catch ( error ) {
			toast( error.message || str( 'openFailed', 'That design could not be opened.' ), 'error' );
		}
	}

	async function openStarter( starter ) {
		if ( state.dirty ) {
			var proceed = await confirmDialog( {
				title: str( 'unsavedTitle', 'Discard unsaved changes?' ),
				message: str( 'starterMessage', 'Starting from a starter replaces this design. Unsaved changes will be lost.' ),
				confirmLabel: str( 'discard', 'Discard and continue' ),
				danger: true,
			} );

			if ( ! proceed ) {
				return;
			}
		}

		if ( ! starter.document ) {
			toast( str( 'starterUnavailable', 'That starter could not be loaded.' ), 'error' );
			return;
		}

		state.document = clone( starter.document );
		state.templateId = 0;
		state.revision = 0;
		state.templateName = nameFromStarter( starter );
		state.format = ( state.document.canvas && state.document.canvas.format ) || 'square';
		state.selection = [];
		state.dirty = true;
		state.history.past.length = 0;
		state.history.future.length = 0;

		updateHistoryButtons();
		syncDocName();
		syncFormatButtons();
		refreshSaveState();

		// The first plan of a session is user-visible: a blank canvas with no
		// explanation is the worst failure mode there is.
		await requestPlan( true, { reportErrors: true } );
		renderAll();
		fitToScreen();
		toast( str( 'starterLoaded', 'Starter loaded — save it to keep it.' ), 'success' );
	}

	function nameFromStarter( starter ) {
		return starter.name ? starter.name : str( 'untitled', 'Untitled design' );
	}

	async function duplicateTemplate( id ) {
		if ( ! state.meta.can.save ) {
			toast( str( 'cannotSave', 'You do not have permission to save designs.' ), 'error' );
			return;
		}

		try {
			var payload = await api( CONFIG.restPath + 'templates/' + id + '/duplicate', { method: 'POST', body: {} } );
			var copy = payload && payload.data ? payload.data : payload;

			if ( copy && copy.id ) {
				upsertTemplateRow( copy );
				renderTemplateList();
				toast( str( 'designDuplicated', 'Design duplicated' ), 'success' );
			}
		} catch ( error ) {
			toast( error.message || str( 'duplicateFailed', 'That design could not be duplicated.' ), 'error' );
		}
	}

	async function deleteTemplate( template ) {
		if ( ! state.meta.can.save ) {
			toast( str( 'cannotSave', 'You do not have permission to delete designs.' ), 'error' );
			return;
		}

		var confirmed = await confirmDialog( {
			title: str( 'deleteDesignTitle', 'Delete this design?' ),
			message: str( 'deleteDesignMessage', 'The design leaves your library. Images already published are not affected.' ),
			detail: template.name,
			confirmLabel: str( 'delete', 'Delete' ),
			danger: true,
		} );

		if ( ! confirmed ) {
			return;
		}

		try {
			await api( CONFIG.restPath + 'templates/' + template.id, { method: 'DELETE' } );
			state.meta.templates = ( state.meta.templates || [] ).filter( function ( row ) {
				return row.id !== template.id;
			} );

			if ( state.templateId === template.id ) {
				state.templateId = 0;
				state.revision = 0;
				state.dirty = true;
				syncDocName();
				refreshSaveState();
			}

			renderTemplateList();
			toast( str( 'designDeleted', 'Design deleted' ), 'success' );
		} catch ( error ) {
			toast( error.message || str( 'deleteFailed', 'That design could not be deleted.' ), 'error' );
		}
	}

	function createNewDesign() {
		var nameInput = document.getElementById( 'cpic-new-name' );
		var name = nameInput && nameInput.value.trim() ? nameInput.value.trim() : str( 'untitled', 'Untitled design' );
		var format = refs.newFormat || 'square';
		var preset = ( state.meta.formats || {} )[ format ] || { width: 1080, height: 1080, safe: { top: 64, right: 64, bottom: 64, left: 64 } };

		var confirmStart = async function () {
			if ( state.dirty ) {
				var proceed = await confirmDialog( {
					title: str( 'unsavedTitle', 'Discard unsaved changes?' ),
					message: str( 'newDesignMessage', 'Creating a new design closes this one. Unsaved changes will be lost.' ),
					confirmLabel: str( 'discard', 'Discard and create' ),
					danger: true,
				} );

				if ( ! proceed ) {
					return;
				}
			}

			state.document = blankDocument( format, preset );
			state.templateId = 0;
			state.revision = 0;
			state.templateName = name;
			state.format = format;
			state.selection = [];
			state.dirty = true;
			state.history.past.length = 0;
			state.history.future.length = 0;

			updateHistoryButtons();
			syncDocName();
			syncFormatButtons();
			refreshSaveState();

			await requestPlan( true );
			renderAll();
			fitToScreen();
			switchTab( 'elements' );
			toast( str( 'newDesignCreated', 'New design ready — save it to add it to your library.' ), 'success' );

			if ( nameInput ) {
				nameInput.value = '';
			}
		};

		confirmStart();
	}

	/**
	 * Build a blank document client-side, mirroring TemplateSchema::blank().
	 *
	 * @param {string} format Format key.
	 * @param {Object} preset Format preset.
	 * @return {Object} Document.
	 */
	function blankDocument( format, preset ) {
		var width = num( preset.width, 1080 );
		var height = num( preset.height, 1080 );
		var safe = preset.safe || { top: 64, right: 64, bottom: 64, left: 64 };

		var document_ = {
			schema: '7.0',
			canvas: {
				width: width,
				height: height,
				format: format,
				background_type: 'gradient',
				background_color: '#0F172A',
				gradient_from: '#667EEA',
				gradient_to: '#764BA2',
				gradient_angle: 135,
				background_image: '',
				background_fit: 'cover',
				padding: 48,
				safe_area: { top: safe.top, right: safe.right, bottom: safe.bottom, left: safe.left },
				grid: 0,
				rulers: 0,
				overlay: { enabled: true, from: 'rgba(9,12,24,0.05)', to: 'rgba(9,12,24,0.82)', angle: 90 },
			},
			elements: {},
			layout: {},
			brand: { id: 0, overrides: {} },
			caption: { include_headline: 1, include_first_paragraph: 1, include_link: 1, hashtags: '' },
			meta: { source: 'studio', variants: [ format ] },
		};

		var headline = defaultText( {
			id: 'headline',
			name: str( 'headline', 'Headline' ),
			source: 'post_title',
			y: Math.round( height * 0.62 ),
			width: width - safe.left - safe.right,
			x: safe.left,
			font_size: Math.round( width * 0.048 ),
			max_font_size: Math.round( width * 0.075 ),
			z: 40,
		} );

		var eyebrow = defaultText( {
			id: 'category',
			name: str( 'category', 'Category' ),
			source: 'post_category',
			font_size: Math.round( width * 0.024 ),
			max_lines: 1,
			x: safe.left,
			y: Math.round( height * 0.55 ),
			background: '#E11D48',
			padding_x: 18,
			padding_y: 8,
			border_radius: 6,
			uppercase: true,
			letter_spacing: 1,
			width: Math.max( 160, Math.round( width * 0.35 ) ),
			z: 35,
		} );

		var featured = defaultImage( {
			id: 'featured_image',
			name: str( 'featuredImage', 'Featured image' ),
			width: width,
			height: Math.round( height * 0.62 ),
			x: 0,
			y: 0,
			z: 10,
		} );

		var logo = defaultImage( {
			id: 'logo',
			type: 'logo',
			name: str( 'logo', 'Logo' ),
			source: 'brand_logo',
			width: Math.round( width * 0.14 ),
			height: Math.round( width * 0.14 ),
			x: width - safe.right - Math.round( width * 0.14 ),
			y: safe.top,
			z: 45,
		} );

		var accent = defaultShape( {
			id: 'accent_bar',
			name: str( 'accentBar', 'Accent bar' ),
			x: safe.left,
			y: Math.round( height * 0.585 ),
			width: Math.round( width * 0.12 ),
			height: 8,
			fill: '#E11D48',
			z: 33,
		} );

		[ featured, accent, eyebrow, headline, logo ].forEach( function ( element ) {
			var key = element.id;
			delete element.id;
			document_.elements[ key ] = Object.assign( { id: key }, element );
		} );

		return document_;
	}

	/* ═══ 10. Panels, formats, preferences, shortcuts, boot ══════ */

	function switchTab( name ) {
		Array.prototype.forEach.call( document.querySelectorAll( '.cpic-tab' ), function ( tab ) {
			var active = tab.getAttribute( 'data-tab' ) === name;
			tab.classList.toggle( 'is-active', active );
			tab.setAttribute( 'aria-selected', String( active ) );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( '.cpic-tabpanel' ), function ( panel ) {
			panel.hidden = panel.getAttribute( 'data-panel' ) !== name;
		} );
	}

	function setPanel( side, open ) {
		var panel = side === 'left' ? els.panelLeft : els.panelRight;
		var toggle = side === 'left' ? els.toggleLeft : els.toggleRight;

		if ( ! panel ) {
			return;
		}

		panel.classList.toggle( 'is-collapsed', ! open );
		els.app.classList.toggle( 'is-' + side + '-collapsed', ! open );

		if ( toggle ) {
			toggle.setAttribute( 'aria-expanded', String( open ) );
		}

		state.prefs[ side + 'PanelOpen' ] = open;
		savePrefs();

		window.setTimeout( function () {
			if ( state.fitMode ) {
				fitToScreen();
			} else {
				applyViewportTransform();
				scheduleOverlay();
			}
			drawRulers();
		}, 260 );
	}

	function togglePanel( side ) {
		var collapsed = els.app.classList.contains( 'is-' + side + '-collapsed' );
		setPanel( side, collapsed );
	}

	function savePrefs() {
		try {
			window.localStorage.setItem( STORE_PREFS, JSON.stringify( state.prefs ) );
		} catch ( error ) {
			// Storage can be unavailable in private mode; preferences are a nicety.
		}
	}

	function loadPrefs() {
		try {
			var raw = window.localStorage.getItem( STORE_PREFS );
			if ( raw ) {
				state.prefs = Object.assign( state.prefs, JSON.parse( raw ) );
			}
		} catch ( error ) {
			// Ignored.
		}
	}

	/* ── Format switching ─────────────────────────────────────────── */

	function syncFormatButtons() {
		Array.prototype.forEach.call( document.querySelectorAll( '.cpic-format' ), function ( button ) {
			var active = button.getAttribute( 'data-format' ) === state.format;
			button.setAttribute( 'aria-pressed', String( active ) );
			button.classList.toggle( 'is-active', active );
		} );
	}

	/**
	 * Switch output format.
	 *
	 * The document's base canvas keeps its own dimensions; a different format
	 * uses that format's preset plus any existing `layout.<format>` overrides,
	 * which are never discarded.
	 *
	 * @param {string} format Format key.
	 * @return {Promise<void>}
	 */
	async function switchFormat( format ) {
		if ( ! format || format === state.format ) {
			return;
		}

		state.format = format;
		syncFormatButtons();

		// Remember every format used, so variants() reports them.
		if ( ! state.document.meta ) {
			state.document.meta = {};
		}
		var variants = Array.isArray( state.document.meta.variants ) ? state.document.meta.variants.slice() : [];
		if ( variants.indexOf( format ) === -1 ) {
			variants.push( format );
		}
		state.document.meta.variants = variants;

		state.selection = state.selection.filter( function ( id ) {
			return !! elementFor( id );
		} );

		applyViewportTransform();
		await requestPlan( false );
		renderAll();
		fitToScreen();
	}

	function bindFormats() {
		Array.prototype.forEach.call( document.querySelectorAll( '.cpic-format' ), function ( button ) {
			button.addEventListener( 'click', function () {
				switchFormat( button.getAttribute( 'data-format' ) );
			} );
		} );
	}

	/* ── Canvas toggles ───────────────────────────────────────────── */

	function toggleGrid() {
		state.showGrid = ! state.showGrid;
		syncToggle( els.toggleGrid, state.showGrid );
		scheduleOverlay();
		renderInspector();
	}

	function toggleRulers( force ) {
		state.showRulers = force === undefined ? ! state.showRulers : !! force;
		syncToggle( els.toggleRulers, state.showRulers );

		if ( els.rulers ) {
			els.rulers.hidden = ! state.showRulers;
		}

		if ( els.canvasArea ) {
			els.canvasArea.classList.toggle( 'has-rulers', state.showRulers );
		}

		if ( state.showRulers ) {
			drawRulers();
		}

		scheduleOverlay();
	}

	function toggleSafe() {
		state.showSafe = ! state.showSafe;
		syncToggle( els.toggleSafe, state.showSafe );
		scheduleOverlay();
	}

	function toggleSnap() {
		state.snap = ! state.snap;
		syncToggle( els.toggleSnap, state.snap );
		toast( state.snap ? str( 'snapOn', 'Snapping on' ) : str( 'snapOff', 'Snapping off' ), 'info', { duration: 1400 } );
	}

	function syncToggle( button, value ) {
		if ( ! button ) {
			return;
		}
		button.setAttribute( 'aria-pressed', String( !! value ) );
		button.classList.toggle( 'is-active', !! value );
	}

	function bindCanvasTools() {
		Array.prototype.forEach.call( document.querySelectorAll( '[data-tool]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				state.tool = button.getAttribute( 'data-tool' );
				Array.prototype.forEach.call( document.querySelectorAll( '[data-tool]' ), function ( sibling ) {
					var active = sibling === button;
					sibling.classList.toggle( 'is-active', active );
					sibling.setAttribute( 'aria-pressed', String( active ) );
				} );
				els.viewport.classList.toggle( 'is-pan-tool', state.tool === 'pan' );
			} );
		} );

		els.zoomIn && els.zoomIn.addEventListener( 'click', function () { setZoom( state.zoom * 1.25 ); } );
		els.zoomOut && els.zoomOut.addEventListener( 'click', function () { setZoom( state.zoom / 1.25 ); } );
		els.zoomFit && els.zoomFit.addEventListener( 'click', fitToScreen );
		els.zoomActual && els.zoomActual.addEventListener( 'click', actualSize );
		els.zoomValue && els.zoomValue.addEventListener( 'click', function () {
			if ( state.zoom === 1 ) {
				fitToScreen();
			} else {
				actualSize();
			}
		} );

		els.toggleGrid && els.toggleGrid.addEventListener( 'click', toggleGrid );
		els.toggleRulers && els.toggleRulers.addEventListener( 'click', function () { toggleRulers(); } );
		els.toggleSafe && els.toggleSafe.addEventListener( 'click', toggleSafe );
		els.toggleSnap && els.toggleSnap.addEventListener( 'click', toggleSnap );
		els.shortcutsBtn && els.shortcutsBtn.addEventListener( 'click', openShortcuts );
	}

	/* ── Keyboard shortcuts ───────────────────────────────────────── */

	function openShortcuts() {
		var rows = [
			[ 'Ctrl / ⌘ + Z', str( 'scUndo', 'Undo' ) ],
			[ 'Ctrl / ⌘ + Shift + Z', str( 'scRedo', 'Redo (Ctrl + Y also works)' ) ],
			[ 'Ctrl / ⌘ + S', str( 'scSave', 'Save design' ) ],
			[ 'Ctrl / ⌘ + D', str( 'scDuplicate', 'Duplicate selection' ) ],
			[ 'Delete / Backspace', str( 'scDelete', 'Delete selection' ) ],
			[ 'Arrow keys', str( 'scNudge', 'Move selection by 1px' ) ],
			[ 'Shift + Arrow keys', str( 'scNudgeBig', 'Move selection by 10px' ) ],
			[ 'Ctrl / ⌘ + A', str( 'scSelectAll', 'Select all layers' ) ],
			[ 'Esc', str( 'scEscape', 'Deselect / close menu' ) ],
			[ 'Space + drag', str( 'scPan', 'Pan the canvas' ) ],
			[ 'Ctrl / ⌘ + wheel', str( 'scZoom', 'Zoom around the pointer' ) ],
			[ 'Shift + 1', str( 'scFit', 'Fit to screen' ) ],
			[ 'Shift + 0', str( 'scActual', 'Actual size (100%)' ) ],
			[ 'Ctrl / ⌘ + ]', str( 'scForward', 'Bring forward' ) ],
			[ 'Ctrl / ⌘ + [', str( 'scBackward', 'Send backward' ) ],
			[ 'Ctrl / ⌘ + C / V', str( 'scCopyPaste', 'Copy / paste layers' ) ],
			[ 'V', str( 'scSelectTool', 'Select tool' ) ],
			[ 'H', str( 'scPanTool', 'Pan tool' ) ],
			[ 'F2', str( 'scRename', 'Rename the selected layer' ) ],
			[ 'Enter', str( 'scEditText', 'Edit the selected text layer' ) ],
		];

		var body = el( 'div', { class: 'cpic-shortcuts' } );

		rows.forEach( function ( row ) {
			body.appendChild(
				el( 'div', { class: 'cpic-shortcuts__row' }, [
					el( 'kbd', { class: 'cpic-kbd', text: row[ 0 ] } ),
					el( 'span', { text: row[ 1 ] } ),
				] )
			);
		} );

		openDialog( {
			title: str( 'shortcutsTitle', 'Keyboard shortcuts' ),
			body: body,
			actions: [ { label: str( 'close', 'Close' ), primary: true } ],
		} );
	}

	function bindShortcuts() {
		document.addEventListener( 'keydown', function ( event ) {
			var target = event.target;

			// Never intercept typing.
			if ( isEditable( target ) && ! ( target.classList && target.classList.contains( 'cpic-range' ) ) ) {
				if ( event.key === 'Escape' && target.classList && target.classList.contains( 'cpic-input' ) ) {
					target.blur();
				}
				return;
			}

			var mod = modKey( event );
			var key = event.key;

			// Space holds temporary pan mode; it must not scroll the page.
			if ( key === ' ' && ! isEditable( target ) ) {
				event.preventDefault();
				if ( ! state.spacePanning ) {
					state.spacePanning = true;
					if ( els.viewport ) {
						els.viewport.classList.add( 'is-pan-ready' );
					}
				}
				return;
			}

			if ( key === 'Escape' ) {
				if ( ! els.modal.hidden ) {
					closeDialog();
					return;
				}
				if ( ! els.menu.hidden || ! els.contextMenu.hidden ) {
					closeMenus();
					return;
				}
				if ( state.editingText ) {
					endTextEdit( true );
					return;
				}
				state.selection = [];
				renderSelectionState();
				return;
			}

			if ( mod && ( key === 'z' || key === 'Z' ) ) {
				event.preventDefault();
				if ( event.shiftKey ) {
					redo();
				} else {
					undo();
				}
				return;
			}

			if ( mod && ( key === 'y' || key === 'Y' ) ) {
				event.preventDefault();
				redo();
				return;
			}

			if ( mod && ( key === 's' || key === 'S' ) ) {
				event.preventDefault();
				saveDesign();
				return;
			}

			if ( mod && ( key === 'd' || key === 'D' ) ) {
				event.preventDefault();
				duplicateSelection();
				return;
			}

			if ( mod && ( key === 'a' || key === 'A' ) ) {
				event.preventDefault();
				selectAll();
				return;
			}

			if ( mod && ( key === 'c' || key === 'C' ) ) {
				if ( state.selection.length ) {
					event.preventDefault();
					copySelection();
				}
				return;
			}

			if ( mod && ( key === 'v' || key === 'V' ) ) {
				if ( state.clipboard ) {
					event.preventDefault();
					pasteClipboard();
				}
				return;
			}

			if ( mod && ( key === ']' || key === '[' ) ) {
				event.preventDefault();
				reorderSelection( key === ']' ? 'forward' : 'backward' );
				return;
			}

			if ( mod && ( key === '+' || key === '=' ) ) {
				event.preventDefault();
				setZoom( state.zoom * 1.25 );
				return;
			}

			if ( mod && key === '-' ) {
				event.preventDefault();
				setZoom( state.zoom / 1.25 );
				return;
			}

			if ( key === 'Delete' || key === 'Backspace' ) {
				if ( state.selection.length ) {
					event.preventDefault();
					deleteSelection();
				}
				return;
			}

			if ( key === 'ArrowUp' || key === 'ArrowDown' || key === 'ArrowLeft' || key === 'ArrowRight' ) {
				if ( ! state.selection.length ) {
					return;
				}

				event.preventDefault();
				var step = event.shiftKey ? 10 : 1;
				var dx = key === 'ArrowLeft' ? -step : key === 'ArrowRight' ? step : 0;
				var dy = key === 'ArrowUp' ? -step : key === 'ArrowDown' ? step : 0;
				nudgeSelection( dx, dy );
				return;
			}

			if ( ! mod && event.shiftKey && key === '1' ) {
				event.preventDefault();
				fitToScreen();
				return;
			}

			if ( ! mod && key === '0' ) {
				event.preventDefault();
				actualSize();
				return;
			}

			if ( ! mod && ! event.shiftKey && ( key === 'v' || key === 'V' ) ) {
				state.tool = 'select';
				Array.prototype.forEach.call( document.querySelectorAll( '[data-tool]' ), function ( button ) {
					var active = button.getAttribute( 'data-tool' ) === 'select';
					button.classList.toggle( 'is-active', active );
					button.setAttribute( 'aria-pressed', String( active ) );
				} );
				return;
			}

			if ( ! mod && ! event.shiftKey && ( key === 'h' || key === 'H' ) ) {
				state.tool = 'pan';
				Array.prototype.forEach.call( document.querySelectorAll( '[data-tool]' ), function ( button ) {
					var active = button.getAttribute( 'data-tool' ) === 'pan';
					button.classList.toggle( 'is-active', active );
					button.setAttribute( 'aria-pressed', String( active ) );
				} );
				return;
			}

			if ( key === 'F2' && state.selection.length === 1 ) {
				event.preventDefault();
				switchTab( 'layers' );
				var node = els.layers && els.layers.querySelector( '[data-layer="' + state.selection[ 0 ] + '"]' );
				if ( node ) {
					renameLayerInline( state.selection[ 0 ], node );
				}
				return;
			}

			if ( key === 'Enter' && state.selection.length === 1 ) {
				var element = elementFor( state.selection[ 0 ] );
				if ( element && element.type === 'text' ) {
					event.preventDefault();
					startTextEdit( state.selection[ 0 ] );
				}
			}
		} );

		document.addEventListener( 'keyup', function ( event ) {
			if ( event.key === ' ' ) {
				state.spacePanning = false;
				if ( els.viewport ) {
					els.viewport.classList.remove( 'is-pan-ready' );
				}
				if ( state.interaction && state.interaction.type === 'pan' ) {
					endPan();
				}
			}
		} );

		// Losing focus (alt-tab) must not leave the editor stuck in pan mode.
		window.addEventListener( 'blur', function () {
			state.spacePanning = false;
			if ( els.viewport ) {
				els.viewport.classList.remove( 'is-pan-ready' );
			}
		} );
	}

	/* ── Header wiring ────────────────────────────────────────────── */

	function syncDocName() {
		if ( els.docName ) {
			els.docName.textContent = state.templateName || str( 'untitled', 'Untitled design' );
		}
		if ( els.docInput ) {
			els.docInput.value = state.templateName || '';
		}
	}

	function focusDocName() {
		if ( ! els.docInput || ! els.docName ) {
			return;
		}

		els.docName.hidden = true;
		els.docInput.hidden = false;
		els.docInput.focus();
		els.docInput.select();
	}

	function commitDocName() {
		if ( ! els.docInput || ! els.docName ) {
			return;
		}

		var value = els.docInput.value.trim();

		if ( value && value !== state.templateName ) {
			state.templateName = value.slice( 0, 120 );
			markDirty();
			renderInspector();
		}

		els.docInput.hidden = true;
		els.docName.hidden = false;
		syncDocName();
	}

	function bindHeader() {
		els.docName && els.docName.addEventListener( 'click', focusDocName );

		if ( els.docInput ) {
			els.docInput.addEventListener( 'blur', commitDocName );
			els.docInput.addEventListener( 'keydown', function ( event ) {
				event.stopPropagation();
				if ( event.key === 'Enter' ) {
					event.preventDefault();
					commitDocName();
					els.docInput.blur();
				} else if ( event.key === 'Escape' ) {
					event.preventDefault();
					els.docInput.value = state.templateName;
					els.docInput.hidden = true;
					els.docName.hidden = false;
				}
			} );
		}

		els.undo && els.undo.addEventListener( 'click', undo );
		els.redo && els.redo.addEventListener( 'click', redo );
		els.save && els.save.addEventListener( 'click', function () { saveDesign(); } );
		els.preview && els.preview.addEventListener( 'click', function () { renderAndStore(); } );
		els.publish && els.publish.addEventListener( 'click', publishDesign );
		els.toggleLeft && els.toggleLeft.addEventListener( 'click', function () { togglePanel( 'left' ); } );
		els.toggleRight && els.toggleRight.addEventListener( 'click', function () { togglePanel( 'right' ); } );

		els.exportBtn && els.exportBtn.addEventListener( 'click', function () {
			openMenu( els.exportBtn, [
				{ heading: str( 'export', 'Export' ) },
				{ label: str( 'downloadPng', 'Download PNG' ), icon: 'download', hint: ( ( state.meta.formats || {} )[ state.format ] || {} ).label, run: downloadPng },
				{ label: str( 'renderPreview', 'Render preview' ), icon: 'eye', run: function () { renderAndStore(); } },
				{ label: str( 'storeArtifact', 'Render and store artifact' ), icon: 'save', disabled: ! state.meta.can.save, run: function () { renderAndStore(); } },
				{ separator: true },
				{ heading: str( 'exportAllFormats', 'Other formats' ) },
			].concat(
				Object.keys( state.meta.formats || {} )
					.filter( function ( key ) {
						return key !== state.format;
					} )
					.map( function ( key ) {
						return {
							label: state.meta.formats[ key ].label,
							icon: 'template',
							hint: 'PNG',
							run: function () {
								var previous = state.format;

								// Remember the dirty flag: exporting another format
								// must not make a saved design look unsaved.
								var wasDirty = state.dirty;

								switchFormat( key ).then( function () {
									return renderPng().then( function ( png ) {
										downloadDataUrl( png.dataUrl, png.name + '-' + key + '.png' );
										toast( str( 'exported', 'PNG downloaded' ), 'success' );
										return switchFormat( previous );
									} ).then( function () {
										state.dirty = wasDirty;
										refreshSaveState();
									} );
								} ).catch( function ( error ) {
									toast( error.message || str( 'renderFailed', 'Rendering failed' ), 'error' );
									switchFormat( previous ).then( function () {
										state.dirty = wasDirty;
										refreshSaveState();
									} );
								} );
							},
						};
					} )
			), { align: 'end' } );
		} );

		els.moreBtn && els.moreBtn.addEventListener( 'click', function () {
			openMenu( els.moreBtn, [
				{ heading: state.templateName || str( 'untitled', 'Untitled design' ) },
				{ label: str( 'duplicateDesign', 'Duplicate design' ), icon: 'copy', disabled: ! state.templateId || ! state.meta.can.save, run: function () { duplicateTemplate( state.templateId ); } },
				{ label: str( 'versionHistory', 'Version history' ), icon: 'history', disabled: ! state.templateId, run: openVersionHistory },
				{ separator: true },
				{ label: str( 'choosePost', 'Choose post…' ), icon: 'type', hint: state.postId ? '#' + state.postId : '', run: openPostPicker },
				{ label: str( 'brand', 'Brand kit' ), icon: 'star', hint: state.brandId ? '#' + state.brandId : '', run: openBrandPicker },
				{ separator: true },
				{ label: str( 'canvasSettings', 'Canvas settings' ), icon: 'settings', run: function () {
					state.selection = [];
					renderSelectionState();
				} },
				{ label: str( 'shortcutsTitle', 'Keyboard shortcuts' ), icon: 'keyboard', hint: '?', run: openShortcuts },
				{ separator: true },
				{ label: str( 'openLibrary', 'Open the template library' ), icon: 'external', run: function () {
					window.location.href = CONFIG.adminUrl + 'admin.php?page=cpic-templates';
				} },
			], { align: 'end' } );
		} );

		els.resultClose && els.resultClose.addEventListener( 'click', function () {
			els.result.hidden = true;
		} );

		// Menus and dialogs close on outside clicks / Escape.
		document.addEventListener( 'pointerdown', function ( event ) {
			if ( ! els.menu.hidden && ! els.menu.contains( event.target ) && ! event.target.closest( '.cpic-menu-wrap' ) && ! event.target.closest( '.cpic-layer__btn--menu' ) && ! event.target.closest( '.cpic-design-item__menu' ) ) {
				closeMenus();
			}
			if ( ! els.contextMenu.hidden && ! els.contextMenu.contains( event.target ) ) {
				els.contextMenu.hidden = true;
			}
		} );

		Array.prototype.forEach.call( els.modal.querySelectorAll( '[data-modal-close]' ), function ( node ) {
			node.addEventListener( 'click', closeDialog );
		} );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! state.dirty ) {
				return undefined;
			}
			event.preventDefault();
			event.returnValue = '';
			return '';
		} );
	}

	function openBrandPicker() {
		var body = el( 'div', { class: 'cpic-brand-picker' } );
		var options = [ { id: 0, name: str( 'designDefault', 'Design default' ) } ].concat( state.meta.brands || [] );

		options.forEach( function ( brand ) {
			body.appendChild(
				el( 'button', {
					type: 'button',
					class: 'cpic-post-item' + ( brand.id === state.brandId ? ' is-active' : '' ),
					onClick: function () {
						state.brandId = brand.id;
						if ( state.document.brand ) {
							state.document.brand.id = brand.id;
						}
						closeDialog();
						requestPlan( true ).then( renderAll );
						toast( str( 'brandApplied', 'Brand kit applied' ) + ': ' + brand.name, 'success', { duration: 2000 } );
					},
				}, [ el( 'span', { class: 'cpic-post-item__thumb cpic-post-item__thumb--empty' }, [ iconNode( 'star', 14 ) ] ), el( 'span', { class: 'cpic-post-item__text' }, [
					el( 'span', { class: 'cpic-post-item__title', text: brand.name }),
				] ) ] )
			);
		} );

		openDialog( {
			title: str( 'brand', 'Brand kit' ),
			body: body,
			actions: [ { label: str( 'close', 'Close' ) } ],
		} );
	}

	/* ── Onboarding ───────────────────────────────────────────────── */

	function maybeShowOnboarding() {
		if ( state.prefs.onboardingSeen || ! els.onboarding ) {
			return;
		}

		var seenInDocument = false;
		try {
			seenInDocument = window.localStorage.getItem( 'cpicStudioOnboarded' ) === '1';
		} catch ( error ) {
			seenInDocument = false;
		}

		if ( seenInDocument ) {
			return;
		}

		els.onboarding.hidden = false;

		var dismiss = function () {
			els.onboarding.hidden = true;
			state.prefs.onboardingSeen = true;
			savePrefs();
			try {
				window.localStorage.setItem( 'cpicStudioOnboarded', '1' );
			} catch ( error ) {
				// Ignored.
			}
		};

		var dismissBtn = document.getElementById( 'cpic-onboarding-dismiss' );
		dismissBtn && dismissBtn.addEventListener( 'click', dismiss );

		var shortcutsBtn = document.getElementById( 'cpic-onboarding-shortcuts' );
		shortcutsBtn && shortcutsBtn.addEventListener( 'click', function () {
			dismiss();
			openShortcuts();
		} );
	}

	/* ── Panel bindings ───────────────────────────────────────────── */

	function bindPanels() {
		Array.prototype.forEach.call( document.querySelectorAll( '.cpic-tab' ), function ( tab ) {
			tab.addEventListener( 'click', function () {
				switchTab( tab.getAttribute( 'data-tab' ) );
			} );
		} );

		var search = document.getElementById( 'cpic-template-search' );
		if ( search ) {
			search.addEventListener( 'input', debounce( function () {
				refs.templateSearch = search.value;
				renderTemplateList();
			}, 150 ) );
		}

		var createButton = document.getElementById( 'cpic-new-design' );
		createButton && createButton.addEventListener( 'click', createNewDesign );

		var nameInput = document.getElementById( 'cpic-new-name' );
		nameInput && nameInput.addEventListener( 'keydown', function ( event ) {
			event.stopPropagation();
			if ( event.key === 'Enter' ) {
				createNewDesign();
			}
		} );

		els.resultClose = document.getElementById( 'cpic-result-close' );
	}

	/* ── Render everything ────────────────────────────────────────── */

	function renderAll() {
		renderLayers();
		renderInspector();
		syncFormatButtons();
		syncDocName();
		updateHistoryButtons();
		refreshSaveState();
		scheduleOverlay();
		positionSelectionToolbar();
		drawRulers();
		applyLayerThumbnails();
	}

	/* ── Boot ─────────────────────────────────────────────────────── */

	function readState() {
		var node = document.getElementById( 'cpic-studio-state' );

		if ( ! node ) {
			return null;
		}

		try {
			return JSON.parse( node.textContent );
		} catch ( error ) {
			window.console && window.console.warn( 'CPIC: studio state is not valid JSON' );
			return null;
		}
	}

	function collectElements() {
		els = {
			app: document.getElementById( 'cpic-app' ),
			canvas: document.getElementById( 'cpic-canvas' ),
			canvasArea: document.getElementById( 'cpic-canvas-area' ),
			canvasMeta: document.getElementById( 'cpic-canvas-meta' ),
			frame: document.getElementById( 'cpic-canvas-frame' ),
			viewport: document.getElementById( 'cpic-viewport' ),
			loading: document.getElementById( 'cpic-canvas-loading' ),
			layers: document.getElementById( 'cpic-layers' ),
			inspector: document.getElementById( 'cpic-inspector' ),
			inspectorTitle: document.getElementById( 'cpic-inspector-title' ),
			inspectorBadge: document.getElementById( 'cpic-inspector-badge' ),
			panelLeft: document.getElementById( 'cpic-panel-left' ),
			panelRight: document.getElementById( 'cpic-panel-right' ),
			toggleLeft: document.getElementById( 'cpic-toggle-left' ),
			toggleRight: document.getElementById( 'cpic-toggle-right' ),
			undo: document.getElementById( 'cpic-undo' ),
			redo: document.getElementById( 'cpic-redo' ),
			save: document.getElementById( 'cpic-save' ),
			publish: document.getElementById( 'cpic-publish' ),
			preview: document.getElementById( 'cpic-preview' ),
			exportBtn: document.getElementById( 'cpic-export' ),
			moreBtn: document.getElementById( 'cpic-more' ),
			docName: document.getElementById( 'cpic-doc-name' ),
			docInput: document.getElementById( 'cpic-doc-input' ),
			saveState: document.getElementById( 'cpic-save-state' ),
			menu: document.getElementById( 'cpic-menu' ),
			contextMenu: document.getElementById( 'cpic-context-menu' ),
			modal: document.getElementById( 'cpic-modal' ),
			modalTitle: document.getElementById( 'cpic-modal-title' ),
			modalBody: document.getElementById( 'cpic-modal-body' ),
			modalFoot: document.getElementById( 'cpic-modal-foot' ),
			onboarding: document.getElementById( 'cpic-onboarding' ),
			toasts: document.getElementById( 'cpic-toasts' ),
			result: document.getElementById( 'cpic-result' ),
			resultBody: document.getElementById( 'cpic-result-body' ),
			selectionToolbar: document.getElementById( 'cpic-selection-toolbar' ),
			zoomIn: document.getElementById( 'cpic-zoom-in' ),
			zoomOut: document.getElementById( 'cpic-zoom-out' ),
			zoomFit: document.getElementById( 'cpic-zoom-fit' ),
			zoomActual: document.getElementById( 'cpic-zoom-actual' ),
			zoomValue: document.getElementById( 'cpic-zoom-value' ),
			toggleGrid: document.getElementById( 'cpic-toggle-grid' ),
			toggleRulers: document.getElementById( 'cpic-toggle-rulers' ),
			toggleSafe: document.getElementById( 'cpic-toggle-safe' ),
			toggleSnap: document.getElementById( 'cpic-toggle-snap' ),
			shortcutsBtn: document.getElementById( 'cpic-shortcuts' ),
			rulers: document.getElementById( 'cpic-rulers' ),
			rulerH: document.getElementById( 'cpic-ruler-h' ),
			rulerV: document.getElementById( 'cpic-ruler-v' ),
		};

		refs = {
			rulerH: els.rulerH,
			rulerV: els.rulerV,
			scaler: document.getElementById( 'cpic-canvas-scaler' ),
			newFormat: 'square',
			templateSearch: '',
		};
	}

	async function boot() {
		collectElements();

		if ( ! els.canvas ) {
			return;
		}

		hydrateIcons( document );

		var initial = readState();

		if ( ! initial ) {
			toast( 'The studio could not start because its state is missing.', 'error' );
			return;
		}

		state.document = initial.document;
		state.templateId = initial.templateId || 0;
		state.templateName = initial.templateName || str( 'untitled', 'Untitled design' );
		state.revision = initial.revision || 0;
		state.postId = initial.postId || 0;
		state.format = ( initial.document && initial.document.canvas && initial.document.canvas.format ) || 'square';
		state.meta = {
			templates: initial.templates || [],
			starters: initial.starters || [],
			posts: initial.posts || [],
			fonts: initial.fonts || [],
			destinations: initial.destinations || [],
			formats: initial.formats || CONFIG.formats || {},
			can: initial.can || {},
			brands: initial.brands || [],
		};
		refs.newFormat = state.format;

		if ( state.document.brand && state.document.brand.id ) {
			state.brandId = num( state.document.brand.id );
		}

		loadPrefs();

		if ( false === state.prefs.leftPanelOpen ) {
			setPanel( 'left', false );
		}
		if ( false === state.prefs.rightPanelOpen ) {
			setPanel( 'right', false );
		}

		// Each binder covers one surface; a failure in one must not leave the
		// whole studio dead, so they are wrapped individually.
		[
			bindHeader,
			bindPanels,
			bindFormats,
			bindCanvasTools,
			bindCanvasInteraction,
			bindViewport,
			bindLayerToolbar,
			bindSelectionToolbar,
			bindShortcuts,
			bindMediaDragDrop,
		].forEach( function ( binder ) {
			try {
				binder();
			} catch ( error ) {
				window.console && window.console.error( 'CPIC: a studio control failed to bind', binder.name, error );
				toast( str( 'bindFailed', 'One part of the editor did not start. Reloading the page usually fixes it.' ), 'error' );
			}
		} );

		renderElementsPanel();
		renderDesignPanel();
		syncDocName();
		syncFormatButtons();
		updateHistoryButtons();
		refreshSaveState();

		await requestPlan( true );
		renderAll();

		// Fit after layout settles (fonts, scrollbars, panels).
		window.setTimeout( function () {
			fitToScreen();
			if ( ! state.showRulers ) {
				toggleRulers( false );
			} else if ( els.rulers ) {
				els.rulers.hidden = false;
				els.canvasArea.classList.add( 'has-rulers' );
			}
			syncToggle( els.toggleGrid, state.showGrid );
			syncToggle( els.toggleSafe, state.showSafe );
			syncToggle( els.toggleSnap, state.snap );
			syncToggle( els.toggleRulers, state.showRulers );
			drawRulers();
			scheduleOverlay();
		}, 120 );

		maybeShowOnboarding();

		// Capabilities come from the block editor's own config, not the page
		// state; only a false value is treated as "no".
		if ( ( state.meta.can.save === false || CONFIG.canPublish === false ) && els.save ) {
			els.save.disabled = true;
			els.save.title = str( 'cannotSave', 'You do not have permission to save designs.' );
		}
	}

	/* ── Public API (used by the block editor) ────────────────────── */

	/**
	 * Render a document to a PNG data URL without the studio screen.
	 *
	 * The editor panel calls this so the block editor and the studio cannot
	 * produce different images.
	 *
	 * @param {Object} document Design document.
	 * @param {Object} options  { postId, format, brandId }.
	 * @return {Promise<string>} data:image/png URL.
	 */
	async function exportDocument( document_, options ) {
		options = options || {};

		var previousCanvas = els.canvas;

		state.document = document_;
		state.postId = options.postId || 0;
		state.format = options.format || ( document_.canvas && document_.canvas.format ) || 'square';
		state.brandId = options.brandId || 0;

		var plan = await requestPlan( true );

		if ( ! plan ) {
			throw new Error( str( 'noPlan', 'The design could not be prepared for export. Check your connection, then reload the page.' ) );
		}

		var target = document.createElement( 'canvas' );
		target.width = num( plan.width, 1080 );
		target.height = num( plan.height, 1080 );

		els.canvas = target;
		els.headless = true;

		try {
			await paint( plan );
		} finally {
			els.canvas = previousCanvas;
			els.headless = false;
		}

		return target.toDataURL( 'image/png' );
	}

	window.CPICStudio = {
		export: exportDocument,
		paint: paint,
		state: state,
		rerender: function ( document_, options ) {
			options = options || {};
			if ( document_ ) {
				state.document = document_;
			}
			state.format = options.format || state.format;
			state.postId = options.postId !== undefined ? options.postId : state.postId;
			return requestPlan( true, { reportErrors: true } ).then( function () {
				renderAll();
				return state.plan;
			} );
		},
		version: CONFIG.version || '',
		/**
		 * Ground truth from the last paint: per-layer sizes, overflow and any
		 * colours that could not be parsed. Diagnostics and the render gates
		 * read this instead of guessing from the DOM.
		 */
		get lastReport() {
			return paintReport;
		},
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();

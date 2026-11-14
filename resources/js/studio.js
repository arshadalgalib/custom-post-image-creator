/**
 * Post Image Creator — design studio.
 *
 * The studio draws the *render plan* the server produces (the same structure a
 * renderer receives), so the preview and the saved PNG cannot drift apart.
 * Bengali shaping is done by the browser, which is the whole reason the canvas
 * lives here rather than in PHP.
 *
 * No globals beyond `window.CPICStudio`; everything else is module-private.
 */
( function () {
	'use strict';

	var CONFIG = window.CPIC || {};

	var state = {
		document: null,
		plan: null,
		selected: null,
		format: 'square',
		postId: 0,
		templateId: 0,
		brandId: 0,
		dirty: false,
		busy: false,
		imageCache: new Map(),
		fontsReady: false,
	};

	var els = {};

	/* ── Small helpers ─────────────────────────────────────────────── */

	function str( key, fallback ) {
		return CONFIG.strings && CONFIG.strings[ key ] ? CONFIG.strings[ key ] : fallback;
	}

	function clamp( value, min, max ) {
		return Math.min( max, Math.max( min, value ) );
	}

	function el( tag, attributes, children ) {
		var node = document.createElement( tag );

		Object.keys( attributes || {} ).forEach( function ( key ) {
			if ( key === 'text' ) {
				node.textContent = attributes[ key ];
			} else if ( key === 'class' ) {
				node.className = attributes[ key ];
			} else if ( key.indexOf( 'on' ) === 0 && typeof attributes[ key ] === 'function' ) {
				node.addEventListener( key.slice( 2 ).toLowerCase(), attributes[ key ] );
			} else if ( attributes[ key ] !== null && attributes[ key ] !== undefined ) {
				node.setAttribute( key, attributes[ key ] );
			}
		} );

		( children || [] ).forEach( function ( child ) {
			if ( child ) {
				node.appendChild( typeof child === 'string' ? document.createTextNode( child ) : child );
			}
		} );

		return node;
	}

	function setStatus( message, kind ) {
		if ( ! els.status ) {
			return;
		}

		els.status.textContent = message || '';
		els.status.className = 'cpic-studio__status' + ( kind ? ' is-' + kind : '' );
	}

	/**
	 * Call a plugin REST route.
	 *
	 * @param {string} path   Route path, already namespaced.
	 * @param {Object} options Fetch options.
	 * @return {Promise<Object>} Parsed JSON.
	 */
	async function api( path, options ) {
		options = options || {};

		if ( ! CONFIG.restRoot ) {
			throw new Error( 'REST root missing' );
		}

		var url = CONFIG.restRoot.replace( /\/$/, '' ) + path;
		var response = await fetch( url, {
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
		} );

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

		return payload;
	}

	/* ── Fonts ─────────────────────────────────────────────────────── */

	/**
	 * Wait for the @font-face rules printed by the admin header. A canvas drawn
	 * before Bengali faces load silently falls back to a Latin face, which
	 * produces broken conjuncts — so this is a correctness step, not polish.
	 *
	 * @return {Promise<void>} Resolves once fonts are ready (or after a timeout).
	 */
	async function ensureFonts() {
		if ( state.fontsReady || ! document.fonts || ! document.fonts.ready ) {
			state.fontsReady = true;
			return;
		}

		var families = [ 'Noto Sans Bengali', 'Noto Serif Bengali', 'Hind Siliguri' ];
		var loads = families.map( function ( family ) {
			return document.fonts.load( '400 32px "' + family + '"', 'বাংলা' ).catch( function () {
				return null;
			} );
		} );

		await Promise.race( [
			Promise.all( loads ),
			new Promise( function ( resolve ) {
				setTimeout( resolve, 3000 );
			} ),
		] );

		state.fontsReady = true;
	}

	/* ── Images ────────────────────────────────────────────────────── */

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
				resolve( image );
			};
			image.onerror = function () {
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

	/* ── Canvas painting ───────────────────────────────────────────── */

	function paintCanvasBackground( ctx, plan ) {
		var canvas = plan.canvas || {};
		var width = plan.width;
		var height = plan.height;

		if ( canvas.background_type === 'image' && canvas.background_image ) {
			var cached = state.imageCache.get( canvas.background_image );
			if ( cached ) {
				cached.then( function ( image ) {
					if ( image ) {
						drawCover( ctx, image, 0, 0, width, height );
					}
				} );
			}
			return;
		}

		if ( canvas.background_type === 'color' || ! canvas.gradient_from ) {
			ctx.fillStyle = canvas.background_color || '#0F172A';
			ctx.fillRect( 0, 0, width, height );
			return;
		}

		// CSS gradient angles are 0deg = to top; convert to canvas coordinates.
		var radians = ( ( ( canvas.gradient_angle || 135 ) - 90 ) * Math.PI ) / 180;
		var cx = width / 2;
		var cy = height / 2;
		var length = Math.abs( width * Math.cos( radians ) ) + Math.abs( height * Math.sin( radians ) );
		var x1 = cx - ( Math.cos( radians ) * length ) / 2;
		var y1 = cy - ( Math.sin( radians ) * length ) / 2;
		var x2 = cx + ( Math.cos( radians ) * length ) / 2;
		var y2 = cy + ( Math.sin( radians ) * length ) / 2;
		var gradient = ctx.createLinearGradient( x1, y1, x2, y2 );

		gradient.addColorStop( 0, canvas.gradient_from || '#667EEA' );
		gradient.addColorStop( 1, canvas.gradient_to || '#764BA2' );
		ctx.fillStyle = gradient;
		ctx.fillRect( 0, 0, width, height );
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

	function roundedRect( ctx, x, y, width, height, radius ) {
		var r = Math.min( radius || 0, width / 2, height / 2 );

		ctx.beginPath();
		ctx.moveTo( x + r, y );
		ctx.arcTo( x + width, y, x + width, y + height, r );
		ctx.arcTo( x + width, y + height, x, y + height, r );
		ctx.arcTo( x, y + height, x, y, r );
		ctx.arcTo( x, y, x + width, y, r );
		ctx.closePath();
	}

	/**
	 * Break text into lines that fit `maxWidth`.
	 *
	 * Bengali has no inter-word requirement for shaping, so wrapping on spaces
	 * is correct for headlines and cheaper than measuring clusters.
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

		paragraphs.forEach( function ( paragraph ) {
			var words = paragraph.split( /\s+/ ).filter( Boolean );
			var current = '';

			if ( ! words.length ) {
				lines.push( '' );
				return;
			}

			words.forEach( function ( word ) {
				var candidate = current ? current + ' ' + word : word;

				if ( ctx.measureText( candidate ).width <= maxWidth || ! current ) {
					current = candidate;
					return;
				}

				lines.push( current );
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
	 * Shrink type until it fits its box, then paint it.
	 *
	 * @param {CanvasRenderingContext2D} ctx   Context.
	 * @param {Object}                   layer Layer.
	 */
	function paintText( ctx, layer ) {
		var text = layer.text || {};
		var value = ( text.value || '' ).trim();

		if ( ! value ) {
			return;
		}

		if ( text.uppercase ) {
			value = value.toUpperCase();
		}

		var boxWidth = Math.max( 1, layer.width - ( text.padding_x || 0 ) * 2 );
		var boxHeight = Math.max( 1, layer.height > 0 ? layer.height : 10 ** 6 );

		var size = text.font_size || 40;
		var minSize = text.min_font_size || 12;
		var maxSize = Math.max( size, text.max_font_size || size );
		var lines = [];

		var applyFont = function ( fontSize ) {
			ctx.font =
				( text.font_style || 'normal' ) +
				' ' +
				( text.font_weight || 400 ) +
				' ' +
				fontSize +
				'px "' +
				( text.font_family || 'Noto Sans Bengali' ) +
				'", "Noto Sans Bengali", sans-serif';
		};

		// Grow if there is room, then shrink until the block fits.
		size = Math.min( maxSize, size );
		applyFont( size );
		lines = wrapText( ctx, value, boxWidth, text.max_lines || 0 );

		var lineHeight = ( text.line_height || 1.3 ) * size;
		var totalHeight = lines.length * lineHeight;

		if ( text.auto_scale && totalHeight < boxHeight && lines.length === 1 && size < maxSize ) {
			while ( size < maxSize ) {
				size += 2;
				applyFont( size );
				lines = wrapText( ctx, value, boxWidth, text.max_lines || 0 );
				lineHeight = ( text.line_height || 1.3 ) * size;

				if ( lines.length * lineHeight > boxHeight ) {
					size -= 2;
					break;
				}
			}
		}

		while ( size > minSize && lines.length * ( text.line_height || 1.3 ) * size > boxHeight ) {
			size -= 2;
			applyFont( size );
			lines = wrapText( ctx, value, boxWidth, text.max_lines || 0 );
		}

		applyFont( size );
		lineHeight = ( text.line_height || 1.3 ) * size;
		totalHeight = lines.length * lineHeight;

		var boxX = layer.x;
		var boxY = layer.y;

		// Optional chip behind the text.
		if ( text.background ) {
			ctx.save();
			ctx.fillStyle = text.background;
			roundedRect(
				ctx,
				boxX,
				boxY,
				boxWidth + ( text.padding_x || 0 ) * 2,
				totalHeight + ( text.padding_y || 0 ) * 2,
				text.border_radius || 0
			);
			ctx.fill();
			ctx.restore();
		}

		var textX = boxX + ( text.padding_x || 0 );
		var textY = boxY + ( text.padding_y || 0 );

		if ( text.border_width > 0 && text.border_color ) {
			ctx.save();
			ctx.strokeStyle = text.border_color;
			ctx.lineWidth = text.border_width;
			roundedRect( ctx, boxX, boxY, boxWidth, totalHeight, text.border_radius || 0 );
			ctx.stroke();
			ctx.restore();
		}

		ctx.save();
		ctx.textBaseline = 'top';

		if ( text.align === 'center' ) {
			ctx.textAlign = 'center';
			textX = boxX + boxWidth / 2;
		} else if ( text.align === 'right' ) {
			ctx.textAlign = 'right';
			textX = boxX + boxWidth;
		} else {
			ctx.textAlign = 'left';
		}

		if ( text.shadow_color && ( text.shadow_blur > 0 || text.shadow_offset_x || text.shadow_offset_y ) ) {
			ctx.shadowColor = text.shadow_color;
			ctx.shadowBlur = text.shadow_blur || 0;
			ctx.shadowOffsetX = text.shadow_offset_x || 0;
			ctx.shadowOffsetY = text.shadow_offset_y || 0;
		}

		lines.forEach( function ( line, index ) {
			var y = textY + index * lineHeight;

			if ( text.stroke_width > 0 && text.stroke_color ) {
				ctx.lineWidth = text.stroke_width;
				ctx.strokeStyle = text.stroke_color;
				ctx.strokeText( line, textX, y );
			}

			ctx.fillStyle = text.color || '#FFFFFF';
			ctx.fillText( line, textX, y );
		} );

		ctx.restore();
	}

	function paintShape( ctx, layer ) {
		var shape = layer.shape || {};

		ctx.save();
		roundedRect( ctx, layer.x, layer.y, layer.width, layer.height, shape.border_radius || 0 );

		if ( shape.gradient_from && shape.gradient_to ) {
			var gradient = ctx.createLinearGradient( layer.x, layer.y, layer.x + layer.width, layer.y + layer.height );
			gradient.addColorStop( 0, shape.gradient_from );
			gradient.addColorStop( 1, shape.gradient_to );
			ctx.fillStyle = gradient;
		} else {
			ctx.fillStyle = shape.fill || '#FFFFFF';
		}

		ctx.fill();

		if ( shape.stroke_width > 0 && shape.stroke_color ) {
			ctx.lineWidth = shape.stroke_width;
			ctx.strokeStyle = shape.stroke_color;
			ctx.stroke();
		}

		ctx.restore();
	}

	function paintPlaceholder( ctx, layer, image ) {
		// No image (or it failed to load): the plan's fallback decides what the
		// editor sees, so a missing featured image never leaves a silent hole.
		if ( image.fallback === 'hide' ) {
			return;
		}

		ctx.save();

		if ( image.fallback === 'blur' ) {
			ctx.fillStyle = 'rgba(15, 23, 42, 0.55)';
			roundedRect( ctx, layer.x, layer.y, layer.width, layer.height, image.border_radius || 0 );
			ctx.fill();
			ctx.restore();
			return;
		}

		var gradient = ctx.createLinearGradient(
			layer.x,
			layer.y,
			layer.x + layer.width,
			layer.y + layer.height
		);

		gradient.addColorStop( 0, 'rgba(148, 163, 184, 0.55)' );
		gradient.addColorStop( 1, 'rgba(71, 85, 105, 0.35)' );
		ctx.fillStyle = gradient;
		roundedRect( ctx, layer.x, layer.y, layer.width, layer.height, image.border_radius || 0 );
		ctx.fill();

		// A quiet mark so the editor can tell a placeholder from artwork.
		ctx.strokeStyle = 'rgba(255, 255, 255, 0.35)';
		ctx.lineWidth = Math.max( 1, layer.width / 300 );
		ctx.setLineDash( [ 12, 10 ] );
		ctx.strokeRect( layer.x + 1, layer.y + 1, Math.max( 0, layer.width - 2 ), Math.max( 0, layer.height - 2 ) );

		ctx.restore();
	}

	function paintImage( ctx, layer ) {
		var image = layer.image || {};
		var cached = image.url ? state.imageCache.get( image.url ) : null;

		if ( ! cached ) {
			paintPlaceholder( ctx, layer, image );
			return;
		}

		ctx.save();
		roundedRect( ctx, layer.x, layer.y, layer.width, layer.height, image.border_radius || 0 );
		ctx.clip();

		cached.then( function ( loaded ) {
			if ( ! loaded ) {
				return;
			}

			// Registered as a promise so the first paint is never blank once the
			// image arrives; repaint happens through the same queued frame.
			if ( image.fit === 'contain' ) {
				drawContain( ctx, loaded, layer.x, layer.y, layer.width, layer.height );
			} else {
				drawCover( ctx, loaded, layer.x, layer.y, layer.width, layer.height, image.focus_x, image.focus_y );
			}
		} );

		ctx.restore();
	}

	async function paint( plan ) {
		var canvas = els.canvas;

		if ( ! canvas || ! plan ) {
			return;
		}

		canvas.width = plan.width;
		canvas.height = plan.height;

		var ctx = canvas.getContext( '2d', { alpha: false } );
		ctx.setTransform( 1, 0, 0, 1, 0, 0 );
		ctx.clearRect( 0, 0, plan.width, plan.height );

		await ensureFonts();
		await preloadPlanImages( plan );

		paintCanvasBackground( ctx, plan );

		var layers = ( plan.layers || [] ).slice().sort( function ( a, b ) {
			return ( a.z || 0 ) - ( b.z || 0 );
		} );

		for ( var index = 0; index < layers.length; index++ ) {
			var layer = layers[ index ];

			ctx.save();
			ctx.globalAlpha = typeof layer.opacity === 'number' ? layer.opacity : 1;

			if ( layer.rotation ) {
				var centerX = layer.x + layer.width / 2;
				var centerY = layer.y + layer.height / 2;
				ctx.translate( centerX, centerY );
				ctx.rotate( ( layer.rotation * Math.PI ) / 180 );
				ctx.translate( -centerX, -centerY );
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
			}

			ctx.restore();
		}

		drawSelection( ctx, plan );

		if ( els.canvasMeta ) {
			els.canvasMeta.textContent = plan.width + ' × ' + plan.height + ' px';
		}
	}

	function drawSelection( ctx, plan ) {
		if ( ! state.selected ) {
			return;
		}

		var layer = ( plan.layers || [] ).filter( function ( item ) {
			return item.id === state.selected;
		} )[ 0 ];

		if ( ! layer ) {
			return;
		}

		ctx.save();
		ctx.strokeStyle = '#2271b1';
		ctx.lineWidth = Math.max( 2, plan.width / 400 );
		ctx.setLineDash( [ 10, 6 ] );
		ctx.strokeRect( layer.x, layer.y, Math.max( layer.width, 8 ), Math.max( layer.height, 8 ) );
		ctx.restore();
	}

	/* ── Plan + document plumbing ──────────────────────────────────── */

	async function requestPlan() {
		var payload = await api( CONFIG.restPath + 'render/plan'.replace( /^\//, '' ), {
			method: 'POST',
			body: {
				document: state.document,
				format: state.format,
				post_id: state.postId || 0,
				brand_id: state.brandId || 0,
			},
		} );

		// REST responses are wrapped as { success, data } by the plugin.
		return payload && payload.data ? payload.data : payload;
	}

	async function rerender() {
		if ( state.busy ) {
			return;
		}

		state.busy = true;
		els.loading && ( els.loading.hidden = false );
		setStatus( str( 'rendering', 'Rendering…' ), 'busy' );

		try {
			state.plan = await requestPlan();
			await paint( state.plan );
			renderLayers();
			renderInspector();
			setStatus( '' );
		} catch ( error ) {
			setStatus( error.message || str( 'renderFailed', 'Rendering failed' ), 'error' );
		} finally {
			state.busy = false;
			els.loading && ( els.loading.hidden = true );
		}
	}

	async function exportPng() {
		await ensureFonts();
		await paint( state.plan );
		return els.canvas.toDataURL( 'image/png' );
	}

	/* ── Layers list ───────────────────────────────────────────────── */

	function renderLayers() {
		if ( ! els.layers ) {
			return;
		}

		while ( els.layers.firstChild ) {
			els.layers.removeChild( els.layers.firstChild );
		}

		var layers = ( ( state.plan && state.plan.layers ) || [] ).slice().sort( function ( a, b ) {
			return ( b.z || 0 ) - ( a.z || 0 );
		} );

		layers.forEach( function ( layer ) {
			var toggle = el( 'button', {
				type: 'button',
				class: 'cpic-layer__toggle',
				'aria-label': 'Toggle visibility',
				'aria-pressed': String( ! layer.hidden ),
				text: '◉',
				onClick: function ( event ) {
					event.stopPropagation();
					toggleLayer( layer.id );
				},
			} );

			var item = el(
				'li',
				{
					class: 'cpic-layer' + ( state.selected === layer.id ? ' is-selected' : '' ),
					tabindex: '0',
					role: 'button',
					'data-layer': layer.id,
					'aria-pressed': String( state.selected === layer.id ),
					onClick: function () {
						select( layer.id );
					},
					onKeydown: function ( event ) {
						if ( event.key === 'Enter' || event.key === ' ' ) {
							event.preventDefault();
							select( layer.id );
						}
					},
				},
				[
					el( 'span', { class: 'cpic-layer__type', text: layer.type } ),
					el( 'span', { class: 'cpic-layer__name', text: layer.name || layer.id } ),
					toggle,
				]
			);

			els.layers.appendChild( item );
		} );
	}

	function elementFor( id ) {
		if ( ! state.document || ! state.document.elements ) {
			return null;
		}

		return state.document.elements[ id ] || null;
	}

	function toggleLayer( id ) {
		var element = elementFor( id );

		if ( ! element ) {
			return;
		}

		element.visible = ! element.visible;
		state.dirty = true;
		rerender();
	}

	function select( id ) {
		state.selected = state.selected === id ? null : id;
		renderLayers();
		renderInspector();

		if ( state.plan ) {
			paint( state.plan );
		}
	}

	/* ── Inspector ─────────────────────────────────────────────────── */

	function field( label, input ) {
		return el( 'p', { class: 'cpic-field' }, [ el( 'label', { text: label } ), input ] );
	}

	function numberInput( value, step, onChange ) {
		return el( 'input', {
			type: 'number',
			step: step || 1,
			value: value === undefined || value === null ? '' : String( value ),
			onInput: function ( event ) {
				onChange( parseFloat( event.target.value ) || 0 );
			},
		} );
	}

	function textInput( value, onChange ) {
		return el( 'input', {
			type: 'text',
			value: value || '',
			onInput: function ( event ) {
				onChange( event.target.value );
			},
		} );
	}

	function colourInput( value, onChange ) {
		var input = el( 'input', {
			type: 'text',
			value: value || '',
			placeholder: '#RRGGBB',
			onInput: function ( event ) {
				onChange( event.target.value );
			},
		} );

		return input;
	}

	function updateElement( id, patch ) {
		var element = elementFor( id );

		if ( ! element ) {
			return;
		}

		Object.keys( patch ).forEach( function ( key ) {
			element[ key ] = patch[ key ];
		} );

		state.dirty = true;

		// Geometry and colour changes repaint instantly; long edits debounce.
		window.clearTimeout( updateElement.timer );
		updateElement.timer = window.setTimeout( rerender, 180 );
	}

	function renderInspector() {
		if ( ! els.inspector ) {
			return;
		}

		while ( els.inspector.firstChild ) {
			els.inspector.removeChild( els.inspector.firstChild );
		}

		var element = state.selected ? elementFor( state.selected ) : null;

		if ( ! element ) {
			els.inspector.appendChild(
				el( 'p', { class: 'cpic-inspector__empty', text: str( 'noSelection', 'Select an element on the canvas to edit it.' ) } )
			);
			return;
		}

		var id = state.selected;

		var geometry = el( 'div', { class: 'cpic-inspector__section' }, [
			el( 'h3', { text: 'Position & size' } ),
			el( 'div', { class: 'cpic-field cpic-field--pair' }, [
				field( 'X', numberInput( element.x, 1, function ( v ) {
					updateElement( id, { x: v } );
				} ) ),
				field( 'Y', numberInput( element.y, 1, function ( v ) {
					updateElement( id, { y: v } );
				} ) ),
			] ),
			el( 'div', { class: 'cpic-field cpic-field--pair' }, [
				field( 'Width', numberInput( element.width, 1, function ( v ) {
					updateElement( id, { width: clamp( v, 0, 6000 ) } );
				} ) ),
				field( 'Height', numberInput( element.height, 1, function ( v ) {
					updateElement( id, { height: clamp( v, 0, 6000 ) } );
				} ) ),
			] ),
			field( 'Rotation', numberInput( element.rotation, 1, function ( v ) {
				updateElement( id, { rotation: clamp( v, -360, 360 ) } );
			} ) ),
		] );

		els.inspector.appendChild( geometry );

		if ( element.type === 'text' ) {
			var typography = el( 'div', { class: 'cpic-inspector__section' }, [
				el( 'h3', { text: 'Text' } ),
				field( 'Content', el( 'textarea', {
					rows: '3',
					value: '',
					onInput: function ( event ) {
						updateElement( id, { text: event.target.value, source: 'static' } );
					},
				} ) ),
				field( 'Font size', numberInput( element.font_size, 1, function ( v ) {
					updateElement( id, { font_size: clamp( v, 8, 400 ) } );
				} ) ),
				field( 'Colour', colourInput( element.color, function ( v ) {
					updateElement( id, { color: v } );
				} ) ),
				field( 'Align', el( 'select', {
					onChange: function ( event ) {
						updateElement( id, { align: event.target.value } );
					},
				}, [ 'left', 'center', 'right' ].map( function ( value ) {
					var option = el( 'option', { value: value, text: value } );

					if ( element.align === value ) {
						option.selected = true;
					}

					return option;
				} ) ) ),
			] );

			var textarea = typography.querySelector( 'textarea' );
			if ( textarea ) {
				textarea.value = element.text || '';
			}

			els.inspector.appendChild( typography );
		}

		if ( element.type === 'shape' || element.type === 'gradient' ) {
			els.inspector.appendChild(
				el( 'div', { class: 'cpic-inspector__section' }, [
					el( 'h3', { text: 'Fill' } ),
					field( 'Colour', colourInput( element.fill, function ( v ) {
						updateElement( id, { fill: v } );
					} ) ),
					field( 'Corner radius', numberInput( element.border_radius, 1, function ( v ) {
						updateElement( id, { border_radius: clamp( v, 0, 400 ) } );
					} ) ),
				] )
			);
		}

		if ( element.type === 'image' || element.type === 'logo' ) {
			var preview = el( 'img', {
				src: element.url || '',
				alt: '',
				style: 'max-width:100%;height:auto;',
			} );

			els.inspector.appendChild(
				el( 'div', { class: 'cpic-inspector__section' }, [
					el( 'h3', { text: 'Image' } ),
					field( 'URL', textInput( element.url, function ( v ) {
						updateElement( id, { url: v } );
					} ) ),
					el( 'button', {
						type: 'button',
						class: 'button button-small',
						text: 'Choose from media library',
						onClick: function () {
							openMedia( function ( url ) {
								updateElement( id, { url: url } );
							} );
						},
					} ),
					preview,
				] )
			);
		}

		els.inspector.appendChild(
			el( 'div', { class: 'cpic-inspector__section' }, [
				el( 'button', {
					type: 'button',
					class: 'button button-link-delete',
					text: 'Hide layer',
					onClick: function () {
						toggleLayer( id );
					},
				} ),
			] )
		);
	}

	/* ── Media picker ──────────────────────────────────────────────── */

	function openMedia( callback ) {
		if ( ! window.wp || ! window.wp.media ) {
			setStatus( 'The media library is unavailable on this screen.', 'error' );
			return;
		}

		var frame = window.wp.media( {
			title: 'Choose an image',
			multiple: false,
			library: { type: 'image' },
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			callback( attachment.url );
		} );

		frame.open();
	}

	/* ── Saving ────────────────────────────────────────────────────── */

	async function save() {
		if ( ! state.document ) {
			return;
		}

		setStatus( str( 'saving', 'Saving…' ), 'busy' );

		try {
			var payload;
			var body = {
				name: els.templateSelect && els.templateSelect.value === '0' ? 'Studio design' : undefined,
				document: state.document,
			};

			if ( state.templateId > 0 ) {
				payload = await api( CONFIG.restPath + 'templates/' + state.templateId, {
					method: 'PUT',
					body: body,
				} );
			} else {
				payload = await api( CONFIG.restPath + 'templates', { method: 'POST', body: body } );
			}

			var saved = payload && payload.data ? payload.data : payload;

			if ( saved && saved.id ) {
				state.templateId = saved.id;

				if ( els.templateSelect ) {
					var option = els.templateSelect.querySelector( 'option[value="' + saved.id + '"]' );

					if ( ! option ) {
						option = el( 'option', { value: String( saved.id ), text: saved.name || 'Studio design' } );
						els.templateSelect.appendChild( option );
					}

					els.templateSelect.value = String( saved.id );
				}
			}

			state.dirty = false;
			setStatus( str( 'saved', 'Saved' ) );
		} catch ( error ) {
			setStatus( error.message || str( 'saveFailed', 'Could not save' ), 'error' );
		}
	}

	async function storeArtifact() {
		var dataUrl = await exportPng();

		var payload = await api( CONFIG.restPath + 'artifacts', {
			method: 'POST',
			body: {
				post_id: state.postId || 0,
				template_id: state.templateId || 0,
				format: state.format,
				document: state.document,
				binary: dataUrl,
			},
		} );

		return payload && payload.data ? payload.data : payload;
	}

	async function renderAndStore() {
		setStatus( str( 'rendering', 'Rendering…' ), 'busy' );

		try {
			state.plan = await requestPlan();
			await paint( state.plan );

			var artifact = await storeArtifact();

			setStatus( str( 'saved', 'Saved' ) );
			showResult( artifact );

			return artifact;
		} catch ( error ) {
			setStatus( error.message || str( 'renderFailed', 'Rendering failed' ), 'error' );
			throw error;
		}
	}

	function showResult( artifact ) {
		if ( ! els.result || ! els.resultBody || ! artifact ) {
			return;
		}

		els.result.hidden = false;

		while ( els.resultBody.firstChild ) {
			els.resultBody.removeChild( els.resultBody.firstChild );
		}

		if ( artifact.file_url ) {
			els.resultBody.appendChild( el( 'img', { src: artifact.file_url, alt: '' } ) );
		}

		els.resultBody.appendChild(
			el( 'p', { class: 'cpic-muted', text: ( artifact.width || '' ) + ' × ' + ( artifact.height || '' ) } )
		);

		if ( artifact.attach_url ) {
			els.resultBody.appendChild(
				el( 'p', {}, [
					el( 'a', { class: 'button', href: artifact.attach_url, text: 'Open in the media library' } ),
				] )
			);
		}
	}

	async function publish() {
		if ( ! state.postId ) {
			setStatus( 'Choose a post first — publishing needs one.', 'error' );
			return;
		}

		setStatus( str( 'publishing', 'Publishing…' ), 'busy' );

		try {
			await renderAndStore();

			var payload = await api( CONFIG.restPath + 'publishing/publish', {
				method: 'POST',
				body: {
					post_id: state.postId,
					format: state.format,
					destination_ids: [],
				},
			} );

			var result = payload && payload.data ? payload.data : payload;
			setStatus( str( 'published', 'Published' ) );

			if ( els.resultBody && result && result.deliveries ) {
				els.resultBody.appendChild(
					el( 'p', {
						class: 'cpic-muted',
						text: ( result.deliveries.length || 0 ) + ' destination(s) queued.',
					} )
				);
			}
		} catch ( error ) {
			setStatus( error.message || 'Publishing failed', 'error' );
		}
	}

	/* ── Canvas interaction ────────────────────────────────────────── */

	function canvasPoint( event ) {
		var canvas = els.canvas;
		var rect = canvas.getBoundingClientRect();
		var scaleX = canvas.width / rect.width;
		var scaleY = canvas.height / rect.height;

		return {
			x: ( event.clientX - rect.left ) * scaleX,
			y: ( event.clientY - rect.top ) * scaleY,
		};
	}

	function hitTest( point ) {
		var layers = ( ( state.plan && state.plan.layers ) || [] ).slice().sort( function ( a, b ) {
			return ( b.z || 0 ) - ( a.z || 0 );
		} );

		for ( var index = 0; index < layers.length; index++ ) {
			var layer = layers[ index ];
			var width = Math.max( layer.width, 24 );
			var height = Math.max( layer.height, 24 );

			if (
				point.x >= layer.x &&
				point.x <= layer.x + width &&
				point.y >= layer.y &&
				point.y <= layer.y + height
			) {
				return layer;
			}
		}

		return null;
	}

	function bindCanvas() {
		if ( ! els.canvas ) {
			return;
		}

		var dragging = null;

		els.canvas.addEventListener( 'pointerdown', function ( event ) {
			var layer = hitTest( canvasPoint( event ) );

			if ( ! layer ) {
				state.selected = null;
				renderLayers();
				renderInspector();

				if ( state.plan ) {
					paint( state.plan );
				}
				return;
			}

			state.selected = layer.id;
			renderLayers();
			renderInspector();

			var element = elementFor( layer.id );

			if ( ! element || element.locked ) {
				return;
			}

			var point = canvasPoint( event );
			dragging = {
				id: layer.id,
				offsetX: point.x - layer.x,
				offsetY: point.y - layer.y,
				moved: false,
			};

			els.canvas.setPointerCapture( event.pointerId );
		} );

		els.canvas.addEventListener( 'pointermove', function ( event ) {
			if ( ! dragging ) {
				return;
			}

			var point = canvasPoint( event );
			var element = elementFor( dragging.id );

			if ( ! element ) {
				return;
			}

			element.x = Math.round( point.x - dragging.offsetX );
			element.y = Math.round( point.y - dragging.offsetY );
			dragging.moved = true;
			state.dirty = true;

			// Move the preview layer without a round trip to the server.
			if ( state.plan ) {
				state.plan.layers.forEach( function ( layer ) {
					if ( layer.id === dragging.id ) {
						layer.x = element.x;
						layer.y = element.y;
					}
				} );

				paint( state.plan );
			}
		} );

		els.canvas.addEventListener( 'pointerup', function ( event ) {
			if ( ! dragging ) {
				return;
			}

			var moved = dragging.moved;
			dragging = null;

			if ( els.canvas.hasPointerCapture && els.canvas.hasPointerCapture( event.pointerId ) ) {
				els.canvas.releasePointerCapture( event.pointerId );
			}

			if ( moved ) {
				rerender();
			}
		} );
	}

	/* ── Boot ──────────────────────────────────────────────────────── */

	async function loadTemplate( id ) {
		try {
			var payload = await api( CONFIG.restPath + 'templates/' + id );
			var template = payload && payload.data ? payload.data : payload;

			if ( template && template.document ) {
				state.document = template.document;
				state.templateId = template.id;
				state.selected = null;
				rerender();
			}
		} catch ( error ) {
			setStatus( error.message || 'Could not load that design', 'error' );
		}
	}

	function bindControls() {
		if ( els.formatButtons ) {
			els.formatButtons.forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					state.format = button.getAttribute( 'data-format' );

					els.formatButtons.forEach( function ( sibling ) {
						sibling.setAttribute( 'aria-pressed', String( sibling === button ) );
					} );

					if ( state.document && state.document.canvas ) {
						state.document.canvas.format = state.format;
					}

					rerender();
				} );
			} );
		}

		if ( els.templateSelect ) {
			els.templateSelect.addEventListener( 'change', function () {
				var value = parseInt( els.templateSelect.value, 10 ) || 0;

				if ( 0 === value ) {
					state.templateId = 0;
					return;
				}

				loadTemplate( value );
			} );
		}

		if ( els.postSelect ) {
			els.postSelect.addEventListener( 'change', function () {
				state.postId = parseInt( els.postSelect.value, 10 ) || 0;
				rerender();
			} );
		}

		if ( els.brandSelect ) {
			els.brandSelect.addEventListener( 'change', function () {
				state.brandId = parseInt( els.brandSelect.value, 10 ) || 0;

				if ( state.document ) {
					state.document.brand = { id: state.brandId, overrides: ( state.document.brand && state.document.brand.overrides ) || {} };
				}

				rerender();
			} );
		}

		els.render && els.render.addEventListener( 'click', renderAndStore );
		els.save && els.save.addEventListener( 'click', save );
		els.publish && els.publish.addEventListener( 'click', publish );

		window.addEventListener( 'beforeunload', function ( event ) {
			if ( ! state.dirty ) {
				return undefined;
			}

			event.preventDefault();
			event.returnValue = '';
			return '';
		} );
	}

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

	async function boot() {
		els = {
			canvas: document.getElementById( 'cpic-canvas' ),
			canvasMeta: document.getElementById( 'cpic-canvas-meta' ),
			loading: document.getElementById( 'cpic-canvas-loading' ),
			layers: document.getElementById( 'cpic-layers' ),
			inspector: document.getElementById( 'cpic-inspector' ),
			status: document.getElementById( 'cpic-status' ),
			formatButtons: Array.prototype.slice.call( document.querySelectorAll( '.cpic-format' ) ),
			templateSelect: document.getElementById( 'cpic-template-select' ),
			postSelect: document.getElementById( 'cpic-post-select' ),
			brandSelect: document.getElementById( 'cpic-brand-select' ),
			render: document.getElementById( 'cpic-render' ),
			save: document.getElementById( 'cpic-save' ),
			publish: document.getElementById( 'cpic-publish' ),
			result: document.getElementById( 'cpic-result' ),
			resultBody: document.getElementById( 'cpic-result-body' ),
		};

		if ( ! els.canvas ) {
			return;
		}

		var initial = readState();

		if ( ! initial ) {
			setStatus( 'The studio could not start because its state is missing.', 'error' );
			return;
		}

		state.document = initial.document;
		state.templateId = initial.templateId || 0;
		state.postId = initial.postId || 0;
		state.format = ( initial.document && initial.document.canvas && initial.document.canvas.format ) || 'square';

		bindControls();
		bindCanvas();
		await rerender();
	}

	// Admin screens and the editor both call into the same renderer.
	window.CPICStudio = {
		/**
		 * Render a document to a PNG data URL without the studio screen.
		 *
		 * @param {Object} document Design document.
		 * @param {Object} options  { postId, format, brandId }.
		 * @return {Promise<string>} data:image/png URL.
		 */
		export: async function ( document, options ) {
			options = options || {};
			state.document = document;
			state.postId = options.postId || 0;
			state.format = options.format || ( document.canvas && document.canvas.format ) || 'square';
			state.brandId = options.brandId || 0;
			state.plan = await requestPlan();

			if ( ! els.canvas ) {
				// Headless use (editor panel): paint into an offscreen canvas.
				var canvas = window.document.createElement( 'canvas' );
				els.canvas = canvas;
			}

			await paint( state.plan );

			return els.canvas.toDataURL( 'image/png' );
		},
		state: state,
		paint: paint,
		rerender: rerender,
	};

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', boot );
	} else {
		boot();
	}
} )();

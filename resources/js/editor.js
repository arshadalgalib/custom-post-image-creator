/**
 * Post Image Creator — editor integration.
 *
 * Adds a "Social image" sidebar panel to the block editor and enhances the
 * classic metabox. Rendering is delegated to CPICStudio (resources/js/studio.js)
 * so the editor and the studio cannot produce different images.
 */
( function ( wp ) {
	'use strict';

	if ( ! wp || ! wp.plugins || ! wp.element ) {
		return;
	}

	var CONFIG = window.CPIC || {};
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var apiFetch = wp.apiFetch;

	if ( apiFetch && CONFIG.nonce ) {
		apiFetch.use( apiFetch.createNonceMiddleware( CONFIG.nonce ) );
	}

	/**
	 * Fetch the latest artifact for a post.
	 *
	 * @param {number} postId Post id.
	 * @return {Promise<Object|null>} Artifact or null.
	 */
	async function latestArtifact( postId ) {
		if ( ! postId ) {
			return null;
		}

		try {
			return await apiFetch( {
				path: '/cpic/v1/render/post/' + postId + '/latest',
			} );
		} catch ( error ) {
			return null;
		}
	}

	function statusLine( text, tone ) {
		return el(
			'p',
			{
				style: {
					margin: '8px 0 0',
					fontSize: '12px',
					color: 'error' === tone ? '#b32d2e' : tone === 'busy' ? '#7a5c00' : '#646970',
				},
			},
			text
		);
	}

	function SocialImagePanel() {
		var postIdState = useState( 0 );
		var postId = postIdState[ 0 ];
		var setPostId = postIdState[ 1 ];

		var artifactState = useState( null );
		var artifact = artifactState[ 0 ];
		var setArtifact = artifactState[ 1 ];

		var messageState = useState( '' );
		var message = messageState[ 0 ];
		var setMessage = messageState[ 1 ];

		var toneState = useState( '' );
		var tone = toneState[ 0 ];
		var setTone = toneState[ 1 ];

		useEffect( function () {
			var editor = wp.data.select( 'core/editor' );
			var id = editor && editor.getCurrentPostId ? editor.getCurrentPostId() : 0;

			setPostId( id );

			if ( id ) {
				latestArtifact( id ).then( setArtifact );
			}
		}, [] );

		async function generate() {
			if ( ! window.CPICStudio ) {
				setMessage( __( 'The renderer has not loaded. Reload the editor and try again.', 'custom-post-image-creator' ) );
				setTone( 'error' );
				return;
			}

			setMessage( CONFIG.strings.rendering );
			setTone( 'busy' );

			try {
				// The server owns the template choice: ask for the plan the editor
				// would publish, then draw it in this browser (where Bengali is
				// shaped correctly) and store the PNG.
				var plan = await apiFetch( {
					path: '/cpic/v1/render/plan',
					method: 'POST',
					data: { post_id: postId, format: ( CONFIG.formats && Object.keys( CONFIG.formats )[ 0 ] ) || 'square' },
				} );

				var document = plan && plan.document ? plan.document : null;
				var dataUrl;

				if ( document ) {
					dataUrl = await window.CPICStudio.export( document, { postId: postId, format: plan.format } );
				} else {
					// No document in the plan: paint the plan itself.
					await window.CPICStudio.paint( plan );
					dataUrl = await window.CPICStudio.paint( plan ) || null;
				}

				if ( ! dataUrl ) {
					throw new Error( CONFIG.strings.renderFailed );
				}

				var stored = await apiFetch( {
					path: '/cpic/v1/artifacts',
					method: 'POST',
					data: {
						post_id: postId,
						format: plan.format || 'square',
						document: document,
						binary: dataUrl,
					},
				} );

				setArtifact( stored );
				setMessage( CONFIG.strings.saved );
				setTone( '' );
			} catch ( error ) {
				setMessage( error.message || CONFIG.strings.renderFailed );
				setTone( 'error' );
			}
		}

		return el(
			Fragment,
			null,
			el(
				'div',
				{ className: 'cpic-panel' },
				el(
					'p',
					{ style: { margin: '0 0 8px' } },
					__( 'The social image is rendered in your browser so Bengali text joins correctly.', 'custom-post-image-creator' )
				),

				artifact && artifact.file_url
					? el( 'img', {
							src: artifact.file_url,
							alt: '',
							style: { width: '100%', height: 'auto', borderRadius: '4px', display: 'block' },
					  } )
					: el(
							'p',
							{ style: { margin: '0', color: '#646970' } },
							__( 'No social image yet for this post.', 'custom-post-image-creator' )
					  ),

				artifact && artifact.width
					? el(
							'p',
							{ style: { fontSize: '12px', color: '#646970', margin: '6px 0 0' } },
							artifact.width + ' × ' + artifact.height
					  )
					: null,

				el(
					'div',
					{ style: { display: 'flex', gap: '8px', marginTop: '12px', flexWrap: 'wrap' } },
					el(
						wp.components.Button,
						{ variant: 'primary', onClick: generate, disabled: ! postId },
						__( 'Generate image', 'custom-post-image-creator' )
					),
					el(
						wp.components.Button,
						{
							variant: 'secondary',
							href: CONFIG.adminUrl + 'admin.php?page=cpic-studio&post=' + postId,
						},
						__( 'Open studio', 'custom-post-image-creator' )
					)
				),

				message ? statusLine( message, tone ) : null
			)
		);
	}

	wp.plugins.registerPlugin( 'cpic-social-image', {
		render: function () {
			if ( ! wp.editPost || ! wp.editPost.PluginSidebar ) {
				return null;
			}

			return el(
				wp.editPost.PluginSidebar,
				{ name: 'cpic-social-image', title: __( 'Social image', 'custom-post-image-creator' ) },
				el( SocialImagePanel )
			);
		},
	} );

	/* ── Classic editor metabox ────────────────────────────────────── */

	function enhanceMetabox() {
		var metabox = document.querySelector( '.cpic-metabox' );

		if ( ! metabox || metabox.getAttribute( 'data-cpic-enhanced' ) ) {
			return;
		}

		metabox.setAttribute( 'data-cpic-enhanced', '1' );

		var postIdField = document.getElementById( 'post_ID' );
		var postId = postIdField ? parseInt( postIdField.value, 10 ) : 0;

		if ( ! postId || ! window.CPICStudio ) {
			return;
		}

		var button = document.createElement( 'button' );
		button.type = 'button';
		button.className = 'button';
		button.textContent = __( 'Regenerate image', 'custom-post-image-creator' );

		var note = document.createElement( 'p' );
		note.className = 'description';

		button.addEventListener( 'click', async function () {
			button.disabled = true;
			note.textContent = CONFIG.strings.rendering;

			try {
				var response = await fetch( CONFIG.restRoot.replace( /\/$/, '' ) + '/cpic/v1/render/plan', {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': CONFIG.nonce || '',
					},
					body: JSON.stringify( { post_id: postId } ),
				} );

				if ( ! response.ok ) {
					throw new Error( CONFIG.strings.renderFailed );
				}

				var plan = await response.json();
				var dataUrl = await window.CPICStudio.export(
					plan.document || { canvas: plan.canvas, elements: {} },
					{ postId: postId, format: plan.format }
				);

				var stored = await fetch( CONFIG.restRoot.replace( /\/$/, '' ) + '/cpic/v1/artifacts', {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': CONFIG.nonce || '',
					},
					body: JSON.stringify( {
						post_id: postId,
						format: plan.format || 'square',
						document: plan.document,
						binary: dataUrl,
					} ),
				} );

				if ( ! stored.ok ) {
					throw new Error( CONFIG.strings.saveFailed );
				}

				var artifact = await stored.json();
				var image = metabox.querySelector( 'img' );

				if ( artifact.file_url && image ) {
					image.src = artifact.file_url;
				}

				note.textContent = CONFIG.strings.saved;
			} catch ( error ) {
				note.textContent = error.message || CONFIG.strings.renderFailed;
			} finally {
				button.disabled = false;
			}
		} );

		metabox.appendChild( button );
		metabox.appendChild( note );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', enhanceMetabox );
	} else {
		enhanceMetabox();
	}
} )( window.wp );

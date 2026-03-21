/**
 * Admin JavaScript for the Changelog to Blog Post settings page.
 */

/* global ctbpAdmin */

document.addEventListener( 'DOMContentLoaded', function () {
	// -------------------------------------------------------------------------
	// Tab switching with ARIA and dirty-flag guard.
	// -------------------------------------------------------------------------
	const tabs   = Array.from( document.querySelectorAll( '[role="tab"]' ) );
	const panels = Array.from( document.querySelectorAll( '[role="tabpanel"]' ) );
	let formDirty = false;

	function activateTab( tab ) {
		const targetId = tab.getAttribute( 'aria-controls' );

		tabs.forEach( function ( t ) {
			t.setAttribute( 'aria-selected', 'false' );
			t.classList.remove( 'nav-tab-active' );
		} );

		tab.setAttribute( 'aria-selected', 'true' );
		tab.classList.add( 'nav-tab-active' );

		panels.forEach( function ( p ) {
			p.hidden = p.id !== targetId;
		} );
	}

	tabs.forEach( function ( tab, idx ) {
		tab.addEventListener( 'click', function ( e ) {
			if (
				formDirty &&
				! window.confirm( ctbpAdmin.i18n.unsavedChanges )
			) {
				e.preventDefault();
				return;
			}
			// Allow the browser to follow the href so the URL/tab updates
			// server-side routing handles the correct panel on load.
		} );

		tab.addEventListener( 'keydown', function ( e ) {
			let nextIdx = null;

			if ( e.key === 'ArrowRight' ) {
				nextIdx = ( idx + 1 ) % tabs.length;
			} else if ( e.key === 'ArrowLeft' ) {
				nextIdx = ( idx - 1 + tabs.length ) % tabs.length;
			}

			if ( null !== nextIdx ) {
				e.preventDefault();
				tabs[ nextIdx ].focus();
				tabs[ nextIdx ].click();
			}
		} );
	} );

	// Track unsaved changes.
	document.querySelectorAll( 'input, select, textarea' ).forEach( function ( el ) {
		el.addEventListener( 'change', function () {
			formDirty = true;
		} );
	} );

	// Clear the dirty flag on form submit.
	document.querySelectorAll( 'form' ).forEach( function ( form ) {
		form.addEventListener( 'submit', function () {
			formDirty = false;
		} );
	} );

	// -------------------------------------------------------------------------
	// REST API helper.
	// -------------------------------------------------------------------------

	/**
	 * Sends a request to a ctbp/v1 REST endpoint.
	 *
	 * For GET requests, `data` is serialised as query parameters.
	 * For POST requests, `data` is sent as a JSON body.
	 *
	 * @param {string}   method    HTTP method ('GET' or 'POST').
	 * @param {string}   path      Endpoint path relative to ctbp/v1 (e.g. '/ai/test-connection').
	 * @param {Object}   data      Query params (GET) or body payload (POST).
	 * @param {Function} onSuccess Called with the parsed response object on success.
	 * @param {Function} onError   Called with an object containing a `message` key on failure.
	 */
	window.ctbpFetch = function ( method, path, data, onSuccess, onError ) {
		const isGet = method.toUpperCase() === 'GET';
		let url     = ctbpAdmin.restUrl.replace( /\/$/, '' ) + path;

		if ( isGet && data && Object.keys( data ).length ) {
			url += '?' + new URLSearchParams( data ).toString();
		}

		const options = {
			method:  method.toUpperCase(),
			headers: {
				'X-WP-Nonce': ctbpAdmin.restNonce,
			},
		};

		if ( ! isGet ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( data || {} );
		}

		fetch( url, options )
			.then( function ( res ) {
				return res.json().then( function ( json ) {
					return { ok: res.ok, json };
				} );
			} )
			.then( function ( result ) {
				if ( result.ok ) {
					if ( typeof onSuccess === 'function' ) {
						onSuccess( result.json );
					}
				} else {
					if ( typeof onError === 'function' ) {
						onError( { message: result.json.message || ctbpAdmin.i18n.notImplemented } );
					}
				}
			} )
			.catch( function () {
				if ( typeof onError === 'function' ) {
					onError( { message: ctbpAdmin.i18n.notImplemented } );
				}
			} );
	};

	// -------------------------------------------------------------------------
	// AI provider field visibility.
	// -------------------------------------------------------------------------
	const providerSelect = document.getElementById( 'ctbp_ai_provider' );

	function updateProviderVisibility() {
		const selected = providerSelect ? providerSelect.value : '';

		document.querySelectorAll( '.ctbp-api-key-row, .ctbp-provider-note' ).forEach( function ( el ) {
			el.hidden = el.dataset.provider !== selected;
		} );
	}

	if ( providerSelect ) {
		providerSelect.addEventListener( 'change', updateProviderVisibility );
		updateProviderVisibility();
	}

	// Test connection.
	const testBtn = document.getElementById( 'ctbp-test-connection' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			const resultEl = document.getElementById( 'ctbp-connection-result' );
			if ( resultEl ) {
				resultEl.textContent = ctbpAdmin.i18n.validating;
			}

			window.ctbpFetch(
				'GET',
				'/ai/test-connection',
				{},
				function ( data ) {
					if ( resultEl ) {
						resultEl.textContent = data.message || '';
					}
				},
				function ( data ) {
					if ( resultEl ) {
						resultEl.textContent = ( data && data.message ) ? data.message : ctbpAdmin.i18n.notImplemented;
					}
				}
			);
		} );
	}

	// -------------------------------------------------------------------------
	// Repository edit row toggling.
	// -------------------------------------------------------------------------
	document.querySelectorAll( '.ctbp-edit-repo-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			const editRowId = btn.getAttribute( 'aria-controls' );
			const editRow   = document.getElementById( editRowId );

			if ( ! editRow ) {
				return;
			}

			const isExpanded = btn.getAttribute( 'aria-expanded' ) === 'true';

			if ( isExpanded ) {
				editRow.hidden = true;
				btn.setAttribute( 'aria-expanded', 'false' );
				btn.textContent = ctbpAdmin.i18n.edit || 'Edit';
			} else {
				editRow.hidden = false;
				btn.setAttribute( 'aria-expanded', 'true' );
				btn.textContent = ctbpAdmin.i18n.done || 'Done';
			}
		} );
	} );

	// -------------------------------------------------------------------------
	// Remove repository — dialog-based confirmation.
	// -------------------------------------------------------------------------
	const removeDialog    = document.getElementById( 'ctbp-remove-dialog' );
	const removeRepoInput = document.getElementById( 'ctbp-remove-repo-input' );
	const removeCancelBtn = document.getElementById( 'ctbp-remove-cancel' );

	document.querySelectorAll( '.ctbp-remove-repo-btn' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			const repo = btn.dataset.repo;

			if ( removeDialog && removeRepoInput ) {
				removeRepoInput.value = repo;
				removeDialog.showModal();
			} else {
				// Fallback for browsers without <dialog> support.
				if ( window.confirm( ctbpAdmin.i18n.confirmRemove ) ) {
					const form = document.createElement( 'form' );
					form.method = 'post';
					form.innerHTML =
						'<input type="hidden" name="ctbp_action" value="repositories">' +
						'<input type="hidden" name="ctbp_nonce" value="">' +
						'<input type="hidden" name="ctbp_remove_repo" value="' +
						encodeURIComponent( repo ) +
						'">';
					document.body.appendChild( form );
					form.submit();
				}
			}
		} );
	} );

	if ( removeCancelBtn && removeDialog ) {
		removeCancelBtn.addEventListener( 'click', function () {
			removeDialog.close();
		} );
	}

	// -------------------------------------------------------------------------
	// WP.org slug validation.
	// -------------------------------------------------------------------------
	document.querySelectorAll( '.ctbp-validate-slug' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			const row      = btn.closest( 'p' );
			const input    = row ? row.querySelector( '.ctbp-wporg-slug-input' ) : null;
			const resultEl = row ? row.querySelector( '.ctbp-slug-validation-result' ) : null;

			if ( ! input || ! resultEl ) {
				return;
			}

			const slug = input.value.trim();
			if ( ! slug ) {
				return;
			}

			resultEl.textContent = ctbpAdmin.i18n.validating;

			window.ctbpFetch(
				'GET',
				'/wporg/validate',
				{ slug },
				function ( data ) {
					if ( data && data.warning ) {
						resultEl.textContent = ctbpAdmin.i18n.slugNotFound;
					} else {
						resultEl.textContent = ctbpAdmin.i18n.slugValid;
					}
				},
				function () {
					resultEl.textContent = ctbpAdmin.i18n.slugNotFound;
				}
			);
		} );
	} );

	// -------------------------------------------------------------------------
	// Generate draft now + conflict resolution dialog.
	// -------------------------------------------------------------------------
	const conflictDialog    = document.getElementById( 'ctbp-conflict-dialog' );
	const conflictPostInfo  = document.getElementById( 'ctbp-conflict-post-info' );
	const conflictReplace   = document.getElementById( 'ctbp-conflict-replace' );
	const conflictAlongside = document.getElementById( 'ctbp-conflict-alongside' );
	const conflictCancel    = document.getElementById( 'ctbp-conflict-cancel' );

	/**
	 * Shows an inline result message below the generate button.
	 *
	 * @param {HTMLElement} btn     The generate button.
	 * @param {string}      message Text to display.
	 * @param {string}      [url]   Optional edit URL for a "View draft" link.
	 * @param {boolean}     [isErr] Whether this is an error (affects styling).
	 */
	function showGenerateResult( btn, message, url, isErr ) {
		let resultEl = btn.nextElementSibling;
		if ( ! resultEl || ! resultEl.classList.contains( 'ctbp-generate-result' ) ) {
			resultEl = document.createElement( 'span' );
			resultEl.className = 'ctbp-generate-result';
			btn.parentNode.insertBefore( resultEl, btn.nextSibling );
		}

		resultEl.style.color = isErr ? '#d63638' : '#00a32a';

		if ( url ) {
			resultEl.innerHTML =
				document.createTextNode( message + ' ' ).textContent +
				'<a href="' + encodeURI( url ) + '">' + ctbpAdmin.i18n.viewDraft + '</a>';
		} else {
			resultEl.textContent = message;
		}
	}

	/**
	 * Fires the resolve-conflict REST call and handles the response.
	 *
	 * @param {HTMLElement} btn        The generate button that triggered the flow.
	 * @param {string}      repo       Repository identifier.
	 * @param {string}      resolution 'replace' or 'alongside'.
	 * @param {number}      postId     ID of the existing post (for replace).
	 */
	function resolveConflict( btn, repo, resolution, postId ) {
		if ( conflictDialog ) {
			conflictDialog.close();
		}

		btn.disabled    = true;
		btn.textContent = ctbpAdmin.i18n.generating;

		window.ctbpFetch(
			'POST',
			'/releases/resolve-conflict',
			{ repo, resolution, post_id: postId },
			function ( data ) {
				btn.disabled    = false;
				btn.textContent = ctbpAdmin.i18n.draftCreated || 'Generate draft now';
				const post = data && data.post;
				showGenerateResult(
					btn,
					ctbpAdmin.i18n.draftCreated,
					post ? post.edit_url : null,
					false
				);
			},
			function ( data ) {
				btn.disabled    = false;
				btn.textContent = 'Generate draft now';
				showGenerateResult(
					btn,
					( data && data.message ) || ctbpAdmin.i18n.notImplemented,
					null,
					true
				);
			}
		);
	}

	document.querySelectorAll( '.ctbp-generate-draft' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			const repo = btn.dataset.repo;

			btn.disabled    = true;
			btn.textContent = ctbpAdmin.i18n.generating;

			window.ctbpFetch(
				'POST',
				'/releases/generate-draft',
				{ repo },
				function ( data ) {
					btn.disabled = false;

					if ( data && data.conflict ) {
						// Existing post found — show conflict dialog.
						const post = data.post;

						if ( conflictPostInfo ) {
							conflictPostInfo.textContent =
								'"' + ( post ? post.title : '' ) + '" (' + ( post ? post.status : '' ) + ')';
						}

						// Wire up resolution buttons for this specific request.
						function onReplace() {
							cleanup();
							resolveConflict( btn, repo, 'replace', post ? post.id : 0 );
						}
						function onAlongside() {
							cleanup();
							resolveConflict( btn, repo, 'alongside', 0 );
						}
						function onCancel() {
							cleanup();
							if ( conflictDialog ) {
								conflictDialog.close();
							}
							btn.textContent = 'Generate draft now';
						}
						function cleanup() {
							if ( conflictReplace )   { conflictReplace.removeEventListener( 'click', onReplace ); }
							if ( conflictAlongside ) { conflictAlongside.removeEventListener( 'click', onAlongside ); }
							if ( conflictCancel )    { conflictCancel.removeEventListener( 'click', onCancel ); }
						}

						if ( conflictReplace )   { conflictReplace.addEventListener( 'click', onReplace ); }
						if ( conflictAlongside ) { conflictAlongside.addEventListener( 'click', onAlongside ); }
						if ( conflictCancel )    { conflictCancel.addEventListener( 'click', onCancel ); }

						if ( conflictDialog ) {
							conflictDialog.showModal();
						} else {
							// Fallback: no <dialog> support — use confirm() chain.
							if ( window.confirm( ctbpAdmin.i18n.replaceWarning ) ) {
								resolveConflict( btn, repo, 'replace', post ? post.id : 0 );
							} else {
								resolveConflict( btn, repo, 'alongside', 0 );
							}
						}
					} else {
						// Draft created without conflict.
						const post = data && data.post;
						btn.textContent = 'Generate draft now';
						showGenerateResult(
							btn,
							ctbpAdmin.i18n.draftCreated,
							post ? post.edit_url : null,
							false
						);
					}
				},
				function ( data ) {
					btn.disabled    = false;
					btn.textContent = 'Generate draft now';
					showGenerateResult(
						btn,
						( data && data.message ) || ctbpAdmin.i18n.notImplemented,
						null,
						true
					);
				}
			);
		} );
	} );

	if ( conflictCancel && conflictDialog ) {
		// Base cancel handler (no-op if already cleaned up by resolution).
		conflictCancel.addEventListener( 'click', function () {
			conflictDialog.close();
		} );
	}
} );

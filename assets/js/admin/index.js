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
	// AJAX helper.
	// -------------------------------------------------------------------------

	/**
	 * Sends a request to the WordPress admin-ajax endpoint.
	 *
	 * @param {string}   action    WP AJAX action name.
	 * @param {Object}   data      Additional POST data.
	 * @param {Function} onSuccess Called with response.data on success.
	 * @param {Function} onError   Called with response.data on failure.
	 */
	window.ctbpAjax = function ( action, data, onSuccess, onError ) {
		const body = new URLSearchParams(
			Object.assign( { action, nonce: ctbpAdmin.nonce }, data )
		);

		fetch( ctbpAdmin.ajaxUrl, {
			method:  'POST',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body:    body.toString(),
		} )
			.then( function ( res ) {
				return res.json();
			} )
			.then( function ( json ) {
				if ( json.success ) {
					if ( typeof onSuccess === 'function' ) {
						onSuccess( json.data );
					}
				} else {
					if ( typeof onError === 'function' ) {
						onError( json.data );
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

			window.ctbpAjax(
				'ctbp_test_ai_connection',
				{ provider: providerSelect ? providerSelect.value : '' },
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

			window.ctbpAjax(
				'ctbp_validate_wporg_slug',
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
	// Generate draft now.
	// -------------------------------------------------------------------------
	document.querySelectorAll( '.ctbp-generate-draft' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			const repo = btn.dataset.repo;
			btn.disabled = true;

			window.ctbpAjax(
				'ctbp_generate_draft_now',
				{ repo },
				function ( data ) {
					btn.disabled = false;
					window.alert( ( data && data.message ) || ctbpAdmin.i18n.notImplemented );
				},
				function ( data ) {
					btn.disabled = false;
					window.alert( ( data && data.message ) || ctbpAdmin.i18n.notImplemented );
				}
			);
		} );
	} );
} );

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
			const spinner  = testBtn.parentNode.querySelector( '.ctbp-connection-spinner' );

			if ( spinner ) {
				spinner.style.display = 'inline-block';
				spinner.classList.add( 'is-active' );
			}
			if ( resultEl ) {
				resultEl.textContent = ctbpAdmin.i18n.validating || 'Testing...';
			}

			// Read currently selected provider and key from the form (not saved values).
			var selectedProvider = providerSelect ? providerSelect.value : '';
			var keyInput = selectedProvider
				? document.getElementById( 'ctbp_api_key_' + selectedProvider )
				: null;
			var apiKey = keyInput ? keyInput.value : '';

			var params = {};
			if ( selectedProvider ) {
				params.provider = selectedProvider;
			}
			if ( apiKey ) {
				params.api_key = apiKey;
			}

			window.ctbpFetch(
				'GET',
				'/ai/test-connection',
				params,
				function () {
					if ( spinner ) {
						spinner.classList.remove( 'is-active' );
						spinner.style.display = 'none';
					}
					if ( resultEl ) {
						resultEl.innerHTML = validIcon( ctbpAdmin.i18n.connectionSuccess || 'Connection successful' );
					}
				},
				function ( data ) {
					if ( spinner ) {
						spinner.classList.remove( 'is-active' );
						spinner.style.display = 'none';
					}
					if ( resultEl ) {
						var msg = ( data && data.message ) ? data.message : ctbpAdmin.i18n.notImplemented;
						resultEl.innerHTML = warningIcon( msg ) + ' ' + msg;
					}
				}
			);
		} );
	}

	// -------------------------------------------------------------------------
	// Repository inline edit — WP Quick Edit clone pattern.
	// -------------------------------------------------------------------------
	const editTemplate = document.getElementById( 'ctbp-inline-edit' );

	/**
	 * Closes the currently active inline edit row (if any),
	 * removes it from the DOM, and restores the data row.
	 */
	function closeActiveEditRow() {
		const activeEdit = document.querySelector( '.wp-list-table tbody > .ctbp-repo-edit-row' );
		if ( ! activeEdit ) {
			return;
		}
		// Spacer is between the data row and the edit row.
		const spacer  = activeEdit.previousElementSibling;
		const dataRow = spacer ? spacer.previousElementSibling : null;

		var editLink = dataRow ? dataRow.querySelector( '.ctbp-edit-repo-btn' ) : null;

		if ( dataRow ) {
			dataRow.style.display = '';
		}
		if ( spacer && spacer.classList.contains( 'hidden' ) ) {
			spacer.remove();
		}
		activeEdit.remove();

		if ( editLink ) {
			editLink.focus();
		}
	}

	/**
	 * Opens the inline edit row for a data row by cloning the template,
	 * populating it with the row's data-* attributes, and injecting it.
	 *
	 * @param {HTMLElement} dataRow The <tr> data row to edit.
	 */
	function openEditRow( dataRow ) {
		// Close any already-open edit row first.
		closeActiveEditRow();

		if ( ! editTemplate ) {
			return;
		}

		const repo        = dataRow.dataset.repo || '';
		const displayName = dataRow.dataset.displayName || '';
		const editRow     = editTemplate.querySelector( 'tr' ).cloneNode( true );

		// Populate the legend.
		const legend = editRow.querySelector( '.inline-edit-legend' );
		if ( legend ) {
			legend.textContent = ( ctbpAdmin.i18n.editLabel || 'Edit:' ) + ' ' + displayName;
		}

		// Populate text fields.
		var fields = {
			display_name: dataRow.dataset.displayName || '',
			plugin_link:  dataRow.dataset.pluginLink || '',
			tags:         dataRow.dataset.tags || '',
		};

		Object.keys( fields ).forEach( function ( key ) {
			var input = editRow.querySelector( '[data-field="' + key + '"]' );
			if ( input ) {
				input.value = fields[ key ];
				input.name  = 'repos[' + repo + '][' + key + ']';
			}
		} );

		// Populate select: post_status.
		var statusSelect = editRow.querySelector( '[data-field="post_status"]' );
		if ( statusSelect ) {
			statusSelect.name  = 'repos[' + repo + '][post_status]';
			statusSelect.value = dataRow.dataset.postStatus || 'draft';
		}

		// Populate category checklist.
		var catHidden = editRow.querySelector( '.ctbp-tpl-cat-hidden' );
		if ( catHidden ) {
			catHidden.name = 'repos[' + repo + '][categories][]';
		}
		var savedCats = [];
		try {
			savedCats = JSON.parse( dataRow.dataset.categories || '[]' );
		} catch ( e ) {
			savedCats = [];
		}
		editRow.querySelectorAll( '.ctbp-tpl-categories input[type="checkbox"]' ).forEach( function ( cb ) {
			cb.name    = 'repos[' + repo + '][categories][]';
			cb.checked = savedCats.indexOf( parseInt( cb.value, 10 ) ) !== -1;
		} );

		// Populate select: author.
		var authorSelect = editRow.querySelector( '.ctbp-tpl-author' );
		if ( authorSelect ) {
			authorSelect.name  = 'repos[' + repo + '][author]';
			authorSelect.value = dataRow.dataset.author || '0';
		}

		// Populate checkbox: paused.
		var pausedCheckbox = editRow.querySelector( '[data-field="paused"]' );
		if ( pausedCheckbox ) {
			pausedCheckbox.name    = 'repos[' + repo + '][paused]';
			pausedCheckbox.checked = dataRow.dataset.paused === '1';
		}

		// Populate featured image.
		var featuredImageId = parseInt( dataRow.dataset.featuredImage || '0', 10 );
		var imgInput        = editRow.querySelector( '[data-field="featured_image"]' );
		if ( imgInput ) {
			imgInput.name  = 'repos[' + repo + '][featured_image]';
			imgInput.value = featuredImageId;
		}
		wireFeatureImagePicker( editRow, featuredImageId );

		// Wire up Cancel button.
		var cancelBtn = editRow.querySelector( '.ctbp-cancel-edit' );
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', closeActiveEditRow );
		}

		// Wire up plugin link blur validation.
		var pluginLinkInput = editRow.querySelector( '.ctbp-plugin-link-input' );
		if ( pluginLinkInput ) {
			wirePluginLinkValidation( pluginLinkInput );
		}

		// Insert a hidden spacer + the edit row after the data row.
		// WP Quick Edit does the same: dataRow, spacer, editRow — so
		// the edit row lands at the same nth-child parity as the data row.
		var spacer = document.createElement( 'tr' );
		spacer.className = 'hidden';

		dataRow.style.display = 'none';
		dataRow.parentNode.insertBefore( spacer, dataRow.nextSibling );
		spacer.parentNode.insertBefore( editRow, spacer.nextSibling );

		var firstInput = editRow.querySelector( 'input, select, textarea' );
		if ( firstInput ) {
			firstInput.focus();
		}
	}

	// Open edit row from row-action "Edit" link.
	document.querySelectorAll( '.ctbp-edit-repo-btn' ).forEach( function ( link ) {
		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();
			var dataRow = link.closest( 'tr' );
			if ( dataRow ) {
				openEditRow( dataRow );
			}
		} );
	} );

	// -------------------------------------------------------------------------
	// Remove repository — dialog-based confirmation.
	// -------------------------------------------------------------------------
	const removeDialog    = document.getElementById( 'ctbp-remove-dialog' );
	const removeRepoInput = document.getElementById( 'ctbp-remove-repo-input' );
	const removeCancelBtn = document.getElementById( 'ctbp-remove-cancel' );

	document.querySelectorAll( '.ctbp-remove-repo-btn' ).forEach( function ( link ) {
		link.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			const repo = link.dataset.repo;

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
	// Featured image media picker.
	// -------------------------------------------------------------------------

	/**
	 * Wires the Select / Remove featured image buttons on an edit row.
	 *
	 * @param {HTMLElement} editRow         The inline edit <tr>.
	 * @param {number}      attachmentId    Current attachment ID (0 = none).
	 */
	function wireFeatureImagePicker( editRow, attachmentId ) {
		var selectBtn = editRow.querySelector( '.ctbp-select-image' );
		var removeBtn = editRow.querySelector( '.ctbp-remove-image' );
		var preview   = editRow.querySelector( '.ctbp-featured-image-preview' );
		var input     = editRow.querySelector( '[data-field="featured_image"]' );

		if ( ! selectBtn || ! input ) {
			return;
		}

		// Show existing thumbnail if set.
		if ( attachmentId > 0 && preview ) {
			preview.innerHTML = '<img src="" style="max-width:75px;height:auto;display:block;margin-bottom:8px;" />';
			var img = preview.querySelector( 'img' );
			// Use wp.media attachment to get the URL.
			var attachment = wp.media.attachment( attachmentId );
			attachment.fetch().then( function () {
				var url = ( attachment.get( 'sizes' ) && attachment.get( 'sizes' ).thumbnail )
					? attachment.get( 'sizes' ).thumbnail.url
					: attachment.get( 'url' );
				if ( img ) {
					img.src = url;
				}
			} );
			if ( removeBtn ) {
				removeBtn.style.display = '';
			}
		}

		selectBtn.addEventListener( 'click', function ( e ) {
			e.preventDefault();

			var frame = wp.media( {
				title:    ctbpAdmin.i18n.selectImage || 'Select Featured Image',
				button:   { text: ctbpAdmin.i18n.useImage || 'Use this image' },
				multiple: false,
				library:  { type: 'image' },
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				input.value = attachment.id;

				if ( preview ) {
					var url = ( attachment.sizes && attachment.sizes.thumbnail )
						? attachment.sizes.thumbnail.url
						: attachment.url;
					preview.innerHTML = '<img src="' + url + '" style="max-width:75px;height:auto;display:block;margin-bottom:8px;" />';
				}
				if ( removeBtn ) {
					removeBtn.style.display = '';
				}
			} );

			frame.open();
		} );

		if ( removeBtn ) {
			removeBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				input.value = '0';
				if ( preview ) {
					preview.innerHTML = '';
				}
				removeBtn.style.display = 'none';
			} );
		}
	}

	// -------------------------------------------------------------------------
	// Plugin Link blur validation.
	// -------------------------------------------------------------------------

	/**
	 * Wires focus/blur validation on a plugin link input.
	 * - URL: client-side format check.
	 * - Plain string: WP.org slug API check.
	 * - Skips validation if value unchanged since focus.
	 *
	 * @param {HTMLInputElement} input The plugin link input element.
	 */
	var warningTooltip = ctbpAdmin.i18n.pluginLinkHint || 'Enter a valid URL or WordPress.org plugin slug';

	/**
	 * Returns a green check dashicon with screen reader text.
	 *
	 * @param {string} [label] Accessible label. Defaults to "Valid".
	 */
	function validIcon( label ) {
		var text = label || ctbpAdmin.i18n.valid || 'Valid';
		return '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" aria-hidden="true"></span>' +
			'<span class="screen-reader-text">' + text + '</span>';
	}

	/**
	 * Returns a yellow warning dashicon with screen reader text.
	 *
	 * @param {string} [label] Accessible label. Defaults to warning tooltip.
	 */
	function warningIcon( label ) {
		var text = label || warningTooltip;
		return '<span class="dashicons dashicons-warning" style="color: #dba617; cursor: help;" title="' + text + '" aria-hidden="true"></span>' +
			'<span class="screen-reader-text">' + text + '</span>';
	}

	/**
	 * Checks whether a value looks like a URL (has dots, suggesting a domain).
	 */
	function looksLikeUrl( value ) {
		return /^https?:\/\//i.test( value ) || /[^\/\s]+\.[^\/\s]+/.test( value );
	}

	function wirePluginLinkValidation( input ) {
		var focusValue = '';

		input.addEventListener( 'focus', function () {
			focusValue = input.value;
		} );

		input.addEventListener( 'blur', function () {
			var value    = input.value.trim();
			var statusEl = input.closest( '.input-text-wrap' ).querySelector( '.ctbp-plugin-link-status' );

			if ( ! statusEl ) {
				return;
			}

			// Skip if unchanged.
			if ( value === focusValue ) {
				return;
			}

			// Empty — clear status.
			if ( ! value ) {
				statusEl.innerHTML = '';
				return;
			}

			// Looks like a URL (has dots) — auto-prepend https:// if missing.
			if ( looksLikeUrl( value ) ) {
				if ( ! /^https?:\/\//i.test( value ) ) {
					value = 'https://' + value;
					input.value = value;
				}
				try {
					new URL( value );
					statusEl.innerHTML = validIcon();
				} catch ( e ) {
					statusEl.innerHTML = warningIcon();
				}
				return;
			}

			// Plain string — validate as WP.org slug via REST.
			statusEl.innerHTML = '<span class="spinner is-active" style="float:none;margin:0;"></span>';

			window.ctbpFetch(
				'GET',
				'/wporg/validate',
				{ value: value },
				function ( data ) {
					statusEl.innerHTML = data && data.valid ? validIcon() : warningIcon();
				},
				function () {
					statusEl.innerHTML = warningIcon();
				}
			);
		} );
	}

	// -------------------------------------------------------------------------
	// Generate draft post + regeneration dialog.
	// -------------------------------------------------------------------------
	const conflictDialog  = document.getElementById( 'ctbp-conflict-dialog' );
	const conflictInfo    = document.getElementById( 'ctbp-conflict-post-info' );
	const conflictConfirm = document.getElementById( 'ctbp-conflict-confirm' );
	const conflictCancel  = document.getElementById( 'ctbp-conflict-cancel' );

	/**
	 * Updates the Last Post column for a data row with a fade-in highlight.
	 *
	 * @param {HTMLElement} btn  The generate button (used to find the row).
	 * @param {Object}      post Post data from the REST response.
	 */
	function updateLastPostColumn( btn, post ) {
		var dataRow = btn.closest( 'tr' );
		if ( ! dataRow || ! post ) {
			return;
		}

		var lastPostCell = dataRow.querySelector( '.column-last_post' );
		if ( ! lastPostCell ) {
			return;
		}

		var label = post.tag ? post.tag + ' on ' + post.date : post.date;
		lastPostCell.innerHTML = '<a href="' + encodeURI( post.edit_url ) + '">' +
			document.createTextNode( label ).textContent + '</a>';

		// Highlight the cell briefly to signal the update.
		lastPostCell.style.transition = 'background-color 0.3s';
		lastPostCell.style.backgroundColor = '#dff0d8';
		setTimeout( function () {
			lastPostCell.style.backgroundColor = '';
		}, 1500 );
	}

	/**
	 * Shows a result indicator next to the generate button.
	 *
	 * Success: green check icon, updates the Last Post column.
	 * Error: yellow warning icon with error message as tooltip.
	 *
	 * @param {HTMLElement} btn     The generate button.
	 * @param {Object|null} post    Post data on success, null on error.
	 * @param {string}      [error] Error message on failure.
	 */
	function showGenerateResult( btn, post, error ) {
		// Hide any active spinner.
		var spinner = btn.closest( 'td' ).querySelector( '.ctbp-generate-spinner' );
		if ( spinner ) {
			spinner.style.display = 'none';
		}

		var statusEl = btn.closest( 'td' ).querySelector( '.ctbp-generate-status' );
		if ( ! statusEl ) {
			return;
		}

		if ( post ) {
			statusEl.innerHTML = validIcon( ctbpAdmin.i18n.draftCreated || 'Post created' );
			updateLastPostColumn( btn, post );
		} else {
			var msg = error || ctbpAdmin.i18n.notImplemented;
			statusEl.innerHTML = warningIcon( msg );
		}
	}

	/**
	 * Disables row action links (Edit, Remove) in the same table row.
	 */
	function disableRowActions( btn ) {
		var row = btn.closest( 'tr' );
		if ( ! row ) return;
		row.querySelectorAll( '.row-actions a' ).forEach( function ( link ) {
			link.dataset.ctbpHref = link.getAttribute( 'href' );
			link.removeAttribute( 'href' );
			link.style.pointerEvents = 'none';
			link.style.opacity = '0.5';
		} );
	}

	/**
	 * Re-enables row action links.
	 */
	function enableRowActions( btn ) {
		var row = btn.closest( 'tr' );
		if ( ! row ) return;
		row.querySelectorAll( '.row-actions a' ).forEach( function ( link ) {
			if ( link.dataset.ctbpHref ) {
				link.setAttribute( 'href', link.dataset.ctbpHref );
				delete link.dataset.ctbpHref;
			}
			link.style.pointerEvents = '';
			link.style.opacity = '';
		} );
	}

	/**
	 * Fires the resolve-conflict REST call and handles the response.
	 *
	 * @param {HTMLElement} btn        The generate button that triggered the flow.
	 * @param {string}      repo       Repository identifier.
	 * @param {string}      resolution 'replace' or 'alongside'.
	 * @param {number}      postId     ID of the existing post (for replace).
	 */
	/**
	 * Regenerates an existing post via the regenerate endpoint (creates a revision).
	 */
	function regenerateExisting( btn, postId ) {
		if ( conflictDialog ) {
			conflictDialog.close();
		}
		btn.focus();

		btn.disabled = true;
		disableRowActions( btn );

		var spinner = btn.closest( 'td' ).querySelector( '.ctbp-generate-spinner' );
		if ( spinner ) {
			spinner.style.display = 'inline-block';
			spinner.classList.add( 'is-active' );
		}

		window.ctbpFetch(
			'POST',
			'/releases/regenerate',
			{ post_id: postId },
			function ( data ) {
				btn.disabled = false;
				enableRowActions( btn );
				showGenerateResult( btn, data && data.post, null );
			},
			function ( data ) {
				btn.disabled = false;
				enableRowActions( btn );
				showGenerateResult( btn, null, ( data && data.message ) || null );
			}
		);
	}

	document.querySelectorAll( '.ctbp-generate-draft' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			const repo = btn.dataset.repo;

			btn.disabled = true;
			disableRowActions( btn );

			// Show the adjacent spinner.
			const spinner = btn.closest( 'td' ).querySelector( '.ctbp-generate-spinner' );
			if ( spinner ) {
				spinner.style.display = 'inline-block';
				spinner.classList.add( 'is-active' );
			}

			var statusEl = btn.closest( 'td' ).querySelector( '.ctbp-generate-status' );
			if ( statusEl ) {
				statusEl.innerHTML = '<span class="screen-reader-text">' + ctbpAdmin.i18n.generating + '</span>';
			}

			window.ctbpFetch(
				'POST',
				'/releases/generate-draft',
				{ repo },
				function ( data ) {
					btn.disabled = false;

					// Hide spinner — either showing dialog or result.
					var sp = btn.closest( 'td' ).querySelector( '.ctbp-generate-spinner' );
					if ( sp ) {
						sp.style.display = 'none';
					}

					if ( data && data.conflict ) {
						// Existing post — show regenerate confirmation.
						var post = data.post;

						if ( conflictInfo ) {
							conflictInfo.textContent =
								'"' + ( post ? post.title : '' ) + '" (' + ( post ? post.status : '' ) + ')';
						}

						function onConfirm() {
							cleanup();
							regenerateExisting( btn, post ? post.id : 0 );
						}
						function onCancel() {
							cleanup();
							if ( conflictDialog ) {
								conflictDialog.close();
							}
							var s = btn.closest( 'td' ).querySelector( '.ctbp-generate-status' );
							if ( s ) {
								s.innerHTML = '';
							}
							enableRowActions( btn );
							btn.focus();
						}
						function cleanup() {
							if ( conflictConfirm ) { conflictConfirm.removeEventListener( 'click', onConfirm ); }
							if ( conflictCancel )  { conflictCancel.removeEventListener( 'click', onCancel ); }
						}

						if ( conflictConfirm ) { conflictConfirm.addEventListener( 'click', onConfirm ); }
						if ( conflictCancel )  { conflictCancel.addEventListener( 'click', onCancel ); }

						if ( conflictDialog ) {
							conflictDialog.showModal();
						} else {
							// Fallback: no <dialog> support.
							if ( window.confirm( ctbpAdmin.i18n.regenerateConfirm || 'A post already exists. Regenerate it?' ) ) {
								regenerateExisting( btn, post ? post.id : 0 );
							}
						}
					} else {
						// Draft created without conflict.
						enableRowActions( btn );
						showGenerateResult( btn, data && data.post, null );
					}
				},
				function ( data ) {
					btn.disabled = false;
					enableRowActions( btn );
					showGenerateResult( btn, null, ( data && data.message ) || null );
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

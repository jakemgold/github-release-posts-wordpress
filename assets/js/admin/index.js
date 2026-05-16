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
	 * Sends a request to a ghrp/v1 REST endpoint.
	 *
	 * For GET requests, `data` is serialised as query parameters.
	 * For POST requests, `data` is sent as a JSON body.
	 *
	 * @param {string}   method    HTTP method ('GET' or 'POST').
	 * @param {string}   path      Endpoint path relative to ghrp/v1 (e.g. '/ai/test-connection').
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
	// Test notification email.
	// -------------------------------------------------------------------------
	const testNotifBtn = document.getElementById( 'ghrp-test-notification' );
	if ( testNotifBtn ) {
		testNotifBtn.addEventListener( 'click', function () {
			const resultEl = document.getElementById( 'ghrp-test-notification-result' );
			const spinner = testNotifBtn.parentNode.querySelector( '.ghrp-test-notification-spinner' );

			if ( spinner ) {
				spinner.style.display = 'inline-block';
				spinner.classList.add( 'is-active' );
			}
			if ( resultEl ) {
				resultEl.textContent = '';
			}

			window.ctbpFetch(
				'POST',
				'/notifications/test',
				{},
				function ( data ) {
					if ( spinner ) {
						spinner.classList.remove( 'is-active' );
						spinner.style.display = 'none';
					}
					if ( resultEl ) {
						resultEl.innerHTML = validIcon( data.message || 'Sent!' ) + ' ' + ( data.message || 'Sent!' );
					}
				},
				function ( data ) {
					if ( spinner ) {
						spinner.classList.remove( 'is-active' );
						spinner.style.display = 'none';
					}
					if ( resultEl ) {
						var msg = ( data && data.message ) ? data.message : 'Failed to send test email.';
						resultEl.innerHTML = warningIcon( msg ) + ' ' + msg;
					}
				}
			);
		} );
	}

	// -------------------------------------------------------------------------
	// Repository picker — combobox with popover listbox.
	// On focus, the listbox appears below the input with the user's accessible
	// repos grouped by owner. Typing filters; arrow keys + Enter select;
	// Escape closes; click outside closes; clicking an option fills the
	// input. Free-form text entry is still allowed for any public repo
	// (e.g. WordPress/gutenberg) that's not in the list.
	//
	// Implements the WAI-ARIA combobox pattern with aria-activedescendant
	// so keyboard and screen reader users get a real listbox experience.
	// -------------------------------------------------------------------------
	const repoPicker = document.querySelector( '.ghrp-repo-picker' );
	const repoInput  = document.getElementById( 'ghrp-new-repo' );
	const repoList   = document.getElementById( 'ghrp-repo-picker-list' );

	const repoState = {
		open:           false,
		activeId:       null,
		visibleOptions: [],
		nextOptId:      0,
	};

	function repoOptions() {
		return repoList ? Array.from( repoList.querySelectorAll( '[role="option"]' ) ) : [];
	}

	function refreshVisibleOptions() {
		if ( ! repoList ) {
			return;
		}
		const q = repoInput.value.toLowerCase().trim();
		const groups = repoList.querySelectorAll( '.ghrp-repo-picker__group' );
		const visible = [];

		groups.forEach( function ( group ) {
			const ownerMatches = group.dataset.owner && group.dataset.owner.toLowerCase().includes( q );
			const opts = group.querySelectorAll( '[role="option"]' );
			let groupVisible = 0;

			opts.forEach( function ( opt ) {
				const value = opt.dataset.value.toLowerCase();
				const matches = '' === q || value.includes( q ) || ownerMatches;
				opt.hidden = ! matches;
				if ( matches ) {
					groupVisible++;
					visible.push( opt );
				}
			} );

			group.hidden = 0 === groupVisible;
		} );

		const emptyEl = repoList.querySelector( '.ghrp-repo-picker__empty' );
		if ( emptyEl ) {
			emptyEl.hidden = visible.length > 0;
		}

		repoState.visibleOptions = visible;

		// If the active option got filtered out, clear it.
		if ( repoState.activeId && ! visible.find( function ( o ) { return o.id === repoState.activeId; } ) ) {
			clearRepoActive();
		}
	}

	function clearRepoActive() {
		if ( ! repoList ) {
			return;
		}
		repoList.querySelectorAll( '.is-active' ).forEach( function ( el ) {
			el.classList.remove( 'is-active' );
			el.setAttribute( 'aria-selected', 'false' );
		} );
		repoState.activeId = null;
		if ( repoInput ) {
			repoInput.removeAttribute( 'aria-activedescendant' );
		}
	}

	function setRepoActive( opt ) {
		clearRepoActive();
		if ( ! opt ) {
			return;
		}
		opt.classList.add( 'is-active' );
		opt.setAttribute( 'aria-selected', 'true' );
		opt.scrollIntoView( { block: 'nearest' } );
		repoState.activeId = opt.id;
		repoInput.setAttribute( 'aria-activedescendant', opt.id );
	}

	function moveRepoActive( direction ) {
		const visible = repoState.visibleOptions;
		if ( 0 === visible.length ) {
			return;
		}

		const currentIdx = visible.findIndex( function ( o ) { return o.id === repoState.activeId; } );
		let newIdx;

		if ( -1 === currentIdx ) {
			newIdx = direction > 0 ? 0 : visible.length - 1;
		} else {
			newIdx = currentIdx + direction;
			if ( newIdx < 0 ) {
				newIdx = 0;
			}
			if ( newIdx >= visible.length ) {
				newIdx = visible.length - 1;
			}
		}

		setRepoActive( visible[ newIdx ] );
	}

	function openRepoPopover() {
		if ( repoState.open || ! repoList ) {
			return;
		}
		refreshVisibleOptions();
		repoList.hidden = false;
		repoInput.setAttribute( 'aria-expanded', 'true' );
		repoState.open = true;
	}

	function closeRepoPopover() {
		if ( ! repoState.open || ! repoList ) {
			return;
		}
		repoList.hidden = true;
		repoInput.setAttribute( 'aria-expanded', 'false' );
		clearRepoActive();
		repoState.open = false;
	}

	function selectRepoOption( opt ) {
		if ( ! opt ) {
			return;
		}
		repoInput.value = opt.dataset.value;
		closeRepoPopover();
		repoInput.focus();
	}

	if ( repoPicker && repoInput && repoList ) {
		repoInput.addEventListener( 'focus', openRepoPopover );

		repoInput.addEventListener( 'input', function () {
			if ( ! repoState.open ) {
				openRepoPopover();
			} else {
				refreshVisibleOptions();
			}
		} );

		repoInput.addEventListener( 'keydown', function ( e ) {
			switch ( e.key ) {
				case 'ArrowDown':
					e.preventDefault();
					if ( ! repoState.open ) {
						openRepoPopover();
						if ( repoState.visibleOptions.length > 0 ) {
							setRepoActive( repoState.visibleOptions[ 0 ] );
						}
					} else {
						moveRepoActive( 1 );
					}
					break;
				case 'ArrowUp':
					if ( repoState.open ) {
						e.preventDefault();
						moveRepoActive( -1 );
					}
					break;
				case 'Enter':
					if ( repoState.open && repoState.activeId ) {
						e.preventDefault();
						const active = document.getElementById( repoState.activeId );
						if ( active ) {
							selectRepoOption( active );
						}
					}
					break;
				case 'Escape':
					if ( repoState.open ) {
						e.preventDefault();
						closeRepoPopover();
					}
					break;
				case 'Tab':
					if ( repoState.open ) {
						closeRepoPopover();
					}
					break;
			}
		} );

		// mousedown on an option must not blur the input — the click handler
		// below fills the value, then we close the popover. Skip the external-
		// link icon so it behaves like a normal hyperlink.
		repoList.addEventListener( 'mousedown', function ( e ) {
			if ( e.target.closest( '.ghrp-repo-picker__option-link' ) ) {
				return;
			}
			if ( e.target.closest( '[role="option"]' ) ) {
				e.preventDefault();
			}
		} );

		repoList.addEventListener( 'click', function ( e ) {
			// External-link icon → let the <a> navigate normally; don't
			// select the option underneath it.
			if ( e.target.closest( '.ghrp-repo-picker__option-link' ) ) {
				return;
			}
			const opt = e.target.closest( '[role="option"]' );
			if ( opt ) {
				selectRepoOption( opt );
			}
		} );

		// Close when focus leaves the picker entirely. Exempt the Refresh
		// button: clicking it should keep the popover state intact, since the
		// refresh handler may want to leave the popover open (showing fresh
		// content) after success.
		document.addEventListener( 'mousedown', function ( e ) {
			if ( ! repoState.open ) {
				return;
			}
			if ( repoPicker.contains( e.target ) ) {
				return;
			}
			const refreshBtn = document.getElementById( 'ghrp-refresh-repos' );
			if ( refreshBtn && refreshBtn.contains( e.target ) ) {
				return;
			}
			closeRepoPopover();
		} );
	}

	// Rebuild the listbox from a fresh server response. Called by Refresh.
	function rebuildRepoList( groups ) {
		if ( ! repoList ) {
			return;
		}
		repoList.querySelectorAll( '.ghrp-repo-picker__group' ).forEach( function ( g ) {
			g.remove();
		} );

		const emptyEl = repoList.querySelector( '.ghrp-repo-picker__empty' );
		const owners  = Object.keys( groups || {} );

		owners.forEach( function ( owner ) {
			const groupId = 'ghrp-repo-group-' + owner.replace( /[^A-Za-z0-9_-]/g, '-' );
			const group = document.createElement( 'div' );
			group.className     = 'ghrp-repo-picker__group';
			group.setAttribute( 'role', 'group' );
			group.setAttribute( 'aria-labelledby', groupId );
			group.dataset.owner = owner;

			const header = document.createElement( 'div' );
			header.className = 'ghrp-repo-picker__group-name';
			header.id = groupId;
			header.textContent = owner;
			group.appendChild( header );

			groups[ owner ].forEach( function ( r ) {
				const opt = document.createElement( 'div' );
				++repoState.nextOptId;
				opt.id = 'ghrp-repo-opt-r' + repoState.nextOptId;
				opt.className = 'ghrp-repo-picker__option';
				opt.setAttribute( 'role', 'option' );
				opt.setAttribute( 'aria-selected', 'false' );
				opt.dataset.value = r.identifier;

				const nameSpan = document.createElement( 'span' );
				nameSpan.className = 'ghrp-repo-picker__option-name';
				nameSpan.textContent = r.name;
				opt.appendChild( nameSpan );

				const link = document.createElement( 'a' );
				link.href = 'https://github.com/' + r.identifier;
				link.target = '_blank';
				link.rel = 'noopener';
				link.className = 'ghrp-repo-picker__option-link';
				link.tabIndex = -1;
				link.setAttribute( 'aria-label', 'View ' + r.identifier + ' on GitHub' );
				const icon = document.createElement( 'span' );
				icon.className = 'dashicons dashicons-external';
				icon.setAttribute( 'aria-hidden', 'true' );
				link.appendChild( icon );
				opt.appendChild( link );

				group.appendChild( opt );
			} );

			repoList.insertBefore( group, emptyEl );
		} );

		if ( repoState.open ) {
			refreshVisibleOptions();
		}
	}

	// -------------------------------------------------------------------------
	// Refresh accessible-repos cache (Repositories tab, next to "Add").
	// Updates the picker list in place from the server response — no reload.
	// On success, focuses the input so the popover opens with the new list.
	// -------------------------------------------------------------------------
	const refreshReposBtn = document.getElementById( 'ghrp-refresh-repos' );

	function setRefreshResult( html ) {
		const resultEl = document.getElementById( 'ghrp-refresh-repos-result' );
		if ( resultEl ) {
			resultEl.innerHTML = html;
		}
	}

	if ( refreshReposBtn ) {
		refreshReposBtn.addEventListener( 'click', function () {
			const spinner = refreshReposBtn.parentNode.querySelector( '.ghrp-refresh-repos-spinner' );

			refreshReposBtn.disabled = true;
			if ( spinner ) {
				spinner.classList.add( 'is-active' );
			}
			// Clear any lingering message so the user sees fresh state.
			setRefreshResult( '' );

			window.ctbpFetch(
				'POST',
				'/repos/refresh',
				{},
				function ( data ) {
					refreshReposBtn.disabled = false;
					if ( spinner ) {
						spinner.classList.remove( 'is-active' );
					}
					rebuildRepoList( ( data && data.groups ) || {} );

					const msg = ( data && data.message ) ? data.message : 'Refreshed.';
					setRefreshResult( validIcon( msg ) + ' ' + msg );

					// Focus the input so the popover opens (or stays open)
					// with the refreshed list visible.
					if ( repoInput ) {
						repoInput.focus();
					}
				},
				function ( data ) {
					refreshReposBtn.disabled = false;
					if ( spinner ) {
						spinner.classList.remove( 'is-active' );
					}
					const msg = ( data && data.message ) ? data.message : 'Failed to refresh repository list.';
					setRefreshResult( warningIcon( msg ) + ' ' + msg );
				}
			);
		} );
	}

	// -------------------------------------------------------------------------
	// PAT validation indicator — tab-out re-check after the field changes.
	// Server renders the initial state on page load (with a 1-minute cache);
	// this handler updates the indicator without a full reload when the user
	// edits the field.
	// -------------------------------------------------------------------------
	const patInput = document.getElementById( 'ghrp_github_pat' );
	const patStatus = document.getElementById( 'ghrp-pat-status' );
	if ( patInput && patStatus ) {
		const initialPat = patInput.value;

		patInput.addEventListener( 'blur', function () {
			const current = patInput.value;

			// Empty field → no indicator.
			if ( '' === current ) {
				patStatus.className = 'ghrp-pat-status ghrp-pat-status--none';
				patStatus.innerHTML = '';
				return;
			}

			// Unchanged from page-load value → server-rendered indicator is still correct.
			if ( current === initialPat ) {
				return;
			}

			patStatus.className = 'ghrp-pat-status ghrp-pat-status--checking';
			patStatus.innerHTML = '<span class="spinner is-active" style="float:none;vertical-align:middle;margin:0 4px 0 0;"></span>';

			window.ctbpFetch(
				'POST',
				'/pat/validate',
				{ pat: current },
				function ( data ) {
					if ( data && data.valid ) {
						patStatus.className = 'ghrp-pat-status ghrp-pat-status--valid';
						patStatus.innerHTML = validIcon( data.message || 'Validated' ) + ' ' + ( data.message || 'Validated' );
					} else {
						patStatus.className = 'ghrp-pat-status ghrp-pat-status--invalid';
						var msg = ( data && data.message ) ? data.message : 'Could not validate.';
						patStatus.innerHTML = warningIcon( msg ) + ' ' + msg;
					}
				},
				function ( data ) {
					patStatus.className = 'ghrp-pat-status ghrp-pat-status--invalid';
					var msg = ( data && data.message ) ? data.message : 'Could not validate.';
					patStatus.innerHTML = warningIcon( msg ) + ' ' + msg;
				}
			);
		} );
	}

	// -------------------------------------------------------------------------
	// Repository inline edit — WP Quick Edit clone pattern.
	// -------------------------------------------------------------------------
	const editTemplate = document.getElementById( 'ghrp-inline-edit' );

	/**
	 * Closes the currently active inline edit row (if any),
	 * removes it from the DOM, and restores the data row.
	 */
	function closeActiveEditRow() {
		const activeEdit = document.querySelector( '.wp-list-table tbody > .ghrp-repo-edit-row' );
		if ( ! activeEdit ) {
			return;
		}
		// Spacer is between the data row and the edit row.
		const spacer  = activeEdit.previousElementSibling;
		const dataRow = spacer ? spacer.previousElementSibling : null;

		var editLink = dataRow ? dataRow.querySelector( '.ghrp-edit-repo-btn' ) : null;

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
		var catHidden = editRow.querySelector( '.ghrp-tpl-cat-hidden' );
		if ( catHidden ) {
			catHidden.name = 'repos[' + repo + '][categories][]';
		}
		var savedCats = [];
		try {
			savedCats = JSON.parse( dataRow.dataset.categories || '[]' );
		} catch ( e ) {
			savedCats = [];
		}
		editRow.querySelectorAll( '.ghrp-tpl-categories input[type="checkbox"]' ).forEach( function ( cb ) {
			cb.name    = 'repos[' + repo + '][categories][]';
			cb.checked = savedCats.indexOf( parseInt( cb.value, 10 ) ) !== -1;
		} );

		// Populate select: author.
		var authorSelect = editRow.querySelector( '.ghrp-tpl-author' );
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
		var cancelBtn = editRow.querySelector( '.ghrp-cancel-edit' );
		if ( cancelBtn ) {
			cancelBtn.addEventListener( 'click', closeActiveEditRow );
		}

		// Wire up plugin link blur validation.
		var pluginLinkInput = editRow.querySelector( '.ghrp-plugin-link-input' );
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
	document.querySelectorAll( '.ghrp-edit-repo-btn' ).forEach( function ( link ) {
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
	const removeDialog    = document.getElementById( 'ghrp-remove-dialog' );
	const removeRepoInput = document.getElementById( 'ghrp-remove-repo-input' );
	const removeCancelBtn = document.getElementById( 'ghrp-remove-cancel' );

	document.querySelectorAll( '.ghrp-remove-repo-btn' ).forEach( function ( link ) {
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
						'<input type="hidden" name="ghrp_action" value="repositories">' +
						'<input type="hidden" name="ghrp_nonce" value="">' +
						'<input type="hidden" name="ghrp_remove_repo" value="' +
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
		var selectBtn = editRow.querySelector( '.ghrp-select-image' );
		var removeBtn = editRow.querySelector( '.ghrp-remove-image' );
		var preview   = editRow.querySelector( '.ghrp-featured-image-preview' );
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
			var statusEl = input.closest( '.input-text-wrap' ).querySelector( '.ghrp-plugin-link-status' );

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
	const conflictDialog  = document.getElementById( 'ghrp-conflict-dialog' );
	const conflictInfo    = document.getElementById( 'ghrp-conflict-post-info' );
	const conflictConfirm = document.getElementById( 'ghrp-conflict-confirm' );
	const conflictCancel  = document.getElementById( 'ghrp-conflict-cancel' );

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

		// Build the anchor via DOM API rather than innerHTML interpolation —
		// post.tag is a git ref name that can legitimately contain characters
		// the HTML parser would interpret (`<`, `>`, `&`, quotes). textContent
		// + property assignment is the only escape that's actually safe here.
		var label = post.tag ? post.tag + ' on ' + post.date : post.date;

		while ( lastPostCell.firstChild ) {
			lastPostCell.removeChild( lastPostCell.firstChild );
		}
		var anchor = document.createElement( 'a' );
		anchor.href = post.edit_url;
		anchor.textContent = label;
		lastPostCell.appendChild( anchor );

		// Highlight the cell briefly to signal the update.
		lastPostCell.style.transition = 'background-color 0.3s';
		lastPostCell.style.backgroundColor = '#dff0d8';
		setTimeout( function () {
			lastPostCell.style.backgroundColor = '';
		}, 1500 );
	}

	/**
	 * Builds a clickable success-checkmark icon linked to the post's edit screen.
	 *
	 * @param {string} editUrl Post edit URL.
	 * @param {string} label   Tooltip / screen-reader label.
	 * @returns {string} HTML markup.
	 */
	function successLinkIcon( editUrl, label ) {
		return '<a href="' + encodeURI( editUrl ) + '" class="ghrp-generate-success" title="' + label + '" aria-label="' + label + '">' +
			'<span class="dashicons dashicons-yes-alt" style="color: #00a32a;" aria-hidden="true"></span>' +
			'<span class="screen-reader-text">' + label + '</span>' +
			'</a>';
	}

	/**
	 * Shows a result indicator next to the generate button.
	 *
	 * Success: green check icon linking to the edit screen, with tooltip.
	 *          The Last Post column is only updated when the new post is the
	 *          latest release for the repo (otherwise an older release would
	 *          appear to overwrite a newer post in that column).
	 * Error: yellow warning icon with error message as tooltip.
	 *
	 * @param {HTMLElement} btn      The generate button.
	 * @param {Object|null} post     Post data on success, null on error.
	 * @param {string}      [error]  Error message on failure.
	 * @param {boolean}     [isLatest] Whether this generation was for the latest release.
	 */
	function showGenerateResult( btn, post, error, isLatest ) {
		// Hide any active spinner.
		var spinner = btn.closest( 'td' ).querySelector( '.ghrp-generate-spinner' );
		if ( spinner ) {
			spinner.style.display = 'none';
		}

		var statusEl = btn.closest( 'td' ).querySelector( '.ghrp-generate-status' );
		if ( ! statusEl ) {
			return;
		}

		if ( post ) {
			var label = ctbpAdmin.i18n.editGeneratedPost || 'Edit the generated post';
			statusEl.innerHTML = post.edit_url
				? successLinkIcon( post.edit_url, label )
				: validIcon( ctbpAdmin.i18n.draftCreated || 'Post created' );
			if ( isLatest !== false ) {
				updateLastPostColumn( btn, post );
			}
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
	 *
	 * @param {HTMLElement} btn      Generate button that initiated the flow.
	 * @param {number}      postId   Existing post ID to regenerate.
	 * @param {boolean}     isLatest Whether the post is for the latest release (controls Last Post flash).
	 */
	function regenerateExisting( btn, postId, isLatest ) {
		if ( conflictDialog ) {
			conflictDialog.close();
		}
		btn.focus();

		btn.disabled = true;
		disableRowActions( btn );

		var spinner = btn.closest( 'td' ).querySelector( '.ghrp-generate-spinner' );
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
				showGenerateResult( btn, data && data.post, null, isLatest );
			},
			function ( data ) {
				btn.disabled = false;
				enableRowActions( btn );
				showGenerateResult( btn, null, ( data && data.message ) || null );
			}
		);
	}

	// -------------------------------------------------------------------------
	// Version picker dialog
	// -------------------------------------------------------------------------
	var versionDialog       = document.getElementById( 'ghrp-version-picker-dialog' );
	var versionSelect       = document.getElementById( 'ghrp-version-picker-select' );
	var versionConfirm      = document.getElementById( 'ghrp-version-picker-confirm' );
	var versionCancel       = document.getElementById( 'ghrp-version-picker-cancel' );
	var versionConflictRow  = document.getElementById( 'ghrp-version-picker-conflict' );
	var versionConflictText = document.getElementById( 'ghrp-version-picker-conflict-text' );
	var versionBackdateHint = document.getElementById( 'ghrp-version-picker-backdate' );

	function formatReleasePublishedAt( iso ) {
		if ( ! iso ) {
			return '';
		}
		var d = new Date( iso );
		if ( isNaN( d.getTime() ) ) {
			return '';
		}
		return d.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } );
	}

	/**
	 * Sends the generate request with an explicit tag.
	 *
	 * @param {HTMLElement} btn                 Generate button that initiated the flow.
	 * @param {string}      tag                 Selected release tag (empty = latest).
	 * @param {boolean}     conflictAcknowledged
	 *     When true, a conflict response triggers regeneration immediately
	 *     instead of opening the conflict dialog. Used by the version picker
	 *     since its own UI already surfaced the "post exists" warning before
	 *     the user clicked Regenerate.
	 */
	function generateForTag( btn, tag, conflictAcknowledged ) {
		btn.disabled = true;
		disableRowActions( btn );

		var spinner = btn.closest( 'td' ).querySelector( '.ghrp-generate-spinner' );
		if ( spinner ) {
			spinner.style.display = 'inline-block';
			spinner.classList.add( 'is-active' );
		}

		var statusEl = btn.closest( 'td' ).querySelector( '.ghrp-generate-status' );
		if ( statusEl ) {
			statusEl.innerHTML = '<span class="screen-reader-text">' + ctbpAdmin.i18n.generating + '</span>';
		}

		window.ctbpFetch(
			'POST',
			'/releases/generate-draft',
			{ repo: btn.dataset.repo, tag: tag || '' },
			function ( data ) {
				btn.disabled = false;

				var sp = btn.closest( 'td' ).querySelector( '.ghrp-generate-spinner' );
				if ( sp ) {
					sp.style.display = 'none';
				}

				var isLatest = ! data || data.is_latest !== false;

				if ( data && data.conflict ) {
					var existing = data.post;

					// If the caller already showed a conflict warning (e.g. the
					// version picker), skip the confirmation dialog and go
					// straight to regeneration — the user has already confirmed.
					if ( conflictAcknowledged ) {
						regenerateExisting( btn, existing ? existing.id : 0, isLatest );
						return;
					}

					if ( conflictInfo ) {
						conflictInfo.textContent =
							'"' + ( existing ? existing.title : '' ) + '" (' + ( existing ? existing.status : '' ) + ')';
					}

					function onConfirm() {
						cleanup();
						regenerateExisting( btn, existing ? existing.id : 0, isLatest );
					}
					function onCancel() {
						cleanup();
						if ( conflictDialog ) {
							conflictDialog.close();
						}
						var s = btn.closest( 'td' ).querySelector( '.ghrp-generate-status' );
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
					} else if ( window.confirm( ctbpAdmin.i18n.regenerateConfirm || 'A post already exists. Regenerate it?' ) ) {
						regenerateExisting( btn, existing ? existing.id : 0, isLatest );
					}
				} else {
					enableRowActions( btn );
					showGenerateResult( btn, data && data.post, null, isLatest );
				}
			},
			function ( data ) {
				btn.disabled = false;
				enableRowActions( btn );
				showGenerateResult( btn, null, ( data && data.message ) || null );
			}
		);
	}

	/**
	 * Opens the version picker dialog populated with the given releases.
	 *
	 * @param {HTMLElement} btn       Generate button that initiated the flow.
	 * @param {Array}       releases  Releases from /releases/list.
	 * @param {string}      latestTag Tag of the most recent release.
	 */
	function openVersionPicker( btn, releases, latestTag ) {
		if ( ! versionDialog || ! versionSelect ) {
			generateForTag( btn, '' );
			return;
		}

		// Build the option list. Latest is preselected.
		versionSelect.innerHTML = '';
		releases.forEach( function ( r ) {
			var opt = document.createElement( 'option' );
			opt.value = r.tag;
			var dateLabel = formatReleasePublishedAt( r.published_at );
			opt.textContent = r.tag + ( dateLabel ? '  —  ' + dateLabel : '' ) +
				( r.has_post ? '  (' + ( ctbpAdmin.i18n.postExists || 'post exists' ) + ')' : '' );
			opt.dataset.hasPost = r.has_post ? '1' : '';
			opt.dataset.postTitle = ( r.post_status && r.post_status ) || '';
			opt.dataset.postEditUrl = r.post_edit_url || '';
			opt.dataset.published = r.published_at || '';
			if ( r.tag === latestTag ) {
				opt.selected = true;
			}
			versionSelect.appendChild( opt );
		} );

		function refreshHints() {
			var opt = versionSelect.options[ versionSelect.selectedIndex ];
			if ( ! opt ) {
				return;
			}
			var isOlder = opt.value !== latestTag;
			var hasPost = '1' === opt.dataset.hasPost;

			if ( versionBackdateHint ) {
				versionBackdateHint.hidden = ! isOlder;
			}

			if ( versionConflictRow && versionConflictText ) {
				if ( hasPost ) {
					versionConflictText.textContent =
						ctbpAdmin.i18n.versionPickerConflict || 'A post already exists for this release. Generating will create a new revision and keep the existing post date.';
					versionConflictRow.hidden = false;
					if ( versionConfirm ) {
						versionConfirm.textContent = ctbpAdmin.i18n.regenerate || 'Regenerate';
					}
				} else {
					versionConflictRow.hidden = true;
					if ( versionConfirm ) {
						versionConfirm.textContent = ctbpAdmin.i18n.generatePost || 'Generate post';
					}
				}
			}
		}

		refreshHints();
		versionSelect.onchange = refreshHints;

		function onConfirm() {
			cleanup();
			var tag = versionSelect.value || '';
			var opt = versionSelect.options[ versionSelect.selectedIndex ];
			// The picker already surfaced a conflict warning for this release;
			// no need to re-confirm via the conflict dialog downstream.
			var acknowledged = !! ( opt && '1' === opt.dataset.hasPost );
			versionDialog.close();
			generateForTag( btn, tag, acknowledged );
		}
		function onCancel() {
			cleanup();
			versionDialog.close();
			var spinner = btn.closest( 'td' ).querySelector( '.ghrp-generate-spinner' );
			if ( spinner ) {
				spinner.style.display = 'none';
			}
			var s = btn.closest( 'td' ).querySelector( '.ghrp-generate-status' );
			if ( s ) {
				s.innerHTML = '';
			}
			btn.disabled = false;
			enableRowActions( btn );
			btn.focus();
		}
		function cleanup() {
			if ( versionConfirm ) { versionConfirm.removeEventListener( 'click', onConfirm ); }
			if ( versionCancel )  { versionCancel.removeEventListener( 'click', onCancel ); }
		}

		if ( versionConfirm ) { versionConfirm.addEventListener( 'click', onConfirm ); }
		if ( versionCancel )  { versionCancel.addEventListener( 'click', onCancel ); }

		versionDialog.showModal();
	}

	document.querySelectorAll( '.ghrp-generate-draft' ).forEach( function ( btn ) {
		btn.addEventListener( 'click', function () {
			btn.disabled = true;
			disableRowActions( btn );

			var spinner = btn.closest( 'td' ).querySelector( '.ghrp-generate-spinner' );
			if ( spinner ) {
				spinner.style.display = 'inline-block';
				spinner.classList.add( 'is-active' );
			}

			var statusEl = btn.closest( 'td' ).querySelector( '.ghrp-generate-status' );
			if ( statusEl ) {
				statusEl.innerHTML = '';
			}

			// Step 1: list releases. If 0/1, skip the picker.
			window.ctbpFetch(
				'GET',
				'/releases/list',
				{ repo: btn.dataset.repo },
				function ( data ) {
					var sp = btn.closest( 'td' ).querySelector( '.ghrp-generate-spinner' );
					if ( sp ) { sp.style.display = 'none'; }

					var releases  = ( data && data.releases ) || [];
					var latestTag = ( data && data.latest_tag ) || '';

					if ( releases.length <= 1 ) {
						// Single release (or empty — backend will error) — go directly.
						generateForTag( btn, '' );
					} else {
						btn.disabled = false;
						enableRowActions( btn );
						openVersionPicker( btn, releases, latestTag );
					}
				},
				function ( errData ) {
					btn.disabled = false;
					enableRowActions( btn );
					showGenerateResult( btn, null, ( errData && errData.message ) || null );
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

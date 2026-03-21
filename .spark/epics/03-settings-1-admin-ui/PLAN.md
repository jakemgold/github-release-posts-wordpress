---
epic: 03-settings/1-admin-ui
created: 2026-03-21T00:00:00Z
status: complete
---

# Epic Plan: 03-settings/1-admin-ui

## Overview

**Epic:** 03-settings/1-admin-ui
**Goal:** Register a plugin settings page under Tools menu with a two-tab layout, page-scoped asset enqueuing, nonce-protected form shell, AJAX endpoint stubs, and WCAG 2.2 AA tabbed navigation.
**PRDs:** PRD-03.1.01 (Admin Page)
**Requirements:** 5 user stories, 16 acceptance criteria

---

## Tasks

### Task 1: Admin_Page class — menu registration and asset enqueuing

**PRD:** PRD-03.1.01
**Implements:** US-001, US-004
**Complexity:** Medium
**Dependencies:** None

**Steps:**

1. Create `includes/classes/Admin/Admin_Page.php` with class `TenUp\ChangelogToBlogPost\Admin\Admin_Page`
2. Add `setup()` method hooking `admin_menu` → `register_menu_page()` and `admin_enqueue_scripts` → `enqueue_assets()`; also hook `init` → `register_ajax_actions()`
3. Implement `register_menu_page()` calling `add_management_page()` with title "Changelog to Blog Post", capability `manage_options`, menu slug `changelog-to-blog-post`, and callback `render_page()`; store the returned hook suffix in `$this->page_hook`
4. Implement `enqueue_assets( string $hook_suffix )` that returns early unless `$hook_suffix === $this->page_hook`
5. In `enqueue_assets()`, enqueue `changelog-to-blog-post-admin` CSS from `CHANGELOG_TO_BLOG_POST_URL . 'assets/css/admin/style.css'` and `changelog-to-blog-post-admin-js` script from `assets/js/admin/index.js` with `['jquery']` dependency and `in_footer: true`
6. Call `wp_localize_script()` to pass `window.ctbpAdmin = { ajaxUrl, nonce }` where nonce is `wp_create_nonce( 'ctbp_admin_nonce' )`
7. Wire `( new \TenUp\ChangelogToBlogPost\Admin\Admin_Page() )->setup()` in `Plugin::init()`

**Verification:**

- [ ] AC-001: Submenu appears under Tools for `manage_options` users
- [ ] AC-002: Capability gate in place
- [ ] AC-011: CSS and JS enqueued only on plugin page

**Files to create/modify:**

- `includes/classes/Admin/Admin_Page.php` — new
- `includes/classes/Plugin.php` — add `Admin_Page` instantiation in `init()`

---

### Task 2: Page shell template with tabbed navigation

**PRD:** PRD-03.1.01
**Implements:** US-001, US-002, US-003, US-005
**Complexity:** Medium
**Dependencies:** Task 1

**Steps:**

1. Create `includes/templates/admin-page.php`
2. Add outer shell: `<div class="wrap"><h1><?php echo esc_html__( 'Changelog to Blog Post', 'changelog-to-blog-post' ); ?></h1>`
3. Read and sanitize active tab: `$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'repositories';` then validate against allowed values `['repositories', 'settings']`, defaulting to `repositories`
4. Output any saved/error admin notices: check for `$_GET['saved']` and display a `notice-success` div; check for a transient `ctbp_admin_errors_{user_id}` and display inline `notice-error` div
5. Render tab navigation as `<nav><ul role="tablist" class="ctbp-tabs">` with two `<li>` items; each `<li>` contains an `<a href="?page=changelog-to-blog-post&tab={slug}">` with `role="tab"`, `id="ctbp-tab-{slug}"`, `aria-controls="ctbp-panel-{slug}"`, and `aria-selected="true/false"` based on `$active_tab`; active tab also gets CSS class `nav-tab-active`
6. Render two tab panels `<div id="ctbp-panel-repositories" role="tabpanel" aria-labelledby="ctbp-tab-repositories">` and `<div id="ctbp-panel-settings" ...>`; inactive panel gets `hidden` attribute
7. Each panel wraps its content in `<form method="post" action="">` with `wp_nonce_field( 'ctbp_save_repositories', 'ctbp_nonce' )` / `wp_nonce_field( 'ctbp_save_settings', 'ctbp_nonce' )` and a hidden `<input name="ctbp_action" value="repositories/settings">`
8. Each form ends with `<p class="submit"><button type="submit" class="button button-primary">...Save...</button></p>`
9. Include `CHANGELOG_TO_BLOG_POST_PATH . 'includes/templates/tab-repositories.php'` inside the repositories form body
10. Include `CHANGELOG_TO_BLOG_POST_PATH . 'includes/templates/tab-settings.php'` inside the settings form body
11. Create `includes/templates/tab-repositories.php` as placeholder: `<p><?php esc_html_e( 'Repository settings — loaded by EPC-03.2', 'changelog-to-blog-post' ); ?></p>`
12. Create `includes/templates/tab-settings.php` as placeholder: `<p><?php esc_html_e( 'Global settings — loaded by EPC-03.2', 'changelog-to-blog-post' ); ?></p>`
13. In `Admin_Page::render_page()`: verify `current_user_can( 'manage_options' )` with `wp_die()` fallback, call `$this->handle_form_submission()`, then `include CHANGELOG_TO_BLOG_POST_PATH . 'includes/templates/admin-page.php'`

**Verification:**

- [ ] AC-003: `<h1>` identifies the plugin by name
- [ ] AC-004: Two tabs, active state visually distinguished
- [ ] AC-005: `tab` query parameter drives active tab
- [ ] AC-007: Each tab has its own form
- [ ] AC-010: `wp_nonce_field()` present in each form
- [ ] AC-015: All interactive elements have labels
- [ ] AC-016: ARIA `role="tablist/tab/tabpanel"`, `aria-selected`, `aria-controls` present

**Files to create/modify:**

- `includes/templates/admin-page.php` — new
- `includes/templates/tab-repositories.php` — new (placeholder)
- `includes/templates/tab-settings.php` — new (placeholder)
- `includes/classes/Admin/Admin_Page.php` — add `render_page()` and `handle_form_submission()` stubs

---

### Task 3: Admin CSS for tab layout

**PRD:** PRD-03.1.01
**Implements:** US-002, US-005
**Complexity:** Low
**Dependencies:** Task 2

**Steps:**

1. Add to `assets/css/admin/style.css`:
   - Reset tab list: `.ctbp-tabs { margin: 0; padding: 0; list-style: none; display: flex; border-bottom: 1px solid #c3c4c7; }`
   - Tab item: `.ctbp-tabs li { margin: 0; padding: 0; }`
   - Tab link: `.ctbp-tabs a { display: block; padding: 8px 16px; text-decoration: none; border: 1px solid transparent; border-bottom: none; }`
   - Active tab: `.ctbp-tabs a.nav-tab-active { background: #f0f0f1; border-color: #c3c4c7; margin-bottom: -1px; }`
   - Tab panels: `.ctbp-tab-panel { padding-top: 1em; }` and `[hidden] { display: none !important; }`
   - Repo table basics: `.ctbp-repo-table { border-collapse: collapse; width: 100%; }` etc.

**Verification:**

- [ ] AC-004: Active tab is visually distinguished from inactive tabs

**Files to create/modify:**

- `assets/css/admin/style.css` — add tab and layout styles

---

### Task 4: Admin JS — tab switching and AJAX infrastructure

**PRD:** PRD-03.1.01
**Implements:** US-002, US-004
**Complexity:** Medium
**Dependencies:** Tasks 2, 3

**Steps:**

1. Add to `assets/js/admin/index.js`:
   ```js
   document.addEventListener( 'DOMContentLoaded', function() {
       // Tab switching
       const tabs = document.querySelectorAll( '[role="tab"]' );
       const panels = document.querySelectorAll( '[role="tabpanel"]' );
       let formDirty = false;

       tabs.forEach( function( tab ) {
           tab.addEventListener( 'click', function( e ) {
               if ( formDirty && ! window.confirm( ctbpAdmin.i18n.unsavedChanges ) ) {
                   e.preventDefault();
                   return;
               }
               // Update aria-selected on all tabs
               tabs.forEach( t => t.setAttribute( 'aria-selected', 'false' ) );
               tab.setAttribute( 'aria-selected', 'true' );
               // Toggle panels
               const targetId = tab.getAttribute( 'aria-controls' );
               panels.forEach( p => p.hidden = ( p.id !== targetId ) );
           } );
       } );

       // Keyboard navigation for tabs (arrow keys)
       tabs.forEach( function( tab, idx ) {
           tab.addEventListener( 'keydown', function( e ) {
               if ( e.key === 'ArrowRight' || e.key === 'ArrowLeft' ) {
                   const next = e.key === 'ArrowRight'
                       ? tabs[ ( idx + 1 ) % tabs.length ]
                       : tabs[ ( idx - 1 + tabs.length ) % tabs.length ];
                   next.focus();
                   next.click();
               }
           } );
       } );

       // Dirty flag
       document.querySelectorAll( 'input, select, textarea' ).forEach( function( el ) {
           el.addEventListener( 'change', () => formDirty = true );
       } );
   } );
   ```
2. Add AJAX helper below: `window.ctbpAjax = function( action, data, successCb, errorCb ) { ... }` using `fetch` with `ctbpAdmin.ajaxUrl`, POST, `action`, `nonce: ctbpAdmin.nonce`, and spread `data`; call `successCb( response.data )` or `errorCb( response.data )`
3. Extend `wp_localize_script` data in `Admin_Page::enqueue_assets()` to include `i18n.unsavedChanges` string (so it's translatable)

**Verification:**

- [ ] AC-005: URL updates on tab switch (browser history via `<a>` href — server-side routing already handles this)
- [ ] AC-006: Confirmation dialog on tab switch when form is dirty
- [ ] AC-012: `window.ctbpAjax` helper available for repo table and test connection
- [ ] AC-014: Forms work without JS (server-side tab routing handles it)

**Files to create/modify:**

- `assets/js/admin/index.js` — tab switching, dirty flag, AJAX helper
- `includes/classes/Admin/Admin_Page.php` — extend localize data with `i18n`

---

### Task 5: AJAX endpoint stubs and form submission handling

**PRD:** PRD-03.1.01
**Implements:** US-003, US-004
**Complexity:** Medium
**Dependencies:** Task 1

**Steps:**

1. Implement `Admin_Page::register_ajax_actions()`:
   - `add_action( 'wp_ajax_ctbp_generate_draft_now', [ $this, 'ajax_generate_draft_now' ] )`
   - `add_action( 'wp_ajax_ctbp_test_ai_connection', [ $this, 'ajax_test_ai_connection' ] )`
   - `add_action( 'wp_ajax_ctbp_validate_wporg_slug', [ $this, 'ajax_validate_wporg_slug' ] )`
2. Each handler: `check_ajax_referer( 'ctbp_admin_nonce', 'nonce' )`, then `if ( ! current_user_can( 'manage_options' ) ) { wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'changelog-to-blog-post' ) ], 403 ); }`
3. `ajax_generate_draft_now()` and `ajax_test_ai_connection()`: return `wp_send_json_error( [ 'message' => __( 'Not yet implemented.', 'changelog-to-blog-post' ) ] )` (stubs; replaced when those domains are executed)
4. `ajax_validate_wporg_slug()`: sanitize `$_POST['slug']`, call `wp_remote_get( 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=' . rawurlencode( $slug ) )`, return `wp_send_json_success()` if HTTP 200 body has no `error` key, else `wp_send_json_success( [ 'warning' => true ] )`
5. Implement `Admin_Page::handle_form_submission()`:
   - Return early if `$_SERVER['REQUEST_METHOD'] !== 'POST'` or `empty( $_POST['ctbp_action'] )`
   - For `ctbp_action === 'repositories'`: verify nonce `ctbp_save_repositories`, call `$this->handle_repositories_save()` (stub: just redirect with `?saved=1&tab=repositories` for now — EPC-03.2 fills in real logic)
   - For `ctbp_action === 'settings'`: verify nonce `ctbp_save_settings`, call `$this->handle_settings_save()` (stub: redirect with `?saved=1&tab=settings`)
   - Both stubs redirect via `wp_safe_redirect()` then `exit`

**Verification:**

- [ ] AC-010: Nonce verified before processing any POST data
- [ ] AC-012: AJAX actions registered
- [ ] AC-013: Nonce + `manage_options` on every AJAX handler
- [ ] AC-008: Success redirect with `?saved=1` provides notice hook for EPC-03.2

**Files to create/modify:**

- `includes/classes/Admin/Admin_Page.php` — AJAX registration, stub save handlers, form submission dispatcher

---

### Task 6: Unit tests for Admin_Page

**PRD:** PRD-03.1.01
**Implements:** US-001, US-002, US-003, US-004
**Complexity:** Low
**Dependencies:** Tasks 1–5

**Steps:**

1. Create `tests/php/unit/Admin/Admin_PageTest.php`
2. Test `setup()` registers `admin_menu`, `admin_enqueue_scripts`, `init` hooks
3. Test `enqueue_assets()` does NOT call `wp_enqueue_style/script` when hook suffix is `edit.php`
4. Test `enqueue_assets()` DOES call `wp_enqueue_style` and `wp_enqueue_script` when hook suffix matches stored `$page_hook`
5. Test `render_page()` calls `wp_die()` when `current_user_can( 'manage_options' )` returns false
6. Test each AJAX handler calls `check_ajax_referer` and `current_user_can`

**Verification:**

- [ ] All 6 test cases pass

**Files to create/modify:**

- `tests/php/unit/Admin/Admin_PageTest.php` — new

---

## Acceptance Criteria Coverage

| AC | Task | Requirement |
|----|------|-------------|
| AC-001 | Task 1 | Submenu under Tools |
| AC-002 | Task 1 | `manage_options` gate |
| AC-003 | Task 2 | `<h1>` heading |
| AC-004 | Tasks 2, 3 | Two tabs, active state |
| AC-005 | Task 2 | Tab state in URL |
| AC-006 | Task 4 | Unsaved changes warning |
| AC-007 | Task 2 | Each tab has own form |
| AC-008 | Task 5 | Success notice on save |
| AC-009 | Task 5 | Error transient mechanism |
| AC-010 | Tasks 2, 5 | Nonce on all forms |
| AC-011 | Task 1 | Assets scoped to plugin page |
| AC-012 | Task 4 | AJAX infrastructure |
| AC-013 | Task 5 | Nonce + capability on AJAX |
| AC-014 | Task 4 | Works without JS |
| AC-015 | Tasks 2, 3 | WCAG 2.2 AA |
| AC-016 | Task 2 | ARIA tab roles |

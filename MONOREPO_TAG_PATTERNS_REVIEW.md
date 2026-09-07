# Stacked post-1.2.0 fix review

## Review Scope

Reviewed `git diff main...fix/admin-ui-docs`, excluding `dist/` and `vendor/`, including changed tests, JavaScript, templates, uninstall handling, changelog, and ignore rules. Read all three commit messages and live GitHub PR descriptions. Local tips matched the PR head SHAs:

| PR | Base | Reviewed commit |
| --- | --- | --- |
| [#23: Content correctness](https://github.com/jakemgold/github-release-posts-wordpress/pull/23) | `main` | `9cbe581bf9a7b17fd4c41a28b86a28085ae93887` |
| [#24: Monitoring robustness](https://github.com/jakemgold/github-release-posts-wordpress/pull/24) | `fix/content-correctness` | `ccd6baf5a2505ef35159adb637d677ffb8793d1d` |
| [#25: Admin UI and docs](https://github.com/jakemgold/github-release-posts-wordpress/pull/25) | `fix/monitoring-robustness` | `88ccbea566df8585d0a5e51e44eb95030dcee1b1` |

Comparison base: `main` at `a9816892fd062cbe5d53c54c4e26b5df05103334`. Working tree was clean before review. No plugin source, repository tests, dependencies, or build outputs were edited. Supplemental probes and baseline copies are under `/tmp/ghrp-stack-review/`.

The deferred upgrade-window baseline, policy rebaseline, 404 handling, PAT decryption, version ordering, and picker-depth decisions remain outside this review.

## Status

**Request changes. Two introduced regressions block merging the stack: a publication regression in #24 and an image-content regression in #23.** No additional merge-blocking regression was found in #25. There are two separate, non-blocking simplification recommendations.

All five required commands exited **0**. PHPUnit's precise result was **424 tests, 733 assertions, 1 skipped**: 423 executed successfully, rather than 424 passes plus a skip. The same skipped notification test exists on `main`.

Supplemental status-preservation and image-preservation assertions pass against the relevant `main` implementations and fail against the stacked head.

## Findings

### R1. [P1] A fresh dedup hit can publish another request's draft or unpublish its live post

**Introduced by #24.** Changed entry point: [Post_Creator.php:66](/Users/jake/Documents/claude-project/includes/classes/Post/Post_Creator.php:66), particularly the new invalidation at line 72. Its newly reachable existing-post branch fires `ghrp_post_created` at line 84. [Publish_Workflow.php:56](/Users/jake/Documents/claude-project/includes/classes/Post/Publish_Workflow.php:56) then applies the losing request's publication context to that existing post.

Reproduction:

1. A generation request calls `find_post()` and memoizes no existing post, then waits for AI generation.
2. A second request creates the same repository/tag post and chooses its status.
3. The first request resumes. The new `forget_post()` correctly discovers that post, but passes its ID into the creation hooks instead of stopping at deduplication.
4. A cron request with repository status `publish` changes that manual draft to `publish` and resets its date. Conversely, a manual request with `force_draft=true` changes the other request's newly published post to `draft`, removing it from public view.

The existing trash guard does not protect drafts, published posts, scheduled posts, or custom workflow statuses. Creation hooks can also reapply taxonomy and send misleading creation notifications.

**Executed evidence:** `PublicationRaceProbeTest.php` primes the negative memo, exposes post 73 on the second lookup, and connects the real creator and publication workflow. The stacked head writes `post_status=publish` to post 73 in the cron case and `post_status=draft` to post 73 in the manual case. Both preservation assertions fail. With the `main` creator and monitor, post 73 retains its status; the old duplicate-insert defect creates a separate post 99 instead. This is a new mutation of the winning post, not a report of the old duplication bug.

**Smallest correction:** keep the fresh lookup, but return on an existing-post hit without replaying creation side effects. The cron confirmation and manual REST response already call `find_post()` after processing, so they can observe the existing post without this action. Add coverage where the second lookup actually returns a post and invokes the publication callback. The added suite test returns an empty result twice and misses this case.

### R2. [P2] The new void-element splitter loses valid images containing a quoted greater-than sign

**Introduced by #23.** [Post_Creator.php:549](/Users/jake/Documents/claude-project/includes/classes/Post/Post_Creator.php:549) matches a void tag with `<(?:hr|img)\b[^>]*>`. That stops at the first `>` even when it belongs to a quoted attribute.

This valid input demonstrates the regression:

```html
<img alt="Before > After" src="https://github.com/acme/repo/screenshot.png" /><p>Next paragraph</p>
```

On `main`, the self-closing image is captured intact, parsed with `DOMDocument`, and rebuilt as a canonical image block with escaped alt text. The new splitter sends only `<img alt="Before >` into `wrap_img_block()` and treats the remaining attributes as paragraph content. At the existing `wp_kses_post()` save boundary, this becomes an `<img>` with no `src`, followed by a paragraph containing the tail of the original tag. The image disappears and attribute text leaks into the article.

**Executed evidence:** `VoidElementProbeTest.php` runs the real converter and an unmodified local WordPress KSES implementation, then inspects the output DOM. `main` preserves both URL and alt text; the stacked head yields an empty image `src`. The malformed split also reproduces before KSES. The supplemental sanitizer is from local WordPress 6.9.4, not a full WordPress 7.0 integration environment.

**Smallest correction:** retain the separate void-element branch, but recognize single- and double-quoted attribute values as complete units before accepting `>` as the terminator. For example, replace `[^>]*` with `(?:[^>"']|"[^"]*"|'[^']*')*`. Add this case alongside the bare-image, horizontal-rule, and line-break tests. This needs a bounded change to the new matcher, not a replacement parser.

## Simplification

These recommendations are **not merge blockers**. Most changes are proportionate. The two material opportunities are overlapping cron provisioning and a duplicated name resolver.

### S1. Use the per-site cron self-heal instead of also traversing the network

[#24, Activator.php:39](/Users/jake/Documents/claude-project/includes/classes/Activator.php:39) adds an unbounded `get_sites(number => 0)` loop, switches site context, writes every default, and clears/recreates each schedule. [Plugin.php:138](/Users/jake/Documents/claude-project/includes/classes/Plugin.php:138) independently repairs the schedule on every site's `init`, including sites created after activation.

**Concrete alternative:** remove the new network traversal and retain current-site activation setup plus `ensure_cron_event()` on `init`. Settings accessors already supply defaults for absent options. Each subsite gets its missing event when it boots, including through its cron request. This fixes the stated missing-subsite-monitor defect without fetching all sites or running network-sized synchronous writes during activation.

The tradeoff is provisioning an untouched subsite on its first request instead of eagerly during network activation. The stated defect does not require eager provisioning. Keeping both mechanisms adds network-size-dependent activation work and overlapping lifecycle responsibility. No activation timeout was reproduced, so this remains non-blocking.

### S2. Delegate row names to the existing display-name resolver

[#25, Repository_List_Table.php:571](/Users/jake/Documents/claude-project/includes/classes/Admin/Repository_List_Table.php:571) adds `display_name_for()`, repeating the configured-name check, repository splitting, and fallback in [Repository_Settings.php:209](/Users/jake/Documents/claude-project/includes/classes/Settings/Repository_Settings.php:209). Its own comment says it mirrors that method.

**Concrete alternative:** make the helper delegate to `(new Repository_Settings())->get_display_name((string) ($item['identifier'] ?? ''))`, or call that resolver at the two changed sites. The table receives saved repository configurations. This removes the duplicate fallback policy while preserving the blank-name fix; it requires no new service or class.

### Assessment of every change

| Change | Proportionality assessment |
| --- | --- |
| #23: Slash insert, regenerate, and sideload payloads | Proportionate. Three boundary wrappers address three distinct writes. |
| #23: Notification edit URLs | Proportionate. The small helper serves three uses and removes dependence on the cron user's capabilities. |
| #23: Whole-word security matching | Proportionate. Local replacement of substring matching; no new state or dependency. |
| #23: Separator blocks and void/container splitting | Proportionate in size, but fix R2 in the new matcher. |
| #23: Multibyte description truncation | Proportionate. Uses facilities already used elsewhere in generation. |
| #23: Anonymous off-repository requests, rate-limit return, translated error | Proportionate. A defaulted flag reuses the request builder; existing errors propagate through the existing API contract. |
| #23: Shared title prefix and display tag | Proportionate. Two pure helpers preserve existing title/filter behavior and let the prompt quote the saved prefix. The new prompt dependency on these formatting methods is justified. |
| #23/#24: Effective tag-pattern resolver and migration | Proportionate. Replaces seven filter copies and adds two missing consumers; optional supplied configuration avoids lookups without new cache state. |
| #23: README underscore emphasis | Proportionate. Local regex changes preserve intraword underscores. |
| #24: Heartbeat, conditional release, uninstall cleanup | Proportionate. Two ownership fields and two lifecycle methods support conditional SQL on the existing row. Removing ownership state loses protection. |
| #24: Fresh lookup and blog-aware memo key | Proportionate invalidation and key helper. R1 concerns the action on a resulting hit. No new cache layer. |
| #24: Registered statuses in dedup and Last Post | Proportionate. One registry helper prevents list drift; the column's trash exclusion serves its separate display purpose. |
| #24: Cron self-heal and network activation | Self-heal is small and appropriate. Simplify the overlapping loop as S1. |
| #24: Render AI-failure notice | Proportionate. Escapes and consumes an existing transient without another notification mechanism. |
| #24: Clear run results | Proportionate. One deletion after acquiring the lock prevents cross-run accumulation. |
| #24: PAT validation TTL | Proportionate. Changes the duration of the existing PAT-keyed cache. |
| #24: Catch connector model-list failures | Proportionate. Local catch continues to another provider or the existing default. |
| #24: Picker trash exclusion | Proportionate. One condition aligns the picker with manual-generation conflict behavior. |
| #24: Regenerate block-editor gate | Proportionate. Reuses the existing gate. |
| #25: Enter routes to Add | Proportionate. One handler works without the picker and yields to its highlighted-option handler. No extra submission state. |
| #25: Blank row-name fallback | Simplify as S2. The fix is warranted; a second resolver is unnecessary. |
| #25: Preserve unavailable author | Proportionate. Omission reuses the existing update-merge behavior. |
| #25: Report failed repository save | Proportionate. Checks the existing save result, including its unchanged-value readback, and uses the existing error response. |
| #25: Dead string, ignored artifacts, changelog/docs | Small housekeeping. The claimed developer-doc refresh is local only; see Follow-up. |
| Added and adjusted tests | Generally proportionate. The memo test misses an actual competing insert; lock tests inspect SQL shapes rather than ownership outcomes. Supplemental probes address those review gaps. |

## Follow-up

1. **Narrow the concurrency claims in the changelog.** [readme.txt:172](/Users/jake/Documents/claude-project/readme.txt:172) describes duplicate races as fixed and says overlapping runs can no longer double-generate. Refreshing the memo does not make lookup plus insert atomic. Heartbeats occur between work items, so one operation exceeding the reclaim age can still overlap a successor, and losing ownership does not stop the old worker. These residual windows existed on `main`; they are not additional introduced regressions. Describe the specific stale-memo and lock-release fixes without an absolute guarantee. No expanded locking redesign is requested.
2. **Clarify the developer-doc deliverable in #25.** `CLAUDE.md` is ignored by `.gitignore:27`, `git ls-files CLAUDE.md` returns nothing, and it is absent from the stack diff. The PR and commit claim a refresh reviewers will not receive in the PR. Label it as a local documentation update in the description. This is a delivery/description mismatch, not a plugin regression.

## Verification

Each required command was run independently and its exit code read directly, including final exits for the two commands that initially returned running sessions.

| Required command | Exit | Result |
| --- | --- | --- |
| `composer test` | 0 | 424 tests, 733 assertions, 1 pre-existing skip. No failures. |
| `vendor/bin/phpcs --standard=phpcs.xml.dist includes/ uninstall.php` | 0 | No violations. |
| `composer phpstan -- --memory-limit=1G` | 0 | 38 files analyzed; no errors. |
| `git diff --check` | 0 | Clean working-tree diff check. |
| `npm run lint:js` | 0 | Passed. |

Also ran `git diff --check main...fix/admin-ui-docs -- . ':!dist' ':!vendor'`; exit 0. The bare requested command alone does not inspect committed stack changes.

Non-failing diagnostics: PHP 8.5 deprecations from WP_Mock and an existing reflection call, plus the Browserslist data-age warning. No dependency updates were made. The skipped notification test is unchanged from `main` and requires integration support for dynamic filter matching.

Supplemental probes use `php vendor/bin/phpunit --no-configuration --do-not-cache-result /tmp/ghrp-stack-review/<file>`:

| Probe | Stacked head | Relevant main implementations |
| --- | --- | --- |
| `PublicationRaceProbeTest.php` with `--filter test_review` | Exit 1: two status-preservation failures | Exit 0: two tests, four assertions |
| `VoidElementProbeTest.php` | Exit 1: image URL preservation fails | Exit 0: one test, three assertions |
| `LockProbeTest.php` | Exit 0: three tests, eleven assertions | Not applicable |
| `MemoProbeTest.php` | Exit 0: one test, five assertions | Not applicable |

For baseline comparisons, prepend `GHRP_REVIEW_BASELINE=1`. The probes load copies obtained with `git show main:<path>` before autoloading affected classes. Publication workflow behavior is unchanged across the stack. These are deterministic callback/database simulations, not concurrent processes against a live database. The image probe additionally uses installed WordPress 6.9.4 KSES functions.

Targeted trace results:

- **Slashing:** creation slashes its newly built raw array once at `Post_Creator.php:144`. Regeneration independently builds raw fields and slashes once at `Admin_Page.php:1594`. Both sideload by rereading the saved post, editing raw content, and slashing the separate content-update array once at `Post_Creator.php:959`. The publication workflow supplies only ID/status/date fields. Core slashes stored fields before merging updates, so that status-only write does not strip preserved content. This matches the [WordPress update contract and implementation](https://developer.wordpress.org/reference/functions/wp_update_post/). No introduced double-slashing path was found.
- **Lock ownership:** acquisition and local ownership values agree; successful heartbeats advance both. A same-second UPDATE affecting zero rows leaves a valid value for release. After reclamation, the stale worker's UPDATE and DELETE fail against the new value. Executed those three cases with affected-row semantics; all passed. The surrounding `finally`, stale cutoff, and cache invalidation remain present. No new orphan-lock or wrong-owner deletion was substantiated; this is not a proof against every process or database failure.
- **Statuses and memo:** [WordPress get_post_stati()](https://developer.wordpress.org/reference/functions/get_post_stati/) returns registered names. The helper keeps `future`, custom statuses, and trash while excluding `auto-draft`/`inherit`. Last Post additionally excludes trash. Blog ID participates in lookup and invalidation keys; the two-site probe preserves one site's memo while invalidating the other. The publication consequence of a fresh hit is R1.
- **Every-init scheduling:** when an event exists, the check returns before filtering or scheduling. When absent, it uses the existing interval/filter/scheduling path. It does not clear existing schedules on normal requests or prevent later generation hooks from registering. Network traversal is unnecessary overlap under S1.
- **Tag-pattern migration:** all seven former filter sites are replaced: monitor, onboarding, creator naming, admin post-response labels, release picker, manual generation, and regeneration. Prompt and Last Post are the two additional consumers. Only `get_effective_tag_patterns()` now applies the filter, preserving its arguments and string coercion. There is one definition site, not one invocation per request.
- **Admin changes:** inspected Enter-handler order and picker handoff, author omission and merge behavior, the failed-save caller exit, escaped notice rendering, and connector fallback. No new screen-breaking path was found. No browser-rendered QA or live AI generation was performed.

## Recommendation

**Hold #23 for R2 and #24 for R1, then recheck the stack before merging #23, #24, and #25 in order.** Fix the new matcher and the existing-post side effects exposed by the fresh lookup, add the specific reproductions to the relevant tests, and rerun the required battery.

S1 and S2 are worth taking to keep this a small corrective release, but do not independently block merge. The green existing suite does not override the two demonstrated regressions.

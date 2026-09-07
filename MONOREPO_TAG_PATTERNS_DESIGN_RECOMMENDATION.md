# Monorepo Tag Patterns: Recommended Final Design

## Decision

Replace the current topology and lifecycle heuristics with one snapshot-based stream monitor used by every repository.

The key simplification is this:

> The first successful full release snapshot defines the monitoring baseline. If that snapshot fails, onboarding remains pending and retries the same operation later. Do not infer whether unseen releases are historical or new from tag shape, cursor-map emptiness, timestamps, or marker presence.

This feature has not shipped. Do not preserve compatibility with `tracking_started_at`, intermediate baseline meanings, or any other state written only by this branch during testing. Compatibility is required only for state created by the released code on `main`.

## Product Contract

1. The plugin examines at most the 25 most recent release records returned by GitHub for each repository. Draft records are ignored.
2. Every release belongs to a stream:
   - A recognized package tag belongs to that package stream, such as `@headstartwp/core`.
   - Any unrecognized or repository-wide tag belongs to the default stream, represented internally as `''`.
3. Monitoring uses one algorithm for single-package repositories, monorepos, and mixed package/plain repositories.
4. The package chooser appears only when at least two recognized named packages are present in the snapshot.
5. Pre-releases and tag patterns affect content eligibility, not package discovery.
6. Existing content is never backfilled automatically. Manual Generate Draft remains the explicit backfill tool.

## Explicit Limits

Document these limits in the Help tab and changelog:

- Only the 25 most recent release records returned by GitHub are inspected; drafts within that window are ignored rather than replaced through pagination.
- The package chooser can show only packages represented in those 25 releases.
- At most one release per stream is generated during one scheduled scan. If one package publishes several versions between scans, only that stream's newest eligible version is generated.
- If the initial snapshot fails, monitoring starts from the first later successful snapshot. The plugin does not reconstruct everything that happened during the failed interval.
- Upgrading from the released plugin establishes current stream heads without backfilling older package releases.
- Changing Include pre-releases or package patterns is forward-only. The next successful scan establishes a new baseline under the new policy; it does not generate newly eligible historical releases.

These are acceptable, understandable boundaries. They are safer than attempting to infer complete history from a partial GitHub response.

## One Snapshot, Two Projections

Fetch one raw snapshot per repository:

```text
GET /repos/{owner}/{repo}/releases?per_page=25
```

Ignore GitHub drafts in the returned snapshot but retain the `prerelease` flag. Do not paginate to replace ignored drafts. Build two projections locally.

### Discovery Projection

Used only for package detection and the Quick Edit package chooser:

- Includes stable releases and pre-releases.
- Ignores configured tag patterns.
- Groups recognized package tags for display.
- Sets `ui_has_package_choice=true` only when at least two named packages are recognized.

### Monitoring Projection

Used for baselines, cursors, latest selection, generation, and cron:

- Excludes pre-releases when Include pre-releases is off.
- Applies the effective tag patterns after the `ghrp_repo_tag_patterns` filter.
- Groups eligible releases by stream.
- Selects one head per stream using semantic version comparison within a recognized package stream and publication time as the fallback.

Never create monitoring cursors from the discovery projection. That is the source of the round-7 pre-release bug.

## State Model

Keep only the state needed to make transitions explicit:

```php
[
    'stream_state_version' => 1,
    'onboarding_pending'   => true|false,
    'streams_baseline_at'  => 0|timestamp,
    'policy_hash'          => '...',
    'streams'              => [
        '@headstartwp/core' => [
            'last_seen_tag'          => '@headstartwp/core@1.6.1',
            'last_seen_published_at' => '2026-07-01T12:00:00Z',
        ],
        '' => [
            'last_seen_tag'          => 'v3.0.0',
            'last_seen_published_at' => '2026-07-02T12:00:00Z',
        ],
    ],
]
```

Definitions:

- `onboarding_pending=true`: a newly added repository has not yet produced a successful full snapshot.
- `streams_baseline_at>0`: a trustworthy snapshot has established stream cursors.
- `policy_hash`: a hash of the effective Include pre-releases value and normalized effective tag patterns.
- `streams`: per-stream cursors. A missing cursor means the current head has not been processed or deliberately baselined.

Do not persist `is_monorepo` or topology freshness for monitoring. The snapshot itself tells the monitor how many streams currently exist. The UI may cache its package payload for performance, but that cache must not control monitoring.

The existing repo-wide `last_seen_tag` fields may remain temporarily for admin display or released-version compatibility, but stream monitoring must not make decisions from them after migration.

## Transition Rules

### 1. Fresh Repository Add

Before the API call, persist `onboarding_pending=true`.

Fetch the raw snapshot. Do not make a second latest-release request. A single snapshot must drive package discovery, eligibility, baselines, and initial generation.

If the snapshot fails:

- Keep `onboarding_pending=true`.
- Do not write topology, cursors, or a completed baseline.
- Do not attempt partial generation through another endpoint.
- Show: "Repository added, but the initial release scan failed. It will be retried on the next scheduled check."

If the snapshot succeeds, apply exactly one of the following outcomes.

| Snapshot outcome | Baseline | Initial generation |
| --- | --- | --- |
| No eligible releases | Empty ready baseline | None; future first release generates normally |
| Fewer than two named packages | Baseline every eligible stream except the selected initial release's stream | Generate exactly one overall latest eligible release |
| Two or more named packages | Baseline every eligible stream head | Suppress generation and show the package-choice notice |

For the single/mixed case, select the initial release from the monitoring projection, not the discovery projection. Write the baseline first while omitting that release's stream cursor, then trigger generation. This is crash-safe:

- Successful generation advances the stream cursor.
- Failed generation leaves the cursor absent, so cron retries it.
- Other current streams are already baselined and cannot burst.

After the baseline write, set `onboarding_pending=false`, set `streams_baseline_at`, and save the current `policy_hash` in the same option update.

### 2. Retrying Failed Onboarding

When cron sees `onboarding_pending=true`, it reruns the same full-snapshot onboarding transition above.

Do not use normal monitoring until onboarding succeeds. The first successful snapshot is the boundary:

- For fewer than two named packages, generate exactly one latest eligible release and baseline the rest.
- For two or more named packages, baseline all current heads and record an admin notice that package selection is available.

This deliberately does not reconstruct every release that may have occurred while the initial scan was failing. That limitation is documented and removes the historical-versus-new ambiguity entirely.

### 3. Upgrade From Released `main`

An existing repository with no `stream_state_version` and no `onboarding_pending` is an upgrade, not a fresh add.

On its first successful snapshot:

- Build the monitoring projection using its current settings.
- Baseline every current eligible stream head.
- Generate nothing.
- Set `stream_state_version=1`, `streams_baseline_at`, and `policy_hash`.

This preserves the existing no-backfill upgrade behavior and prevents a one-post-per-package burst.

Do not add migrations for state produced by unreleased review iterations. Local test installations can have their branch-only state reset.

### 4. Normal Scheduled Monitoring

For a repository with a completed baseline and unchanged policy:

1. Fetch the raw snapshot.
2. Build the monitoring projection.
3. Select one head per eligible stream.
4. Compare each head with its stream cursor.
5. Enqueue every newer stream head.
6. If a matching post already exists, advance that cursor without re-running generation or publication.
7. Advance a cursor only after post creation succeeds or an existing matching post is confirmed.
8. Leave the cursor unchanged after AI, insertion, or publication failure so the next scan retries.

There is no separate single-repository path and no separate monorepo path. A normal repository simply has one default stream.

### 5. Eligibility Policy Changes

Compute `policy_hash` from:

```text
include_prereleases + normalized effective tag patterns
```

If the hash changes:

- Build the new monitoring projection.
- Baseline every currently eligible stream head under the new policy.
- Generate nothing during that scan.
- Replace `policy_hash` and continue normally on later scans.

This makes settings changes explicitly forward-only. It also prevents an old pre-release cursor from blocking a lower-version stable release after pre-releases are disabled.

Manual Generate Draft remains available when the administrator intentionally wants current or older content after changing policy.

## Functions and Ownership

Use a small set of helpers with narrow responsibilities:

```php
API_Client::fetch_release_snapshot( string $identifier, int $limit = 25 )
Release_Selector::discovery_projection( array $snapshot )
Release_Selector::monitoring_projection( array $snapshot, bool $include_prereleases, string $patterns )
Release_Selector::select_stream_heads( array $eligible_releases )
Release_Selector::select_latest_head( array $stream_heads )
Release_State::complete_baseline( string $identifier, array $cursors, string $policy_hash )
Release_State::mark_onboarding_pending( string $identifier )
```

The selectors should be pure functions. State changes should update the repository option coherently rather than making several read-modify-write calls that can preserve a partially transitioned state.

## Remove From the Current Branch

Remove these monitoring concepts rather than patching them again:

- `tracking_started_at`
- `is_monorepo` as a monitoring router
- topology freshness and weekly topology rediscovery
- package-shaped latest-tag detection as a monitoring trigger
- separate single-cursor and package-stream monitor paths
- any use of `multi_package` outside the UI decision
- monitoring baselines derived from pre-release-inclusive discovery data

The package parser, stream winner selection, package chooser, tag-pattern filtering, replay guard, and per-stream post-success cursors remain useful.

## Required Tests

### Onboarding

- Single/default stream: one initial draft, no duplicate on cron.
- One named package plus one plain stream: one initial draft, other stream baselined.
- Two named packages: no initial draft, every current stream baselined, chooser notice shown.
- No releases: empty ready baseline; first later release generates.
- Initial snapshot failure: no partial baseline or generation; first successful retry follows the same onboarding matrix.

### Eligibility

- Discovery sees pre-release packages while stable-only monitoring cursors contain only stable releases.
- A `3.0.0-beta` discovery entry cannot block a later `2.1.0` stable release when pre-releases are disabled.
- Mixed package/plain onboarding chooses its pending initial stream from the stable monitoring projection.
- Tag patterns exclude content consistently from the picker, manual generation, and scheduled monitoring.

### Upgrade and Policy

- Released `main` state receives a one-time baseline with no generated posts.
- A policy-hash change rebaselines current eligible heads without backfill.
- The next release after that policy baseline generates normally.

### Failure Safety

- Existing matching draft advances the cursor without invoking publication.
- AI or post-creation failure leaves the cursor unchanged.
- A crash after baseline completion but before initial generation still allows cron to generate the omitted initial stream.
- Two streams releasing between scans enqueue one head from each stream.

## Acceptance Matrix

| Scenario | Expected result |
| --- | --- |
| Fresh normal repository | One initial draft, then one stream monitored |
| Fresh mixed package/plain repository | One initial draft, all other current streams baselined |
| Fresh repository with 2+ named packages | No initial draft; package chooser shown; current heads baselined |
| Initial API failure | No partial decision; retry full onboarding later |
| Existing released-plugin repository upgrades | Current heads baselined; no burst and no backfill |
| Two packages release before one cron | One post per package stream |
| Two versions of one package release before one cron | Only the newest version gets a post |
| Pre-releases disabled | Pre-releases never become cursors or posts |
| Package policy changes | Current eligible heads become the new baseline; future releases generate |

## Implementation Order

1. Add the raw 25-release snapshot API and pure discovery/monitoring projections.
2. Replace topology routing with the universal stream monitor.
3. Replace branch-only lifecycle markers with `onboarding_pending`, baseline version, policy hash, and stream cursors.
4. Implement fresh-add, failed-add retry, released-version upgrade, and policy-change transitions.
5. Wire the package chooser to the discovery projection only.
6. Add the transition tests before removing the old routing code.
7. Update Help and changelog with the explicit limits.
8. Run PHPUnit, PHPCS, PHPStan, JavaScript lint, and a manual WordPress admin check.

## Recommendation to Claude

Implement this as a replacement of the current monitoring state machine, not as another patch layered onto rounds 3 through 7. Preserve the useful package parsing, filtering, picker, replay protection, and post-generation work. Delete the inferred topology routing and branch-only compatibility code.

The desired result is intentionally boring: one bounded snapshot, one eligibility projection, one stream algorithm, and three explicit lifecycle transitions. That is enough to support this feature safely without pretending to reconstruct unlimited GitHub history.

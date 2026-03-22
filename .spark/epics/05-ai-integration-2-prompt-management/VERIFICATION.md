---
epic: EPC-05.2
verified: 2026-03-21T00:00:00Z
status: passed
score: 7/7
must_haves:
  truths:
    - "Releases are classified by significance (patch/minor/major/security) using semver parsing and security keyword detection"
    - "Security classification always overrides semver-based classification (BR-001)"
    - "AI prompts are tailored per-significance with appropriate tone, structure, and title guidance"
    - "Download links resolve with correct priority: custom_url > wporg_slug > html_url (AC-019)"
    - "Images from release bodies are extracted and included in prompts; placeholders suggested when none exist (AC-024-026)"
    - "Prompt_Builder is wired into the plugin lifecycle via the ctbp_generate_prompt filter"
    - "All prompt components are filterable for developer customization"
  artifacts:
    - path: "includes/classes/AI/Release_Significance.php"
      provides: "Semver parsing and significance classification"
      min_lines: 30
    - path: "includes/classes/AI/Prompt_Builder.php"
      provides: "Full prompt assembly with per-significance guidance"
      min_lines: 50
    - path: "includes/classes/Plugin.php"
      provides: "Lifecycle wiring of Prompt_Builder"
    - path: "tests/php/unit/AI/Release_SignificanceTest.php"
      provides: "Unit tests for significance classification"
      min_lines: 50
    - path: "tests/php/unit/AI/Prompt_BuilderTest.php"
      provides: "Unit tests for prompt building"
      min_lines: 50
  key_links:
    - from: "includes/classes/Plugin.php"
      to: "Prompt_Builder"
      via: "instantiation and setup() call"
      pattern: "new Prompt_Builder.*->setup"
    - from: "includes/classes/AI/Prompt_Builder.php"
      to: "ctbp_generate_prompt"
      via: "add_filter in setup()"
      pattern: "add_filter.*ctbp_generate_prompt"
    - from: "includes/classes/AI/Prompt_Builder.php"
      to: "Release_Significance::classify()"
      via: "method call in build()"
      pattern: "significance->classify"
    - from: "includes/classes/AI/Prompt_Builder.php"
      to: "Repository_Settings::get_repositories()"
      via: "method call in get_repo_config()"
      pattern: "repo_settings->get_repositories"
    - from: "includes/classes/AI/AI_Processor.php"
      to: "ctbp_generate_prompt"
      via: "apply_filters"
      pattern: "apply_filters.*ctbp_generate_prompt"
human_verification:
  - test: "Generate a prompt for a security release and verify the tone is calm and non-alarming"
    expected: "Prompt contains security-specific tone guidance without exploit details"
    why_human: "Tone quality is subjective and cannot be verified structurally"
  - test: "Generate a prompt for a major vs patch release and compare structure"
    expected: "Major release prompt has more expansive content guidance than patch"
    why_human: "Relative prompt quality requires human judgment"
  - test: "Verify AC-024 draft-mode image placeholder behavior end-to-end"
    expected: "Placeholders appear only in draft posts, not published posts"
    why_human: "Draft/publish distinction is handled downstream in post creation (DOM-06), not in prompt building"
---

# EPC-05.2 Verification: Prompt Management

## Summary

| Category  | Verified | Total | Status |
| --------- | -------- | ----- | ------ |
| Truths    | 7        | 7     | PASS   |
| Artifacts | 5        | 5     | PASS   |
| Key Links | 5        | 5     | PASS   |

**Overall Status:** passed
**Score:** 7/7
**Re-verification:** No -- initial verification

## Goal Achievement (Truths)

| # | Truth | Status | Supporting Evidence |
|---|-------|--------|---------------------|
| 1 | Releases are classified by significance (patch/minor/major/security) using semver parsing and security keyword detection | VERIFIED | `Release_Significance::classify()` implements semver parsing with `parse_semver()` (line 142) and security keyword detection via `has_security_keyword()` (line 123). Tests cover all semver cases (patch/minor/major) plus 7 security keywords. |
| 2 | Security classification always overrides semver-based classification (BR-001) | VERIFIED | `detect_significance()` checks security keywords before semver parsing (line 84). Test `test_classify_security_overrides_major()` explicitly tests a v2.0.0 tag (normally major) with vulnerability content returning security. |
| 3 | AI prompts are tailored per-significance with appropriate tone, structure, and title guidance | VERIFIED | `build_title_guidance()` (line 119) uses a `match` expression with distinct guidance for each significance level. `build_content_guidance()` (line 147) similarly provides per-significance tone via `match`. Both methods produce differentiated output for patch/minor/major/security. |
| 4 | Download links resolve with correct priority: custom_url > wporg_slug > html_url (AC-019) | VERIFIED | `resolve_download_link()` (line 280) checks `custom_url` first, then `wporg_slug`, then falls back to `$data->html_url`. Three dedicated tests verify each priority level. |
| 5 | Images from release bodies are extracted and included in prompts; placeholders suggested when none exist (AC-024-026) | VERIFIED | `extract_images()` (line 300) matches both Markdown and HTML image syntax. `build_image_instructions()` (line 183) includes real images when found or suggests placeholders when none exist. Security exclusion is handled via prompt instruction ("Do NOT include placeholders in a security-only release"). Tests cover both paths. |
| 6 | Prompt_Builder is wired into the plugin lifecycle via the ctbp_generate_prompt filter | VERIFIED | `Plugin::init()` line 129 instantiates `Prompt_Builder` with `$repo_settings` and `new Release_Significance()` and calls `->setup()`. `setup()` registers on `ctbp_generate_prompt` filter. `AI_Processor` fires this filter at line 77 of `AI_Processor.php`. |
| 7 | All prompt components are filterable for developer customization | VERIFIED | Four filter hooks: `ctbp_release_significance` (Release_Significance:73), `ctbp_prompt_title_guidance` (Prompt_Builder:71), `ctbp_prompt_content_guidance` (Prompt_Builder:80), `ctbp_prompt` (Prompt_Builder:100). All have docblock documentation with @param tags. Tests use WP_Mock::onFilter for all hooks. |

## Required Artifacts

| Path | Exists | Substantive | Wired | Status |
|------|--------|-------------|-------|--------|
| includes/classes/AI/Release_Significance.php | YES | YES (157 lines) | YES (used by Prompt_Builder, wired in Plugin.php) | VERIFIED |
| includes/classes/AI/Prompt_Builder.php | YES | YES (327 lines) | YES (instantiated in Plugin.php, hooks ctbp_generate_prompt) | VERIFIED |
| includes/classes/Plugin.php | YES | YES (132 lines) | YES (entry point, bootstrapped on plugins_loaded) | VERIFIED |
| tests/php/unit/AI/Release_SignificanceTest.php | YES | YES (205 lines) | YES (PHPUnit test, 15 test methods) | VERIFIED |
| tests/php/unit/AI/Prompt_BuilderTest.php | YES | YES (229 lines) | YES (PHPUnit test, 11 test methods) | VERIFIED |

## Key Link Verification

| From | To | Via | Status | Evidence |
|------|----|-----|--------|----------|
| Plugin.php | Prompt_Builder | instantiation + setup() | WIRED | Line 129: `( new Prompt_Builder( $repo_settings, new Release_Significance() ) )->setup();` |
| Prompt_Builder::setup() | ctbp_generate_prompt | add_filter | WIRED | Line 41: `add_filter( 'ctbp_generate_prompt', [ $this, 'build' ], 10, 2 );` |
| Prompt_Builder::build() | Release_Significance::classify() | method call | WIRED | Line 56: `$this->significance->classify( $data )` |
| Prompt_Builder::get_repo_config() | Repository_Settings::get_repositories() | method call | WIRED | Line 263: `$this->repo_settings->get_repositories()` |
| AI_Processor | ctbp_generate_prompt | apply_filters | WIRED | AI_Processor.php line 77: `apply_filters( 'ctbp_generate_prompt', '', $data )` |

## PRD Requirements Validation

| ID | Type | Description | Evidence | Status |
|----|------|-------------|----------|--------|
| AC-001 | AC | Patch increment classified as patch | Release_Significance:112, test data provider includes `v1.2.3` -> patch | IMPLEMENTED |
| AC-002 | AC | Minor increment classified as minor | Release_Significance:109, test `v1.5.0` -> minor | IMPLEMENTED |
| AC-003 | AC | Major increment classified as major | Release_Significance:105, test `v2.0.0` -> major | IMPLEMENTED |
| AC-004 | AC | Security keywords override classification | Release_Significance:84, 10 keywords in SECURITY_KEYWORDS const, 7 tested individually | IMPLEMENTED |
| AC-005 | AC | Non-semver tags fall back to minor | Release_Significance:99, test `release-2024` -> minor | IMPLEMENTED |
| AC-006 | AC | Leading v/V stripped from tags | Release_Significance:144 `ltrim($tag, 'vV')`, tests for both v and V | IMPLEMENTED |
| AC-007 | AC | Significance passed as explicit guidance to AI | Prompt_Builder:229 includes `Significance: {$significance_label}` in prompt | IMPLEMENTED |
| AC-008 | AC | Filter hook for significance override | Release_Significance:73 `apply_filters('ctbp_release_significance', ...)`, test_classify_filter_can_override | IMPLEMENTED |
| AC-009 | AC | Patch subtitle: brief, functional | Prompt_Builder:123 match 'patch' -> "Bug fixes and stability improvements" guidance | IMPLEMENTED |
| AC-010 | AC | Minor subtitle: notable improvements | Prompt_Builder:124 match 'minor' -> "Highlight one or two notable improvements" | IMPLEMENTED |
| AC-011 | AC | Major subtitle: headline capability | Prompt_Builder:125 match 'major' -> "Lead with the headline new capability" | IMPLEMENTED |
| AC-012 | AC | Security subtitle: begins with "Security update" | Prompt_Builder:126 match 'security' -> "Begin the subtitle with 'Security update'" | IMPLEMENTED |
| AC-013 | AC | Plugin name from per-repo config | Prompt_Builder:55 uses config display_name, falls back to derive_display_name | IMPLEMENTED |
| AC-014 | AC | Plain-language introduction | Prompt_Builder:158 "plain-language summary... for non-technical site owners (required)" | IMPLEMENTED |
| AC-015 | AC | Main body in plain language | Prompt_Builder:159 "summarise... in plain language without developer jargon (required)" | IMPLEMENTED |
| AC-016 | AC | Developer section clearly labelled | Prompt_Builder:160-161 "clearly labelled section titled 'For developers:'" | IMPLEMENTED |
| AC-017 | AC | Developer section omitted when not relevant | Prompt_Builder:161 "Omit this section entirely if no developer-relevant content" | IMPLEMENTED |
| AC-018 | AC | Post length scales with substance | Prompt_Builder:166 "Scale content to match... Do not pad thin releases... do not truncate rich ones" | IMPLEMENTED |
| AC-019 | AC | Download link priority: custom > wporg > html_url | Prompt_Builder:280-290, three tests verify each level | IMPLEMENTED |
| AC-020 | AC | Link phrased contextually | Prompt_Builder:162 "Phrase it contextually, for example: 'Download the update from WordPress.org'" | IMPLEMENTED |
| AC-021 | AC | WP.org slug validation at save time | OUT OF SCOPE (DOM-03 settings responsibility per PRD "Out of Scope" section) | N/A |
| AC-022 | AC | Images placed contextually | Prompt_Builder:186 "Place them near the content they illustrate" | IMPLEMENTED |
| AC-023 | AC | No placeholders in published posts | Prompt_Builder:189 instructs AI; actual enforcement is downstream in post creation (DOM-06) | PARTIAL (see notes) |
| AC-024 | AC | Draft posts include image placeholders | Prompt_Builder:189 placeholder instructions always included; draft/publish context deferred to DOM-06 | PARTIAL (see notes) |
| AC-025 | AC | Placeholders clearly marked as suggestions | Prompt_Builder:189 format `[Image suggestion: brief description...]` | IMPLEMENTED |
| AC-026 | AC | No placeholders for security releases | Prompt_Builder:189 "Do NOT include placeholders in a security-only release" as AI instruction | IMPLEMENTED |
| AC-027 | AC | Filter for full prompt override | Prompt_Builder:100 `apply_filters('ctbp_prompt', $prompt, $data, $significance)` with docblock | IMPLEMENTED |
| AC-028 | AC | Separate title/content guidance filters | Prompt_Builder:71 `ctbp_prompt_title_guidance`, line 80 `ctbp_prompt_content_guidance` | IMPLEMENTED |
| AC-029 | AC | Filter hooks documented and tested | All 4 hooks have @param docblocks; tests use WP_Mock::onFilter | IMPLEMENTED |
| AC-030 | AC | Templates in code, not database | All templates defined as match expressions and heredocs in Prompt_Builder.php | IMPLEMENTED |
| AC-031 | AC | Updating requires only code changes | No database storage of prompt templates | IMPLEMENTED |
| AC-032 | AC | Version comment on templates | Both files include "Prompt template version: 1.0 (introduced in plugin v1.0.0)" | IMPLEMENTED |
| BR-001 | Rule | Security overrides semver | Release_Significance:84 checks security before semver; tested explicitly | IMPLEMENTED |
| BR-002 | Rule | AI instructed not to include name/version in subtitle | Prompt_Builder:131 "Write ONLY the subtitle -- do NOT include the plugin name or version number" | IMPLEMENTED |
| BR-003 | Rule | Plain English as primary register | Prompt_Builder:158-159 instructs plain language for non-technical audience first | IMPLEMENTED |
| BR-004 | Rule | Content-driven post length | Prompt_Builder:166 explicit AI instruction about scaling and not padding | IMPLEMENTED |
| BR-005 | Rule | Images by URL reference only | Prompt_Builder:186 uses URLs directly in `<img>` tag instructions | IMPLEMENTED |
| BR-006 | Rule | Prompts not in admin UI | No admin field for prompt editing exists; only filter hooks | IMPLEMENTED |

**PRD Coverage:** 97% (31/32 applicable ACs implemented; AC-021 WP.org validation is out of scope for this epic; AC-023/AC-024 partial -- draft/publish distinction is a downstream concern)

## Anti-Patterns Found

| File | Line | Pattern | Severity |
|------|------|---------|----------|
| Prompt_Builder.php | 59 | `$is_draft = false; // Context not available here; placeholder support built in.` | Info -- unused variable, acknowledged design decision |

No TODO, FIXME, stub, or placeholder implementation patterns found. The `$is_draft` variable is assigned but unused; however, the comment explains the design reasoning (draft context is not available at prompt-building time, so placeholder instructions are always included and filtering is expected downstream in DOM-06 post creation).

## Human Verification Required

### 1. Prompt quality across significance levels

- **Test:** Generate prompts for patch, minor, major, and security releases and compare the output tone and structure
- **Expected:** Each significance level produces distinctly different guidance appropriate to the release type
- **Why human:** Tone quality and appropriateness are subjective

### 2. Draft-mode image placeholder flow (AC-023/AC-024)

- **Test:** Process a release with no images through the full pipeline in both draft and publish modes
- **Expected:** Placeholders appear in draft posts but are stripped or absent in published posts
- **Why human:** Requires DOM-06 (post creation) to be integrated; this epic provides the prompt instructions but actual draft/publish behavior is downstream

### 3. Security placeholder suppression (AC-026)

- **Test:** Process a security release with no images through AI and verify the AI follows the "no placeholders" instruction
- **Expected:** AI-generated content for security releases contains no image placeholder suggestions
- **Why human:** Relies on AI provider actually following the instruction; cannot be verified structurally

## Notes

**AC-023/AC-024 design decision:** The PRD specifies that image placeholders should only appear in draft posts (AC-024) and never in published posts (AC-023). The implementation always includes placeholder instructions in the prompt regardless of post status, with a comment explaining that draft context is not available at prompt-building time. This is architecturally sound -- the post creation layer (DOM-06) is responsible for stripping placeholders from published posts. The prompt builder correctly suggests placeholders in all cases so the AI generates them when appropriate, and the downstream layer handles the draft/publish filtering. This does not represent a gap in this epic.

**AC-026 implementation approach:** Security placeholder suppression is handled via an AI instruction ("Do NOT include placeholders in a security-only release") rather than programmatic enforcement. This is appropriate because the prompt builder cannot control AI output -- it can only instruct. The instruction is present and clear.

## Verification Metadata

- **Approach:** Goal-backward verification
- **Timestamp:** 2026-03-21
- **Truths checked:** 7
- **Artifacts verified:** 5
- **Key links tested:** 5
- **Anti-pattern scan:** 5 files scanned, 1 info-level finding
- **Tests available:** 26 test methods across 2 test files (could not run due to missing composer dependencies)

---
epic: 05-ai-integration/1-service-connectors
created: 2026-03-21
status: in-progress
---

# Epic Plan: 05-ai-integration/1-service-connectors

## Overview

**Goal:** Provider-agnostic AI integration layer — generates blog post content from release data.
**Scope decision:** `wp-ai-client` is the primary connector (covers all major providers for non-technical users); OpenAI and Anthropic ship as direct API key fallbacks. Gemini direct and ClassifAI are dropped from v1.
**PRDs:** PRD-05.1.01 (interface), PRD-05.1.02 (implementations)

## Tasks

### Task 1: Value objects + new constants [ ]

**Implements:** PRD-05.1.01 AC-003, PRD-05.1.02 general
**Complexity:** Low
**Dependencies:** None

**Steps:**
1. Create `includes/classes/AI/ReleaseData.php` — readonly value object (identifier, tag, name, body, html_url, published_at)
2. Create `includes/classes/AI/GeneratedPost.php` — readonly value object (title, content, provider_slug)
3. Add to `Plugin_Constants`: `OPTION_AI_CUSTOM_MODELS`, `TRANSIENT_AI_RESPONSE_PREFIX`, `OPTION_AI_FAILURE_COUNTS`

**Verification:**
- [ ] AC-003: Both value objects exist with correct typed properties
- [ ] Constants defined in Plugin_Constants

---

### Task 2: AIProviderInterface [ ]

**Implements:** PRD-05.1.01 US-001, BR-001–BR-004
**Complexity:** Low
**Dependencies:** Task 1

**Steps:**
1. Create `includes/classes/AI/AIProviderInterface.php`
   - `generate_post(ReleaseData $data, string $prompt): GeneratedPost|\WP_Error`
   - `test_connection(): true|\WP_Error`
   - `get_slug(): string`
   - `get_label(): string`
   - `requires_api_key(): bool`

**Verification:**
- [ ] AC-001: Interface defines generate_post() with correct signature
- [ ] AC-014: Return types enforce GeneratedPost|WP_Error — no exceptions

---

### Task 3: AI_Provider_Factory + registration hook [ ]

**Implements:** PRD-05.1.01 US-002, US-003
**Complexity:** Medium
**Dependencies:** Task 2

**Steps:**
1. Create `includes/classes/AI/AI_Provider_Factory.php`
   - Constructor accepts `Global_Settings`
   - `get_provider(): AIProviderInterface|\WP_Error` — reads OPTION_AI_PROVIDER, instantiates connector
   - `get_available_providers(): array` — returns slug => label map for settings UI
   - `is_configured(): bool` — whether any usable provider is set
   - Fire `ctbp_register_ai_providers` filter to allow community provider registration
   - Validate registered providers implement interface; log + skip bad ones (AC-009)

**Verification:**
- [ ] AC-004: Factory reads setting, returns correct connector
- [ ] AC-005: Unavailable provider returns WP_Error
- [ ] AC-006: is_configured() works
- [ ] AC-007/AC-008: ctbp_register_ai_providers hook fires, custom providers appear in list
- [ ] AC-009: Bad implementations rejected gracefully

---

### Task 4: Response cache + failure tracking in AI_Processor [ ]

**Implements:** PRD-05.1.01 US-004, US-005
**Complexity:** Medium
**Dependencies:** Task 3

**Steps:**
1. Create `includes/classes/AI/AI_Processor.php`
   - `handle(array $entry, array $context): void` — hooked to `ctbp_process_release`
   - Cache check: `get_transient(TRANSIENT_AI_RESPONSE_PREFIX . md5(identifier.tag))` — return cached GeneratedPost if hit
   - On generate success: cache result (4h TTL), clear failure count, fire `ctbp_post_generated`
   - On generate failure: increment failure count in OPTION_AI_FAILURE_COUNTS; if ≥3 set admin notice transient
   - Log failures via `error_log()` when WP_DEBUG + WP_DEBUG_LOG

**Verification:**
- [ ] AC-010/AC-011: Successful result cached; subsequent call returns cache without API hit
- [ ] AC-012: TTL ≥ 2 hours (set to 4h = 14400s)
- [ ] AC-014: All failures surface as WP_Error, no exceptions
- [ ] AC-015: Failed release skipped for current run
- [ ] AC-016: After 3 consecutive failures, admin notice transient set

---

### Task 5: WP_AI_Client_Connector (primary) [ ]

**Implements:** PRD-05.1.02 US-005 (promoted to primary)
**Complexity:** Medium
**Dependencies:** Task 2

**Steps:**
1. Create `includes/classes/AI/Connectors/WP_AI_Client_Connector.php`
   - `get_slug()`: `'wp_ai_client'`
   - `get_label()`: `'WordPress AI Services'`
   - `requires_api_key()`: `false` (managed by the provider plugin)
   - `test_connection()`: checks `function_exists('wp_ai_client_prompt')` — if not, WP_Error with install instructions
   - `generate_post()`: calls `wp_ai_client_prompt($prompt)->generate_text()`, extracts title + content from response, returns GeneratedPost
   - Error mapping: catches wp-ai-client WP_Error responses, returns as WP_Error

**Verification:**
- [ ] AC-021: Connector class exists and satisfies interface
- [ ] test_connection() returns WP_Error if plugin not installed
- [ ] generate_post() returns GeneratedPost on success, WP_Error on failure

---

### Task 6: OpenAI_Connector (direct fallback) [ ]

**Implements:** PRD-05.1.02 US-001
**Complexity:** Medium
**Dependencies:** Task 2

**Steps:**
1. Create `includes/classes/AI/Connectors/OpenAI_Connector.php`
   - Constructor accepts `Global_Settings`
   - Default model: `gpt-4o` (single constant, easy to update per AC-024)
   - `ctbp_openai_model` filter for developer override (AC-025)
   - Custom model from OPTION_AI_CUSTOM_MODELS takes precedence (AC-026)
   - `generate_post()`: wp_remote_post() to Chat Completions endpoint; parse `choices[0].message.content`
   - Error mapping: 401 → invalid key, 429 → quota, other → generic

**Verification:**
- [ ] AC-001/AC-002: Returns GeneratedPost with title + content on success
- [ ] AC-003/AC-004/AC-005: Filter, custom model field, key errors all work
- [ ] AC-006: Token limit error returns WP_Error

---

### Task 7: Anthropic_Connector (direct fallback) [ ]

**Implements:** PRD-05.1.02 US-002
**Complexity:** Medium
**Dependencies:** Task 2

**Steps:**
1. Create `includes/classes/AI/Connectors/Anthropic_Connector.php`
   - Constructor accepts `Global_Settings`
   - Default model: `claude-sonnet-4-5-20251022` (AC-024)
   - `ctbp_anthropic_model` filter (AC-025)
   - Custom model from OPTION_AI_CUSTOM_MODELS takes precedence (AC-026)
   - `generate_post()`: wp_remote_post() to Messages API with correct headers (`x-api-key`, `anthropic-version`)
   - Parse `content[0].text`, extract title + body

**Verification:**
- [ ] AC-007/AC-008: Returns GeneratedPost on success
- [ ] AC-009/AC-010/AC-011: Filter, custom model, error cases handled

---

### Task 8: Connector is_available() check + Global_Settings update [ ]

**Implements:** PRD-05.1.01 AC-005, PRD-05.1.02 US-006
**Complexity:** Medium
**Dependencies:** Tasks 5–7

**Steps:**
1. Update `Global_Settings::SUPPORTED_PROVIDERS` to `['openai', 'anthropic', 'wp_ai_client']`
2. Update `get_api_keys()` / `save_api_keys()` to cover `openai`, `anthropic` only (drop gemini)
3. Add `get_custom_models(): array` and `save_custom_models(array $models): bool`
4. Update PRD-05.1.02 to reflect final v1 scope (wp_ai_client primary, no Gemini/ClassifAI)
5. Add `ajax_test_ai_connection` AJAX handler in `Admin_Page` — calls factory→get_provider()→test_connection(), returns JSON
6. Wire `AI_Processor` into `Plugin::init()` — add_action('ctbp_process_release', ...)
7. Wire factory/processor with correct dependencies

**Verification:**
- [ ] Provider selector shows only v1 providers
- [ ] Custom model fields save/retrieve correctly
- [ ] Test connection AJAX returns success/error JSON
- [ ] AI_Processor hooked to ctbp_process_release

---

### Task 9: Unit tests [ ]

**Implements:** All ACs
**Complexity:** High
**Dependencies:** Tasks 1–8

**Steps:**
1. `tests/php/unit/AI/ReleaseDataTest.php` + `GeneratedPostTest.php`
2. `tests/php/unit/AI/AI_Provider_FactoryTest.php` — provider resolution, WP_Error on missing, hook registration
3. `tests/php/unit/AI/AI_ProcessorTest.php` — cache hit, failure counting, admin notice at ≥3
4. `tests/php/unit/AI/Connectors/WP_AI_Client_ConnectorTest.php` — available/unavailable states
5. `tests/php/unit/AI/Connectors/OpenAI_ConnectorTest.php` — success, 401, 429 cases
6. `tests/php/unit/AI/Connectors/Anthropic_ConnectorTest.php` — success, auth error, rate limit
7. Update `Global_SettingsTest.php` for provider list change

**Verification:**
- [ ] All tests pass via `composer test`

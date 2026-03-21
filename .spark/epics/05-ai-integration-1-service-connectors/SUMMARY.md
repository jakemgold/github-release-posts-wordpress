---
epic: 05-ai-integration/1-service-connectors
completed: 2026-03-21
---

# Epic Summary: 05-ai-integration/1-service-connectors

**Completed:** 2026-03-21

## Key Scope Decision

`wp-ai-client` (official WordPress AI Services SDK) is the **primary** connector. Non-technical users install an official provider plugin (Anthropic, OpenAI, or Google) from WordPress.org and configure credentials once under Settings > AI Services — no separate API key needed in this plugin. OpenAI and Anthropic ship as direct API key fallbacks for developers. Gemini direct and ClassifAI dropped from v1.

## Tasks Completed

| # | Task | Status |
|---|------|--------|
| 1 | `ReleaseData` + `GeneratedPost` value objects + new constants | ✓ |
| 2 | `AIProviderInterface` | ✓ |
| 3 | `AI_Provider_Factory` + `ctbp_register_ai_providers` hook | ✓ |
| 4 | Response cache (4h transient) + failure tracker (≥3 → admin notice) in `AI_Processor` | ✓ |
| 5 | `AI_Processor` — wired to `ctbp_process_release` | ✓ |
| 6 | `WP_AI_Client_Connector` (primary) | ✓ |
| 7 | `OpenAI_Connector` (direct fallback) | ✓ |
| 8 | `Anthropic_Connector` (direct fallback) | ✓ |
| 9 | Settings wiring + AJAX handler + Plugin bootstrap + tests | ✓ |

## Requirements Delivered

| REQ-ID | Requirement | Notes |
|--------|-------------|-------|
| PRD-05.1.01 AC-001 | Single generate_post() call regardless of provider | AIProviderInterface |
| PRD-05.1.01 AC-002 | Swap provider without code changes | Factory + settings |
| PRD-05.1.01 AC-003 | Canonical input (ReleaseData) | Value object |
| PRD-05.1.01 AC-004 | Factory reads setting, returns connector | AI_Provider_Factory |
| PRD-05.1.01 AC-005 | Unavailable provider → WP_Error | Factory + connectors |
| PRD-05.1.01 AC-006 | is_configured() check | Factory |
| PRD-05.1.01 AC-007–AC-009 | ctbp_register_ai_providers hook; bad impls rejected | Factory |
| PRD-05.1.01 AC-010–AC-012 | 4h response cache, cache hit skips API | AI_Processor |
| PRD-05.1.01 AC-014 | WP_Error only, no exceptions | All connectors |
| PRD-05.1.01 AC-015 | Failed release skipped this run | AI_Processor |
| PRD-05.1.01 AC-016 | ≥3 failures → admin notice transient | AI_Processor |
| PRD-05.1.01 AC-017 | Success clears failure count | AI_Processor |
| PRD-05.1.02 US-001 (AC-001–006) | OpenAI connector | OpenAI_Connector |
| PRD-05.1.02 US-002 (AC-007–011) | Anthropic connector | Anthropic_Connector |
| PRD-05.1.02 US-005 (AC-021–023) | WP AI Services connector (promoted to primary) | WP_AI_Client_Connector |
| PRD-05.1.02 US-006 (AC-024–027) | Model defaults + filter + custom field | Per-connector |

## Files Changed

**New:**
- `includes/classes/AI/ReleaseData.php`
- `includes/classes/AI/GeneratedPost.php`
- `includes/classes/AI/AIProviderInterface.php`
- `includes/classes/AI/AI_Provider_Factory.php`
- `includes/classes/AI/AI_Processor.php`
- `includes/classes/AI/Connectors/WP_AI_Client_Connector.php`
- `includes/classes/AI/Connectors/OpenAI_Connector.php`
- `includes/classes/AI/Connectors/Anthropic_Connector.php`
- `tests/php/unit/AI/ReleaseDataTest.php`
- `tests/php/unit/AI/GeneratedPostTest.php`
- `tests/php/unit/AI/AI_Provider_FactoryTest.php`
- `tests/php/unit/AI/AI_ProcessorTest.php`
- `tests/php/unit/AI/Connectors/WP_AI_Client_ConnectorTest.php`
- `tests/php/unit/AI/Connectors/OpenAI_ConnectorTest.php`
- `tests/php/unit/AI/Connectors/Anthropic_ConnectorTest.php`

**Modified:**
- `includes/classes/Plugin_Constants.php` — added AI option/transient keys
- `includes/classes/Plugin.php` — added AI_Processor to init()
- `includes/classes/Settings/Global_Settings.php` — updated SUPPORTED_PROVIDERS; added get/save_custom_models(); removed gemini from key handling
- `includes/classes/Admin/Admin_Page.php` — ajax_test_ai_connection() implemented

## Integration Points

- `ctbp_process_release` — AI_Processor is now listening; fires `ctbp_post_generated` on success
- `ctbp_post_generated` — DOM-06 will hook here to create the WordPress post
- `ctbp_generate_prompt` — EPC-05.2 will hook here to supply the real prompt
- `ctbp_register_ai_providers` — community developers can add custom connectors

## Next Steps

- Plan and execute `05-ai-integration/2-prompt-management` (EPC-05.2)

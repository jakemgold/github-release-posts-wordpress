---
title: "PRD-05.1.02: AI Provider Implementations"
code: PRD-05.1.02
epic: EPC-05.1
domain: DOM-05
status: approved
created: 2026-03-20
updated: 2026-03-20
---

# PRD-05.1.02: AI Provider Implementations

**Epic:** Service Connectors (EPC-05.1) — AI Integration (DOM-05)
**Status:** Approved
**Created:** 2026-03-20

---

## Problem Statement

With the provider interface defined, the plugin needs concrete implementations for each supported AI service. Site owners have different AI preferences and existing setups — some have OpenAI API keys, others prefer Anthropic or Gemini, and 10up shops likely have ClassifAI already configured. Each connector must handle its provider's specific authentication, HTTP request format, response parsing, and error conditions, while presenting an identical face to the rest of the plugin.

---

## Target Users

- **Site owners** — choose their preferred AI provider in settings; the connector handles everything else
- **10up agency clients** — get zero-config AI generation if ClassifAI is already active
- **Third-party developers** — reference implementations to follow when building custom connectors

---

## Overview

Five concrete provider implementations, each satisfying the `AIProviderInterface`. Three direct-key providers (OpenAI, Anthropic, Gemini) cover the majority of site owners. A ClassifAI connector piggybacks on an existing ClassifAI installation. A WordPress AI API connector is implemented as a stub, ready to be activated when the WordPress AI API reaches sufficient stability. Each provider ships with a sensible model default that can be overridden via filter hook or a custom model field in settings.

---

## Supported Providers (v1)

| Provider | Type | Requires | Default Model |
|----------|------|----------|---------------|
| OpenAI | Direct API key | OpenAI API key | Determined at implementation time; updated with plugin releases |
| Anthropic | Direct API key | Anthropic API key | Determined at implementation time; updated with plugin releases |
| Google Gemini | Direct API key | Google AI API key | Determined at implementation time; updated with plugin releases |
| ClassifAI | Plugin integration | 10up ClassifAI active + configured | Delegated to ClassifAI settings |
| WordPress AI API | Stub | WordPress AI API (when stable) | N/A (stub) |

---

## User Stories & Acceptance Criteria

### US-001: OpenAI connector generates a blog post

As a site owner with an OpenAI API key, I want the plugin to use GPT to generate blog post content from a GitHub release, so I can use the AI service I'm already paying for.

**Acceptance Criteria:**

- [ ] **AC-001:** Given a valid OpenAI API key and release data, the connector returns a generated post with a title and HTML body content.
- [ ] **AC-002:** The connector uses the Chat Completions endpoint with the configured default model.
- [ ] **AC-003:** A filter hook allows developers to override the model used for OpenAI without modifying plugin code.
- [ ] **AC-004:** An advanced settings field allows site owners to enter a custom model ID, which takes precedence over the default.
- [ ] **AC-005:** An invalid or expired API key returns a `WP_Error` with a message that helps the site owner diagnose the problem.
- [ ] **AC-006:** Token limits or quota exhaustion returns a `WP_Error` and logs the failure without crashing.

---

### US-002: Anthropic connector generates a blog post

As a site owner who prefers Claude, I want the plugin to use the Anthropic API to generate blog post content.

**Acceptance Criteria:**

- [ ] **AC-007:** Given a valid Anthropic API key and release data, the connector returns a generated post with a title and HTML body content.
- [ ] **AC-008:** The connector uses the Messages API with the configured default model.
- [ ] **AC-009:** A filter hook allows developers to override the model used for Anthropic.
- [ ] **AC-010:** An advanced settings field allows site owners to enter a custom model ID.
- [ ] **AC-011:** API errors (invalid key, rate limit, quota) return a `WP_Error` with a descriptive message.

---

### US-003: Google Gemini connector generates a blog post

As a site owner in the Google ecosystem, I want the plugin to use the Gemini API to generate blog post content.

**Acceptance Criteria:**

- [ ] **AC-012:** Given a valid Google AI API key and release data, the connector returns a generated post with a title and HTML body content.
- [ ] **AC-013:** The connector uses the Gemini Generate Content endpoint with the configured default model.
- [ ] **AC-014:** A filter hook allows developers to override the model used for Gemini.
- [ ] **AC-015:** An advanced settings field allows site owners to enter a custom model ID.
- [ ] **AC-016:** API errors return a `WP_Error` with a descriptive message.

---

### US-004: ClassifAI connector delegates to an existing installation

As a site owner (or 10up agency client) with ClassifAI already configured, I want the plugin to use my existing ClassifAI AI connection so I don't have to set up a separate API key.

**Acceptance Criteria:**

- [ ] **AC-017:** When ClassifAI is active and has an AI provider configured, the ClassifAI option appears as a selectable provider in plugin settings.
- [ ] **AC-018:** When ClassifAI is not active or not configured, the ClassifAI option is either hidden or shown as unavailable with an explanation.
- [ ] **AC-019:** The connector delegates generation to ClassifAI's text generation feature and maps the response to the standard generated post structure.
- [ ] **AC-020:** If ClassifAI is deactivated after being selected as the provider, the next pipeline run returns a clear `WP_Error` directing the site owner to update their provider setting.

---

### US-005: WordPress AI API connector is ready for future activation

As a plugin maintainer, I want a WordPress AI API connector to exist in the codebase so it can be activated with minimal effort when the WordPress AI API is sufficiently stable.

**Acceptance Criteria:**

- [ ] **AC-021:** A WordPress AI API connector class exists and satisfies the provider interface.
- [ ] **AC-022:** In v1, the connector is not shown as a selectable option in settings (it is not yet active).
- [ ] **AC-023:** Activating the connector in a future release requires only a settings registration change — no structural code changes.

---

### US-006: Model defaults are maintainable and overridable

As a plugin maintainer, I want model defaults to be easy to update with each plugin release, and as a developer I want to override them without patching plugin files.

**Acceptance Criteria:**

- [ ] **AC-024:** Each provider's default model ID is defined in a single, clearly-labelled location in code — changing it for a plugin release requires editing one value per provider.
- [ ] **AC-025:** A per-provider filter hook allows overriding the default model ID at runtime.
- [ ] **AC-026:** A custom model ID entered in settings always takes precedence over both the filter and the default.
- [ ] **AC-027:** When a provider's default model is updated in a new plugin release, existing installations that haven't set a custom model ID automatically use the new default.

---

## Business Rules

- **BR-001:** All external HTTP requests use `wp_remote_post()` — no external SDK libraries.
- **BR-002:** API keys are retrieved from encrypted storage (via DOM-03) and never hardcoded, logged, or exposed in responses.
- **BR-003:** Every connector must return either a structured generated post object or `WP_Error` — no other return types are permitted.
- **BR-004:** The ClassifAI connector must gracefully degrade if ClassifAI's internal API changes between versions — a failed delegation returns `WP_Error`, not a fatal error.
- **BR-005:** No connector may add its own caching — caching is handled exclusively by the interface layer (PRD-05.1.01).

---

## Out of Scope

- The provider interface, factory, caching, and failure-handling pattern (PRD-05.1.01)
- Prompt content and template design (EPC-05.2)
- AI provider selection UI and API key input fields (DOM-03)
- Post creation from generated content (DOM-06)
- Built-in support for free-tier services (Groq, Ollama, OpenRouter) — available via community registration hook documented in PRD-05.1.01

---

## Dependencies

| Depends On | For |
|------------|-----|
| PRD-05.1.01 | Interface contract that all connectors implement |
| DOM-03 Settings | API keys and custom model IDs retrieved from encrypted settings |
| EPC-05.2 Prompt Management | Pre-built prompt string passed into each connector |

---

## Open Questions

None.

---

_Managed by Spark_

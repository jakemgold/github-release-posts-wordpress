---
title: "PRD-05.1.01: AI Provider Interface"
code: PRD-05.1.01
epic: EPC-05.1
domain: DOM-05
status: approved
created: 2026-03-20
updated: 2026-03-20
---

# PRD-05.1.01: AI Provider Interface

**Epic:** Service Connectors (EPC-05.1) — AI Integration (DOM-05)
**Status:** Approved
**Created:** 2026-03-20

---

## Problem Statement

The plugin needs to generate blog post content using AI, but the right AI provider will vary by site owner — some prefer OpenAI, others Anthropic or Gemini, and 10up shops may already have ClassifAI configured. Without a provider-agnostic interface, swapping or adding AI services would require changes throughout the codebase. The interface layer solves this by defining a single contract that all providers implement, so the rest of the plugin never knows or cares which AI is running underneath.

---

## Target Users

- **The plugin pipeline** — calls the interface to generate post content without knowing which provider is active
- **Third-party developers** — register custom provider implementations via hook without modifying plugin code
- **Site owners (indirectly)** — benefit from reliable AI generation that recovers gracefully from failures

---

## Overview

Defines the common contract (`AIProviderInterface`) that all AI provider connectors must implement, the factory that instantiates the correct connector based on settings, a registration hook for community-contributed providers, a transient-based response cache to avoid duplicate API calls, and the failure-handling pattern used across all providers.

---

## User Stories & Acceptance Criteria

### US-001: Consistent interface across all providers

As the plugin pipeline, I want to call a single method to generate a blog post regardless of which AI provider is configured, so that provider-specific logic is completely isolated from the rest of the codebase.

**Acceptance Criteria:**

- [ ] **AC-001:** The pipeline calls a single `generate_post()` method and receives either a structured post object (title + content) or a `WP_Error` — regardless of which provider is active.
- [ ] **AC-002:** Swapping the AI provider in plugin settings requires no code changes outside the factory and settings layer.
- [ ] **AC-003:** All providers receive the same input: structured release data (repo name, release tag, release body, release URL, significance level).

---

### US-002: Provider factory resolves the active connector

As the plugin, I want the correct provider connector to be automatically instantiated based on what the site owner has configured, so I never need to hardcode provider selection.

**Acceptance Criteria:**

- [ ] **AC-004:** The factory reads the configured provider from settings and returns the appropriate connector instance.
- [ ] **AC-005:** If the configured provider is unavailable (e.g. ClassifAI selected but not active), the factory returns a `WP_Error` with a clear explanation rather than a broken connector.
- [ ] **AC-006:** The factory exposes a way to check whether any usable provider is currently configured before attempting generation.

---

### US-003: Community providers can be registered

As a third-party developer, I want to register a custom AI provider implementation so that I can add support for services the plugin doesn't natively support (e.g. Groq, Ollama, OpenRouter).

**Acceptance Criteria:**

- [ ] **AC-007:** A documented registration hook allows developers to add a custom provider class that implements the provider interface.
- [ ] **AC-008:** Registered providers appear alongside built-in providers in the AI provider selector in plugin settings.
- [ ] **AC-009:** A registered provider that does not correctly implement the interface is rejected with a logged error, not a fatal crash.

---

### US-004: Response caching prevents duplicate API calls

As a site owner, I want AI generation for the same release to only happen once, so I'm not charged for duplicate API calls if the pipeline runs multiple times.

**Acceptance Criteria:**

- [ ] **AC-010:** A successful generation response is cached in a transient keyed by the release identifier (repo + tag).
- [ ] **AC-011:** Subsequent pipeline runs for the same release return the cached response without making a new API call.
- [ ] **AC-012:** The cache TTL is long enough to cover typical cron retry windows (minimum 2 hours).
- [ ] **AC-013:** The cache is bypassed and invalidated if a site owner manually triggers a regeneration (a future capability — the interface should support it).

---

### US-005: Failures are handled gracefully with retry

As a site owner, I want the plugin to recover automatically from AI provider failures without breaking my site or spamming me with errors.

**Acceptance Criteria:**

- [ ] **AC-014:** All provider failures return `WP_Error` — no exceptions are ever thrown.
- [ ] **AC-015:** A failed generation is logged and the release is skipped for the current cron run; generation will be re-attempted on the next scheduled run.
- [ ] **AC-016:** After 3 consecutive failed generation attempts for the same release, a persistent admin notice is displayed explaining which release and provider failed, with a link to settings.
- [ ] **AC-017:** A successful generation clears the consecutive failure count for that release.

---

## Business Rules

- **BR-001:** Every provider implementation must satisfy the full interface contract. Partial implementations are not permitted.
- **BR-002:** The interface accepts only the canonical release data structure as input and returns only the canonical generated post structure as output — providers may not extend or alter these contracts.
- **BR-003:** Generation is always asynchronous (runs inside WP-Cron) — the interface must never be called on a page load.
- **BR-004:** All external HTTP calls within any provider must use WordPress's HTTP API, not cURL or external SDKs.

---

## Out of Scope

- Prompt content and templates (EPC-05.2)
- Concrete provider implementations — OpenAI, Anthropic, Gemini, ClassifAI (PRD-05.1.02)
- AI provider selection UI and API key storage (DOM-03)
- Post creation from generated content (DOM-06)

---

## Dependencies

| Depends On | For |
|------------|-----|
| DOM-03 Settings | Active provider slug and credentials passed to factory |
| EPC-04.2 Release Monitoring | Release data structure passed as input |
| EPC-05.2 Prompt Management | Prompt content passed to providers (interface receives pre-built prompt) |

| Depended On By | For |
|----------------|-----|
| PRD-05.1.02 | Implementations conform to this interface |
| DOM-06 Post Generation | Receives generated post output from this layer |

---

## Open Questions

None.

---

_Managed by Spark_

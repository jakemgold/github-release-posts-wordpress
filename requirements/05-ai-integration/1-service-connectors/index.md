---
title: "EPC-05.1: Service Connectors"
---

# Epic: Service Connectors

**Code:** EPC-05.1
**Domain:** AI Integration (DOM-05)
**Description:** Provider-agnostic PHP interface and concrete implementations for each supported AI service.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

Defines `AIProviderInterface` and implements it for each supported AI service. The plugin calls the interface; the concrete implementation handles provider-specific HTTP calls, authentication, and response parsing. Adding a new AI provider in future requires only a new implementation class.

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-05.1.01 | ai-provider-interface | draft | Interface contract, factory, registration hook, caching, failure handling |
| PRD-05.1.02 | ai-provider-implementations | draft | OpenAI, Anthropic, Gemini, ClassifAI, and WordPress AI API stub connectors |

## Refinement Session

**Status:** Complete (2 of 2 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--05-ai-integration--1-service-connectors.json`
**Last Updated:** 2026-03-20

## Epic Scope

**In Scope:**
- `AIProviderInterface` with `generate_post(ReleaseData $data): GeneratedPost|WP_Error`
- `OpenAIConnector` — Chat Completions API (gpt-4o or configured model)
- `WordPressAIConnector` — WordPress.org AI API connector (when available/stable)
- `ClassifAIConnector` — 10up ClassifAI plugin integration (if active)
- Provider factory: instantiate correct connector based on settings
- Error handling: API failures return `WP_Error`, never throw exceptions
- Response caching: transient cache to avoid duplicate API calls for same release

**Out of Scope:**
- Prompt content (EPC-05.2)
- AI provider settings UI (DOM-03)

## Success Criteria

- [ ] Swapping AI provider in settings uses a different connector with no code change
- [ ] All connectors return identically-structured `GeneratedPost` objects
- [ ] API errors logged and surfaced gracefully to admin

---

_Managed by Spark_

---
title: "DOM-05: AI Integration"
---

# Domain: AI Integration

**Code:** DOM-05
**Description:** AI service connectors, prompt engineering, and the content generation pipeline that turns release data into a draft blog post.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

Abstracts AI provider communication behind a common interface so the plugin can support multiple services (WordPress AI API, ClassifAI, OpenAI/ChatGPT, and potentially free-tier services). Takes raw GitHub release data as input and returns a structured blog post (title + body) as output. Prompt design ensures posts read as human-friendly summaries — not raw changelogs — with tone calibrated to release significance (minor patch vs. major release).

## Epics

| Code | Epic | Description | PRDs | Status |
|------|------|-------------|------|--------|
| EPC-05.1 | service-connectors | Provider-agnostic interface + implementations for each AI service | 0 | planned |
| EPC-05.2 | prompt-management | Prompt templates, significance detection, title generation rules | 0 | planned |

## Domain Boundaries

**In Scope:**
- `AIProviderInterface` defining `generate_post(ReleaseData): GeneratedPost`
- Implementations: WordPress AI API connector, OpenAI connector, ClassifAI connector
- Prompt templates stored as filterable constants/options
- Significance classification (patch / minor / major) based on semver and changelog content
- Token/rate limit handling per provider
- Fallback behavior when AI is unavailable

**Out of Scope:**
- AI provider selection and API key storage (DOM-03)
- Creating the WordPress post (DOM-06)
- GitHub data fetching (DOM-04)

## Cross-Domain Dependencies

| Depends On | For |
|------------|-----|
| DOM-03 | Selected AI provider, API credentials |
| DOM-04 | `ReleaseData` input (tag, body, URL, repo name) |

| Depended On By | For |
|----------------|-----|
| DOM-06 | `GeneratedPost` (title + content) passed to post creator |

---

_Managed by Spark_

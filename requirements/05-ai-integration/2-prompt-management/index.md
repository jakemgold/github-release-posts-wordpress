---
title: "EPC-05.2: Prompt Management"
---

# Epic: Prompt Management

**Code:** EPC-05.2
**Domain:** AI Integration (DOM-05)
**Description:** Prompt templates, release significance classification, and title generation rules that produce human-readable, appropriately-toned blog posts.

**Created:** 2026-03-20 | **Last Updated:** 2026-03-20

---

## Overview

Designs and manages the prompts sent to the AI service. Classifies each release as a patch, minor update, or major release (using semver + changelog analysis) and selects prompt tone accordingly. Title generation rules ensure posts always include the plugin name, version, and a plain-language significance signal (e.g. "A minor patch" vs. "A major new release").

## Features (PRDs)

| Code | Feature | Status | Description |
|------|---------|--------|-------------|
| PRD-05.2.01 | prompt-management | draft | Significance classifier, prompt templates, title generation, image handling, filter hooks |

## Refinement Session

**Status:** Complete (1 of 1 PRDs)
**Session:** `.spark/planning/sessions/refine-epic--05-ai-integration--2-prompt-management.json`
**Last Updated:** 2026-03-20

## Epic Scope

**In Scope:**
- Significance classifier: patch (x.x.N) / minor (x.N.x) / major (N.x.x) based on semver tag and changelog keywords (e.g. "breaking change", "new feature", "security fix")
- System prompt: instructs AI to write for a general WordPress user audience, avoid jargon, include a download/changelog link
- Title prompt: rules for format "[Plugin Name] [Version] — [Significance Signal]" (e.g. "My Plugin 2.1.0 — A Notable Update")
- Content prompt: structure (intro paragraph, what's new section, link to full changelog / download)
- `changelog_to_blog_post_prompt` filter hook — allows site owner to customize prompts
- Prompt versioning: stored in code as constants, not database (changes tracked in git)

**Out of Scope:**
- Sending prompts to the API (EPC-05.1)
- Post creation (DOM-06)

## Success Criteria

- [ ] Major releases produce noticeably more enthusiastic/detailed posts than patches
- [ ] All generated titles include plugin name + version
- [ ] Filter hook documented and tested
- [ ] No hardcoded prompt strings outside the defined constants/templates

---

_Managed by Spark_

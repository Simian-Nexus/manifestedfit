# Hermes Social Agent Workflow Brief

Last updated: 2026-05-03

Related project:

```text
D:\JonoFiles\Projects\01_Business\Spinning_Monkey_Studios\02_Active_Projects\AI_Command_Bridge
```

## Purpose

Use the Hermes / AI Command Bridge project to design a supervised agent team that helps Manifested Fit build affiliate-application readiness.

This should be a social media assistance workflow, not an unsupervised posting machine.

## Current Hermes Context

From AI Command Bridge current state:

- Hermes Agent is installed in WSL at `/home/aquar/.hermes/hermes-agent`.
- The `hermes` command is linked at `/home/aquar/.local/bin/hermes`.
- Hermes uses Windows Ollama through `http://host.docker.internal:11434/v1`.
- The starter model is `llama3.1:8b`.
- Chrome DevTools MCP is configured for controlled browser-assisted reasoning.
- Agent Studio exists as a non-executing registry/control surface in the WordPress plugin.
- Agent Studio has draft tables for projects, agents, prompts, hierarchy, schedules, and listeners.
- No Hermes execution, cron runner, connector execution, shell/file actions, or local PC control is wired through Agent Studio yet.

## Safety Posture

Keep the workflow supervised:

- Agents draft, research, summarize, and propose.
- The user approves brand voice, account changes, publishing, and applications.
- Do not enter credentials, submit affiliate applications, publish posts, or change account settings without explicit approval.
- Do not store passwords or affiliate account details in prompts, docs, logs, or frontend assets.

## Proposed Agent Team

### 1. Affiliate Strategist

Goal:

- Find appropriate affiliate programs in the manifestation, wellness, meditation, yoga, journaling, and beginner fitness niche.

Outputs:

- program shortlist
- approval difficulty estimate
- commission/cookie/platform notes
- compliance risks
- recommended application timing

### 2. Brand Voice Editor

Goal:

- Keep Manifested Fit content calm, practical, non-hype, and compliant.

Outputs:

- captions
- short-form scripts
- post hooks
- disclosure-safe CTA variants
- claim-risk review

### 3. Content Calendar Producer

Goal:

- Turn weekly themes into platform-specific assets.

Outputs:

- 30-day content calendar
- YouTube Shorts / TikTok / Reels scripts
- Pinterest pin titles and descriptions
- X/Threads post drafts
- email newsletter drafts

### 4. Pinterest Asset Planner

Goal:

- Create pin concepts and image-generation prompts for Google Flow / Nano Banana.

Outputs:

- pin prompt batches
- title overlays
- description variants
- UTM suggestions

### 5. Social Setup Assistant

Goal:

- Guide account setup and profile consistency across platforms.

Outputs:

- bio variants
- handle availability checklist
- profile image/banner requirements
- link-in-bio plan
- launch checklist

### 6. Metrics And Application Readiness Analyst

Goal:

- Track whether the brand is ready to apply to affiliate programs.

Outputs:

- weekly scorecard
- traffic/list/social growth summary
- screenshots/data to save for applications
- recommended programs to apply to now versus later

## First 30-Day Sprint

Week 1:

- create or finish social accounts
- write bios and profile descriptions
- publish the first 5 Pinterest pins
- publish the first 3 short-form videos
- connect or choose an email platform

Week 2:

- publish 5 more pins
- publish 3 more short-form videos
- create one simple article or resource update
- research 10 lower-barrier affiliate programs

Week 3:

- publish 5 more pins
- publish 3 more short-form videos
- apply to 2 to 3 lower-barrier affiliate programs
- add approved offers to `affiliate-offers.js`

Week 4:

- publish 5 more pins
- publish 3 more short-form videos
- prepare a simple media kit
- review application readiness
- decide whether to wait on Mindvalley or submit a learning application

## Suggested Agent Studio Project

Project name:

```text
Manifested Fit Affiliate Growth
```

Mission:

```text
Build public proof, content consistency, and affiliate-program readiness for Manifested Fit while keeping all publishing, credential entry, and applications behind user approval.
```

Initial agents:

- Affiliate Strategist
- Brand Voice Editor
- Content Calendar Producer
- Pinterest Asset Planner
- Social Setup Assistant
- Metrics And Application Readiness Analyst

Safety tier:

```text
draft / stage_actions only
```

No direct execution until AI Command Bridge has a designed connector and approval path.

## Prompt Seed For Fresh Context

```text
You are helping Manifested Fit become affiliate-application-ready in the manifestation, gentle fitness, and mind-body wellness niche. Current audience is tiny: YouTube has no videos, and most social accounts still need setup. Do not assume affiliate approval exists. Build evidence first: social profiles, consistent content, lead magnet, email platform, resources page, analytics, and lower-barrier affiliate programs. Use Hermes/AI Command Bridge only as a supervised drafting/research/planning team; do not publish, submit applications, enter credentials, or alter accounts without approval.
```


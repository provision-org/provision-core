---
name: provision-publish
description: Publish a reusable skill to your team's Skills library so it appears in the Provision dashboard and can be deployed to other agents.
---

# Provision Publish Skill

Use the provision_publish_tool.js script in {baseDir} to publish a skill you've
authored to your team's Skills library. Published skills appear in the Provision
dashboard's Skills section and can be deployed to other agents on your team.

## Environment

The following environment variables are available:

- PROVISION_API_URL: API base URL for the Provision app
- PROVISION_AGENT_TOKEN: Your API authentication token

## When to Use

Publish a skill when you've worked out a **reusable workflow** worth keeping —
a repeatable process (lead enrichment, a research routine, a report format) that
you or a teammate would run again. Don't publish one-off answers or throwaway
steps.

## Workflow

1. **Write a SKILL.md** describing the workflow: YAML frontmatter (`name`,
   `description`) followed by clear step-by-step instructions, error handling,
   and an example. Save it in your workspace.
2. **Publish it** with a clear name. Re-publishing with the same name updates the
   existing skill and bumps its version.

## Commands

Run from {baseDir} using Node.js.

### Publish a skill from a file

```bash
node {baseDir}/provision_publish_tool.js publish \
  --name "LinkedIn Lead Gen" \
  --file ./SKILL.md \
  --description "Find and enrich leads from LinkedIn" \
  --tags sales,linkedin \
  --tools browser,exec \
  --env LINKEDIN_EMAIL,LINKEDIN_PASSWORD
```

### Publish with inline content

```bash
node {baseDir}/provision_publish_tool.js publish --name "Daily Standup" --content "$(cat SKILL.md)"
```

The tool returns the published skill's slug, version, and dashboard URL.

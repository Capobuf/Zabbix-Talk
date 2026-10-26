---
name: zabbix-frontend-ux
description: Design or change Zabbix Talk's PHP frontend module screens using the native Zabbix UI, with minimal accessible UX and a working preview.
---

# Zabbix Talk frontend / UX

Use this skill whenever the task adds, changes or reviews a Zabbix Talk screen, form, table, wizard step, notification, or user interaction.

## References (local first)
- `AGENTS.md`: mandatory project workflow and user review before push.
- `docs/Zabbix-Talk-Specifica-v0.10.md`: local functional specification/index when present.
- `docs/Zabbix-Talk-Demo-UI-UX.html`: illustrative flows and layout, **not** production code or a pixel-perfect target.
- Current module files under `frontend/` and the actual installed Zabbix frontend version.
- Official Zabbix module documentation: https://www.zabbix.com/documentation/7.4/en/devel/modules

Never request project files from Drive.

## Design decisions
1. Understand the single task the operator needs to complete; read the actual PHP controller, view and related classes before changing them.
2. Prefer Zabbix's existing navigation, forms, tables, modals, message bars, validation feedback and CSS classes. Match the installed version; do not invent component APIs.
3. Place the most useful status, action and next step first. Group related controls; avoid unnecessary cards, descriptions and repeated labels.
4. Use actionable Italian text. Empty, loading, error and success states must say what happened and what can be done next; do not announce success before the API confirms it.
5. Preserve keyboard interaction, visible focus, color contrast, and comprehensibility in light/dark mode. Keep controls usable on ordinary laptop and tablet widths where relevant.
6. Show only settings supported by the implemented behavior. Never display fake data, simulated success, or inert buttons in a production screen.
7. Keep CSS scoped to the module and minimal. Avoid new frontend frameworks, global style overrides, heavy dependencies, animations, and cloned UI libraries.
8. Consider Telegram message UX alongside UI: short content, understandable actions, and clarity about the destination and effect of destructive operations.

## Handoff
Implement one vertical flow and verify that specific flow when available. Before push/PR provide the exact screen/route or a genuine preview URL, how to exercise the new controls, what to expect, and what is not implemented. Wait for user feedback in accordance with `AGENTS.md`.

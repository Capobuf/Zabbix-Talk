# Zabbix Talk — agent working rules

## Sources of truth
- Follow the user's current request and the scope of the relevant GitHub issue.
- Use **only this repository** for project specifications, examples, code, and issue history. **Do not access Google Drive** for this project.
- For feature requirements consult `docs/Zabbix-Talk-Specifica-v0.10.md` when available, the linked source chapters if it is split, and `docs/Zabbix-Talk-Demo-UI-UX.html`. The HTML is **illustrative, not a pixel-perfect design requirement**.
- Read the relevant existing files completely. If a tool truncates output, read the remaining chunks; never assume an ellipsis is part of the file.
- Verify unknown facts from relevant code or official Zabbix/Telegram documentation; never invent APIs, paths, versions, configuration, successful outcomes, or links. If a necessary fact cannot be verified, report the limitation.

## Implementation
- Do exactly what the issue requests, in the smallest coherent **vertical slice** that can be demonstrated on the running Zabbix instance.
- Prefer existing project code, standard libraries, native Zabbix frontend components and APIs, then existing dependencies. Add a library or abstraction only when it solves a concrete requirement better than the native option.
- Avoid speculative architecture, helper layers, frameworks, extra services, broad refactors, feature flags, premature extensibility, and unrelated changes.
- Do not introduce a second monitoring or alerting engine: Zabbix remains authoritative.
- Keep configuration, local bridge, and credentials outside the public frontend directory. Never leak bot tokens, credentials, or secrets to code, logs, screenshots, or Git.
- Keep necessary permission checks, API error handling and authentication. Simplicity does not mean bypassing safety.
- Comment in **English**, and only to explain non-obvious logic or constraints. Do not add filler comments or explanations in code.

## Verification without overengineering
- No ritual pre-/mid-/post-task audit phases. No repository-wide checks, repeated test cycles, duplicated validation, or large test matrices for small changes.
- Do not generate SHA256 manifests, checksum inventories, CDN hashes, file comparison databases, or similar artifacts unless explicitly required for a real technical need.
- Do only the **smallest relevant verification** required to determine whether the modified behavior works. For UI tasks, the primary validation is a visible working flow the user can try.
- Never claim something was tested, installed, pushed or deployed if it was not. Disclose what could not be verified.

## Git workflow and user review
1. **Issue work:** create a dedicated local branch from the current `main`, named `issue/<number>-<short-description>`. Keep one issue's changes in that branch.
2. **Tiny requests without an issue** (e.g. rename one file or folder, fix a label) may be applied directly to `main` when the user requests a small isolated change. Anything material uses a branch. Explicit instructions from the user take priority.
3. Work on the development Zabbix VM when possible, so the updated UI can be inspected **before any push or PR**. The development VM and frontend can use the active working branch; do not silently switch branches or replace another user's active preview.
4. When the vertical slice works locally, **stop before pushing**. Give a short report: what was changed, exactly where in the UI, concrete steps A/B/C to try it, the **real verified** UI URL or path if available, the GitHub issue link, branch name, and any known limitations. Ask for feedback on that working version.
5. Apply requested corrections on the **same branch** and show the updated flow. Implementation ending alone does not authorize publication or completion; the user's approval of the preview does.
6. **Approval of the preview also authorizes completion:** push the feature branch, open a focused PR referencing the issue, merge it, and close the issue without asking for a second confirmation. A short approval such as "per me ok" is sufficient. Respect any explicit limits the user adds to their approval and complete any required checks before merging. Do not treat a PR as something that can be "pushed": the branch is pushed, then a PR is created.
7. If local preview cannot be made available, state that limitation and offer the shortest feasible review method rather than inventing a URL.

## UI/UX
- For any frontend change, apply `.agents/skills/zabbix-frontend-ux/SKILL.md`.
- Use the native Zabbix visual language, controls, navigation, permissions, and feedback conventions. Avoid introducing a second frontend, React/Tailwind just for the module, or unnecessary custom CSS.
- The demo in `docs/Zabbix-Talk-Demo-UI-UX.html` explains intent and layout; adapt it to real capabilities and components instead of copying it.
- Make key tasks discoverable and fast: clear labels in Italian, useful empty/error/success states, visible feedback, consistent forms, and mobile/tablet usability where relevant.
- Do not add decorative components or settings unless they meaningfully improve the requested workflow.

## Simplicity reminder
Use the principle behind [Ponytail](https://github.com/DietrichGebert/ponytail): reuse first, standard/native platform second, smallest necessary custom code last. Ponytail is a reference for decision-making, **not** permission to omit error handling, security, accessibility, or explicit user requirements.

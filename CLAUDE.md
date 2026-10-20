# TBT Translation Drill — Claude Code guidance

Keep this file concise. It is loaded at the start of every Claude Code session.

## Start here

- Work from the task, not from a full-repository scan.
- First inspect `git status`, then read only the files named in the task or located by targeted search.
- Use search to find symbols, selectors, routes, or hooks before opening large files.
- Read only the relevant section of a large file when possible.
- Do not read `README.md` by default. Use it only when the task needs deeper product or architecture context.
- Do not repeatedly reread files already understood; inspect the diff after edits instead.
- Do not change unrelated code, formatting, wording, or styling.

## Project basics

- WordPress 6.x plugin; PHP 8.0+.
- Pure PHP + vanilla JavaScript. No jQuery, build system, CDN, Composer, or npm dependency is required by the plugin.
- Main bootstrap: `translation-drill.php`.
- Server-side logic: `includes/`.
- Frontend/admin assets: `assets/`.
- Standalone logic tests: `tests/`.
- Existing code and `assets/css/tbt-tokens.css` are the source of truth for current visual conventions. Do not redesign or restyle unless the task asks for it.

## Architecture to preserve

- The student page and the teacher page are separate surfaces; keep their asset loading separate (`player.*` vs `teacher.*`).
- `[tbt_drill]` is the student page. `[tbt_drill_builder]` is the teacher page: library and builder on one URL, switched by `?tbtd_new=1` / `?tbtd_edit={id}`.
- The highlight format `[fragment|n]` (n = 1–4) is the contract between PHP (`TBTD_Markup`) and JS (`parseMarkup` / `serialize` in both scripts). Change all three together. Numbers are identities, never renumbered.
- There is no public read route. Students get drill content only inside the server-rendered student page, after the login check. Keep it that way.
- Drafts are visible to their owner and administrators only.
- TBT Notes is optional. The plugin must still work when it is absent. Never query Notes tables directly; use `TBTD_Classes`.
- The OpenAI key and model fall back to TBT Swipe's options (`tbts_api_key`, `tbts_model`). Read them, never write them.
- Teacher-page lists and mutations are owner-scoped, including for administrators. wp-admin is the cross-user oversight surface.
- CSS classes carry the `tbtd-` prefix. TBT-Hub's site-wide sheet claims unprefixed `.tbt-*` names.

## Security rules

- Never expose or commit API keys, credentials, secrets, `.env` data, or real configuration values.
- Keep the OpenAI API key server-side only.
- Authenticated writes must keep nonce, capability, and ownership checks.
- Validate class/lesson ownership when attaching drills.
- Sanitize input and escape rendered output.
- Use `$wpdb->prepare()` for database queries containing values.
- Do not weaken an existing security check merely to make a feature work.

## Coding style

- Follow the surrounding WordPress/PHP style rather than reformatting whole files.
- Preserve existing `TBTD_` class/constant naming and `tbtd_` function naming.
- Prefer small, local changes over new abstractions for one-off behavior.
- Reuse existing helpers and public integration methods before adding parallel logic.
- Do not add a dependency when the same result can be achieved with the existing stack.
- Keep comments for non-obvious decisions and constraints; avoid narrating obvious code.

## Validation

Run the smallest relevant check while working, then all standalone tests before finishing a PHP logic change:

```bash
php tests/test-markup.php
```

For changed PHP files, also run `php -l <file>`.

If a change affects WordPress/Divi behavior that the standalone tests cannot cover, state clearly what still needs browser or live-site verification.

## Git and deployment

- Never merge a PR and never push to `main`. A merge to `main` deploys to the live site; that is Mariusz's decision. Stop at an open PR and report.
- Do not commit directly to `main`; use a focused feature branch unless the user explicitly requests otherwise.
- Keep commits task-focused and descriptive.
- Before finishing, inspect the final diff for accidental unrelated changes.
- Code pushed to `main` is deployed automatically by GitHub Actions over FTPS.
- Markdown-only changes are excluded from automatic deployment.
- Never alter deployment secrets or FTP paths unless the task is specifically about deployment.

## Context discipline

- Prefer targeted search + narrow reads over broad exploration.
- Do not paste large file contents, logs, or test output into the conversation when a short summary is enough.
- If a task is complete, summarize what changed, what was tested, and any remaining manual verification in a few bullets.
- For a new unrelated task, favor a fresh Claude Code session rather than carrying a long old conversation forward.

# Frontend selection and submission update — 9 November 2026

## Scope and status

Updates both `frontend/index.html` and `frontend/wordpress-embed.html`. This is a repository update, not a WordPress or n8n production deployment. Backend workflow, routing criteria, credentials, partner contacts and referral URLs are unchanged. No production webhooks were called and no referral emails were sent during testing.

## Diagnosis: confirmed vs suspected

The original source uses correctly grouped native radios. It has no selection click handler that checks every input. The precise cause of the reported live WordPress all-selected appearance was NOT reproduced or confirmed against the live rendered DOM. Theme CSS or WordPress markup processing remain possible causes, not established facts.

This update removes the peer-checked styling dependency and uses explicit form-local `input:checked + .prequal-option` rules. Labels contain valid inline span markup rather than divs. Each input has a unique ID and explicit label; each question has a fieldset and legend. No answers are preselected, and all four groups are required. Orange border, check mark and keyboard focus indicate the actual checked input, not a separately maintained selection state.

Confirmed submission bug: the original handler treated every response other than tier SUMMAR as a BasicBlock approval. It did not check HTTP status or success. The new handler requires HTTP success, valid JSON, `success: true` and an exact supported tier. Invalid responses, server errors and network errors show an accessible inline error, not a partner approval.

## Other changes

- Self-contained, scoped CSS replaces runtime Tailwind CDN and external font dependencies. Retains the dark/orange mobile card layout, with system fonts and clearer preliminary-match copy. This is a visual simplification, not pixel-identical reproduction.
- In-flight duplicate submission guard and disabled button; 30-second abort timeout. This is NOT backend idempotency. A timed-out request may already have sent email, so errors warn before retrying.
- Safe initialization before or after DOMContentLoaded, with an initialization guard.
- Existing field names, option values and webhook URL preserved.
- Preliminary matching is not described as lender approval; unverified fixed-rate/funding guarantees removed from frontend result copy. Existing backend email templates are unchanged and should be separately reviewed for consistency.
- Email-sent notice only appears when the backend explicitly returns boolean `email_sent: true`. The existing workflow export does not return this field; matching still works, with a neutral unconfirmed-email notice. If added later, set it only after the email node succeeds; it means send acceptance, not inbox delivery.
- Response-provided partner links restricted to the existing HTTPS partner hostnames. Untrusted links cannot replace the default links.
- No secrets added to frontend.

## Regression tests

Install Node.js and Playwright, then run from repository root:

```sh
npm install --no-save playwright
npx playwright install chromium
node tests/prequal.test.cjs
```

For a preinstalled Chromium, set `CHROMIUM_PATH` to its executable path. The test also detects `/usr/local/bin/chromium` in the authoring sandbox.

Local result: PASS for both entry points at 390px and 1280px, with 192 assertion checkpoints. Coverage includes every option, exactly one checked/highlighted option per group, independent groups, keyboard interaction, minimum target size, missing-answer validation, expected payload values, duplicate-submit blocking, mocked Summar and BasicBlock views, unsafe URL rejection, unknown/unsuccessful responses, invalid JSON, HTTP failure, network failure, simulated timeout, late initialization and no uncaught page errors.

All external requests are intercepted or blocked. A mocked SUMMAR response tests rendering, not whether the sample payload actually qualifies for that tier. This suite does not execute n8n or verify live partner terms/email delivery. The timeout test simulates AbortError; it does not wait 30 seconds.

## Safe WordPress update

1. Merge/review this update and back up the existing WordPress page content and settings.
2. On staging, REPLACE the existing form Custom HTML block with the complete contents of `frontend/wordpress-embed.html`. Do not append a second copy. Only one instance per page is supported.
3. Use a Custom HTML block or a trusted code-insertion mechanism that preserves style and script tags; verify the editor does not strip or rewrite them. Inspect selection and focus on the rendered page with the actual theme/plugins.
4. Test submissions only against a mock or dedicated test workflow with email nodes disabled. Do not submit dummy leads to the production webhook.
5. Update the EXISTING production page, purge relevant WordPress/CDN caches, and verify selection behaviour without submitting a lead.
6. Restore the saved page content if any theme-specific issue appears.

CAUTION: `scripts/deploy_wp_page.py` currently creates a new published page rather than updating the existing page and disables TLS verification. It was not run or changed in this frontend fix. Do not run it as an existing-page update. Use the manual replacement above or harden it separately with verified TLS, explicit page targeting and backup/rollback.

## Remaining backend follow-up

The exported workflow defaults missing inputs and its code does not enforce all the equipment restrictions described in README. Server-side validation, anti-spam/rate limiting, idempotency and underwriting/marketing copy consistency remain separate work. This frontend fix must not be mistaken for a backend security or routing audit.

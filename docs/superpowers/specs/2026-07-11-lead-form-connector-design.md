# Lead form connector — native forms → työ (design)

**Date:** 2026-07-11
**Repos touched:** `platform` (new public REST endpoint), `tyo-wordpress` (connector + shortcode, → v0.3.0), `themes/cho-autohuolto` (form re-plumb, reference consumer).
**Status:** approved design, pre-implementation.

## Problem

The WP plugin's only widget-free lead capture is the `[vaivatta_form]` iframe (messenger `mode=form`). Customers who build their own themed HTML forms (e.g. the CHO Autohuolto front page) cannot submit them into työ: the messenger send path requires a browser device-key session (ECDSA P-256 register → challenge → sign → verify → Bearer), which a server-forwarded form post cannot sensibly perform — the ceremony exists to give a *browser visitor* a persistent identity, which a form lead doesn't have.

The platform already has the right precedent: the WhatsApp webhook is server-to-server inbound that mints/reuses an identity and calls `services/ingest.receiveMessage`. This design adds an equivalent thin front door for web forms.

## Decisions locked with the owner

1. **Mechanism:** new anonymous, rate-limited `POST /api/v1/leads` on the platform's public REST facade + a server-side connector in the plugin. Not a postMessage bridge (must work without the chat widget), not PHP device-key crypto.
2. **Reply loop:** the lead lands in the team's worklist/inbox as a normal conversation; the team follows up manually by phone/email. No automated outbound delivery of the reply (deferred).
3. **Plugin scope:** both a generic form-post connector (any custom form) **and** a ready-made `[vaivatta_lead_form]` shortcode rendering a plain, theme-styleable HTML form. The existing iframe `[vaivatta_form]` is untouched.

## Non-goals

- Emailing/SMSing the approved reply to the lead (future; the `senderObservation` capture keeps the door open).
- Integrations with form plugins (CF7/Gravity/WPForms) — the field convention makes those possible later.
- Unifying repeat submitters into one identity (a form-typed phone is unverified, unlike WhatsApp's Meta-verified number; each lead mints a fresh anonymous identity — duplicate CRM rows for repeat submitters are an accepted MVP trade-off).
- Nonce/CSRF on the anonymous form post (page caches serve stale nonces to anonymous visitors; there is no authenticated context to forge — honeypot + platform rate limits carry anti-abuse).

## Architecture

```
themed HTML form ──POST──▶ admin-post.php?action=vaivatta_lead   (plugin, WP server)
                              │  sanitize, honeypot, compose DTO
                              ▼
                    POST {api_base}/leads  + x-tyo-tenant: {scope}
                              │  (platform REST facade)
                              ▼
              createCaller(ctx).chat.lead  ──▶ mint anonymous identity
                              │                (displayName = lead name)
                              ▼
              services/ingest.receiveMessage(channel: "webform",
                     text: localized lead message, defer: true,
                     senderObservation: {displayName, phone, email})
                              ▼
              conversation + work in team worklist; contact_crm row
```

The REST layer stays a curated facade: the route handler validates a public DTO and delegates to a tRPC procedure (`http.ts` pattern), so audit + logic live in the router/service layer as everywhere else.

## Platform changes

### 1. `ChannelId` gains `"webform"`

`src/infra/collections/core.ts`: `"cli" | "web" | "messenger" | "whatsapp" | "email" | "webform"`. Implementation must sweep exhaustive switches / channel label maps (review/worklist rendering, CRM channel chips) for the new value.

### 2. New tRPC procedure `chat.lead`

On a **public + tenant-scoped** tier (no identity required — same trust level as `auth.register`; `scopedProcedure` requires an identity, so this needs the public-with-`x-tyo-tenant` shape used to build a runtime from the scope header, mirroring what the WhatsApp dispatcher does with `runtimeForScope`).

Input (all trimmed, length-capped):

| field | rule |
|---|---|
| `name` | required, 1–200 |
| `phone` | required, 1–60 |
| `email` | optional, ≤254, loose email check |
| `message` | optional, ≤4000 (the same LLM cost cap as `chat.send`) |
| `extras` | optional array ≤10 of `{label ≤120, value ≤500}` |
| `lang` | optional `"fi" \| "en"`, default `"fi"` |
| `source` | optional ≤300 (embedding site URL, informational) |

Behavior:

1. Mint `id_{nanoid}` anonymous identity with `displayName = name` (per-lead; see non-goals). No `contacts` row — a form-typed phone is **not** channel-verified (WhatsApp's is); the phone/email ride `senderObservation` into `contact_crm` instead.
2. Compose the inbound text with localized labels, byte-compatible in spirit with the messenger's `buildLeadMessage`: `Yhteydenottopyyntö` header, `Nimi/Puhelin[/Sähköposti]` lines, one line per extra (`label: value`), blank line, free message.
3. `receiveMessage(rt, { identityId, channel: "webform", text, defer: true, senderObservation: { displayName: name, phone, email } })`.
4. `writeAudit` (`router-mutation-audited` lint applies): event `webform.lead.received`, actor `{kind:"customer", id: identityId}`, resource `{conversationId}`, payload `{source, hasEmail}` — no PII in the payload.
5. Return `{ ok: true }` only. No conversationId/identityId to the anonymous caller — useless without a session, and not worth leaking identifiers.

### 3. New REST route `POST /api/v1/leads`

`src/app/api/v1/leads/route.ts`, mirroring `messages/route.ts`:

- Per-IP fixed window `lead.ip` **5 / 60 s** (failClosed) — leads are rarer than chat messages; stricter than send's 20.
- Per-scope fixed window `lead:scope:{scope}` **60 / 60 s** (failClosed).
- Tenant existence check → 404-shaped `NOT_FOUND` on unknown scope.
- Origin guard: reuse `originAllowed(req, tenant.embed.allowedOrigins)`. The plugin sends `Origin: home_url()` on its server-side request so tenants who opt into the allow-list get the same lockdown; absent/empty list = allowed (unchanged semantics).
- Body → `leadRequest` zod schema in `src/server/rest/schemas.ts`; delegate to `createCaller(ctx).chat.lead`; respond `201 {ok:true}`.
- `OPTIONS` preflight + CORS as the sibling routes (harmless for server-to-server; keeps the facade uniform).
- Document in `src/server/rest/openapi.ts`. Messenger `gen:api` regen optional (it doesn't consume the endpoint).

### 4. Tests (vitest, hermetic)

- Schema validation (missing name/phone, oversize extras, bad lang).
- Rate limits fire (per-IP and per-scope, failClosed like `ratelimit-failclosed.test.ts`).
- Unknown scope → NOT_FOUND; origin guard honored when `embed.allowedOrigins` set.
- Happy path: conversation + work created, message text contains localized labels + extras, `contact_crm` row has phone/email, audit row written, fresh identity per lead.
- Member/customer branch: N/A (identity is always freshly minted — assert it never routes to the internal pipeline).

## Plugin changes (`tyo-wordpress`, v0.3.0)

### 1. `includes/class-vaivatta-lead-handler.php`

Registers `admin_post_vaivatta_lead` + `admin_post_nopriv_vaivatta_lead`.

Field convention (the public contract for any custom form):

| field | meaning |
|---|---|
| `action=vaivatta_lead` | routes to the handler |
| `vaivatta_name` | required |
| `vaivatta_phone` | required |
| `vaivatta_email` | optional |
| `vaivatta_message` | optional |
| `vaivatta_extra[<Label>]` | 0–10 extra fields, label-keyed (label truncated 120, value 500) |
| `vaivatta_hp` | honeypot — non-empty ⇒ redirect as success, drop silently |
| `vaivatta_redirect` | return URL; `wp_validate_redirect( …, home_url() )`; fallback `wp_get_referer()` → `home_url()` |
| `vaivatta_lang` | optional `fi\|en`; default from site locale (reuse `Vaivatta_Embed::lang()` logic) |

Flow: sanitize (`sanitize_text_field` / `sanitize_textarea_field`, `wp_unslash`) → if scope unset or name/phone missing → redirect `?vaivatta_sent=0` → else `wp_remote_post` to `apply_filters( 'vaivatta_api_base', 'https://tyo.vaivatta.fi/api/v1' ) . '/leads'` with headers `Content-Type: application/json`, `x-tyo-tenant: {scope}`, `Origin: home_url()`, timeout 15 → redirect `?vaivatta_sent=1` on 2xx else `?vaivatta_sent=0`. No nonce (see non-goals; cached anonymous pages).

### 2. `includes/class-vaivatta-lead-form-shortcode.php` — `[vaivatta_lead_form]`

Attributes: `extra_label` (adds one `vaivatta_extra[…]` input), `lang` (`fi|en`, default site locale), `show_message` (default true), `redirect` (default current permalink + `#vaivatta-form`).

Renders a plain HTML `<form>` (POST → `admin_url('admin-post.php')`) with bilingual default labels (fi/en by resolved lang, translatable via the plugin text domain), semantic classes only (`vv-lead-form`, `vv-lead-field`, …) and no inline styling beyond minimal layout — the theme styles it. Renders the success/error notice when `vaivatta_sent` is in the query. Returns `''` when no scope is connected (same guard as `[vaivatta_form]`).

### 3. Housekeeping

- `readme.txt`: document both surfaces + the field convention table; bump stable tag 0.3.0; changelog.
- PHPUnit: handler tests mock the platform via `pre_http_request` (same pattern as the connect tests) — asserts payload shape, honeypot drop, redirect targets, unconnected-scope behavior, `vaivatta_redirect` validation (external URL rejected). Shortcode render tests (fields present, extra_label, success notice).
- PHPCS clean; i18n strings in `languages/`.

## Theme changes (`themes/cho-autohuolto`)

`front-page.php` lines 148–187 keep every class/pixel; plumbing only:

- Hidden `action` value: `vaivatta_lead` when the plugin is active (`class_exists( 'Vaivatta_Lead_Handler' )`), else `cho_contact` — `wp_mail` remains the no-plugin fallback, `cho_handle_contact()` stays.
- `cho_name`→`vaivatta_name`, `cho_phone`→`vaivatta_phone`, `cho_reg`→`vaivatta_extra[Rekisterinumero / Reg. number]`, `cho_message`→`vaivatta_message`, honeypot `cho_website`→`vaivatta_hp` (keep `tabindex=-1 autocomplete=off` + visually-hidden class).
- Add hidden `vaivatta_redirect` = front page permalink + `#yhteystiedot`.
- Success banner keys on `vaivatta_sent=1` **or** legacy `sent=1` (fallback path still uses it). The nonce field stays for the fallback path; the plugin handler ignores it.
- Fallback handler `cho_handle_contact` must read BOTH old and new field names (form field names change but the fallback posts with the new names) — simplest: it reads the `vaivatta_*` names too.

## Security summary

- Anonymous intake adds no capability beyond the already-anonymous `/auth/register` + `/messages` chain; it is *stricter*: per-IP 5/min (vs send's 20/min), no session minted, no read-back of the conversation.
- All inputs length-capped before they reach LLM drafting (cost amplifier guard, same 4000 cap as `chat.send`).
- No PII in audit payloads; PII lives in the message text + `contact_crm` as with every other channel.
- `wp_validate_redirect` pins the post-submit redirect to the site.
- Fail-closed rate limiting, consistent with the platform norm.

## Rollout order

1. **platform** — endpoint ships dark (nothing calls it); deploy via normal main → Coolify.
2. **tyo-wordpress 0.3.0** — WP.org tag deploy (⚠ `WPORG_SVN_USERNAME`/`WPORG_SVN_PASSWORD` repo secrets still owed from 0.2.x; set before tagging or re-run the action after).
3. **theme** — swap the form plumbing; verify on the CHO site with the plugin connected.

Live smoke: submit the CHO form → lead visible in the tenant worklist with localized text + reg-number extra + CRM contact row; `vaivatta_sent=1` banner shows; honeypot-filled post creates nothing.

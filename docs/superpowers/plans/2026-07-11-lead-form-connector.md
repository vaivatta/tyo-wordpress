# Lead Form Connector Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let any native WordPress HTML form submit into työ as a lead conversation, via a new anonymous `POST /api/v1/leads` platform endpoint + a plugin connector and `[vaivatta_lead_form]` shortcode, with the CHO Autohuolto theme as first consumer.

**Spec:** `tyo-wordpress/docs/superpowers/specs/2026-07-11-lead-form-connector-design.md` — read it first; decisions there are locked.

**Architecture:** Platform gains a `"webform"` channel + a public-but-tenant-scoped tRPC procedure `chat.lead` (mints a fresh anonymous identity per lead, composes a localized lead message, delegates to `services/ingest.receiveMessage`), fronted by a thin REST route mirroring `messages/route.ts`. The WP plugin gains an `admin-post` handler that forwards sanitized form fields to that endpoint, plus a plain-HTML shortcode form. The theme swaps field names/action only.

**Tech Stack:** platform = Next.js route handlers + tRPC + zod v4 + vitest (hermetic Mongo harness); plugin = WordPress plugin PHP 7.4+, PHPUnit (`composer test`), PHPCS WordPress standard (`composer lint`).

## Global Constraints

- Three separate git repos: `platform/` and `tyo-wordpress/` (commit per task in the repo the task touches); `themes/` is **not** a git repo — no commits there.
- Platform gates per task: `npm run typecheck && npm run lint`, plus the task's test file via `npx vitest run <file>`. Full `npm test` needs the docker stack up (`docker compose up -d`) — run once before the final platform task.
- Platform norms (lint-enforced): no `process.env` outside `config/env.ts`; no module-scope `env` reads; every tRPC `.mutation()` calls `writeAudit` in its body; tenant-scoped Mongo queries carry `scope`.
- Plugin: PHP 7.4 compatible, all output escaped, all input `wp_unslash` + sanitized, text domain `vaivatta`, PHPCS WordPress standard clean.
- Rate limits are **failClosed** everywhere in this feature.
- Lead DTO caps (copy exactly): name 1–200 required, phone 1–60 required, email ≤254 optional, message ≤4000 optional, extras ≤10 × {label ≤120, value ≤500}, lang `fi|en` default `fi`, source ≤300 optional.
- API response to the plugin: `201 {"ok":true}` — never return conversationId/identityId to the anonymous caller.
- No nonce on the anonymous form path (cached pages); honeypot `vaivatta_hp` + platform rate limits are the abuse controls.

---

## Phase 1 — Platform (`platform/`)

### Task 1: `"webform"` channel id + registry adapter

**Files:**
- Modify: `platform/src/infra/collections/core.ts:27`
- Modify: `platform/src/modules/channel/registry.ts:29-35`

**Interfaces:**
- Produces: `ChannelId` union includes `"webform"`; `createChannelRegistry(...).for("webform")` returns the persist-only web adapter. Later tasks pass `channel: "webform"` to `receiveMessage`.

- [ ] **Step 1: Extend the union**

In `platform/src/infra/collections/core.ts` change:

```ts
export type ChannelId = "cli" | "web" | "messenger" | "whatsapp" | "email";
```

to:

```ts
export type ChannelId = "cli" | "web" | "messenger" | "whatsapp" | "email" | "webform";
```

- [ ] **Step 2: Run typecheck to enumerate exhaustiveness breaks**

Run: `cd platform && npm run typecheck`
Expected: FAIL — `src/modules/channel/registry.ts` `Record<ChannelId, Channel>` missing `webform`. (If other files error, they are `Record<ChannelId, …>` maps too — extend each with a `webform` entry that behaves exactly like `web`.)

- [ ] **Step 3: Add the registry entry**

In `platform/src/modules/channel/registry.ts`, inside the `channels` record after `messenger`:

```ts
    // Web-form leads: inbound-only. Replies persist to the conversation like `web`
    // (no push transport) — the team follows up by phone/email from the lead's contact.
    webform: createWebChannel(db, logger),
```

- [ ] **Step 4: Verify gates**

Run: `cd platform && npm run typecheck && npm run lint`
Expected: PASS, zero errors.

- [ ] **Step 5: Commit**

```bash
cd platform && git add src/infra/collections/core.ts src/modules/channel/registry.ts
git commit -m "feat(channels): add webform channel id (persist-only, web adapter)"
```

---

### Task 2: `receiveWebformLead` service (TDD)

**Files:**
- Create: `platform/src/services/webform-lead.ts`
- Test: `platform/src/services/webform-lead.test.ts`

**Interfaces:**
- Consumes: `receiveMessage` from `@/services/ingest` (`{identityId, channel, text, defer, senderObservation}` → `{conversationId, processingWorkId}`); `getCollections` from `@/infra/collections`; `nanoid`.
- Produces: `export interface WebformLeadInput { name: string; phone: string; email?: string; message?: string; extras?: { label: string; value: string }[]; lang?: "fi" | "en"; }` and `export async function receiveWebformLead(rt: Runtime, input: WebformLeadInput): Promise<{ conversationId: string; identityId: string }>` plus `export function buildWebformLeadText(lang: "fi" | "en", input: WebformLeadInput): string`. Task 3 calls `receiveWebformLead`; Task 4's route never imports this directly.

- [ ] **Step 1: Write the failing test**

Create `platform/src/services/webform-lead.test.ts`:

```ts
// receiveWebformLead — web-form lead intake: fresh anonymous identity per lead,
// localized composed text, conversation via the shared ingest pipeline, CRM capture.

import { afterAll, afterEach, beforeAll, describe, expect, it } from "vitest";
import type { Db } from "mongodb";
import { getCollections } from "@/infra/collections";
import { getDb } from "@/infra/mongo";
import { makeLogger } from "@/modules/logger";
import { buildWebformLeadText, receiveWebformLead } from "@/services/webform-lead";
import { runtimeForScope, type Runtime } from "@/services/runtime";
import { createTenant } from "@/services/tenant";
import { registerAndAuth } from "@/test/client";
import { startMongo, stopMongo, wipeMongo } from "@/test/mongo";

let db: Db;
beforeAll(async () => {
  await startMongo();
  db = await getDb();
});
afterEach(async () => {
  await wipeMongo();
});
afterAll(async () => {
  await stopMongo();
});

async function makeRuntime(): Promise<Runtime> {
  const owner = await registerAndAuth(db);
  const { scope } = await createTenant(db, owner.identityId, { name: "Acme Oy" });
  const rt = await runtimeForScope(db, makeLogger("debug"), scope);
  if (!rt) throw new Error("runtime build failed");
  return rt;
}

describe("buildWebformLeadText", () => {
  it("composes localized fi text with extras and message", () => {
    const text = buildWebformLeadText("fi", {
      name: "Matti Meikäläinen",
      phone: "040 123 4567",
      email: "matti@example.fi",
      message: "Ääni kuuluu etuvasemmalta.",
      extras: [{ label: "Rekisterinumero / Reg. number", value: "ABC-123" }],
    });
    expect(text).toBe(
      "Yhteydenottopyyntö\n" +
        "Nimi: Matti Meikäläinen\n" +
        "Puhelin: 040 123 4567\n" +
        "Sähköposti: matti@example.fi\n" +
        "Rekisterinumero / Reg. number: ABC-123\n" +
        "\n" +
        "Ääni kuuluu etuvasemmalta.",
    );
  });

  it("omits absent email/extras/message and localizes to en", () => {
    const text = buildWebformLeadText("en", { name: "Jane", phone: "+358401234567" });
    expect(text).toBe("Contact request\nName: Jane\nPhone: +358401234567");
  });
});

describe("receiveWebformLead", () => {
  it("mints a fresh anonymous identity, creates a webform conversation and CRM contact", async () => {
    const rt = await makeRuntime();
    const r = await receiveWebformLead(rt, {
      name: "Matti Meikäläinen",
      phone: "040 123 4567",
      email: "matti@example.fi",
      lang: "fi",
    });

    const c = getCollections(db);
    const identity = await c.identities.findOne({ _id: r.identityId });
    expect(identity?.status).toBe("anonymous");
    expect(identity?.displayName).toBe("Matti Meikäläinen");
    // No channel-verified contact row — a typed phone is unverified (unlike WhatsApp).
    expect(await c.contacts.findOne({ identityId: r.identityId })).toBeNull();

    const conv = await c.conversations.findOne({ _id: r.conversationId });
    expect(conv?.scope).toBe(rt.scope);
    expect(conv?.channel).toBe("webform");

    const msg = await c.messages.findOne({ conversationId: r.conversationId });
    expect(msg?.text).toContain("Yhteydenottopyyntö");
    expect(msg?.text).toContain("Puhelin: 040 123 4567");

    const crm = await c.contactCrm.findOne({ scope: rt.scope, identityId: r.identityId });
    expect(crm?.phone).toBe("040 123 4567");
    expect(crm?.email).toBe("matti@example.fi");
  });

  it("mints a NEW identity per lead (no unverified-phone unification)", async () => {
    const rt = await makeRuntime();
    const a = await receiveWebformLead(rt, { name: "A", phone: "040 111", lang: "fi" });
    const b = await receiveWebformLead(rt, { name: "B", phone: "040 111", lang: "fi" });
    expect(a.identityId).not.toBe(b.identityId);
    expect(a.conversationId).not.toBe(b.conversationId);
  });
});
```

NOTE for the implementer: field/collection names in the assertions (`contactCrm`, `crm.phone`) must match `@/infra/collections` / `services/crm/contacts.ts` (`upsertContactCrm` sets `email`/`phone` at the top level of `ContactCrmDoc`). If a name differs at implementation time, fix the TEST to the real name — do not invent new doc shapes.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd platform && npx vitest run src/services/webform-lead.test.ts`
Expected: FAIL — `Cannot find module '@/services/webform-lead'`.

- [ ] **Step 3: Implement the service**

Create `platform/src/services/webform-lead.ts`:

```ts
// Web-form lead intake (WP plugin connector et al.) — the server-to-server sibling of
// the messenger's LeadForm: mint a FRESH anonymous identity per lead (a typed phone is
// NOT channel-verified, so no cross-lead unification — see the WhatsApp resolver for
// the verified counterpart), compose the same localized lead text the messenger builds
// client-side, and hand it to the shared ingest pipeline. Contact details ride
// senderObservation into the per-tenant contact CRM.

import { nanoid } from "nanoid";
import { getCollections } from "@/infra/collections";
import { receiveMessage } from "@/services/ingest";
import type { Runtime } from "@/services/runtime";

export interface WebformLeadInput {
  name: string;
  phone: string;
  email?: string;
  message?: string;
  extras?: { label: string; value: string }[];
  lang?: "fi" | "en";
}

// Keep these strings aligned with the messenger's i18n (contactRequest/formName/formPhone).
const LABELS = {
  fi: { header: "Yhteydenottopyyntö", name: "Nimi", phone: "Puhelin", email: "Sähköposti" },
  en: { header: "Contact request", name: "Name", phone: "Phone", email: "Email" },
} as const;

export function buildWebformLeadText(lang: "fi" | "en", input: WebformLeadInput): string {
  const L = LABELS[lang];
  const lines = [L.header, `${L.name}: ${input.name}`, `${L.phone}: ${input.phone}`];
  if (input.email) lines.push(`${L.email}: ${input.email}`);
  for (const e of input.extras ?? []) lines.push(`${e.label}: ${e.value}`);
  const msg = (input.message ?? "").trim();
  return msg ? `${lines.join("\n")}\n\n${msg}` : lines.join("\n");
}

export async function receiveWebformLead(
  rt: Runtime,
  input: WebformLeadInput,
): Promise<{ conversationId: string; identityId: string }> {
  const lang = input.lang ?? "fi";
  const identityId = `id_${nanoid()}`;
  await getCollections(rt.db).identities.insertOne({
    _id: identityId,
    status: "anonymous",
    createdAt: Date.now(),
    displayName: input.name,
  });

  const r = await receiveMessage(rt, {
    identityId,
    channel: "webform",
    text: buildWebformLeadText(lang, input),
    defer: true, // live HTTP path — draft off the request
    senderObservation: {
      displayName: input.name,
      phone: input.phone,
      ...(input.email ? { email: input.email } : {}),
    },
  });
  return { conversationId: r.conversationId, identityId };
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd platform && npx vitest run src/services/webform-lead.test.ts`
Expected: PASS (4 tests). If an `IdentityDoc` field is mandatory beyond `{_id,status,createdAt}`, copy the insert shape from `resolveWhatsappIdentity` in `src/services/whatsapp-inbound.ts:227-235`.

- [ ] **Step 5: Gates + commit**

Run: `cd platform && npm run typecheck && npm run lint`
Expected: PASS.

```bash
cd platform && git add src/services/webform-lead.ts src/services/webform-lead.test.ts
git commit -m "feat(leads): webform lead intake service (fresh identity + localized text + CRM capture)"
```

---

### Task 3: `tenantPublicProcedure` + `chat.lead` mutation (TDD)

**Files:**
- Modify: `platform/src/server/trpc/init.ts` (after `scopedProcedure`, ~line 76)
- Modify: `platform/src/server/routers/chat.ts`
- Test: `platform/src/server/routers/chat.lead.test.ts`

**Interfaces:**
- Consumes: `receiveWebformLead`, `WebformLeadInput` (Task 2).
- Produces: `tenantPublicProcedure` (exported from `@/server/trpc/init`): tenant runtime required, NO identity required. `chat.lead` mutation: input = the lead DTO fields + `source?`; returns `{ ok: true }`. Task 4's route calls `createCaller(ctx).chat.lead(...)`.

- [ ] **Step 1: Write the failing test**

Create `platform/src/server/routers/chat.lead.test.ts`:

```ts
// chat.lead — anonymous, tenant-scoped lead intake: works with NO identity, writes
// an audit row, refuses without tenant context.

import { afterAll, afterEach, beforeAll, describe, expect, it } from "vitest";
import type { Db } from "mongodb";
import { getCollections } from "@/infra/collections";
import { getDb } from "@/infra/mongo";
import { makeLogger } from "@/modules/logger";
import { buildContext } from "@/server/context";
import { createCaller } from "@/server/routers";
import { createTenant } from "@/services/tenant";
import { registerAndAuth } from "@/test/client";
import { startMongo, stopMongo, wipeMongo } from "@/test/mongo";

let db: Db;
beforeAll(async () => {
  await startMongo();
  db = await getDb();
});
afterEach(async () => {
  await wipeMongo();
});
afterAll(async () => {
  await stopMongo();
});

async function makeScope(): Promise<string> {
  const owner = await registerAndAuth(db);
  const { scope } = await createTenant(db, owner.identityId, { name: "Acme Oy" });
  return scope;
}

// Anonymous caller: NO token, only the tenant header — mirrors the REST facade.
async function anonCaller(scope: string | null) {
  const ctx = await buildContext({ db, logger: makeLogger("debug"), token: null, tenantId: scope });
  return createCaller(ctx);
}

describe("chat.lead", () => {
  it("accepts an anonymous lead, creates the conversation, writes audit", async () => {
    const scope = await makeScope();
    const caller = await anonCaller(scope);
    const r = await caller.chat.lead({
      name: "Matti",
      phone: "040 123 4567",
      lang: "fi",
      source: "https://cho-autohuolto.fi",
    });
    expect(r).toEqual({ ok: true });

    const c = getCollections(db);
    const conv = await c.conversations.findOne({ scope, channel: "webform" });
    expect(conv).not.toBeNull();

    const audit = await c.audit.findOne({ scope, event: "webform.lead.received" });
    expect(audit).not.toBeNull();
    // No PII in the audit payload.
    expect(JSON.stringify(audit?.payload ?? {})).not.toContain("040 123 4567");
  });

  it("rejects without tenant context", async () => {
    const caller = await anonCaller(null);
    await expect(caller.chat.lead({ name: "X", phone: "1" })).rejects.toMatchObject({
      code: "BAD_REQUEST",
    });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd platform && npx vitest run src/server/routers/chat.lead.test.ts`
Expected: FAIL — `caller.chat.lead is not a function`.

- [ ] **Step 3: Add the procedure tier**

In `platform/src/server/trpc/init.ts`, directly after the `scopedProcedure` block (line ~76):

```ts
// Tenant-scoped but ANONYMOUS (no identity): server-to-server intake where the caller
// is a machine (e.g. the WP plugin forwarding a web-form lead), trust level = the
// already-anonymous auth.register. The REST edge adds its own rate limits.
export const tenantPublicProcedure = t.procedure.use(({ ctx, next }) => {
  if (!ctx.scope || !ctx.runtime) {
    throw new TRPCError({ code: "BAD_REQUEST", message: "tenant context required" });
  }
  return next({ ctx: { scope: ctx.scope, runtime: ctx.runtime } });
});
```

- [ ] **Step 4: Add the mutation**

In `platform/src/server/routers/chat.ts`: extend the imports —

```ts
import { receiveWebformLead } from "@/services/webform-lead";
import { memberProcedure, router, scopedProcedure, tenantPublicProcedure } from "@/server/trpc/init";
```

and add inside `router({ ... })` after `send`:

```ts
  // Anonymous web-form lead (WP plugin connector). Mints a fresh identity per lead —
  // there is no session, so nothing is returned that only a session could use.
  lead: tenantPublicProcedure
    .input(
      z.object({
        name: z.string().trim().min(1).max(200),
        phone: z.string().trim().min(1).max(60),
        email: z.string().trim().max(254).regex(/^\S+@\S+\.\S+$/).optional(),
        // Same LLM-drafting cost cap as `send`.
        message: z.string().trim().max(4000).optional(),
        extras: z
          .array(z.object({ label: z.string().trim().min(1).max(120), value: z.string().trim().min(1).max(500) }))
          .max(10)
          .optional(),
        lang: z.enum(["fi", "en"]).default("fi"),
        source: z.string().trim().max(300).optional(),
      }),
    )
    .mutation(async ({ ctx, input }) => {
      const r = await receiveWebformLead(ctx.runtime, {
        name: input.name,
        phone: input.phone,
        email: input.email,
        message: input.message,
        extras: input.extras,
        lang: input.lang,
      });
      await withTxn((session) =>
        writeAudit({
          db: ctx.runtime.db,
          session,
          scope: ctx.runtime.scope,
          actor: { kind: "customer", id: r.identityId },
          event: "webform.lead.received",
          resource: { conversationId: r.conversationId },
          // No PII here — name/phone/email live in the message text + contact_crm only.
          payload: { source: input.source ?? null, hasEmail: Boolean(input.email) },
        }),
      );
      return { ok: true as const };
    }),
```

(`z`, `withTxn`, `writeAudit` are already imported in chat.ts.)

- [ ] **Step 5: Run test to verify it passes**

Run: `cd platform && npx vitest run src/server/routers/chat.lead.test.ts`
Expected: PASS (2 tests).

- [ ] **Step 6: Gates + commit**

Run: `cd platform && npm run typecheck && npm run lint`
Expected: PASS — in particular `tyo-ops/router-mutation-audited` must be satisfied by the `writeAudit` call.

```bash
cd platform && git add src/server/trpc/init.ts src/server/routers/chat.ts src/server/routers/chat.lead.test.ts
git commit -m "feat(leads): chat.lead anonymous tenant-scoped mutation + tenantPublicProcedure"
```

---

### Task 4: REST `POST /api/v1/leads` — schema, route, OpenAPI (TDD)

**Files:**
- Modify: `platform/src/server/rest/schemas.ts` (after the messaging section)
- Create: `platform/src/app/api/v1/leads/route.ts`
- Modify: `platform/src/server/rest/openapi.ts` (paths map)
- Test: `platform/src/server/rest/leads.test.ts`

**Interfaces:**
- Consumes: `chat.lead` (Task 3); `handle/json/preflight/readJson/enforceRateLimit/contextFromRequest` from `@/server/rest/http`; `originAllowed` from `@/services/embed-origin`.
- Produces: public contract `POST /leads` → `201 {"ok":true}` with header `x-tyo-tenant`. The WP plugin (Task 5) targets this.

- [ ] **Step 1: Write the failing test**

Create `platform/src/server/rest/leads.test.ts`:

```ts
// POST /api/v1/leads — anonymous web-form lead intake: happy path, validation,
// unknown scope, per-IP rate limit (failClosed limits are covered by
// ratelimit-failclosed.test.ts), embed-origin guard.

import { afterAll, afterEach, beforeAll, describe, expect, it } from "vitest";
import type { Db } from "mongodb";
import { POST as leadsPOST } from "@/app/api/v1/leads/route";
import { getCollections } from "@/infra/collections";
import { getDb } from "@/infra/mongo";
import { __resetRateLimitMemory } from "@/infra/ratelimit";
import { createTenant } from "@/services/tenant";
import { registerAndAuth } from "@/test/client";
import { startMongo, stopMongo, wipeMongo } from "@/test/mongo";

let db: Db;
beforeAll(async () => {
  await startMongo();
  db = await getDb();
});
afterEach(async () => {
  await wipeMongo();
  __resetRateLimitMemory();
});
afterAll(async () => {
  await stopMongo();
});

let ipCounter = 0;
const nextIp = (): string => `203.0.113.${++ipCounter}`;

const post = (body: unknown, headers: Record<string, string> = {}): Request =>
  new Request("http://localhost/api/v1/leads", {
    method: "POST",
    headers: { "content-type": "application/json", "x-forwarded-for": nextIp(), ...headers },
    body: JSON.stringify(body),
  });

async function makeScope(): Promise<string> {
  const owner = await registerAndAuth(db);
  const { scope } = await createTenant(db, owner.identityId, { name: "Acme Oy" });
  return scope;
}

const LEAD = {
  name: "Matti Meikäläinen",
  phone: "040 123 4567",
  extras: [{ label: "Rekisterinumero / Reg. number", value: "ABC-123" }],
  message: "Vaihtoauton huolto.",
  lang: "fi",
  source: "https://cho-autohuolto.fi",
};

describe("POST /api/v1/leads", () => {
  it("201 + {ok:true}, conversation with the composed text exists", async () => {
    const scope = await makeScope();
    const res = await leadsPOST(post(LEAD, { "x-tyo-tenant": scope }));
    expect(res.status).toBe(201);
    expect(await res.json()).toEqual({ ok: true });

    const msg = await getCollections(db).messages.findOne({ scope });
    expect(msg?.text).toContain("Rekisterinumero / Reg. number: ABC-123");
  });

  it("400 on missing phone", async () => {
    const scope = await makeScope();
    const res = await leadsPOST(post({ name: "X" }, { "x-tyo-tenant": scope }));
    expect(res.status).toBe(400);
  });

  it("404 on unknown scope; 400 with no tenant header", async () => {
    expect((await leadsPOST(post(LEAD, { "x-tyo-tenant": "nope" }))).status).toBe(404);
    expect((await leadsPOST(post(LEAD))).status).toBe(400);
  });

  it("429 on the 6th request from one IP inside the window", async () => {
    const scope = await makeScope();
    const ip = { "x-forwarded-for": "203.0.113.250", "x-tyo-tenant": scope };
    for (let i = 0; i < 5; i++) {
      const r = await leadsPOST(
        new Request("http://localhost/api/v1/leads", {
          method: "POST",
          headers: { "content-type": "application/json", ...ip },
          body: JSON.stringify(LEAD),
        }),
      );
      expect(r.status).toBe(201);
    }
    const sixth = await leadsPOST(
      new Request("http://localhost/api/v1/leads", {
        method: "POST",
        headers: { "content-type": "application/json", ...ip },
        body: JSON.stringify(LEAD),
      }),
    );
    expect(sixth.status).toBe(429);
  });

  it("403 when the tenant's embed allow-list excludes the Origin", async () => {
    const scope = await makeScope();
    await getCollections(db).tenants.updateOne(
      { _id: scope },
      { $set: { embed: { allowedOrigins: ["https://cho-autohuolto.fi"] } } },
    );
    const bad = await leadsPOST(post(LEAD, { "x-tyo-tenant": scope, origin: "https://evil.example" }));
    expect(bad.status).toBe(403);
    const ok = await leadsPOST(post(LEAD, { "x-tyo-tenant": scope, origin: "https://cho-autohuolto.fi" }));
    expect(ok.status).toBe(201);
  });
});
```

NOTE: if `TenantDoc.embed` has a different shape, copy the exact `$set` from the existing origin-guard test for `/messages` in `rest.test.ts` — do not invent one.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd platform && npx vitest run src/server/rest/leads.test.ts`
Expected: FAIL — `Cannot find module '@/app/api/v1/leads/route'`.

- [ ] **Step 3: Add the public DTO**

In `platform/src/server/rest/schemas.ts`, after the messaging section:

```ts
// ── leads ─────────────────────────────────────────────────────────────────────
// Anonymous web-form lead intake (the WP plugin's connector). Caps mirror chat.lead;
// the response deliberately returns NOTHING an anonymous caller could correlate.
export const leadRequest = z.object({
  name: z.string().trim().min(1).max(200),
  phone: z.string().trim().min(1).max(60),
  email: z.string().trim().max(254).regex(/^\S+@\S+\.\S+$/).optional(),
  message: z.string().trim().max(4000).optional(),
  extras: z
    .array(z.object({ label: z.string().trim().min(1).max(120), value: z.string().trim().min(1).max(500) }))
    .max(10)
    .optional(),
  lang: z.enum(["fi", "en"]).optional(),
  source: z.string().trim().max(300).optional(),
});
export const leadResponse = z.object({ ok: z.literal(true) });
```

- [ ] **Step 4: Create the route**

Create `platform/src/app/api/v1/leads/route.ts`:

```ts
// POST /api/v1/leads — anonymous web-form lead intake (x-tyo-tenant, NO Bearer).
// The server-to-server sibling of /messages for form submissions forwarded by the
// WP plugin. Abuse controls BEFORE any work:
//   1. Per-IP fixed-window limit   — 5 req / 60 s (failClosed; leads are rare)
//   2. Per-scope fixed-window limit — 60 req / 60 s (failClosed)
//   3. Per-tenant allowed-origins check (plugin sends Origin: site URL) —
//      absent/empty list = any origin allowed, same opt-in as /messages.

import { TRPCError } from "@trpc/server";
import { createCaller } from "@/server/routers";
import {
  contextFromRequest,
  enforceRateLimit,
  handle,
  json,
  preflight,
  readJson,
} from "@/server/rest/http";
import { leadRequest } from "@/server/rest/schemas";
import { getCollections } from "@/infra/collections";
import { checkRateLimit } from "@/infra/ratelimit";
import { originAllowed } from "@/services/embed-origin";

export const dynamic = "force-dynamic";

export function OPTIONS(req: Request): Response {
  return preflight(req);
}

export function POST(req: Request): Promise<Response> {
  return handle(req, async () => {
    await enforceRateLimit(req, "lead.ip", { limit: 5, windowSec: 60, failClosed: true });

    const scopeId = req.headers.get("x-tyo-tenant");
    if (scopeId) {
      const { allowed } = await checkRateLimit(`lead:scope:${scopeId}`, {
        limit: 60,
        windowSec: 60,
        failClosed: true,
      });
      if (!allowed) {
        throw new TRPCError({ code: "TOO_MANY_REQUESTS", message: "rate limit exceeded" });
      }
    }

    const body = await readJson(req, leadRequest);
    const ctx = await contextFromRequest(req);

    // Unknown tenant is a 404 (tenantPublicProcedure would say 400, which reads as
    // "bad payload" to an integrator debugging their scope value).
    if (scopeId && !ctx.runtime) {
      throw new TRPCError({ code: "NOT_FOUND", message: "unknown tenant" });
    }

    if (scopeId) {
      const tenant = await getCollections(ctx.db).tenants.findOne({ _id: scopeId });
      if (!originAllowed(req, tenant?.embed?.allowedOrigins)) {
        throw new TRPCError({ code: "FORBIDDEN", message: "origin not allowed" });
      }
    }

    const r = await createCaller(ctx).chat.lead(body);
    return json(req, r, 201);
  });
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd platform && npx vitest run src/server/rest/leads.test.ts`
Expected: PASS (5 tests).

- [ ] **Step 6: Document in OpenAPI**

In `platform/src/server/rest/openapi.ts`, add to `paths` (after the `/messages` entry, reusing the file's existing `tenantHeader`, `jsonBody`, `jsonOk`, `errorResponses` helpers):

```ts
      "/leads": {
        post: {
          tags: ["messaging"],
          summary: "Submit a web-form lead (anonymous, rate-limited)",
          description:
            "Server-to-server intake for native site forms (e.g. the WordPress plugin connector). Creates a lead conversation in the tenant's worklist; the team follows up via the contact details.",
          parameters: [tenantHeader],
          requestBody: jsonBody(S.leadRequest),
          responses: {
            "201": jsonOk(S.leadResponse, "lead accepted"),
            ...errorResponses("400", "403", "404", "429"),
          },
        },
      },
```

Run: `cd platform && npx vitest run src/server/rest/rest.test.ts`
Expected: PASS (the OpenAPI generation test still validates).

- [ ] **Step 7: Full platform gates + commit**

Run: `cd platform && docker compose up -d && npm run typecheck && npm run lint && npm test`
Expected: all green (LLM-flow tests need the docker stack — see CLAUDE.md).

```bash
cd platform && git add src/server/rest/schemas.ts src/server/rest/openapi.ts src/app/api/v1/leads src/server/rest/leads.test.ts
git commit -m "feat(leads): public POST /api/v1/leads intake route + OpenAPI"
```

---

## Phase 2 — Plugin (`tyo-wordpress/`, → v0.3.0)

### Task 5: `Vaivatta_Lead_Handler` (TDD)

**Files:**
- Create: `tyo-wordpress/includes/class-vaivatta-lead-handler.php`
- Modify: `tyo-wordpress/vaivatta.php` (require + init registration)
- Test: `tyo-wordpress/tests/test-lead-handler.php`

**Interfaces:**
- Consumes: `Vaivatta_Settings::get()` (`['scope' => string, 'lang_mode' => 'auto|fi|en', …]`); filter `vaivatta_api_base` (default `https://tyo.vaivatta.fi/api/v1`).
- Produces: `admin_post(_nopriv)_vaivatta_lead` handling the field convention below; protected `do_redirect(string $url)` for the test stub (same pattern as `Vaivatta_Connect`). Task 6's shortcode and Task 7's theme post to it.

Field convention (public contract, also documented in readme in Task 7):
`action=vaivatta_lead`, `vaivatta_name`*, `vaivatta_phone`*, `vaivatta_email`, `vaivatta_message`, `vaivatta_extra[<Label>]` (≤10), `vaivatta_hp` (honeypot), `vaivatta_redirect` (same-site), `vaivatta_lang` (`fi|en`).

- [ ] **Step 1: Write the failing test**

Create `tyo-wordpress/tests/test-lead-handler.php`:

```php
<?php
/**
 * Tests for Vaivatta_Lead_Handler.
 *
 * @package vaivatta
 */

/**
 * Captures redirects instead of exiting (same pattern as the Connect stub).
 */
class Vaivatta_Lead_Handler_Test_Stub extends Vaivatta_Lead_Handler {

	/**
	 * Last redirect URL.
	 *
	 * @var string|null
	 */
	public $redirect_url = null;

	/**
	 * Captures the redirect URL.
	 *
	 * @param string $url Target URL.
	 * @return void
	 */
	protected function do_redirect( string $url ): void {
		$this->redirect_url = $url;
	}
}

/**
 * Test_Lead_Handler class.
 */
class Test_Lead_Handler extends WP_UnitTestCase {

	/**
	 * Captured wp_remote_post request (url + parsed args), or null.
	 *
	 * @var array|null
	 */
	private $captured = null;

	/**
	 * Mocked HTTP status for the platform response.
	 *
	 * @var int
	 */
	private $mock_status = 201;

	public function set_up() {
		parent::set_up();
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => 'acme' ) );
		$this->captured = null;
		add_filter( 'pre_http_request', array( $this, 'mock_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'mock_http' ), 10 );
		delete_option( Vaivatta_Settings::OPTION );
		$_POST = array();
		parent::tear_down();
	}

	/**
	 * Captures the outgoing request and returns a mocked platform response.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request args.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function mock_http( $pre, $args, $url ) {
		$this->captured = array( 'url' => $url, 'args' => $args, 'body' => json_decode( $args['body'], true ) );
		return array(
			'headers'  => array(),
			'body'     => '{"ok":true}',
			'response' => array( 'code' => $this->mock_status ),
		);
	}

	/**
	 * Runs the handler with the given POST fields and returns the stub.
	 *
	 * @param array $post POST fields.
	 * @return Vaivatta_Lead_Handler_Test_Stub
	 */
	private function run_handler( array $post ): Vaivatta_Lead_Handler_Test_Stub {
		$_POST   = $post;
		$handler = new Vaivatta_Lead_Handler_Test_Stub();
		$handler->handle();
		return $handler;
	}

	public function test_happy_path_posts_lead_and_redirects_sent() {
		$h = $this->run_handler(
			array(
				'vaivatta_name'     => 'Matti Meikäläinen',
				'vaivatta_phone'    => '040 123 4567',
				'vaivatta_message'  => "Ääni kuuluu edestä.\nToinen rivi.",
				'vaivatta_extra'    => array( 'Rekisterinumero / Reg. number' => 'ABC-123' ),
				'vaivatta_lang'     => 'fi',
				'vaivatta_redirect' => home_url( '/?p=1#yhteystiedot' ),
			)
		);

		$this->assertNotNull( $this->captured );
		$this->assertStringEndsWith( '/leads', $this->captured['url'] );
		$this->assertSame( 'acme', $this->captured['args']['headers']['x-tyo-tenant'] );
		$this->assertSame( home_url(), $this->captured['args']['headers']['Origin'] );
		$this->assertSame( 'Matti Meikäläinen', $this->captured['body']['name'] );
		$this->assertSame( '040 123 4567', $this->captured['body']['phone'] );
		$this->assertSame(
			array( array( 'label' => 'Rekisterinumero / Reg. number', 'value' => 'ABC-123' ) ),
			$this->captured['body']['extras']
		);
		$this->assertSame( home_url(), $this->captured['body']['source'] );
		$this->assertStringContainsString( 'vaivatta_sent=1', $h->redirect_url );
		$this->assertStringContainsString( '#yhteystiedot', $h->redirect_url );
	}

	public function test_honeypot_drops_silently_but_redirects_as_success() {
		$h = $this->run_handler(
			array(
				'vaivatta_name'  => 'Bot',
				'vaivatta_phone' => '1',
				'vaivatta_hp'    => 'https://spam.example',
			)
		);
		$this->assertNull( $this->captured );
		$this->assertStringContainsString( 'vaivatta_sent=1', $h->redirect_url );
	}

	public function test_missing_required_fields_redirect_error_without_posting() {
		$h = $this->run_handler( array( 'vaivatta_name' => 'NoPhone' ) );
		$this->assertNull( $this->captured );
		$this->assertStringContainsString( 'vaivatta_sent=0', $h->redirect_url );
	}

	public function test_unconnected_scope_redirects_error() {
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => '' ) );
		$h = $this->run_handler( array( 'vaivatta_name' => 'X', 'vaivatta_phone' => '1' ) );
		$this->assertNull( $this->captured );
		$this->assertStringContainsString( 'vaivatta_sent=0', $h->redirect_url );
	}

	public function test_platform_error_redirects_error() {
		$this->mock_status = 429;
		$h = $this->run_handler( array( 'vaivatta_name' => 'X', 'vaivatta_phone' => '1' ) );
		$this->assertStringContainsString( 'vaivatta_sent=0', $h->redirect_url );
	}

	public function test_external_redirect_is_rejected() {
		$h = $this->run_handler(
			array(
				'vaivatta_name'     => 'X',
				'vaivatta_phone'    => '1',
				'vaivatta_redirect' => 'https://evil.example/phish',
			)
		);
		$this->assertStringStartsWith( home_url(), $h->redirect_url );
	}

	public function test_extras_capped_at_ten() {
		$extras = array();
		for ( $i = 0; $i < 15; $i++ ) {
			$extras[ 'Label ' . $i ] = 'v' . $i;
		}
		$this->run_handler(
			array( 'vaivatta_name' => 'X', 'vaivatta_phone' => '1', 'vaivatta_extra' => $extras )
		);
		$this->assertCount( 10, $this->captured['body']['extras'] );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd tyo-wordpress && composer test -- --filter Test_Lead_Handler`
Expected: FAIL — `Class "Vaivatta_Lead_Handler" not found`.

- [ ] **Step 3: Implement the handler**

Create `tyo-wordpress/includes/class-vaivatta-lead-handler.php`:

```php
<?php
/**
 * Lead connector — forwards a native form post to the työ platform as a lead.
 *
 * @package vaivatta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin-post `vaivatta_lead`: any site form (custom-themed or the
 * [vaivatta_lead_form] shortcode) posts here; the fields are sanitized and
 * forwarded server-side to POST {api_base}/leads with the connected scope.
 *
 * Deliberately NO nonce: the form is anonymous and lives on cacheable pages
 * (a cached nonce fails for every visitor after its lifetime). Abuse controls
 * are the vaivatta_hp honeypot here + rate limits on the platform.
 */
class Vaivatta_Lead_Handler {

	/**
	 * Registers the admin-post hooks (logged-in + visitors).
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'admin_post_vaivatta_lead', array( $this, 'handle' ) );
		add_action( 'admin_post_nopriv_vaivatta_lead', array( $this, 'handle' ) );
	}

	/**
	 * Handles the form post: sanitize → forward → redirect back.
	 *
	 * @return void
	 */
	public function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- anonymous, cache-safe form; see class docblock.
		$back = $this->return_url();

		// Honeypot: pretend success, never forward.
		if ( ! empty( $_POST['vaivatta_hp'] ) ) {
			$this->do_redirect( add_query_arg( 'vaivatta_sent', '1', $back ) );
			return;
		}

		$opts  = Vaivatta_Settings::get();
		$scope = isset( $opts['scope'] ) ? (string) $opts['scope'] : '';
		$name  = $this->text_field( 'vaivatta_name', 200 );
		$phone = $this->text_field( 'vaivatta_phone', 60 );

		if ( '' === $scope || '' === $name || '' === $phone ) {
			$this->do_redirect( add_query_arg( 'vaivatta_sent', '0', $back ) );
			return;
		}

		$payload = array(
			'name'   => $name,
			'phone'  => $phone,
			'lang'   => $this->lang(),
			'source' => home_url(),
		);

		$email = $this->text_field( 'vaivatta_email', 254 );
		if ( '' !== $email ) {
			$payload['email'] = $email;
		}

		$message = isset( $_POST['vaivatta_message'] )
			? mb_substr( sanitize_textarea_field( wp_unslash( $_POST['vaivatta_message'] ) ), 0, 4000 )
			: '';
		if ( '' !== $message ) {
			$payload['message'] = $message;
		}

		$extras = $this->extras();
		if ( array() !== $extras ) {
			$payload['extras'] = $extras;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$ok = $this->post_lead( $scope, $payload );
		$this->do_redirect( add_query_arg( 'vaivatta_sent', $ok ? '1' : '0', $back ) );
	}

	/**
	 * Reads, unslashes, sanitizes and truncates a single text field.
	 *
	 * @param string $key POST key.
	 * @param int    $max Max length.
	 * @return string
	 */
	private function text_field( string $key, int $max ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock.
		if ( ! isset( $_POST[ $key ] ) ) {
			return '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		return mb_substr( sanitize_text_field( wp_unslash( $_POST[ $key ] ) ), 0, $max );
	}

	/**
	 * Collects vaivatta_extra[Label]=value pairs (≤10, label ≤120, value ≤500).
	 *
	 * @return array<int, array{label: string, value: string}>
	 */
	private function extras(): array {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock.
		$raw = isset( $_POST['vaivatta_extra'] ) && is_array( $_POST['vaivatta_extra'] )
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each pair is sanitized in the loop below.
			? wp_unslash( $_POST['vaivatta_extra'] )
			: array();

		$out = array();
		foreach ( $raw as $label => $value ) {
			if ( count( $out ) >= 10 ) {
				break;
			}
			$label = mb_substr( sanitize_text_field( (string) $label ), 0, 120 );
			$value = mb_substr( sanitize_text_field( (string) $value ), 0, 500 );
			if ( '' !== $label && '' !== $value ) {
				$out[] = array(
					'label' => $label,
					'value' => $value,
				);
			}
		}
		return $out;
	}

	/**
	 * Resolves the lead language: posted vaivatta_lang, else the site locale.
	 *
	 * @return string 'fi' or 'en'.
	 */
	private function lang(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock.
		$posted = isset( $_POST['vaivatta_lang'] ) ? sanitize_text_field( wp_unslash( $_POST['vaivatta_lang'] ) ) : '';
		if ( in_array( $posted, array( 'fi', 'en' ), true ) ) {
			return $posted;
		}
		return ( 0 === strpos( get_locale(), 'fi' ) ) ? 'fi' : 'en';
	}

	/**
	 * The same-site URL to send the visitor back to.
	 *
	 * @return string
	 */
	private function return_url(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see class docblock.
		$posted    = isset( $_POST['vaivatta_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['vaivatta_redirect'] ) ) : '';
		$validated = '' !== $posted ? wp_validate_redirect( $posted, '' ) : '';
		if ( '' !== $validated ) {
			return $validated;
		}
		$referer = wp_get_referer();
		return $referer ? $referer : home_url( '/' );
	}

	/**
	 * Forwards the lead to the platform. Extracted for test observation via
	 * the pre_http_request filter (same mechanism as Vaivatta_Connect tests).
	 *
	 * @param string $scope   Connected tenant scope.
	 * @param array  $payload Lead body.
	 * @return bool True on a 2xx platform response.
	 */
	protected function post_lead( string $scope, array $payload ): bool {
		$api_base = apply_filters( 'vaivatta_api_base', 'https://tyo.vaivatta.fi/api/v1' );

		$response = wp_remote_post(
			$api_base . '/leads',
			array(
				'headers' => array(
					'Content-Type' => 'application/json',
					'x-tyo-tenant' => $scope,
					'Origin'       => home_url(),
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return false;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		return $status >= 200 && $status < 300;
	}

	/**
	 * Performs a safe redirect and exits. Overridden in tests.
	 *
	 * @param string $url Target URL.
	 * @return void
	 */
	protected function do_redirect( string $url ): void {
		wp_safe_redirect( $url );
		exit;
	}
}
```

- [ ] **Step 4: Register in the plugin bootstrap**

In `tyo-wordpress/vaivatta.php` add after the form-shortcode require:

```php
require_once VAIVATTA_PATH . 'includes/class-vaivatta-lead-handler.php';
```

and inside the `init` closure:

```php
		( new Vaivatta_Lead_Handler() )->register();
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd tyo-wordpress && composer test -- --filter Test_Lead_Handler`
Expected: PASS (7 tests).

- [ ] **Step 6: Lint + commit**

Run: `cd tyo-wordpress && composer lint`
Expected: no errors (warnings on the phpcs:ignore lines are acceptable only if the existing codebase carries the same pattern — otherwise fix).

```bash
cd tyo-wordpress && git add includes/class-vaivatta-lead-handler.php vaivatta.php tests/test-lead-handler.php
git commit -m "feat: vaivatta_lead admin-post connector — forward native form posts to /leads"
```

---

### Task 6: `[vaivatta_lead_form]` shortcode (TDD)

**Files:**
- Create: `tyo-wordpress/includes/class-vaivatta-lead-form-shortcode.php`
- Modify: `tyo-wordpress/vaivatta.php`
- Test: `tyo-wordpress/tests/test-lead-form-shortcode.php`

**Interfaces:**
- Consumes: the field convention from Task 5 (`vaivatta_*` names, `action=vaivatta_lead`); `Vaivatta_Settings::get()`.
- Produces: `[vaivatta_lead_form extra_label="…" lang="fi|en" show_message="1|0" redirect="…"]` rendering a plain HTML form with `vv-lead-*` classes.

- [ ] **Step 1: Write the failing test**

Create `tyo-wordpress/tests/test-lead-form-shortcode.php`:

```php
<?php
/**
 * Tests for Vaivatta_Lead_Form_Shortcode.
 *
 * @package vaivatta
 */

/**
 * Test_Lead_Form_Shortcode class.
 */
class Test_Lead_Form_Shortcode extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => 'acme' ) );
		( new Vaivatta_Lead_Form_Shortcode() )->register();
	}

	public function tear_down() {
		delete_option( Vaivatta_Settings::OPTION );
		remove_shortcode( 'vaivatta_lead_form' );
		unset( $_GET['vaivatta_sent'] );
		parent::tear_down();
	}

	public function test_renders_native_form_with_required_fields() {
		$html = do_shortcode( '[vaivatta_lead_form lang="en"]' );
		$this->assertStringContainsString( 'class="vv-lead-form"', $html );
		$this->assertStringContainsString( 'action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"', $html );
		$this->assertStringContainsString( 'name="action" value="vaivatta_lead"', $html );
		$this->assertStringContainsString( 'name="vaivatta_name"', $html );
		$this->assertStringContainsString( 'name="vaivatta_phone"', $html );
		$this->assertStringContainsString( 'name="vaivatta_message"', $html );
		$this->assertStringContainsString( 'name="vaivatta_hp"', $html );
		$this->assertStringContainsString( 'name="vaivatta_lang" value="en"', $html );
		// No iframe — this is the native form surface.
		$this->assertStringNotContainsString( '<iframe', $html );
	}

	public function test_extra_label_adds_extra_field() {
		$html = do_shortcode( '[vaivatta_lead_form extra_label="Rekisterinumero"]' );
		$this->assertStringContainsString( 'name="vaivatta_extra[Rekisterinumero]"', $html );
	}

	public function test_show_message_0_omits_textarea() {
		$html = do_shortcode( '[vaivatta_lead_form show_message="0"]' );
		$this->assertStringNotContainsString( '<textarea', $html );
	}

	public function test_success_notice_when_sent() {
		$_GET['vaivatta_sent'] = '1';
		$html                  = do_shortcode( '[vaivatta_lead_form lang="fi"]' );
		$this->assertStringContainsString( 'vv-lead-success', $html );
	}

	public function test_empty_without_scope() {
		update_option( Vaivatta_Settings::OPTION, array( 'scope' => '' ) );
		$this->assertSame( '', do_shortcode( '[vaivatta_lead_form]' ) );
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd tyo-wordpress && composer test -- --filter Test_Lead_Form_Shortcode`
Expected: FAIL — `Class "Vaivatta_Lead_Form_Shortcode" not found`.

- [ ] **Step 3: Implement the shortcode**

Create `tyo-wordpress/includes/class-vaivatta-lead-form-shortcode.php`:

```php
<?php
/**
 * [vaivatta_lead_form] — native HTML lead form (no iframe), posts to the
 * vaivatta_lead connector. The theme styles it via the vv-lead-* classes.
 *
 * @package vaivatta
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a plain, theme-styleable lead form wired to Vaivatta_Lead_Handler.
 */
class Vaivatta_Lead_Form_Shortcode {

	/**
	 * Registers the shortcode.
	 *
	 * @return void
	 */
	public function register() {
		add_shortcode( 'vaivatta_lead_form', array( $this, 'render' ) );
	}

	/**
	 * Bilingual default labels, keyed by resolved language.
	 *
	 * @param string $lang 'fi' or 'en'.
	 * @return array<string, string>
	 */
	private function labels( string $lang ): array {
		if ( 'fi' === $lang ) {
			return array(
				'name'    => __( 'Nimi', 'vaivatta' ),
				'phone'   => __( 'Puhelin', 'vaivatta' ),
				'email'   => __( 'Sähköposti', 'vaivatta' ),
				'message' => __( 'Viesti', 'vaivatta' ),
				'submit'  => __( 'Lähetä yhteydenottopyyntö', 'vaivatta' ),
				'success' => __( 'Kiitos! Viesti lähetetty — olemme yhteydessä pian.', 'vaivatta' ),
				'error'   => __( 'Lähetys epäonnistui. Yritä uudelleen tai soita meille.', 'vaivatta' ),
			);
		}
		return array(
			'name'    => __( 'Name', 'vaivatta' ),
			'phone'   => __( 'Phone', 'vaivatta' ),
			'email'   => __( 'Email', 'vaivatta' ),
			'message' => __( 'Message', 'vaivatta' ),
			'submit'  => __( 'Send request', 'vaivatta' ),
			'success' => __( 'Thanks! Message sent — we will be in touch soon.', 'vaivatta' ),
			'error'   => __( 'Sending failed. Please try again or give us a call.', 'vaivatta' ),
		);
	}

	/**
	 * Shortcode handler.
	 *
	 * @param mixed $atts Shortcode attributes.
	 * @return string
	 */
	public function render( $atts ): string {
		$o = Vaivatta_Settings::get();
		if ( empty( $o['scope'] ) ) {
			return '';
		}

		$a = shortcode_atts(
			array(
				'extra_label'  => '',
				'lang'         => '',
				'show_message' => '1',
				'redirect'     => '',
			),
			$atts,
			'vaivatta_lead_form'
		);

		$lang = in_array( $a['lang'], array( 'fi', 'en' ), true )
			? $a['lang']
			: ( new Vaivatta_Embed() )->lang( $o );
		$l    = $this->labels( $lang );

		$extra_label = mb_substr( sanitize_text_field( (string) $a['extra_label'] ), 0, 120 );
		$redirect    = '' !== $a['redirect']
			? esc_url_raw( (string) $a['redirect'] )
			: get_permalink() . '#vaivatta-form';

		$notice = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only banner keyed on the connector's redirect.
		if ( isset( $_GET['vaivatta_sent'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$sent   = '1' === sanitize_text_field( wp_unslash( $_GET['vaivatta_sent'] ) );
			$notice = sprintf(
				'<p class="%s">%s</p>',
				$sent ? 'vv-lead-success' : 'vv-lead-error',
				esc_html( $sent ? $l['success'] : $l['error'] )
			);
		}

		$extra_field = '';
		if ( '' !== $extra_label ) {
			$extra_field = sprintf(
				'<label class="vv-lead-field"><span>%1$s</span><input type="text" name="vaivatta_extra[%2$s]"></label>',
				esc_html( $extra_label ),
				esc_attr( $extra_label )
			);
		}

		$message_field = '';
		if ( '0' !== $a['show_message'] ) {
			$message_field = sprintf(
				'<label class="vv-lead-field"><span>%s</span><textarea name="vaivatta_message" rows="4"></textarea></label>',
				esc_html( $l['message'] )
			);
		}

		return sprintf(
			'<form id="vaivatta-form" class="vv-lead-form" action="%1$s" method="post">%2$s' .
			'<input type="hidden" name="action" value="vaivatta_lead">' .
			'<input type="hidden" name="vaivatta_lang" value="%3$s">' .
			'<input type="hidden" name="vaivatta_redirect" value="%4$s">' .
			'<p class="vv-lead-hp" aria-hidden="true"><label>Website<input type="text" name="vaivatta_hp" tabindex="-1" autocomplete="off"></label></p>' .
			'<label class="vv-lead-field"><span>%5$s</span><input type="text" name="vaivatta_name" required></label>' .
			'<label class="vv-lead-field"><span>%6$s</span><input type="tel" name="vaivatta_phone" required></label>' .
			'<label class="vv-lead-field"><span>%7$s</span><input type="email" name="vaivatta_email"></label>' .
			'%8$s%9$s' .
			'<button type="submit" class="vv-lead-submit">%10$s</button>' .
			'</form>',
			esc_url( admin_url( 'admin-post.php' ) ),
			$notice, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			esc_attr( $lang ),
			esc_attr( $redirect ),
			esc_html( $l['name'] ),
			esc_html( $l['phone'] ),
			esc_html( $l['email'] ),
			$extra_field, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			$message_field, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above from escaped parts.
			esc_html( $l['submit'] )
		);
	}
}
```

- [ ] **Step 4: Register in the bootstrap**

In `tyo-wordpress/vaivatta.php`: add the require after the lead-handler require and `( new Vaivatta_Lead_Form_Shortcode() )->register();` inside the init closure.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd tyo-wordpress && composer test -- --filter Test_Lead_Form_Shortcode`
Expected: PASS (5 tests).

- [ ] **Step 6: Full plugin suite + lint + commit**

Run: `cd tyo-wordpress && composer test && composer lint`
Expected: all green.

```bash
cd tyo-wordpress && git add includes/class-vaivatta-lead-form-shortcode.php vaivatta.php tests/test-lead-form-shortcode.php
git commit -m "feat: [vaivatta_lead_form] native shortcode form (theme-styleable, no iframe)"
```

---

### Task 7: Plugin version 0.3.0 + readme

**Files:**
- Modify: `tyo-wordpress/vaivatta.php:6,23` (`Version: 0.3.0`, `VAIVATTA_VERSION`)
- Modify: `tyo-wordpress/readme.txt` (stable tag, FAQ/description, changelog)

- [ ] **Step 1: Bump versions**

`vaivatta.php`: header `Version: 0.3.0` and `define( 'VAIVATTA_VERSION', '0.3.0' );`.

- [ ] **Step 2: Document the surfaces in readme.txt**

Set `Stable tag: 0.3.0`. Add to the description/FAQ a "Connect your own form" section containing the field-convention table from Task 5 verbatim (action, `vaivatta_name`*, `vaivatta_phone`*, `vaivatta_email`, `vaivatta_message`, `vaivatta_extra[Label]`, `vaivatta_hp`, `vaivatta_redirect`, `vaivatta_lang`) and a `[vaivatta_lead_form]` section listing its attributes (`extra_label`, `lang`, `show_message`, `redirect`). Changelog entry:

```
= 0.3.0 =
* New: connect any native form to työ — post it to admin-post.php with action=vaivatta_lead and vaivatta_* field names.
* New: [vaivatta_lead_form] shortcode — a plain, theme-styleable lead form (no iframe) posting through the connector.
```

- [ ] **Step 3: Gates + commit**

Run: `cd tyo-wordpress && composer test && composer lint`
Expected: PASS.

```bash
cd tyo-wordpress && git add vaivatta.php readme.txt
git commit -m "chore: v0.3.0 — lead connector + native lead form shortcode"
```

**Do NOT push a release tag yet** — WP.org deploy needs the `WPORG_SVN_USERNAME`/`WPORG_SVN_PASSWORD` repo secrets (owner-owed since 0.2.x). Push `main`; tag when the secrets are set.

---

## Phase 3 — Theme (`themes/cho-autohuolto/`, no git)

### Task 8: Re-plumb the CHO quote form

**Files:**
- Modify: `themes/cho-autohuolto/front-page.php:148-187`
- Modify: `themes/cho-autohuolto/functions.php:86-112`

**Interfaces:**
- Consumes: Task 5's field convention. Falls back to the theme's own `cho_contact` + `wp_mail` when the plugin is inactive.

- [ ] **Step 1: Update the form block**

Replace lines 148–187 of `front-page.php` with (markup/classes unchanged; only names, the action resolution, hidden fields, and the banner condition change):

```php
			<?php $cho_lead_action = class_exists( 'Vaivatta_Lead_Handler' ) ? 'vaivatta_lead' : 'cho_contact'; ?>
			<form class="cho-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
				<div class="cho-form__title"><span data-lang="fi">Pyydä tarjous</span><span data-lang="en">Request a quote</span></div>
				<p class="cho-form__note"><span data-lang="fi">Vastaamme yleensä saman päivän aikana.</span><span data-lang="en">We usually reply the same day.</span></p>

				<?php if ( ( isset( $_GET['vaivatta_sent'] ) && '1' === $_GET['vaivatta_sent'] ) || ( isset( $_GET['sent'] ) && '1' === $_GET['sent'] ) ) : ?>
					<div class="cho-form__success">
						<span data-lang="fi">Kiitos! Viesti lähetetty — olemme yhteydessä pian.</span>
						<span data-lang="en">Thanks! Message sent — we'll be in touch soon.</span>
					</div>
				<?php endif; ?>

				<input type="hidden" name="action" value="<?php echo esc_attr( $cho_lead_action ); ?>">
				<input type="hidden" name="vaivatta_redirect" value="<?php echo esc_url( home_url( '/#yhteystiedot' ) ); ?>">
				<?php wp_nonce_field( 'cho_contact', 'cho_nonce' ); // Used by the wp_mail fallback path only; the plugin connector ignores it. ?>
				<p class="cho-hp"><label>Website<input type="text" name="vaivatta_hp" tabindex="-1" autocomplete="off"></label></p>

				<div class="cho-form__fields">
					<div class="cho-form__row">
						<div>
							<label for="cho_name"><span data-lang="fi">Nimi</span><span data-lang="en">Name</span></label>
							<input type="text" id="cho_name" name="vaivatta_name" required>
						</div>
						<div>
							<label for="cho_phone_f"><span data-lang="fi">Puhelin</span><span data-lang="en">Phone</span></label>
							<input type="tel" id="cho_phone_f" name="vaivatta_phone" required>
						</div>
					</div>
					<div>
						<label for="cho_reg"><span data-lang="fi">Rekisterinumero</span><span data-lang="en">Registration number</span></label>
						<input type="text" id="cho_reg" name="vaivatta_extra[Rekisterinumero / Reg. number]" placeholder="ABC-123">
					</div>
					<div>
						<label for="cho_message"><span data-lang="fi">Viesti</span><span data-lang="en">Message</span></label>
						<textarea id="cho_message" name="vaivatta_message" rows="3"></textarea>
					</div>
					<button type="submit" class="cho-btn cho-btn--yellow">
						<span data-lang="fi">Lähetä yhteydenottopyyntö</span>
						<span data-lang="en">Send request</span>
					</button>
				</div>
			</form>
```

- [ ] **Step 2: Update the fallback handler to the new field names**

In `functions.php`, replace the body of `cho_handle_contact()`'s field reads (lines 91–100) so the `wp_mail` fallback understands the renamed fields:

```php
	// Honeypot.
	if ( ! empty( $_POST['vaivatta_hp'] ) ) {
		wp_safe_redirect( home_url( '/?sent=1#yhteystiedot' ) );
		exit;
	}

	$name  = sanitize_text_field( wp_unslash( $_POST['vaivatta_name'] ?? '' ) );
	$phone = sanitize_text_field( wp_unslash( $_POST['vaivatta_phone'] ?? '' ) );
	$extra = isset( $_POST['vaivatta_extra'] ) && is_array( $_POST['vaivatta_extra'] )
		? array_map( 'sanitize_text_field', wp_unslash( $_POST['vaivatta_extra'] ) )
		: array();
	$reg   = isset( $extra['Rekisterinumero / Reg. number'] ) ? $extra['Rekisterinumero / Reg. number'] : '';
	$msg   = sanitize_textarea_field( wp_unslash( $_POST['vaivatta_message'] ?? '' ) );
```

(the nonce check at the top of the function and everything from `$to = …` down stays as-is).

- [ ] **Step 3: Syntax check**

Run: `php -l themes/cho-autohuolto/front-page.php && php -l themes/cho-autohuolto/functions.php`
Expected: `No syntax errors detected` ×2.

- [ ] **Step 4: Manual smoke (wp-env or the live site)**

With the plugin active and connected: submit the form → redirected back to `#yhteystiedot` with `vaivatta_sent=1` banner; the lead appears in the tenant's työ worklist with the reg-number line; a honeypot-filled post creates nothing. With the plugin deactivated: form falls back to `cho_contact` and the email arrives.

No commit — `themes/` is not a git repo; deploy by copying the theme as usual.

---

## Rollout & final verification

1. Platform → push `main`, Coolify auto-deploys. Endpoint is dark until a plugin calls it. Verify live: `curl -s -X POST https://tyo.vaivatta.fi/api/v1/leads -H 'content-type: application/json' -H 'x-tyo-tenant: <real-scope>' -d '{"name":"Smoke Test","phone":"000"}'` → `{"ok":true}` and the lead visible in the worklist (delete it after).
2. Plugin → push `main`; tag `v0.3.0` only after the `WPORG_SVN_*` secrets exist.
3. Theme → deploy to the CHO site; run the Task 8 manual smoke there.

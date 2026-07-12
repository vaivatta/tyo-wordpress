=== työ. by vaivatta. ===
Contributors: vaivatta
Tags: chat, support, ai, customer-service, live-chat
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI drafts every reply; a human approves before it reaches customers. EU-hosted. English & Finnish.

== Description ==

**työ. by vaivatta.** adds a chat widget to your site so visitors can start a conversation any time. What makes työ different: *no message reaches a customer without a person reviewing and approving it first*. The AI drafts a suggested reply; your team decides whether to send it, edit it, or ignore it.

**How it works**

1. A visitor types a message in the widget on your site.
2. työ's AI drafts a suggested reply based on your workspace knowledge base.
3. Your team sees the draft in the työ dashboard, edits if needed, and clicks Send.
4. Only then does the reply reach the visitor.

Nothing is sent automatically. There is no "bot" that replies on your behalf without your approval.

**Key features**

* AI-assisted drafts — save time without sacrificing quality or accuracy.
* Human-in-the-loop — every outgoing message is reviewed and sent by a real person.
* EU data residency — visitor messages and AI processing stay within the European Union.
* English & Finnish — the widget and dashboard support both languages out of the box.
* Simple setup — paste your workspace ID or use one-click Connect to link your työ workspace.
* Free to start — works on the free tier; optional paid plans unlock higher volumes and features.
* Minimized launcher — the widget loads as a small chat bubble and opens on click (new default; the always-open mode remains available in settings).
* Inline contact form — the [vaivatta_form] shortcode embeds a quote-request form anywhere on your site; submissions arrive as normal conversations for your team.
* Native lead form connector — the [vaivatta_lead_form] shortcode (or any form you build yourself) posts leads straight into työ, no iframe, fully theme-styleable.

**EU data residency**

All visitor data, conversation history, and AI processing are handled on EU-hosted infrastructure. vaivatta does not transfer personal data outside the European Union.

**Requirements**

* A työ account (free at vaivatta.fi).
* Your työ workspace ID (found in your dashboard under the customer chat link).

== External services ==

This plugin relies on the external **työ** service (by vaivatta). By installing and configuring this plugin, visitor chat messages entered in the widget on your site are transmitted to työ servers for processing. If you enable the "Connect with työ" flow and opt in to site learning, your site's public URL is also sent to työ so the AI can learn from your public site content. If a visitor submits a lead form (the [vaivatta_lead_form] shortcode, or any custom form wired to the plugin's lead connector), the submitted fields are also transmitted to työ servers.

**What data is sent and when:**

* **Visitor chat messages** — sent to työ whenever a visitor types a message in the chat widget.
* **Site URL** — sent to työ if the site owner initiates the "Connect with työ" flow and opts in to site content learning (optional; presented as a checkbox during connect); your site's URL is also sent automatically with every lead form submission (see below), so this is no longer the only path by which it reaches työ.
* **Lead form submissions** — sent to työ whenever a visitor submits the [vaivatta_lead_form] shortcode or a custom form wired to the plugin's lead connector (`action=vaivatta_lead`). Data sent: name and phone number (required), plus the page language and your site's URL, plus email address, message, and any custom extra fields (label/value pairs) if the form includes them.

**Service endpoints:**

* Chat widget: `https://chat.vaivatta.fi`
* Platform API: `https://tyo.vaivatta.fi`

**Legal:**

* Terms of Service: https://tyo.vaivatta.fi/terms
* Privacy Policy: https://tyo.vaivatta.fi/privacy

vaivatta is EU-hosted; all processing takes place within the European Union.

== Installation ==

1. Upload the `vaivatta` folder to the `/wp-content/plugins/` directory, or install via the WordPress plugin screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings → työ**.
4. Click **Connect with työ** to link your workspace (recommended), or paste your workspace ID manually under **Advanced**.
5. The chat widget appears on all pages of your site once a workspace ID is saved.

== Frequently Asked Questions ==

= Is työ free? =

Yes — työ has a free tier that lets you get started at no cost. Optional paid plans — Team (€39/month) and Front Desk (€89/month) — unlock higher conversation volumes, additional team members, and priority support. Payment is handled entirely by vaivatta; the plugin never processes payments.

= Do replies send automatically? =

No. työ never sends a reply to a visitor without a team member reviewing and approving it first. The AI drafts a suggested reply; a person on your team decides to send it, edit it, or discard it. This is a core design principle, not a setting.

= Does työ store visitor messages? =

Visitor messages are transmitted to and stored by the työ service (EU-hosted) so your team can review and respond to them. See the vaivatta Privacy Policy at https://tyo.vaivatta.fi/privacy for full details.

= Where is visitor data stored? =

All data — including visitor messages, conversation history, and AI processing — stays within EU-hosted infrastructure. vaivatta does not transfer personal data outside the European Union.

= What languages does the widget support? =

The widget supports English and Finnish. The language can be set to match your site's language automatically, or fixed to English or Finnish in the plugin settings.

= How do I embed the contact form? =

Add the shortcode `[vaivatta_form]` to any page or post. Optional attributes: `extra_label` adds one extra field with your label (e.g. `[vaivatta_form extra_label="Rekisterinumero"]`), `lang` fixes the language (`fi` or `en`), and `height` sets the iframe height in pixels. Your page provides the heading around the form.

= Connect your own form =

Any form on your site — hand-built, or produced by a page builder or another forms plugin — can post directly to työ's lead connector, without using the [vaivatta_lead_form] shortcode. Point the form's `action` at `admin-post.php` in your WordPress install and include these fields:

* `action` — required, must be `vaivatta_lead`.
* `vaivatta_name` — required, the visitor's name.
* `vaivatta_phone` — required, the visitor's phone number.
* `vaivatta_email` — optional, the visitor's email address.
* `vaivatta_message` — optional, a free-text message.
* `vaivatta_extra[Label]` — optional, up to 10 extra fields; use your own label as the array key, e.g. `vaivatta_extra[Rekisterinumero]`.
* `vaivatta_hp` — honeypot field; leave it empty (and hidden from real visitors) so genuine submissions aren't mistaken for spam.
* `vaivatta_redirect` — optional, a same-site URL to send the visitor back to after submitting.
* `vaivatta_lang` — optional, `fi` or `en`; defaults to your site's language.

The connector sanitizes the fields and forwards the submission to työ as a lead — no JavaScript or plugin markup required on your form. The platform accepts up to 5 lead submissions per minute per server IP (a shared ceiling across every site sending from that IP), so bursts beyond that rate are rejected — worth knowing if you're scripting or load-testing submissions.

= What is the [vaivatta_lead_form] shortcode? =

`[vaivatta_lead_form]` renders a plain HTML lead form — no iframe — that posts through the connector described above, so your theme's own CSS styles it directly. Optional attributes: `extra_label` adds one extra field with your label (e.g. `[vaivatta_lead_form extra_label="Rekisterinumero"]`), `lang` fixes the language (`fi` or `en`), `show_message` set to `0` hides the message textarea, and `redirect` sets a same-site URL to return the visitor to after submitting.

== Screenshots ==

1. The työ settings page in WordPress admin — connect your workspace and configure widget options.
2. The "Connect with työ" flow — authenticate on vaivatta, pick your workspace, and return automatically.
3. The chat widget on a live site — visitors can start a conversation; replies require team approval before sending.

== Changelog ==

= 0.3.0 =
* New: connect any native form to työ — post it to admin-post.php with action=vaivatta_lead and vaivatta_* field names.
* New: [vaivatta_lead_form] shortcode — a plain, theme-styleable lead form (no iframe) posting through the connector.

= 0.2.1 =
* Widget launcher CSS/JS now load through the WordPress enqueue API (wp_add_inline_style / wp_add_inline_script) instead of printed tags. No functional changes.

= 0.2.0 =
* Widget now starts minimized as a chat bubble with an unread badge (new default). The always-open behavior is available under Settings → työ → Widget display.
* New [vaivatta_form] shortcode: embed an inline contact/quote form; submissions create normal conversations.
* Widget language setting now takes effect inside the messenger.

= 0.1.0 =
* Initial release — chat widget embed, EN/FI support, Connect with työ flow, EU-hosted.

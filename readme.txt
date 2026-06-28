=== vaivatta — AI Front Desk ===
Contributors: vaivatta
Tags: chat, support, ai, customer-service, live-chat
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI drafts every reply; a human approves before it reaches customers. EU-hosted. English & Finnish.

== Description ==

**vaivatta — AI Front Desk** adds a chat widget to your site so visitors can start a conversation any time. What makes vaivatta different: *no message reaches a customer without a person reviewing and approving it first*. The AI drafts a suggested reply; your team decides whether to send it, edit it, or ignore it.

**How it works**

1. A visitor types a message in the widget on your site.
2. vaivatta's AI drafts a suggested reply based on your workspace knowledge base.
3. Your team sees the draft in the vaivatta dashboard, edits if needed, and clicks Send.
4. Only then does the reply reach the visitor.

Nothing is sent automatically. There is no "bot" that replies on your behalf without your approval.

**Key features**

* AI-assisted drafts — save time without sacrificing quality or accuracy.
* Human-in-the-loop — every outgoing message is reviewed and sent by a real person.
* EU data residency — visitor messages and AI processing stay within the European Union.
* English & Finnish — the widget and dashboard support both languages out of the box.
* Simple setup — paste your workspace ID or use one-click Connect to link your vaivatta workspace.
* Free to start — works on the free tier; optional paid plans unlock higher volumes and features.

**EU data residency**

All visitor data, conversation history, and AI processing are handled on EU-hosted infrastructure. vaivatta does not transfer personal data outside the European Union.

**Requirements**

* A vaivatta account (free at vaivatta.fi).
* Your vaivatta workspace ID (found in your dashboard under the customer chat link).

== External services ==

This plugin relies on the external **vaivatta** service. By installing and configuring this plugin, visitor chat messages entered in the widget on your site are transmitted to vaivatta servers for processing. If you enable the "Connect with vaivatta" flow and opt in to site learning, your site's public URL is also sent to vaivatta so the AI can learn from your public site content.

**What data is sent and when:**

* **Visitor chat messages** — sent to vaivatta whenever a visitor types a message in the chat widget.
* **Site URL** — sent to vaivatta only if the site owner initiates the "Connect with vaivatta" flow and opts in to site content learning (optional; presented as a checkbox during connect).

**Service endpoints:**

* Chat widget: `https://messenger.vaivatta.fi`
* Platform API: `https://app.vaivatta.fi`

**Legal:**

* Terms of Service: https://vaivatta.fi/terms
* Privacy Policy: https://vaivatta.fi/privacy

vaivatta is EU-hosted; all processing takes place within the European Union.

== Installation ==

1. Upload the `vaivatta` folder to the `/wp-content/plugins/` directory, or install via the WordPress plugin screen.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Settings → vaivatta**.
4. Click **Connect with vaivatta** to link your workspace (recommended), or paste your workspace ID manually under **Advanced**.
5. The chat widget appears on all pages of your site once a workspace ID is saved.

== Frequently Asked Questions ==

= Is vaivatta free? =

Yes — vaivatta has a free tier that lets you get started at no cost. Optional paid plans (€9/month and €49/month) unlock higher conversation volumes, additional team members, and priority support. Payment is handled entirely by vaivatta; the plugin never processes payments.

= Do replies send automatically? =

No. vaivatta never sends a reply to a visitor without a team member reviewing and approving it first. The AI drafts a suggested reply; a person on your team decides to send it, edit it, or discard it. This is a core design principle, not a setting.

= Does vaivatta store visitor messages? =

Visitor messages are transmitted to and stored by the vaivatta service (EU-hosted) so your team can review and respond to them. See the vaivatta Privacy Policy at https://vaivatta.fi/privacy for full details.

= Where is visitor data stored? =

All data — including visitor messages, conversation history, and AI processing — stays within EU-hosted infrastructure. vaivatta does not transfer personal data outside the European Union.

= What languages does the widget support? =

The widget supports English and Finnish. The language can be set to match your site's language automatically, or fixed to English or Finnish in the plugin settings.

== Screenshots ==

1. The vaivatta settings page in WordPress admin — connect your workspace and configure widget options.
2. The "Connect with vaivatta" flow — authenticate on vaivatta, pick your workspace, and return automatically.
3. The chat widget on a live site — visitors can start a conversation; replies require team approval before sending.

== Changelog ==

= 0.1.0 =
* Initial release — chat widget embed, EN/FI support, Connect with vaivatta flow, EU-hosted.

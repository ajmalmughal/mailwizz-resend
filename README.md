# MailWizz Resend delivery server

Free, open-source [MailWizz](https://www.mailwizz.com/) delivery server
extension for the [Resend](https://resend.com/) email API.

Sends campaigns over HTTP instead of SMTP, which matters if your host blocks
outbound mail ports. Bounces, suppressions and spam complaints come back
through signed webhooks and are processed automatically.

Requires **MailWizz 2.7.0+**. Installs as a zip. No core files are modified.

---

## Features

- **HTTP delivery** — no SMTP ports required, attachments supported
- **Proper multipart** — plain text and HTML are sent as separate parts, not
  HTML only
- **Unrestricted custom headers** — `List-Unsubscribe`,
  `List-Unsubscribe-Post`, `List-Id`, `X-Report-Abuse` and anything set under
  MailWizz's "Additional headers" all pass through intact, and Resend's DKIM
  signature covers the list headers
- **Verified webhooks** — Svix signature checking with replay protection, so a
  guessed url cannot be used to unsubscribe or blacklist your subscribers
- **Accurate bounce classification** — Resend states the bounce class outright,
  so there is no SMTP reply code guesswork
- **Suppression handling** — messages silently dropped by Resend's suppression
  list are recorded as hard bounces instead of vanishing
- **Fails safely** — a bad key, suspended account or exhausted quota
  deactivates the server instead of burning through the rest of your list
- **Tagged sends** — every message carries the campaign, subscriber and server
  id as Resend tags, so events are traceable in their dashboard
- **Upgrade safe** — registered through MailWizz hooks

## Install

1. Download the latest zip from
   [Releases](https://github.com/ajmalmughal/mailwizz-resend/releases)
2. In MailWizz: **Backend → Extend → Extensions → Upload extension**
3. Enable **Resend Email API**

Or upload the `resend` folder manually to `apps/extensions/`. The folder must
keep that name — it becomes the path alias the form view lookup uses.

## Setup

### 1. Verify your sending domain

Resend dashboard → **Domains** → add your domain and complete the DNS records.

The `from` address on the delivery server must use a verified domain, or every
send fails with a 403 and the server deactivates itself.

### 2. Turn Resend's tracking OFF

On the domain's settings page, leave **Open Tracking** and **Click Tracking**
switched off.

MailWizz does its own open and click tracking. Resend's click tracking rewrites
links that MailWizz has already rewritten, which breaks MailWizz's click
reporting and puts a third party domain in the path of your unsubscribe link.

### 3. Create an API key

Resend dashboard → **API Keys**.

**Sending access is enough.** This extension only ever calls the send endpoint.
There is no need to hand MailWizz a full-access key.

The key is shown once, at creation. Copy it immediately.

### 4. Create the delivery server

In MailWizz, create a **Resend Email API** delivery server. Paste the API key,
fill in the from address and name, and save. Leave the webhook signing secret
empty for now — it cannot exist yet.

### 5. Create the webhook

The webhook url contains this server's id, so the server has to be saved first.
Reopen it and copy the url shown in the blue "Webhook setup" panel. It looks
like:

```
https://your-mailwizz-site.com/index.php/dswh/10
```

Resend dashboard → **Webhooks** → **Add Webhook**. Paste that url and select
**these four events only**:

```
email.bounced
email.complained
email.failed
email.suppressed
```

Do not select "All Events". The other fifteen produce nothing MailWizz can use,
and on a large campaign `email.delivered` alone would mean one HTTP request to
your server per recipient.

### 6. Paste the signing secret

Resend shows a **Signing secret** on the webhook page, starting with `whsec_`.
Copy it into the **Webhook signing secret** field on the delivery server and
save.

Until this is filled in, incoming events are rejected and a warning is written
to the MailWizz log. Sending works without it; bounce processing does not.

## How events are handled

| Resend event | MailWizz action |
|---|---|
| `email.bounced`, `bounce.type: Permanent` | Hard bounce, subscriber blacklisted |
| `email.bounced`, `bounce.type: Temporary` | Soft bounce, no action against subscriber |
| `email.suppressed` | Hard bounce, subscriber blacklisted |
| `email.complained` | Feedback loop complaint, subscriber unsubscribed |
| `email.failed` | Internal bounce, recorded only |

`email.suppressed` matters more than it looks. Resend accepts the API call and
returns a message id even when the recipient is on your account's suppression
list, so without this event those subscribers would sit on your list forever
looking perfectly healthy while receiving nothing.

`email.failed` is treated as internal rather than as a bounce because it
describes a problem with the message or the account, such as a quota being
reached, rather than with the recipient. It never blacklists anyone.

## Things worth knowing

**One webhook endpoint per delivery server.** The url carries the server id, so
each delivery server needs its own webhook. Resend limits webhook endpoints by
plan, so that limit is also the maximum number of Resend delivery servers you
can run against a single Resend account.

**Rate limit is per team, not per key.** The default is 10 requests per second
across every API key on the account, raisable on request. Running several
delivery servers against one Resend account does not multiply it.

**Campaigns bill as transactional volume.** Because sending goes through the
`/emails` endpoint, MailWizz campaigns count against Resend's transactional
quota, which is billed by email volume. Resend's separate marketing pricing is
billed per stored contact and buys their Broadcasts and Contacts features,
which this extension deliberately does not use — MailWizz already does all of
that.

**Keep your complaint rate low.** Resend requires accounts to stay under a
0.08% spam rate, which is tighter than Gmail's own 0.3% guidance for bulk
senders. Exceeding it can pause sending on the account. A paused account looks
exactly like a working one from inside MailWizz until deliveries start failing,
so watch the Metrics page.

**Resend keeps 30 days of data.** Logs and metrics beyond that are gone, which
is another reason the bounce and complaint records this extension writes into
MailWizz are worth having.

**Gmail does not send complaint events.** Gmail has no per-message feedback
loop, by design. Yahoo and Outlook.com do, and their complaints arrive as
`email.complained`. For Gmail recipients the equivalent signal is the one-click
unsubscribe header, which goes straight to MailWizz without Resend being
involved.

**Resend replaces two headers.** The `Message-ID` becomes an Amazon SES one,
and the `Feedback-ID` MailWizz generates is overwritten by Amazon's. Neither
affects anything here, since events are correlated by Resend's own message id
rather than by either header.

**Transactional and marketing share a reputation.** If the same domain sends
both campaigns and password resets, a bad campaign will hurt delivery of the
password resets. Use a separate subdomain or Resend account for transactional
mail if that matters to you.

## What this extension does not use

Resend's Broadcasts, Automations, Contacts, Segments, Topics, Templates and
Audiences are all deliberately untouched. They duplicate what MailWizz already
does, and mixing the two would leave your lists out of sync. A delivery server
is a transport, not a second list manager.

In particular, the `topic_id` and `template` fields are never set. Setting
`topic_id` would let Resend's own subscription system silently fail messages to
recipients who opted out on their side, invisibly to MailWizz.

## Troubleshooting

**The server type does not appear in the dropdown.** Disable and re-enable the
extension. `allowedApps` is read at load time.

**Every send fails with a 403.** The from domain is not verified in Resend, or
the domain in the from address does not match a verified one.

**Sends work but bounces never appear.** Check that the signing secret is
filled in, and that the webhook url in Resend matches the one shown on the
delivery server form exactly, server id included.

**Nothing at all in the MailWizz log.** Confirm the webhook is Enabled in
Resend and that its Events tab shows delivery attempts. A 200 there means the
signature verified and the event was accepted.

## Requirements

- MailWizz 2.7.0 or newer
- PHP with `curl` and `hash` (both standard)
- A Resend account with a verified sending domain

## License

MIT — see [LICENSE](LICENSE).

Free to use, modify and redistribute. If you find it useful, please do not
resell it as your own product. Improvements and bug reports are welcome.

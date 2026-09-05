# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/).

## [1.0.0] - 2026-09-05

Initial release.

### Added
- Resend as a MailWizz delivery server type, sending over the HTTP API so no
  outbound SMTP ports are needed.
- Multipart sending using Resend's separate `text` and `html` fields, with
  MailWizz's own plain text part rather than a generated one.
- Full custom header passthrough, including `List-Unsubscribe`,
  `List-Unsubscribe-Post`, `List-Id`, `X-Report-Abuse` and user configured
  additional headers.
- Native `reply_to` support.
- Attachment support, with the 40MB post-base64 limit enforced before the
  request is made.
- Resend tags on every send carrying the campaign uid, subscriber uid and
  delivery server id.
- Webhook handling for `email.bounced`, `email.complained`, `email.failed` and
  `email.suppressed`.
- Svix signature verification with a five minute timestamp tolerance, multiple
  signature support, and constant time comparison. Events that cannot be
  verified are dropped and logged.
- Bounce classification from Resend's `bounce.type`, mapping `Permanent` to
  hard and `Temporary` to soft.
- `email.suppressed` treated as a hard bounce, so messages silently dropped by
  Resend's suppression list are not mistaken for successful sends.
- Automatic deactivation on a missing, invalid, restricted or suspended API
  key, on an unverified sending domain, and on an exhausted daily or monthly
  quota.
- `retry-after` captured from rate limited responses and surfaced in the
  delivery log.
- An error summary on the delivery server form, so validation failures on
  hidden columns are visible rather than producing an empty red banner.

### Notes
- Verified end to end against live Resend traffic: sending, header passthrough,
  DKIM coverage of the list headers, signature verification, hard bounce,
  suppression, and spam complaints from both Yahoo and Outlook.com.
- The `email.failed` payload shape is handled per Resend's documentation but
  was not observed live.

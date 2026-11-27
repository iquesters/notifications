# Changelog

All notable changes to `iquesters/notifications` are documented here.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [Unreleased]

### Added (when sending fails)
- **Tried again without a queue or cron**: a failed email is tried again in the same send, 5 then 15 seconds later, up to `tries` (3) in all. This runs after the page has answered, so nobody waits; a short mail-server hiccup no longer loses the email. Still failing: *Failed*, with the reason.
- **Send again** in *Sent messages*: open a message for its details (to whom, subject, status, tries, why it failed) and, when it failed, *Send again* (form `notification-log-resend`, `POST /notifications/logs/{uid}/resend`, one try, says why when it still fails). A message holding a one-time code (type option `secret`) can't be sent again: the code isn't kept; the person asks for a new one.
- `notifications:seed` gives an existing *Sent messages* table its record sections.

### Added (an organisation's own email account)
- **The footer's last line** credits the platform: *Platform developed by Iquesters Solutions LLP*, linked to iquesters.com (settings `footer_credit_text` / `footer_credit_url`; empty text hides it).
- **Send emails through: the app's email account (default) or our own**, on *Email settings*: the From address, outgoing server (SMTP host, port, security TLS / SSL / none), username and password. Fields show only for *our own*; the password is stored encrypted, never shown again, and kept when left empty. Every email of the organisation (and *Send me a test* in Templates) then goes out from its address through its account (`OrganisationMailer`); a failing account shows as *Failed* with the server's message.
- **Send a test email** on *Email settings* (form `notification-email-test`): through the saved settings to the admin, with the last result shown.

### Added (phase 1: email)
- **App messages and organisation messages**: the email's header shows who it is from. An organisation's message has its **logo and name** (no logo: the app's); an app message (type option `platform`, e.g. a sign-in code) has the **app's logo and name**, its wording edited only by super-admins and not copied to organisations. The footer: *Sent by {organisation} via {app}*.
- **`Notifications::sendNow()`**: sends one email at once and waits (e.g. a sign-in code), throwing when it can't be sent. Type options **`required`** (can't be switched off) and **`secret`** (values such as the code are never kept in the log; shown as ••••).
- **Message types registry** (`NotificationTypes`): modules declare their messages with placeholders, sample values and default wording.
- **Templates** (`notification_templates`): the platform's default wording (kept in step with the modules' defaults until a super-admin changes it) and each organisation's own; switch a message off per organisation. Placeholders `{{name}}`, Markdown formatting, values always escaped; no Blade.
- **Sending** (`Notifications::send()`): logged per channel and sent after the caller's transaction commits, **right after the page has answered** (default: no queue worker needed; a failure is logged as failed) or through a queue with retries (setting `use_queue`); skipped (with the reason) without an address, when switched off, or when sending is turned off; never throws.
- **Email** in one layout (the organisation's logo and name, a footer), from the organisation's sender name with its reply-to address (`notification_channels`, settings encrypted).
- **Log** (`notification_logs`): sending, sent, failed or skipped, the error, attempts; with a queue, retries with back-off.
- **Screens** (super-admins and org-admins, form schemas and generic tables): **Templates** (wording, preview with sample values, send yourself a test, use the default again), **Email settings**, **Sent messages**.
- Settings (DB conf): `send_enabled`, `use_queue` (default off), `queue`, `tries`.
- `notifications:seed`: the module, permission `manage-notifications` (role `admin`), menu, form schemas, the platform's templates.
- Migration `2026_11_30_000009_create_notifications_tables`.

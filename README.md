# iquesters/notifications

Messages for the iquesters modules: each module **declares** its messages (e.g. *Payment not accepted*), and this module sends them, with wording each organisation can change. Email now; WhatsApp, in-app and more later.

## What it does

- **Message types** declared by the installed modules (payment, event, community…), each with its placeholders and default wording.
- **Templates**: the platform's default wording, which an organisation can replace with its own (and go back to the default). Placeholders such as `{{name}}`, light formatting (**bold**, lists, links); no code runs in a template.
- **Email settings** per organisation: the sender name people see and the reply-to address. Mail goes out through the app's mail server (`MAIL_*` in `.env`).
- **Sending** after the change that caused it is saved: right after the page has answered (default, no queue worker needed), or through a queue with retries (`use_queue`). A **log** of every message (sent, failed, skipped, with the reason).

Screens (sidebar **Notifications**, super-admins and org-admins): **Templates** (edit, preview with sample values, send yourself a test, use the default again), **Email settings**, **Sent messages**.

## Install

```bash
composer require iquesters/notifications
php artisan migrate
php artisan notifications:seed
```

No queue worker is needed (messages are sent right after the page answers); with `use_queue` on, run one (`php artisan queue:work`). Set `MAIL_MAILER`, `MAIL_HOST`… and `MAIL_FROM_ADDRESS` in `.env`.

## Declaring and sending messages (for module authors)

In the module's service provider `boot()`, only when this module is installed:

```php
if (class_exists(\Iquesters\Notifications\Support\NotificationTypes::class)) {
    app(\Iquesters\Notifications\Support\NotificationTypes::class)->register('payment.rejected', [
        'label' => 'Payment not accepted',
        'description' => 'The payer can send the details again.',
        'module' => 'payment',
        'variables' => ['name' => 'Payer name', 'reason' => 'Why'],
        'sample' => ['name' => 'Rahul Sen', 'reason' => 'Not in the bank statement yet'],
        'defaults' => ['email' => ['subject' => '…', 'body' => "Dear {{name}},\n\n…"]],
    ]);
}
```

When it happens:

```php
\Iquesters\Notifications\Notifications::send('payment.rejected', $organisationId,
    ['email' => $email, 'name' => $name, 'user_id' => $userId],
    ['name' => $name, 'reason' => $reason],
    ref: $payment);
```

`send()` never throws; a missing address or a switched-off message is logged as skipped.

## Settings (DB conf, `iquesters/notifications`)

| Key | Default | |
|---|---|---|
| `send_enabled` | `true` | Off: nothing is sent (logged as skipped), e.g. on a test copy |
| `use_queue` | `false` | Send through a queue (needs a worker; retries). Off: sent right after the page answers |
| `queue` | `default` | The queue the messages go through (with `use_queue`) |
| `tries` | `3` | Attempts before a message is marked failed: without a queue, tried again in the same send (5 s, then 15 s later); with `use_queue`, by the worker with back-off. A failed message can be sent again from *Sent messages* |

See `NOTIFICATIONS-SPEC.md` in the platform repo for the plan (WhatsApp, per-organisation SMTP, in-app).

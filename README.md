
# SNN Tickets

WordPress plugin for event ticket generation, management, and validation with QR codes, batch email invitations, and public scanning capabilities.
<br><br>

**If you saved time and money with this project. Support it 😉** 



<a href="https://github.com/sponsors/sinanisler">
<img src="https://img.shields.io/badge/Consider_Supporting_My_Projects_❤-GitHub-d46" width="300" height="auto" />
</a>




<img width="1431" height="932" alt="image" src="https://github.com/user-attachments/assets/6407ff08-fec2-47f0-b5b8-df4f05e2e57f" />

<img width="48%" height="auto" alt="image" src="https://github.com/user-attachments/assets/1f2715d1-aba9-4864-85ca-f78b757a28ab" />

<img width="48%" height="auto" alt="image" src="https://github.com/user-attachments/assets/d45e3de7-c61b-410a-8294-4308fb99c727" />

<img width="1600" height="738" alt="image" src="https://github.com/user-attachments/assets/5a8cd89c-df97-4a22-881d-c4d53a6e8b76" />


<img width="48%" height="auto" alt="image" src="https://github.com/user-attachments/assets/d71b0868-5648-4b60-a255-bf60e139e8e4" />

<img width="48%" height="auto" alt="image" src="https://github.com/user-attachments/assets/9ff488f0-15ab-459d-897c-3e82581e756c" />





## 🎫 Features

### Registration forms
- **Visual form builder** — drag to reorder fields, edit them inline, and watch a live preview update as you go
- **Field types** — text, email, phone, number, date, paragraph, dropdown, radio, checkboxes, consent checkbox, hidden
- **Field mapping** — point one field at the ticket holder's name and one at their email; everything else is stored as custom answers
- **Shortcode** — drop a form on any page with `[snn_ticket_form id="1"]`
- **Capacity limits** — cap registrations per form and show a "fully booked" message once it fills
- **One per email** — optionally block a second registration from the same address
- **Spam protection** — honeypot field, minimum fill time, and a per-IP submission throttle

### Approval logic
Every form decides for itself what happens on submit:
- **Approve automatically** — the ticket is created and emailed straight away
- **Hold for review** — the submitter gets a confirmation email; you approve or reject from the Submissions screen
- **Decide by rules** — auto-approve only when the answers match, with the rest held or rejected

Rules run against any field, with operators for *is exactly*, *is not*, *contains*, *starts with*, *is empty*, *is not empty*, *is checked*, *is not checked*, *email domain is*, and *is one of*. Match on **all** rules or **any**, and choose whether a non-match is held for review or rejected outright.

### Submissions
- Review screen with pending / approved / rejected tabs, filtered by form
- Approve, reject, resend or delete — one at a time or in bulk
- Every custom answer stored and viewable per submission
- CSV export of submissions with all custom fields
- Permanent delete removes the submission, its ticket, and its queued mail (GDPR erasure)

### QR codes, generated in PHP
- **No browser needed** — QR codes are rendered on the server, so a form submitted at 3am still gets its ticket
- **No dependencies** — pure PHP, using GD when it is available and a built-in PNG encoder when it is not
- **Signed URLs** — each QR encodes a URL carrying an HMAC signature, so any phone camera opens your scan page and forged codes are rejected
- **Unguessable filenames** — cached PNGs are named by hash, so the QR directory cannot be enumerated
- **Automatic sizing** — the QR version is chosen to fit the payload rather than being fixed

### Email
- **Server-side queue** — sending runs on WP-Cron in the background; close the tab and it keeps going
- **Rate limited** — set how many messages go out per minute to stay inside your host's limits
- **Retries** — failed sends are retried up to three times, with the error recorded
- **Inline QR** — the QR is attached to the message rather than hotlinked, so it renders even when a client blocks remote images
- **Three template roles** — ticket, submission confirmation, and rejection, each selectable per form
- **Placeholders** — `{name}`, `{email}`, `{ticket}`, `{qr_inline}`, `{qr}`, `{scan_url}`, `{list}`, `{form}`, `{site}`, `{date}`, and `{field:key}` for any form field
- **Queue monitor** — pending / sent / failed counts, per-message errors, retry and process-now controls

### Tickets
- Manual and bulk ticket generation with unique codes
- CSV import with a downloadable template
- Ticket lists with inline editing of name and email
- Per-ticket QR preview and one-click ticket email
- Batch email for a whole list, skipping anyone already sent

### Scanning & validation
- **Public scan page** via `[tickets_scan_page]`, using `BarcodeDetector` where available and jsQR elsewhere
- **Camera or manual entry** — pasted scan URLs are accepted as well as bare codes
- **Repeat scans flagged** — an already-scanned ticket is shown as a warning, not a silent pass
- **Rate limited** — the public endpoint throttles per IP, and unsigned lookups never increment the scan count, so codes cannot be enumerated or burned through

### Dashboard
- Lists, tickets, validations, forms, submissions awaiting review, and mail queue at a glance
- System check for GD/zlib availability, cron health, and the signing key

## Getting started

1. **Create a ticket list** — Tickets → Tickets Generator, or import one from CSV
2. **Set the scan page** — put `[tickets_scan_page]` on a page, then paste its URL into Tickets → Settings so QR codes point at it
3. **Build a form** — Tickets → Forms → Add New, pick the list, arrange the fields, and choose how submissions are approved
4. **Publish it** — paste the form's shortcode onto any page
5. **Watch it work** — submissions land in Tickets → Submissions, tickets go out through Tickets → Mail Queue

### Cron

Sending relies on WP-Cron. If your site sets `DISABLE_WP_CRON`, point a real cron job at `wp-cron.php`; the Settings page will tell you if this needs attention. You can always press **Process now** on the Mail Queue screen.

## Tests

The plugin ships with two standalone test suites that need only PHP — no WordPress install:

```bash
php tests/qr-test.php      # QR encoder: full round-trip decode, all 40 versions x 4 EC levels
php tests/logic-test.php   # signatures, placeholders, field sanitising, approval rules
```

`qr-test.php` re-derives the GF(256) arithmetic, function-module map and format-info decoder from the spec, then decodes every generated matrix back to its payload and checks that the Reed-Solomon syndromes vanish — so a bug in the encoder cannot cancel itself out.

## Requirements

- WordPress 5.8+
- PHP 8.1+
- zlib or GD (almost always present; the plugin uses whichever it finds)

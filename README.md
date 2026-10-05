 
# SNN Tickets 
 
WordPress plugin for event sign-ups and tickets: a guided event setup, sign-up forms with automatic or rule-based approval, designed ticket emails with PDF, Apple Wallet and Google Wallet passes, and a mobile door scanner. Pure PHP, no Composer, no external services required.
<br><br>

## How it's organised

The **Tickets** menu has five entries. On a WooCommerce site shop managers can use all of it (only resetting the QR signature stays with administrators); without WooCommerce it is for administrators. The `snn_tickets_capability` filter changes who gets in.

| Menu | What's there |
|---|---|
| **Home** | What needs you (people waiting for approval, failed emails, full events), upcoming events, last check-ins, and a getting-started list |
| **Events** | Every event: upcoming, past and undated. Each event has its own page (below) |
| **Add New Event** | A five-step guide: the event, questions, who gets a ticket, ticket & email, go live |
| **Emails** | Sent & waiting (every email, failures first to fix, retry and view), saved templates, sender name and address, sending speed and a test email |
| **Settings** | Style, door & scanner, Apple & Google Wallet, wording of the public pages, advanced |

Each **event page** has these tabs (Sell tickets only when WooCommerce is active):

- **People**: everyone in one list. Sign-ups waiting for approval, tickets, check-ins, cancelled and declined, with filters, search, bulk actions, and a side panel per person (answers, ticket, history, private note). Add one person, import a spreadsheet, make blank tickets, email everyone, download the list.
- **Sign-up form**: questions, who gets a ticket (everyone / you approve / rules with a "Try it" tester), spot limit, messages, form colour.
- **Sell tickets**: ticket types (WooCommerce products) with price, quantity and how many people each admits; add, reprice, stop and restart sales without leaving the event.
- **Emails**: the event's own ticket, "we got your request", "sorry, no spot" and notice-to-you emails, already written, with a live preview, test send and a shared template library.
- **Door**: live check-in count, recent check-ins with undo, and a QR that opens the scanner on a phone.
- **Event settings**: date, venue, notes, web address, ticket style, attachments, duplicate, delete.

## Links, no pages to create

Every event is live as soon as it's created:

| Link | What it is |
|---|---|
| `/events/{event}/` | Sign-up page, shown inside your theme |
| `/events/{event}/door/` | Door scanner for that event only |
| `/events/door/` | Door scanner for every event |
| `/events/ticket/{code}/` | The attendee's own ticket |

`events` can be changed in Settings → Door & scanner. Changing an event's web address keeps the old link working. For custom pages, the shortcodes still work: `[snn_ticket_form id="1"]` and `[tickets_scan_page]` (or `[tickets_scan_page list="3"]`).

## Features

### Sign-up forms
- Question types: short and long answer, dropdown, pick one, pick several, "I agree" box, email, phone, number, date, hidden value
- Name and email are fixed questions, so tickets always reach someone; every other answer becomes an email chip
- One ticket per email, spot limit (people waiting hold a spot), "spots left", closed/full message
- Submits in the background with errors under each field; works without JavaScript
- Spam protection: honeypot, minimum fill time, per-IP throttle

### Approval
- **Everyone, right away**, **I approve each person**, or **automatically by rules**
- Rules read as a sentence: *is, is not, contains, starts with, ends with, is one of, email domain is, is filled in, is empty, is ticked, is not ticked*, with all/any and "everyone else waits / is declined"

### Emails
- Each event has its own emails, already written; blank means the built-in wording
- Editor with named chips (Name, Event, Date, Ticket with QR, Wallet buttons, answers…), a small toolbar and an HTML mode
- Live preview (desktop and phone), send a test, reset to default
- **Template library**: "Save as template" from any email, "Start from a template" in any event; events keep their own copy
- Designed, Outlook-safe email shell with logo, footer and plain-text alternative
- Background queue on WP-Cron with rate limiting and an email log. Queuing mail asks for a send straight away, so tickets are not held until the next visitor on a quiet site
- A failed email is tried again after 5 minutes, 30 minutes and 2 hours; an email cut off mid-send is picked up again; sent emails leave the log after 90 days
- "Notice to you" goes to one or several addresses

### Tickets
- PDF (built-in writer, Turkish and Central European letters), Apple Wallet (.pkpass), Google Wallet, calendar invite (.ics)
- Six styles (Minimal, Boarding pass, Midnight, Festival, Corporate, Classic stub) with colour tweaks and logo; events can pick their own
- Cancelled tickets print as cancelled and their wallet passes are voided

### Selling tickets with WooCommerce
- Optional: without WooCommerce the plugin works as before
- Any simple or variable product can be a ticket: tick **Ticket** next to Virtual / Downloadable and the **Tickets** tab opens. It starts as **+ New event**, named after the product ("Jazz Night – VIP" → "Jazz Night"): add a date, publish, and the event exists with sign-ups closed. Or pick an existing event; its date, venue and spots show in the same fields and edits there update the event. Variations make tiers (VIP, Student…)
- **Tickets per purchase** for couple or family tickets
- **Attendee details** (optional per product): the product page asks the event's sign-up questions once per ticket, and each ticket is emailed to its attendee. Without it, every ticket goes to the buyer
- Tickets are issued when an order is paid (processing / completed) and cancelled when it is cancelled, refunded, failed or trashed. Partial refunds cancel that many tickets, newest and unused first. Reopened orders get their old codes back
- The event's spot limit is shared by the shop and the sign-up form; unpaid orders hold their spots (pending for WooCommerce's hold-stock time, on-hold until paid)
- **Passing tickets on**: someone buying several tickets gets the first one; each of the others waits for a name and has a private link. The buyer copies the link or emails it from their ticket list, and the guest fills in their name (and the event's questions) on a page that needs no account. Claiming gives the ticket a new code, so a QR the buyer kept stops working. Links can be sent again or taken back until they are claimed, by the buyer or by you from the person's panel in People. Opening a used link says the ticket was claimed and which (masked) address it went to, without showing the ticket
- **Buying for someone else**: when the product's **Gift option** is ticked (Tickets tab, More options; off by default), the product page asks "Who are these tickets for?". *For me* makes the first ticket the buyer's; *It's a gift* makes none of them the buyer's, and an optional email gets all of that line's tickets in one email after payment (a couples ticket or three tickets for one friend arrive together). A gift and tickets for the buyer can share one cart
- **Give it away**: plans change, so the buyer's own ticket can still be passed on from their ticket list, as long as it has not been used at the door. It keeps working for the buyer until someone claims it
- The quantity box on the product page (and in the block cart) stops at the spots that are left
- **Ticket types that stay with the buyer**: untick "Passing on" for a product (e.g. named VIP tickets) and every ticket it makes is in the buyer's name
- "Notice to you" can also tell you when a guest claims a ticket
- **One email per person**: the buyer gets one "Your tickets" email per event (their own ticket plus the links), each guest gets their own ticket email. Both are editable in the event's Emails tab, like every other email
- The buyer's ticket list is on the thank-you page, in My Account → Orders, in the order emails and at a private link (`/events/tickets/{order}/`) that works without an account
- Unclaimed tickets work at the door for the buyer by default; **Tickets need a name to get in** (Sell tickets → Guests) makes the scanner refuse them
- People has a "Not named yet" filter and shows each waiting ticket's link; WooCommerce → Orders gets a Tickets column ("4 · 2 without a name · waiting 3 days"), a filter, and an "Email the buyer their tickets again" action. The order screen lists each line's ticket codes
- The event page lists what is for sale with Buy buttons
- Works with the block and classic cart and checkout, and with HPOS

### Wording
- Settings → Wording holds every text of the claim page, the buyer's ticket list and the shop pages. A blank box uses the standard wording, translated into the site's language

### Door
- Mobile scanner: full-screen green / amber / red, sound, vibration, counter, recent scans, typed codes
- Staff only: logged-in admins or volunteers with the door PIN (24 hours)
- Only the scanner checks tickets in. A ticket that arrives by link (a phone's camera app, an email) asks "Check in this ticket?" first; an attendee opening their own QR just sees their ticket
- Repeat scans show when the ticket was first used; cancelled tickets and tickets for another event are refused

## Upgrading from 0.25 and earlier

The database upgrades itself the first time an admin page loads. Each event gets a web address, the wording from its form's emails and templates is copied into the event, and old admin bookmarks redirect to their new place. Existing shortcodes and printed QR codes keep working.

## Tests

Three standalone test suites that need only PHP — no WordPress install:

```bash
php tests/qr-test.php        # QR encoder: full round-trip decode, all 40 versions x 4 EC levels
php tests/logic-test.php     # signatures, placeholders, field sanitising, approval rules
php tests/features-test.php  # events, designs, PDF, Apple/Google Wallet, calendar, check-in rules, CSV import
```

`features-test.php` checks output with independent tools when they are installed: `pdftotext` reads the PDF back, and the `openssl` CLI verifies the Apple Wallet signature against a throwaway CA. Missing tools are reported as skipped.

## Requirements

- WordPress 5.8+ (pretty permalinks recommended for short event links)
- PHP 8.1+ with mbstring
- zlib or GD
- Optional: zip + openssl extensions for Apple Wallet, openssl for Google Wallet

Sending relies on WP-Cron. If your site sets `DISABLE_WP_CRON`, point a real cron job at `wp-cron.php`; Settings → Advanced tells you if this needs attention.

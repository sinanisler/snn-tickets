<?php
/**
 * Wording the public reads outside of emails: the claim page, the buyer's
 * ticket list and the shop pages. Emails have their own editor per event.
 *
 * Each text has a translated default; Settings → Wording can replace it.
 * A blank box means the default, so a German site stays German until
 * someone deliberately writes their own words.
 */

if (!defined('ABSPATH')) exit;

class SNN_T_Texts {

    const OPTION = 'snn_tickets_texts';

    /**
     * The one list of texts: group => [title, intro, fields => key => [label, default, placeholders]].
     */
    public static function groups() {
        static $groups = null;
        if ($groups !== null) return $groups;
        $groups = [
            'claim' => [
                'title'  => __('Claim page', 'snn-tickets'),
                'intro'  => __('Where a guest fills in their name for a ticket someone passed on to them.', 'snn-tickets'),
                'fields' => [
                    'claim_title_from' => [__('Heading', 'snn-tickets'), __('{buyer} got you a ticket to {event}', 'snn-tickets'), '{buyer} {event}'],
                    'claim_title'      => [__('Heading, when the sender is unknown', 'snn-tickets'), __('A ticket to {event} is waiting for you', 'snn-tickets'), '{event}'],
                    'claim_intro'      => [__('Text under the heading', 'snn-tickets'), __("Tell us who's coming. Your ticket arrives by email straight away and opens here too.", 'snn-tickets'), ''],
                    'claim_submit'     => [__('Button', 'snn-tickets'), __('Get my ticket', 'snn-tickets'), ''],
                    'claim_done'       => [__('Message on the ticket afterwards', 'snn-tickets'), __("It's yours! Your ticket is also on its way to your inbox.", 'snn-tickets'), ''],
                    'claim_used_title' => [__('Link already used: heading', 'snn-tickets'), __('This ticket has been claimed', 'snn-tickets'), ''],
                    'claim_used_text'  => [__('Link already used: text', 'snn-tickets'), __('The ticket to {event} was emailed to {email}. Look in that inbox (and the spam folder) for the email with the QR code.', 'snn-tickets'), '{event} {email}'],
                    'claim_used_help'  => [__('Link already used: help', 'snn-tickets'), __("Can't find it? Contact the organiser and they can send it again.", 'snn-tickets'), ''],
                    'claim_dead_title' => [__('Link taken back: heading', 'snn-tickets'), __('This link does not work any more', 'snn-tickets'), ''],
                    'claim_dead_text'  => [__('Link taken back: text', 'snn-tickets'), __('The ticket has already been claimed, or the person who sent it took it back. Ask them for a new link.', 'snn-tickets'), ''],
                ],
            ],
            'list' => [
                'title'  => __("Buyer's ticket list", 'snn-tickets'),
                'intro'  => __('On the thank-you page, in My Account, at the private "manage your tickets" link and in the order emails.', 'snn-tickets'),
                'fields' => [
                    'manage_title'      => [__('Page heading', 'snn-tickets'), __('Your tickets · Order #{order}', 'snn-tickets'), '{order}'],
                    'list_title'        => [__('Heading per event', 'snn-tickets'), __('Your tickets for {event}', 'snn-tickets'), '{event}'],
                    'email_title'       => [__('Heading in order emails', 'snn-tickets'), __('Your tickets', 'snn-tickets'), ''],
                    'your_ticket'       => [__("The buyer's own ticket", 'snn-tickets'), __('Your ticket', 'snn-tickets'), ''],
                    'you'               => [__('Marker next to the buyer', 'snn-tickets'), __('(you)', 'snn-tickets'), ''],
                    'open'              => [__('Open ticket button', 'snn-tickets'), __('Open ticket', 'snn-tickets'), ''],
                    'unnamed'           => [__('A ticket without a name', 'snn-tickets'), __('Not named yet', 'snn-tickets'), ''],
                    'unnamed_hint'      => [__('Hint under it', 'snn-tickets'), __('Send it on: your guest fills in their name and gets their own ticket.', 'snn-tickets'), ''],
                    'email_link_note'   => [__('Hint in emails, above the link', 'snn-tickets'), __('Send this link to your guest:', 'snn-tickets'), ''],
                    'copy'              => [__('Copy button', 'snn-tickets'), __('Copy link', 'snn-tickets'), ''],
                    'copied'            => [__('After copying', 'snn-tickets'), __('Copied', 'snn-tickets'), ''],
                    'email_placeholder' => [__('Email box', 'snn-tickets'), __("Guest's email", 'snn-tickets'), ''],
                    'email_button'      => [__('Email button', 'snn-tickets'), __('Email it', 'snn-tickets'), ''],
                    'sent'              => [__('A sent ticket', 'snn-tickets'), __('Sent to {email}', 'snn-tickets'), '{email}'],
                    'sent_hint'         => [__('Hint under it', 'snn-tickets'), __('Waiting for them to fill in their name.', 'snn-tickets'), ''],
                    'resend'            => [__('Send again button', 'snn-tickets'), __('Send again', 'snn-tickets'), ''],
                    'takeback'          => [__('Take back button', 'snn-tickets'), __('Take back', 'snn-tickets'), ''],
                    'guest'             => [__('A claimed ticket', 'snn-tickets'), __('Has their ticket ({email})', 'snn-tickets'), '{email}'],
                    'email_emailed'     => [__('A claimed ticket, in emails', 'snn-tickets'), __('Ticket emailed to {email}', 'snn-tickets'), '{email}'],
                    'msg_sent'          => [__('After sending', 'snn-tickets'), __('Sent to {email}. They fill in their name and get the ticket.', 'snn-tickets'), '{email}'],
                    'msg_taken'         => [__('After taking back', 'snn-tickets'), __('Taken back. The link you sent no longer works.', 'snn-tickets'), ''],
                    'giveaway'          => [__('Give it away button', 'snn-tickets'), __('Give it away', 'snn-tickets'), ''],
                    'msg_giveaway'      => [__('After giving away', 'snn-tickets'), __('This ticket now has a link to pass on. It still works for you until someone claims it.', 'snn-tickets'), ''],
                    'manage_button'     => [__('Manage button in emails', 'snn-tickets'), __('Manage your tickets', 'snn-tickets'), ''],
                    'claim_button'      => [__('Claim button in the gift email', 'snn-tickets'), __('Claim my ticket', 'snn-tickets'), ''],
                    'claim_button_n'    => [__('Claim buttons, when several tickets are given', 'snn-tickets'), __('Claim ticket {n}', 'snn-tickets'), '{n}'],
                ],
            ],
            'shop' => [
                'title'  => __('Shop pages', 'snn-tickets'),
                'intro'  => __('The product page of a ticket, and the ticket list on the event page.', 'snn-tickets'),
                'fields' => [
                    'for_question'     => [__('Me-or-gift question', 'snn-tickets'), __('Who are these tickets for?', 'snn-tickets'), ''],
                    'for_me'           => [__('Choice: for me', 'snn-tickets'), __('For me', 'snn-tickets'), ''],
                    'for_me_hint'      => [__('Hint under "for me"', 'snn-tickets'), __('The first ticket is yours. Any others come with a link to pass on.', 'snn-tickets'), ''],
                    'for_gift'         => [__('Choice: a gift', 'snn-tickets'), __("It's a gift", 'snn-tickets'), ''],
                    'for_gift_hint'    => [__('Hint under "a gift"', 'snn-tickets'), __('Every ticket comes with a link to pass on. Nothing is made out to you.', 'snn-tickets'), ''],
                    'gift_email_label' => [__('Gift email box', 'snn-tickets'), __('Their email (optional)', 'snn-tickets'), ''],
                    'gift_email_hint'  => [__('Hint under the gift email', 'snn-tickets'), __("We email them the tickets once you've paid. Leave it empty to send them yourself.", 'snn-tickets'), ''],
                    'cart_gift'        => [__('Cart: gift label', 'snn-tickets'), __('Gift', 'snn-tickets'), ''],
                    'cart_gift_for'    => [__('Cart: gift with an address', 'snn-tickets'), __('The tickets go to {email} after payment', 'snn-tickets'), '{email}'],
                    'cart_gift_link'   => [__('Cart: gift without an address', 'snn-tickets'), __('You get a link to pass on', 'snn-tickets'), ''],
                    'when'            => [__('"When" label', 'snn-tickets'), __('When', 'snn-tickets'), ''],
                    'where'           => [__('"Where" label', 'snn-tickets'), __('Where', 'snn-tickets'), ''],
                    'attendees_intro' => [__('Above the guest details', 'snn-tickets'), __('Who is coming? Each ticket is emailed to the person named on it.', 'snn-tickets'), ''],
                    'attendee_legend' => [__('Each guest block', 'snn-tickets'), __('Ticket {n}', 'snn-tickets'), '{n}'],
                    'choose_tickets'  => [__('Shop button for tickets with guest details', 'snn-tickets'), __('Choose tickets', 'snn-tickets'), ''],
                    'shop_title'      => [__('Event page: heading', 'snn-tickets'), __('Tickets', 'snn-tickets'), ''],
                    'buy'             => [__('Event page: Buy button', 'snn-tickets'), __('Buy', 'snn-tickets'), ''],
                    'choose'          => [__('Event page: Choose button', 'snn-tickets'), __('Choose', 'snn-tickets'), ''],
                    'sold_out'        => [__('Event page: sold out', 'snn-tickets'), __('Sold out', 'snn-tickets'), ''],
                    'sale_soon'       => [__('Ticket sales not open yet', 'snn-tickets'), __('Ticket sales open on {date}.', 'snn-tickets'), '{date}'],
                    'sale_over'       => [__('Ticket sales ended', 'snn-tickets'), __('Ticket sales have ended.', 'snn-tickets'), ''],
                ],
            ],
        ];
        return $groups;
    }

    /** key => default */
    public static function defaults() {
        $out = [];
        foreach (self::groups() as $g) foreach ($g['fields'] as $k => $f) $out[$k] = $f[1];
        return $out;
    }

    public static function saved() {
        $v = function_exists('get_option') ? get_option(self::OPTION, []) : [];
        return is_array($v) ? $v : [];
    }

    /**
     * The text for a key, with {placeholders} filled in. Values are plain
     * text; escape them where they are printed.
     */
    public static function get($key, $tokens = []) {
        $saved = self::saved();
        $text  = isset($saved[$key]) && trim((string)$saved[$key]) !== '' ? (string)$saved[$key] : (self::defaults()[$key] ?? $key);
        $map = [];
        foreach ((array)$tokens as $k => $v) $map['{' . $k . '}'] = (string)$v;
        return strtr($text, $map);
    }

    /** Store only what differs from the default, so better defaults (and translations) still arrive. */
    public static function save($in) {
        $defaults = self::defaults();
        $out = [];
        foreach ($defaults as $k => $d) {
            $v = trim(sanitize_text_field((string)($in[$k] ?? '')));
            if ($v !== '' && $v !== $d) $out[$k] = $v;
        }
        update_option(self::OPTION, $out, false);
        return $out;
    }
}

<?php

// Vocabularies that come back from the worker or the database as fixed values.
// Anything the worker composes as free text (activity evidence, scoring reasons,
// log lines) stays in the language the worker wrote it in — see docs/LANGUAGES.md.

return [

    'run_status' => [
        'queued' => 'Queued',
        'claimed' => 'Starting',
        'running' => 'Running',
        'completed' => 'Finished',
        'failed' => 'Failed',
        'cancelled' => 'Cancelled',
    ],

    'run_type' => [
        'scrape' => 'Search',
        'reprocess' => 'Re-process',
        'import' => 'Import',
    ],

    'role' => [
        'admin' => 'Admin',
        'member' => 'Member',
    ],

    'priority' => [
        'Hot' => 'Hot',
        'Warm' => 'Warm',
        'Cold' => 'Cold',
    ],

    // Scoring areas, keyed by the `key` the worker sends.
    'area' => [
        'web' => 'Website & UX',
        'conversion' => 'Conversion & lead capture',
        'paidMedia' => 'Paid media & tracking',
        'social' => 'Social presence & content',
        'automation' => 'Chat & response',
        'brand' => 'Brand & listing consistency',
    ],

    // Suggested packages, keyed by the exact string the worker sends.
    'pitch' => [
        'New website' => 'New website',
        'Website redesign / UI-UX' => 'Website redesign / UI-UX',
        'Hosting, domain & email' => 'Hosting, domain & email',
        'Landing page & lead capture' => 'Landing page & lead capture',
        'Online booking' => 'Online booking',
        'E-commerce sales creatives' => 'E-commerce sales creatives',
        'Meta awareness & lead-gen campaign' => 'Meta awareness & lead-gen campaign',
        'Google Discovery & SEM creatives' => 'Google Discovery & SEM creatives',
        'Analytics setup' => 'Analytics setup',
        'Reels & content production' => 'Reels & content production',
        'AI chatbot kit' => 'AI chatbot kit',
        'Community management' => 'Community management',
        'Claim & fix Google listing' => 'Claim & fix Google listing',
        'Branding & identity' => 'Branding & identity',
    ],

    // Website status, keyed by the exact string the worker sends. The two with a
    // platform name in brackets are matched on their prefix.
    'website_status' => [
        'No website' => 'No website',
        'Has website' => 'Has website',
        'Website down' => 'Website down',
        'Website parked/expired' => 'Website parked/expired',
        'Website looks like a clone/spam site' => 'Website looks like a clone/spam site',
        'Has website (not checked: robots.txt)' => 'Has website (not checked: robots.txt)',
        'Has website (blocks automated checks)' => 'Has website (blocks automated checks)',
        'Social page only' => 'Social page only (:platform)',
        'Third-party page only' => 'Third-party page only (:platform)',
    ],

    // Why a business ended up in a contactability tier.
    'reason_code' => [
        'EMAIL_FOUND' => 'Email found',
        'WHATSAPP' => 'WhatsApp',
        'CONTACT_FORM' => 'Contact form',
        'INSTAGRAM_DM' => 'Instagram DM',
        'PHONE_CALL' => 'Phone call / SMS',
        'FACEBOOK_MESSENGER_ONLY' => 'Facebook Messenger only',
        'WEBSITE_NOT_CHECKABLE' => 'Website could not be checked',
        'PHONE_ONLY' => 'Phone only',
        'ADDRESS_ONLY' => 'Address only',
        'NO_CONTACT_DETAILS' => 'No contact details',
    ],
];

<?php

namespace App\Services;

use App\Models\RunBusiness;

/**
 * CSV column layouts, following the original GMSCraper leads.csv / unreachable.csv
 * / excluded.csv, with the scoring areas taken from whatever the worker sent.
 */
class RunRows
{
    public const LINKS = ['yes' => 'OK', 'risky' => 'risky', 'no' => 'NO: opener must stand alone'];

    public static function lead(RunBusiness $b): array
    {
        $emails = (array) $b->d('channels.emails', []);
        $site = (array) $b->d('site', []);
        $trackers = (array) ($site['trackers'] ?? []);

        $row = [
            'priority' => $b->priority,
            'score' => $b->score,
            'contact_tier' => $b->contact_tier,
            'contact_reason' => $b->reason_code,
            'links_in_opener' => self::LINKS[$b->d('contact.linksInOpener')] ?? '',
            'email' => $emails[0]['value'] ?? '',
            'email_found_on' => $emails[0]['source'] ?? '',
            'other_emails' => array_map(fn ($e) => $e['value'] ?? '', array_slice($emails, 1)),
            'whatsapp' => $b->d('channels.whatsapp.display', ''),
            'whatsapp_url' => $b->d('channels.whatsapp.url', ''),
            'contact_form_url' => $b->d('channels.contactFormUrl', ''),
            'instagram' => $b->d('channels.instagram.handle') ? '@'.$b->d('channels.instagram.handle') : '',
            'instagram_url' => $b->d('channels.instagram.url', ''),
            'facebook_page' => $b->d('channels.facebook.url', ''),
            'facebook_messaging' => $b->d('channels.facebook.messaging', ''),
            'phone' => $b->phone,
            'phone_is_a_channel' => self::phoneIsChannel($b) ? 'yes' : 'no (stored only)',
            'name' => $b->name,
            'category' => $b->category,
            'website' => $b->website ?: '(none)',
            'website_status' => $b->website_status,
            'pitch' => $b->pitch ?? [],
            'reasons' => array_map(fn ($r) => ($r['reason'] ?? '').' (+'.($r['points'] ?? 0).')', (array) $b->d('scoring.reasons', [])),
        ];

        // One column per scoring area, e.g. web_pts, conversion_pts, paid_media_pts.
        foreach ((array) $b->d('scoring.areas', []) as $area) {
            if (isset($area['key'])) {
                $row[str_replace('-', '_', \Illuminate\Support\Str::snake($area['key'])).'_pts'] = $area['points'] ?? 0;
            }
        }

        return $row + [
            'rating' => $b->rating,
            'reviews' => $b->review_count,
            'newest_review' => $b->newest_review,
            'activity_evidence' => (array) $b->d('activity.reasons', []),
            'online_booking' => $site['onlineBooking'] ?? '',
            'has_contact_form' => $site['contactForm'] ?? '',
            'chat_widget' => $site['chatWidget'] ?? '',
            'mobile_friendly' => $site['mobileFriendly'] ?? '',
            'site_builder' => $site['builder'] ?? '',
            'ecommerce' => $site['ecommerce'] ?? '',
            'meta_pixel' => $trackers['metaPixel'] ?? '',
            'google_ads_tag' => $trackers['googleAds'] ?? '',
            'analytics' => $site['measures'] ?? '',
            'address' => $b->address,
            'maps_url' => $b->maps_url,
            'query' => $b->query,
        ];
    }

    public static function unreachable(RunBusiness $b): array
    {
        return [
            'name' => $b->name,
            'category' => $b->category,
            'reason_code' => $b->reason_code,
            'why' => $b->d('contact.why', ''),
            'website_status' => $b->website_status,
            'website' => $b->website ?: '(none)',
            'phone' => $b->phone,
            'address' => $b->address,
            'sources_checked' => self::sources($b),
            'maps_url' => $b->maps_url,
            'query' => $b->query,
        ];
    }

    public static function inactive(RunBusiness $b): array
    {
        return [
            'name' => $b->name,
            'category' => $b->category,
            'status_on_maps' => $b->d('place.businessStatus', ''),
            'reason' => (array) $b->d('activity.reasons', []),
            'newest_review' => $b->newest_review,
            'reviews' => $b->review_count,
            'website' => $b->website,
            'maps_url' => $b->maps_url,
            'query' => $b->query,
        ];
    }

    /** Whether this run treated phone calls as a usable channel. */
    public static function phoneIsChannel(RunBusiness $b): bool
    {
        return in_array('phone call / SMS', (array) $b->d('contact.usableChannels', []), true);
    }

    /** @return string[] */
    public static function sources(RunBusiness $b): array
    {
        return array_map(
            fn ($s) => ($s['source'] ?? '').': '.($s['outcome'] ?? ''),
            (array) $b->d('channels.sources', [])
        );
    }
}

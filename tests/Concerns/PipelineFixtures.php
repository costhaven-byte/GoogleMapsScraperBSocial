<?php

namespace Tests\Concerns;

use App\Enums\RunStatus;
use App\Enums\RunType;
use App\Models\Run;
use App\Models\User;
use App\Models\Worker;
use App\Services\BusinessRecorder;
use Illuminate\Support\Str;

/**
 * Synthetic records shaped exactly like the worker pipeline output
 * (the same structure as GMSCraper's leads.json).
 */
trait PipelineFixtures
{
    protected function place(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Acme Heating & Cooling',
            'category' => 'HVAC contractor',
            'address' => '100 Main St, Chicago, IL 60601, United States',
            'phone' => '+13125550100',
            'website' => 'https://acme-hvac.example/',
            'rating' => 4.7,
            'reviewCount' => 180,
            'businessStatus' => 'Operational',
            'socialProfiles' => [],
            'listingEmails' => [],
            'mapsBookingLink' => '',
            'mapsUrl' => 'https://www.google.com/maps/place/Acme+Heating',
            'placeKey' => '0x'.Str::lower(Str::random(12)).':0x'.Str::lower(Str::random(12)),
            'reviewDates' => ['a month ago', '3 months ago'],
            'ownerResponseDates' => ['3 weeks ago'],
            'reviewTexts' => ['Great service, came the same day.'],
            'reviewsSortedByNewest' => false,
            'reviewsHidden' => false,
        ], $overrides);
    }

    protected function activeActivity(): array
    {
        return ['active' => true, 'reasons' => ['Recent owner reply: 3 weeks ago'], 'newestReview' => 'a month ago', 'newestOwnerReply' => '3 weeks ago', 'newestActivityMonths' => 0.75];
    }

    protected function leadItem(array $overrides = [], array $place = []): array
    {
        $p = $this->place($place);

        return array_replace([
            'stage' => 'lead',
            'query' => 'hvac in chicago',
            'place' => $p,
            'activity' => $this->activeActivity(),
            'site' => [
                'url' => $p['website'], 'kind' => ['type' => 'own-site'], 'reachable' => true, 'socialLinks' => [],
                'fetchLog' => [['source' => 'Website homepage', 'url' => $p['website'], 'outcome' => 'ok']],
                'status' => 200, 'blocked' => false, 'loadSeconds' => 4.2, 'finalUrl' => $p['website'], 'https' => true,
                'parked' => false, 'wordCount' => 320, 'builder' => 'Wix', 'mobileFriendly' => false, 'copyrightYear' => 2021,
                'onlineBooking' => false, 'bookingProvider' => '', 'contactForm' => false, 'chatWidget' => false,
                'phoneOnSite' => true, 'phoneMatches' => true, 'addressMatches' => true, 'nameMatch' => 1,
            ],
            'socials' => [],
            'channels' => [
                'emails' => [['value' => 'office@acme-hvac.example', 'source' => $p['website'], 'via' => 'mailto link', 'confidence' => 'high (on their own domain)']],
                'rejectedEmails' => [],
                'contactFormUrl' => null,
                'instagram' => null,
                'facebook' => null,
                'whatsapp' => null,
                'phone' => $p['phone'],
                'sources' => [['source' => 'Website homepage', 'url' => $p['website'], 'outcome' => 'ok']],
            ],
            'contact' => ['tier' => 'A', 'reasonCode' => 'EMAIL_FOUND', 'linksInOpener' => 'yes', 'usableChannels' => ['email'], 'summary' => 'email office@acme-hvac.example'],
            'websiteStatus' => 'Has website',
            'scoring' => [
                'score' => 62,
                'areas' => [
                    ['key' => 'web', 'label' => 'Website & UX', 'max' => 25, 'points' => 25],
                    ['key' => 'conversion', 'label' => 'Conversion & lead capture', 'max' => 20, 'points' => 16],
                    ['key' => 'paidMedia', 'label' => 'Paid media & tracking', 'max' => 20, 'points' => 13],
                    ['key' => 'social', 'label' => 'Social presence & content', 'max' => 15, 'points' => 6],
                    ['key' => 'automation', 'label' => 'Chat & response', 'max' => 10, 'points' => 2],
                    ['key' => 'brand', 'label' => 'Brand & listing consistency', 'max' => 10, 'points' => 0],
                ],
                'breakdown' => ['web' => 25, 'conversion' => 16, 'paidMedia' => 13, 'social' => 6, 'automation' => 2, 'brand' => 0],
                'reasons' => [['area' => 'web', 'points' => 10, 'reason' => 'Website is not mobile-friendly']],
                'pitch' => ['Website redesign / UI-UX', 'Meta awareness & lead-gen campaign'],
                'bookingType' => 'quote',
            ],
            'priority' => 'Hot',
        ], $overrides);
    }

    protected function unreachableItem(array $place = []): array
    {
        $p = $this->place(['name' => 'Phone Only Plumbing', 'website' => '', 'placeKey' => '0xd:0x'.Str::random(8)] + $place);

        return [
            'stage' => 'unreachable',
            'query' => 'hvac in chicago',
            'place' => $p,
            'activity' => $this->activeActivity(),
            'site' => ['kind' => ['type' => 'none'], 'reachable' => false, 'socialLinks' => [], 'fetchLog' => []],
            'socials' => [],
            'channels' => ['emails' => [], 'rejectedEmails' => [], 'contactFormUrl' => null, 'instagram' => null, 'facebook' => null, 'whatsapp' => null, 'phone' => $p['phone'], 'sources' => [['source' => 'Google Maps listing', 'url' => $p['mapsUrl'], 'outcome' => 'ok']]],
            'contact' => ['tier' => 'D', 'reasonCode' => 'PHONE_ONLY', 'linksInOpener' => 'no', 'usableChannels' => [], 'summary' => 'phone only', 'why' => 'no website and no email/social links on the Maps listing'],
            'websiteStatus' => 'No website',
        ];
    }

    protected function inactiveItem(array $place = []): array
    {
        return [
            'stage' => 'inactive',
            'query' => 'hvac in chicago',
            'place' => $this->place(['name' => 'Closed Furnace Co', 'reviewDates' => ['3 years ago'], 'placeKey' => '0xe:0x'.Str::random(8)] + $place),
            'activity' => ['active' => false, 'reasons' => ['Last sign of life was a review "3 years ago" (limit 11 months)'], 'newestReview' => '3 years ago'],
        ];
    }

    /** @return array{0: Worker, 1: string} */
    protected function makeWorker(string $name = 'Office PC'): array
    {
        return Worker::issue($name, null);
    }

    protected function makeRun(?User $user = null, array $attributes = []): Run
    {
        $run = new Run;
        $run->forceFill(array_merge([
            'user_id' => $user?->id,
            'type' => RunType::Scrape,
            'status' => RunStatus::Queued,
            'queries' => ['hvac in chicago'],
            'options' => ['limit' => 20, 'months' => 11, 'noSocials' => false, 'allowUnverified' => false],
        ], $attributes))->save();

        return $run;
    }

    /** A finished run holding one lead, one unreachable and one inactive business. */
    protected function completedRun(?User $user = null, array $leadOverrides = [], array $leadPlace = []): Run
    {
        $run = $this->makeRun($user, ['status' => RunStatus::Completed, 'finished_at' => now()]);
        $recorder = app(BusinessRecorder::class);
        $items = [$this->leadItem($leadOverrides, $leadPlace), $this->unreachableItem(), $this->inactiveItem()];
        $recorder->recordPlaces($run, array_map(fn ($i) => ['query' => $i['query'], 'place' => $i['place']], $items));
        $recorder->recordBusinesses($run, $items);
        $recorder->refreshCounts($run);

        return $run->refresh();
    }
}

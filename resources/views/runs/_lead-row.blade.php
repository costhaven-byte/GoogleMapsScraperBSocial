@php
    $c = (array) $b->d('channels', []);
    $s = (array) $b->d('site', []);
    $emails = (array) ($c['emails'] ?? []);
    $areas = (array) $b->d('scoring.areas', []);
    $phoneIsChannel = \App\Services\RunRows::phoneIsChannel($b);
    $weakSite = preg_match('/^no website|social page|third-party|down|parked/i', (string) $b->website_status);
    $siteStatus = \App\Support\Vocab::websiteStatus($b->website_status);
    $yn = fn ($v) => $v === true ? __('app.common.yes') : ($v === false ? __('app.common.no') : __('app.common.dash'));
    $sourceLabel = fn ($src) => \App\Support\SafeUrl::http($src) ? \App\Support\SafeUrl::short($src) : $src;
@endphp
<tr class="clickable" data-toggle-detail tabindex="0" aria-expanded="false">
    <td><span class="text-lg font-bold tabular-nums">{{ $b->score ?? __('app.common.dash') }}</span><br>@if ($b->priority)<span class="badge badge-{{ $b->priority }}">{{ \App\Support\Vocab::priority($b->priority) }}</span>@endif</td>
    <td>
        <div class="flex min-w-[190px] max-w-[260px] items-start gap-2">
            @if ($b->contact_tier)<span class="tier tier-{{ $b->contact_tier }}">{{ $b->contact_tier }}</span>@endif
            <div class="[overflow-wrap:anywhere]">
                @if ($b->email)
                    <span dir="ltr">{{ $b->email }}</span>
                @elseif (! empty($c['whatsapp']))
                    {{ __('app.runs.detail.whatsapp') }} <span dir="ltr">{{ $c['whatsapp']['display'] ?? '' }}</span>
                @elseif (! empty($c['contactFormUrl']))
                    {{ __('app.runs.detail.contact_form') }}{{ ! empty($c['instagram']['handle']) ? ' · @'.$c['instagram']['handle'] : '' }}
                @elseif (! empty($c['instagram']['handle']))
                    {{ __('app.runs.detail.instagram') }} <span dir="ltr">{{ '@'.$c['instagram']['handle'] }}</span>
                @elseif ($phoneIsChannel && $b->phone)
                    <span dir="ltr">{{ $b->phone }}</span> <div class="muted text-xs">{{ __('app.runs.detail.call_or_sms') }}</div>
                @elseif (! empty($c['facebook']))
                    {{ __('app.runs.detail.facebook') }} <div class="nolinks">{{ __('app.runs.detail.no_links') }}</div>
                @else
                    <span class="muted">{{ __('app.runs.detail.no_contact_check') }}</span>
                @endif
            </div>
        </div>
    </td>
    <td><div class="font-semibold">{{ $b->name }}</div><div class="muted text-xs">{{ $b->category }}</div></td>
    <td @class(['weak' => $weakSite])>{{ $siteStatus }}</td>
    <td>
        <div class="grid min-w-[190px] grid-cols-[86px_1fr_26px] items-center gap-x-2 gap-y-[3px] text-[11px] muted">
            @foreach ($areas as $area)
                @php($max = max(1, (int) ($area['max'] ?? 1)))
                <span>{{ \App\Support\Vocab::area($area) }}</span>
                <div class="meter"><i style="width: {{ round(((int) ($area['points'] ?? 0)) / $max * 100) }}%"></i></div>
                <span>{{ $area['points'] ?? 0 }}</span>
            @endforeach
        </div>
    </td>
    <td>@foreach ($b->pitch ?? [] as $pitch)<span class="pill">{{ \App\Support\Vocab::pitch($pitch) }}</span>@endforeach</td>
    <td>{{ $b->review_count }}@if ($b->rating)<div class="muted text-xs">★ {{ $b->rating }}</div>@endif</td>
    <td class="muted">{{ $b->newest_review }}</td>
</tr>
<tr class="detail" hidden>
    <td colspan="8">
        <div class="grid gap-5 py-1 md:grid-cols-2 xl:grid-cols-4">
            <div>
                <h4 class="detail-h">{{ __('app.runs.detail.how_to_reach', ['tier' => $b->contact_tier ?? '?']) }}</h4>
                <ul class="detail-list">
                    @foreach ($emails as $e)
                        <li><b>{{ __('app.runs.detail.email') }}</b> <span dir="ltr">{{ $e['value'] ?? '' }}</span> <span class="muted">({{ $e['confidence'] ?? '' }} · {{ $e['via'] ?? '' }} — <x-ext-link :url="$e['source'] ?? ''" :label="$sourceLabel($e['source'] ?? '')" />)</span></li>
                    @endforeach
                    @foreach ((array) ($c['rejectedEmails'] ?? []) as $e)
                        <li class="muted"><s dir="ltr">{{ $e['value'] ?? '' }}</s>: {{ __('app.runs.detail.rejected_email', ['reason' => $e['reason'] ?? '']) }}</li>
                    @endforeach
                    @if (! empty($c['whatsapp']))
                        <li><b>{{ __('app.runs.detail.whatsapp') }}</b> <x-ext-link :url="$c['whatsapp']['url'] ?? ''" :label="$c['whatsapp']['display'] ?? __('app.runs.detail.whatsapp_chat')" /> <span class="muted">({{ __('app.runs.detail.found_on', ['source' => $sourceLabel($c['whatsapp']['source'] ?? '')]) }})</span></li>
                    @endif
                    @if (! empty($c['contactFormUrl']))
                        <li><b>{{ __('app.runs.detail.contact_form') }}</b> <x-ext-link :url="$c['contactFormUrl']" /></li>
                    @endif
                    @if (! empty($c['instagram']))
                        <li><b>{{ __('app.runs.detail.instagram') }}</b> <x-ext-link :url="$c['instagram']['url'] ?? ''" :label="'@'.($c['instagram']['handle'] ?? '')" /> <span class="muted">({{ __('app.runs.detail.found_on', ['source' => $c['instagram']['source'] ?? '']) }})</span></li>
                    @endif
                    @if (! empty($c['facebook']))
                        <li><b>{{ __('app.runs.detail.facebook') }}</b> <x-ext-link :url="$c['facebook']['url'] ?? ''" /> <span class="muted">{{ __('app.runs.detail.messaging', ['state' => $c['facebook']['messaging'] ?? __('app.runs.detail.unverified')]) }}{{ ! empty($c['facebook']['messagingNote']) ? ' ('.$c['facebook']['messagingNote'].')' : '' }}</span></li>
                    @endif
                    @if ($b->phone)
                        <li @class(['muted' => ! $phoneIsChannel])>
                            <b>{{ __('app.runs.detail.phone') }}</b> <span dir="ltr">{{ $b->phone }}</span>
                            <span class="muted">{{ $phoneIsChannel ? __('app.runs.detail.phone_channel') : __('app.runs.detail.phone_not_channel') }}</span>
                        </li>
                    @endif
                </ul>
                @if ($b->d('contact.linksInOpener'))
                    <div class="{{ $b->d('contact.linksInOpener') === 'no' ? 'nolinks' : 'muted' }} mt-1.5">{{ __('app.runs.detail.links.'.$b->d('contact.linksInOpener')) }}</div>
                @endif
                @if (! empty($c['sources']))
                    <details class="mt-2">
                        <summary class="muted cursor-pointer text-xs">{{ __('app.common.sources_checked') }}</summary>
                        <ul class="detail-list">@foreach (\App\Services\RunRows::sources($b) as $source)<li>{{ $source }}</li>@endforeach</ul>
                    </details>
                @endif
            </div>
            <div>
                <h4 class="detail-h">{{ __('app.runs.detail.why_score') }}</h4>
                <ul class="detail-list">
                    @forelse ((array) $b->d('scoring.reasons', []) as $r)
                        <li>{{ $r['reason'] ?? '' }}@if (! empty($r['points'])) <span class="muted">+{{ $r['points'] }}</span>@endif</li>
                    @empty
                        <li>{{ __('app.runs.detail.no_gaps') }}</li>
                    @endforelse
                </ul>
            </div>
            <div>
                <h4 class="detail-h">{{ __('app.runs.detail.audit') }}</h4>
                @if ($b->website && ! empty($s['reachable']))
                    @php($t = (array) ($s['trackers'] ?? []))
                    <ul class="detail-list">
                        <li>{{ __('app.runs.detail.mobile_https_load', ['mobile' => $yn($s['mobileFriendly'] ?? null), 'https' => $yn($s['https'] ?? null), 'seconds' => $s['loadSeconds'] ?? '?']) }}</li>
                        <li>{{ __('app.runs.detail.form_booking_chat', ['form' => $yn($s['contactForm'] ?? null), 'booking' => $yn($s['onlineBooking'] ?? null), 'chat' => $yn($s['chatWidget'] ?? null)]) }}</li>
                        <li>{{ __('app.runs.detail.pixels', ['pixel' => $yn($t['metaPixel'] ?? null), 'ads' => $yn($t['googleAds'] ?? null), 'analytics' => $yn($s['measures'] ?? null)]) }}</li>
                        <li>{{ __('app.runs.detail.store_video', ['store' => ($s['ecommerce'] ?? '') ?: __('app.common.no'), 'video' => $yn($s['hasVideo'] ?? null)]) }}</li>
                        <li>{{ __('app.runs.detail.phone_address_match', ['phone' => $yn($s['phoneMatches'] ?? null), 'address' => $yn($s['addressMatches'] ?? null)]) }}</li>
                        <li>{{ __('app.runs.detail.builder', ['builder' => ($s['builder'] ?? '') ?: __('app.common.dash'), 'year' => $s['copyrightYear'] ?? __('app.common.dash')]) }}</li>
                    </ul>
                @else
                    <div class="weak">{{ $siteStatus }}</div>
                @endif
                <div class="mt-2">
                    <x-ext-link :url="$b->maps_url" :label="__('app.runs.detail.google_maps')" />
                    @if ($b->website) · <x-ext-link :url="$b->website" :label="__('app.runs.detail.website')" />@endif
                </div>
            </div>
            <div>
                <h4 class="detail-h">{{ __('app.runs.detail.proof_active') }}</h4>
                <ul class="detail-list">@foreach ((array) $b->d('activity.reasons', []) as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                <div class="muted mt-1.5 text-xs">{{ $b->address }}</div>
                <div class="muted mt-1 text-xs">{{ __('app.runs.detail.search', ['query' => $b->query]) }}</div>
            </div>
        </div>
    </td>
</tr>

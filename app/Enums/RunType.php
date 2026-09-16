<?php

namespace App\Enums;

enum RunType: string
{
    /** Search Google Maps, then run the full pipeline. Uses the worker's daily limit. */
    case Scrape = 'scrape';

    /** Re-run activity, enrichment and scoring on a run's saved places. No Google Maps traffic. */
    case Reprocess = 'reprocess';

    /** Imported from a GMSCraper output folder (leads.json). */
    case Import = 'import';

    /** Translated for the interface language; see lang/<locale>/data.php. */
    public function label(): string
    {
        return (string) __('data.run_type.'.$this->value);
    }
}

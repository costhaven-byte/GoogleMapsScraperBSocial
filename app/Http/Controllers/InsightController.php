<?php

namespace App\Http\Controllers;

use App\Models\RunBusiness;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Contactability by search across all runs, so searches that mostly produce
 * inactive or unreachable businesses can be dropped.
 */
class InsightController extends Controller
{
    public function __invoke(Request $request): View
    {
        $all = $request->boolean('all');

        $rows = RunBusiness::query()
            ->when(! $all, fn ($q) => $q->whereIn('id', RunBusiness::query()->selectRaw('MAX(id)')->groupBy('place_key')))
            ->selectRaw("query,
                COUNT(*) AS total,
                SUM(CASE WHEN stage = 'inactive' THEN 1 ELSE 0 END) AS inactive,
                SUM(CASE WHEN stage = 'lead' AND contact_tier = 'A' THEN 1 ELSE 0 END) AS tier_a,
                SUM(CASE WHEN stage = 'lead' AND contact_tier = 'B' THEN 1 ELSE 0 END) AS tier_b,
                SUM(CASE WHEN stage = 'lead' AND contact_tier = 'C' THEN 1 ELSE 0 END) AS tier_c,
                SUM(CASE WHEN stage = 'unreachable' THEN 1 ELSE 0 END) AS tier_d,
                SUM(CASE WHEN priority = 'Hot' THEN 1 ELSE 0 END) AS hot,
                COUNT(DISTINCT run_id) AS runs")
            ->groupBy('query')
            ->orderByDesc('total')
            ->paginate(50)
            ->withQueryString();

        return view('insights', ['rows' => $rows, 'all' => $all]);
    }
}

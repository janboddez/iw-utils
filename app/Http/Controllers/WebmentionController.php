<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WebmentionController extends Controller
{
    public function handle(Request $request)
    {
        if (! $request->has('source')) {
            abort(400);
        }

        if (! $request->has('target')) {
            abort(400);
        }

        $source = $request->input('source');
        $target = $request->input('target');

        if (parse_url($target, PHP_URL_HOST) !== parse_url(env('SITE_URL', url('/')), PHP_URL_HOST)) {
            // Not on our domain.
            abort(400);
        }

        // List of valid URLs. Something an SSG could generate during build.
        if (! file_exists(env('SITE_PAGE_LIST', ''))) {
            abort(500);
        }

        $urls = file_get_contents(env('SITE_PAGE_LIST'));

        if (empty($urls)) {
            abort(500);
        }

        // Alternatively, process and thus request URLs immediately, and look
        // for, e.g., a 200 OK HTTP status code.
        if (strpos($urls, $target."\n") === false) {
            // Not found.
            abort(404);
        }

        // Saving webmentions separately from final comments, to ease
        // asynchronous processing and enable future, additional comment
        // sources.
        DB::insert(
            'INSERT INTO webmentions (source, target, status, ip, created_at) VALUES (?, ?, ?, ?, NOW())',
            [$source, $target, 'new', $request->server('HTTP_CF_CONNECTING_IP') ?: $request->ip()]
        );

        return response()->json([], 202);
    }
}

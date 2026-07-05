<?php

return [

    /*
     * Fire PostMetricsFetched / AccountMetricsFetched / MetricsFetchFailed /
     * MetricsRunCompleted events in addition to returning the MetricsResult.
     * Turn off if you only consume the return value.
     */
    'events' => true,

    /*
     * Per-driver settings. These are app-level only: API versions and tuning
     * knobs. Credentials and account identifiers (tokens, API keys, IG user id,
     * channel id, LinkedIn URN, ...) are NOT configured here — you pass them per
     * call via the AccountRef/PostRef (accountId, accessToken, meta) or the
     * resolver, from your own wiring.
     */
    'drivers' => [

        'instagram' => [
            'graph_version' => 'v21.0',
        ],

        'facebook' => [
            'graph_version' => 'v21.0',
        ],

        'linkedin' => [
            'api_version' => '202605',
        ],

        'tiktok' => [
            // How far back to page the video listing when filtering by id.
            'max_videos' => 200,
        ],

    ],

];

<?php

return [

    /*
     * Fire PostMetricsFetched / AccountMetricsFetched / MetricsFetchFailed /
     * MetricsRunCompleted events in addition to returning the MetricsResult.
     * Turn off if you only consume the return value.
     */
    'events' => true,

    /*
     * Map a Laravel-Social-Tokens provider name to a driver name where they
     * differ. Example: if you store YouTube accounts under the 'google'
     * provider, map it to the youtube driver.
     */
    'driver_map' => [
        // 'google' => 'youtube',
    ],

    'drivers' => [

        'instagram' => [
            'graph_version' => 'v21.0',
            // Fallback IG business user id when not on the account profile (profile.ig_user_id).
            'user_id' => env('SOCIAL_METRICS_IG_USER_ID'),
        ],

        'facebook' => [
            'graph_version' => 'v21.0',
        ],

        'threads' => [
            // Fallback Threads user id when not on the account profile (profile.threads_user_id).
            'user_id' => env('SOCIAL_METRICS_THREADS_USER_ID'),
        ],

        'tiktok' => [
            // How far back to page the video listing when filtering by id.
            'max_videos' => 200,
        ],

        'youtube' => [
            'api_key' => env('SOCIAL_METRICS_YOUTUBE_KEY'),
            'channel_id' => env('SOCIAL_METRICS_YOUTUBE_CHANNEL_ID'),
        ],

        'linkedin' => [
            'api_version' => '202605',
            // Optional org URN fallback for organization follower stats. Not needed
            // for personal profiles (they use the token owner) or when the account
            // exposes isPerson()/identifier.
            'organization_urn' => env('SOCIAL_METRICS_LINKEDIN_ORG_URN'),
        ],

    ],

];

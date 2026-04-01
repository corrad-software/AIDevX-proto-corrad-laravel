<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Origins allowed to embed the admin SPA in an <iframe> (e.g. MYFIS2)
    |--------------------------------------------------------------------------
    |
    | Comma-separated full origins, e.g. https://myfisv2-tourism.datasc.dev
    | When non-empty, responses from the web stack get:
    | Content-Security-Policy: frame-ancestors 'self' <origins>...
    |
    | Cross-site iframe auth: set SESSION_SAME_SITE=none and SESSION_SECURE_COOKIE=true
    | (HTTPS required). See docs/kerisi-myfis-user-chat-embed.md.
    |
    */

    'embed_iframe_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('KERISI_EMBED_IFRAME_ORIGINS', '')),
    ))),

];

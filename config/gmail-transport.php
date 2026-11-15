<?php

return [

    /*
     * The OAuth client of your Google Cloud project (type "Web application"), with the Gmail API
     * enabled. Use one project per environment: revoking a token clears every grant of the project
     * for that Google user.
     */
    'client_id' => env('GMAIL_CLIENT_ID'),

    'client_secret' => env('GMAIL_CLIENT_SECRET'),

    /*
     * Leave empty to use the callback route's URL on the host the user connects from. Google
     * accepts plain http only for localhost and 127.0.0.1, and only hosts with a public top-level
     * domain otherwise. Set it when a proxy changes the scheme or host.
     */
    'redirect_uri' => env('GMAIL_REDIRECT_URI'),

    'routes' => [
        'enabled' => true,
        'prefix' => 'gmail',
        'middleware' => ['web'],
    ],

    /*
     * A path the browser goes to after the callback. The session then holds
     * `gmail-transport.status`: connected, denied, missing_scope, invalid_state, taken or failed.
     */
    'redirect_after' => '/',

];

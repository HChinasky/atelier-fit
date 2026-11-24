<?php

/*
 * What a JSON request hears when the framework itself stops it (bootstrap/app.php):
 * a signed-out session, a missing record, an expired form, a throttle. The
 * error pages' own titles are the framework's JSON keys (English as written).
 */

return [
    'unauthenticated' => 'Your session has ended. Please sign in again.',
    'forbidden' => 'You do not have access to this.',
    'not_found' => 'Not found.',
    'method_not_allowed' => 'This request is not supported.',
    'page_expired' => 'This page has expired. Refresh it and try again.',
    'too_many_requests' => 'Too many attempts. Please wait a minute and try again.',
    'server_error' => 'Something went wrong. Please try again a little later.',
    'unavailable' => 'The service is temporarily unavailable. Please try again a little later.',

    // The error page (resources/views/errors/minimal.blade.php).
    'home' => 'Back to the start',
];

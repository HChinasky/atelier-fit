<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Terms of Use acceptance
    |--------------------------------------------------------------------------
    |
    | The Terms used to be accepted "by using the service", which is easy to
    | write and hard to prove: nobody can show what a particular person was shown
    | or when. They are now accepted by ticking a box before the first upload,
    | and the acceptance is recorded next to the photo consent — same moment, one
    | row, so the two cannot disagree about what happened.
    |
    | version pins the wording. Acceptance is recorded per version, and a
    | recorded acceptance of superseded terms does not authorise anything: bump
    | this whenever the Terms change materially and everyone is asked again.
    | Keep it in step with the published document.
    |
    | The checkbox stays SEPARATE from the photo consent. Bundling "I accept the
    | Terms" with "you may process my photograph" would make the second one
    | unfree, and an unfree consent is not a consent.
    |
    */

    'version' => (string) env('TERMS_VERSION', '2'),

    'text' => 'I have read and accept the Terms of Use.',

];

<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Published legal documents
    |--------------------------------------------------------------------------
    |
    | Where the documents live and what to call them in the interface. One list,
    | so the footer, the sign-in notice and the consent checkbox cannot drift
    | apart — and so moving the files (a different host, HTML instead of PDF)
    | is one env change rather than a hunt through templates.
    |
    | The documents are Markdown files in docs/legal/*.md, rendered as HTML by
    | LegalDocumentController. They are sample placeholders, not legal advice:
    | replace them with your own texts before running a real service.
    |
    | `consent.md` is deliberately absent: it is the wording of the consent
    | checkbox plus notes on how to implement it, not a page for a reader.
    |
    */

    'base_url' => rtrim((string) env('LEGAL_BASE_URL', 'https://demo.atelierfit.example/legal'), '/'),

    'documents' => [
        'privacy-policy' => 'Privacy Policy',
        'terms-of-use' => 'Terms of Use',
        'refund-policy' => 'Refund Policy',
        'ai-disclosure' => 'How your photo is processed by AI',
        'cookie-policy' => 'Cookie Policy',
        'copyright-takedown' => 'Copyright & Takedown',
        'affiliate-disclosure' => 'Affiliate Disclosure',
        'legal-notice' => 'Legal Notice',
    ],

    /*
    | The operator line shown under the links. Kept identical to the wording in
    | the documents themselves and to the brand's own footer — three places that
    | must say the same thing about who runs this service.
    */

    /*
    | Where rights requests go — deletion included. The same address the
    | Privacy Policy names in "Your rights" and "Contact"; /data-deletion
    | shows it, so the two cannot disagree about where to write.
    */

    'privacy_email' => 'info@atelierfit.example',

    'operator_line' => 'Atelier Fit is a demo project · Sample operator line, replace with your own legal entity details',

];

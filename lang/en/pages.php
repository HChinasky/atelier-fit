<?php

/*
 * The smaller account pages: My Looks (membership/looks), the stylist
 * (membership/vip), /pricing and Profile & Settings (membership/settings).
 */

return [
    'looks' => [
        'title' => 'Atelier Fit — My Looks',
        'eyebrow' => 'History · Last 30',
        'heading' => 'My Looks',
        'empty_title' => 'Your gallery awaits',
        'empty_text' => 'Every look you create will be kept here. Begin your first try-on.',
        'new_tryon' => 'New Try-On',
        'save' => 'Save',
        'save_title' => 'Save the look to your device',
        'shop' => 'Shop',
        'shop_title' => 'Shop this look',
        // A look whose image went with the 72-hour photo lifecycle.
        'image_expired' => 'Image no longer stored',
        'more_pieces' => 'More pieces in this look: :count',
    ],

    'vip' => [
        'title' => 'Atelier Fit — Your Stylist',
        'heading' => 'Your Stylist',
        'review' => 'Monthly review',
        'review_status' => '1 review available this month',
        'send' => 'Send capsule to stylist',
        'play' => 'Play voice verdict',
        // The mock verdict shown until stylist reviews exist.
        'demo_meta' => 'Senior stylist · June 28',
        'demo_verdict' => '"Layla, the trench with the slip dress is beautifully done — very much your signature. For the desert evening, I’d swap the flats for a heeled sandal and add a gold cuff. I’ve pinned two pieces to your wardrobe. — Camille"',
    ],

    'pricing' => [
        'title' => 'Atelier Fit — Membership',
        'back' => 'My Atelier',
        'heading' => 'Membership',
        'lede' => 'Choose the atelier that suits your season.',
        'soon_strong' => 'Coming soon.',
        'soon_text' => 'Memberships are not available for purchase yet — these plans are a preview, no payment is taken, and nothing here can be subscribed to.',
        'popular' => 'Most Popular',
        'per_month' => '/mo',
        'soon_button' => 'Coming soon',
        // Future tense on purpose: nothing of this is live.
        'trust' => 'Payments are not live yet · Visa / Mastercard / Apple Pay planned · Cancel anytime',
        'terms_heading' => 'Before you subscribe',
        'terms_billing' => 'You are charged in advance, once a month. The subscription renews automatically until you cancel it. After you cancel there are no new charges, and you keep access until the end of the period you have already paid for.',
        'terms_credits' => 'One completed AI try-on uses one try-on. Unused try-ons expire at the end of the paid month: they do not carry over and cannot be exchanged for money.',
        'terms_failed' => 'If a try-on fails because of a technical error, it is not deducted or it is returned to your balance.',
        'terms_vip' => 'Diva VIP includes one written review of one saved look per month: up to 10 minutes of the stylist’s work and one reply, within 5 working days. An unused review does not carry over.',
        'terms_ai' => 'An AI try-on is a visualisation, not a guarantee of exact fit, size or colour. The current price and availability of a garment are those on the retailer’s page.',
        'prices_aed' => 'All prices are in UAE dirhams (AED).',
        'vat_included' => 'Prices include VAT.',
        'vat_excluded' => 'Prices do not include VAT; VAT is added at checkout.',
        'link_terms' => 'Terms of Use',
        'link_refund' => 'Refund Policy',
        'links_lead' => 'Before paying, read the',
        'links_and' => 'and the',
        'contact' => 'Questions or a refund request:',
    ],

    'settings' => [
        'title' => 'Atelier Fit — Profile & Settings',
        'eyebrow' => 'Account',
        'heading' => 'Profile & Settings',
        'more' => 'Other sections',
        'more_looks' => 'My Looks',
        'more_vip' => 'Stylist',
        'avatar' => 'Profile photo',
        'avatar_change' => 'Change photo',
        'avatar_upload' => 'Upload photo',
        'avatar_remove' => 'Remove',
        'avatar_hint' => 'JPG, PNG or WebP. Shown only to you; it is not used for try-ons and is kept until you remove it or delete your account.',
        'references' => 'Reference photos',
        'upsell' => 'Add up to 3 photos on Shopaholic and above.',
        'upgrade' => 'Upgrade',
        'membership' => 'Membership',
        'change_plan' => 'Change plan',
        'cancel' => 'Cancel',
        'quiz' => 'Style quiz',
        'quiz_text' => 'Hair, jewellery, size and budget — used for your colours and recommendations.',
        'quiz_edit' => 'Edit answers',
        'quiz_take' => 'Take the quiz',
        'language' => 'Language',
        'language_text' => 'The language of the site and of the e-mails we send you.',
        'session' => 'Session',
        'signed_in_as' => 'Signed in as :email',
        'sign_out' => 'Sign out',
        'privacy' => 'Privacy',
        // Constraint 3 (CLAUDE.md): within 72 hours, never "immediately".
        'privacy_text' => 'Your photos are stored privately and processed securely. Try-on source photos are deleted within 72 hours.',
        'delete' => 'Delete my account & all photos',
        'delete_title' => 'Delete your account?',
        'delete_text' => 'This will permanently delete your account, photos and looks. This cannot be undone.',
        'delete_yes' => 'Yes, delete everything',
        'delete_keep' => 'Keep my account',
    ],
];

<?php

/*
 * Signing in: the landing page "/", the e-mail and code steps it shares with
 * the overlays, the provider buttons and the server's answers to them.
 */

return [
    'title' => 'Atelier Fit — Sign In',

    // The landing page frames the two steps as a sign-in.
    'page' => [
        'purpose' => 'Atelier Fit is a virtual fitting room by stylist Atelier Fit. Upload a photo of yourself, pick a clothing item from our catalog and see how it looks on you. We use your Google account only to sign you in and never to create or process images of any kind. The service only places clothes on your own photo and does not generate nude, sexual or intimate imagery.',
        'eyebrow' => 'Sign in',
        'title' => 'Welcome Back',
        'lede' => 'Enter your e-mail to receive a one-time sign-in code, or continue with Google or Apple.',
        'cta' => 'Send Code',
        'otp_eyebrow' => 'One-Time Code',
        'otp_lede_before' => 'We sent a 6-digit sign-in code to',
        'otp_cta' => 'Verify & Sign In',
        'pricing_link' => 'View membership',
        'legal' => 'By continuing you agree to the :terms and confirm you have read the :privacy.',
        'terms' => 'Terms of Use',
        'privacy' => 'Privacy Policy',
    ],

    // Step one: the address.
    'email' => [
        'eyebrow' => 'Step One of Two',
        'title' => 'Your E-mail',
        'lede' => "We'll send a one-time code to your e-mail to confirm it's you.",
        'placeholder' => 'you@example.com',
        'label' => 'E-mail address',
        'cta' => 'Send Code',
        'sending' => 'Sending…',
        'or' => 'or',
        'continue_with' => 'Continue with :provider',
        'invalid' => 'Please enter a valid e-mail address.',
    ],

    // Step two: the code.
    'otp' => [
        'eyebrow' => 'Step Two of Two',
        'title' => 'Enter the Code',
        'lede_before' => 'We sent a 6-digit code to',
        'lede_after' => '. Check your spam folder if it is not there in a minute.',
        'blocked_title' => 'Too many attempts',
        'blocked_text' => 'For your security, please try again in :wait.',
        'blocked_wait' => '3 hours',
        'label' => '6-digit code',
        'resend_in' => 'Resend code in',
        'resend' => 'Resend Code',
        'cta' => 'Verify & Continue',
        'verifying' => 'Verifying…',
        'invalid' => 'Invalid code.',
        'invalid_last' => 'Invalid code — 1 attempt left.',
        'expired' => 'This code has expired — request a new one.',
        'resend_failed' => 'Could not resend the code. Please try again.',
    ],

    // What the server answers (EmailAuthController, SocialAuthController).
    'server' => [
        'not_human' => 'We could not confirm you are human. Please refresh and try again.',
        'wait' => 'Please wait a moment before requesting another code.',
        'unavailable' => 'Codes are temporarily unavailable. Please try again in a few minutes.',
        'send_failed' => 'We could not send the code. Please check the address and try again.',
        'expired' => 'This code has expired. Please request a new one.',
        'social_failed' => 'Sign-in did not complete. Please try again.',
        'social_no_email' => 'We did not receive an e-mail address from :provider. Please try again or sign in with your e-mail.',
    ],
];

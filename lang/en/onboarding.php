<?php

/*
 * The style quiz (membership/onboarding). `quiz` holds the questions' words,
 * keyed as App\Services\Onboarding\StyleQuiz asks them; `ui` the page around them.
 */

return [
    'quiz' => [
        'natural_hair' => [
            'short' => 'Hair',
            'question' => 'Is your hair colour natural right now?',
            'hint' => 'Dyed hair is not taken into account: the season is set by your skin and eyes.',
            'options' => ['1' => 'Yes', '0' => 'No, it is dyed'],
        ],
        'metal' => [
            'short' => 'Jewellery',
            'question' => 'Which jewellery suits you better?',
            'hint' => 'Hold gold and silver up to your face in daylight.',
            'options' => ['gold' => 'Gold', 'silver' => 'Silver', 'both' => 'Both equally'],
        ],
        'tanning' => [
            'short' => 'Tan',
            'question' => 'How does your skin react to the sun?',
            'hint' => null,
            'options' => ['burns' => 'Turns red quickly, hardly tans', 'tans' => 'Tans easily', 'both' => 'It varies'],
        ],
        'clarity' => [
            'short' => 'Near the face',
            'question' => 'Which colours near your face usually suit you better?',
            'hint' => 'Think of the pieces you get the most compliments in.',
            'options' => ['clear' => 'Clear and bright', 'soft' => 'Soft and muted', 'unsure' => 'Hard to say'],
        ],
        'categories' => [
            'short' => 'Looking for',
            'question' => 'What are you looking for now?',
            'hint' => 'You can pick several.',
            'options' => ['upper_body' => 'Tops, blouses, jumpers', 'lower_body' => 'Trousers and skirts', 'dresses' => 'Dresses'],
        ],
        'size' => [
            'short' => 'Size',
            'question' => 'Which size do you wear most often?',
            'hint' => null,
            'options' => [],
        ],
        'budget' => [
            'short' => 'Budget',
            'question' => 'How much do you usually spend on one piece?',
            'hint' => null,
            'options' => ['under_200' => 'Under 200 AED', '200_500' => '200–500 AED', '500_1000' => '500–1000 AED', 'over_1000' => 'Over 1000 AED'],
        ],
    ],

    'ui' => [
        'title' => 'Atelier Fit — Style Quiz',
        'eyebrow' => 'Style quiz',
        'heading' => 'Tell us a little about yourself',
        'lede_photo' => 'A few short questions help personalise the recommendations, and a selfie finds your colour type. Any step can be skipped.',
        'lede' => 'A few short questions help personalise the recommendations. Any question can be skipped.',
        'steps' => 'Quiz questions',
        'group_colour' => 'About colours',
        'group_shopping' => 'About shopping',
        'selfie' => 'Selfie',
        'skip_all' => 'Skip the quiz',
        // :step and :total; the page also builds it in JS from the same string.
        'count' => 'Step :step of :total',
        'selfie_heading' => 'Last step — a selfie',
        'selfie_editing' => 'For the new answers to change your season, add a fresh selfie — the season will be found again. Without a photo we save only the answers, and the picks update at once.',
        'selfie_hint' => 'From the photo and your answers we will find your colour type. It takes a few seconds.',
        'pick_photo' => 'Choose a photo of your face',
        'your_photo' => 'Your photo',
        'photo_chosen' => 'Photo chosen',
        'drop_photo' => 'Tap or drag a photo here',
        'rules' => [
            'Daylight, facing a window. No flash and not in direct sun.',
            'No make-up, filters or retouching — the shot as it is.',
            'Hair off the forehead and cheeks, glasses off.',
            'The original from your gallery — not a screenshot and not a photo forwarded in a messenger: those are compressed and overexposed, and the skin colour is lost.',
            'Face and shoulders in the frame, looking straight at the camera, eyes open and in focus — the colour of the eyes, brows and hair must be visible.',
            'A dark or blurry photo cannot be measured: if the colours are hard to see on your own screen, retake it in better light.',
        ],
        // Constraint 3 (CLAUDE.md): "within :hours hours", never "at once".
        'consent' => 'I agree that a photo of my face will be analysed to find my colour type. The photo is deleted within :hours hours; the result stays in my account.',
        'privacy' => 'Privacy Policy',
        'photo_again' => 'Choose the photo again — the browser does not keep it after the check.',
        'consent_needed' => 'To find your colour type, tick the consent box — or skip this step.',
        'back' => 'Back',
        'save' => 'Save changes',
        'saving' => 'Saving…',
        'skip' => 'Skip',
        'sending' => 'Sending…',
        'analyse' => 'Find my colour type',
        'done' => 'Done',
        'next' => 'Next',
        'note' => 'Your answers are kept in your profile and can be changed any time. The colour questions are only a hint: the photo sets the season.',
    ],
];

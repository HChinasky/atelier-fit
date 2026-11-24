<?php

namespace App\Services\Onboarding;

/**
 * The style quiz offered after sign-up: what is asked, what counts as an
 * answer, and which answers the colour engine is given.
 *
 * Two kinds of question share one flow:
 *   - colour: the engine's own Quiz fields (services/color-engine models.py),
 *     values spelled exactly as the engine's enums. Metal and tanning nudge
 *     the photo's reading on temperature, clarity on chroma; none outvotes it
 *     — the engine caps them, and that cap is deliberate (classifier.json,
 *     "quiz.why"). Dyed hair takes hair out of the measurement. The engine
 *     still accepts `veins`, which this quiz no longer asks (stored answers
 *     are simply not sent).
 *   - shopping: what the member is looking for, in what size, for how much.
 *     The engine never sees these; they are what recommendations filter on.
 *
 * Every question may be left unanswered. The copy is Ukrainian, like the rest
 * of the colour flow.
 */
class StyleQuiz
{
    /** Answers the colour engine accepts, as its Quiz model names them. */
    public const COLOUR_KEYS = ['natural_hair', 'metal', 'tanning', 'clarity'];

    /**
     * Answers the colour page collects for the engine that the onboarding quiz
     * never asks: the natural colour from before a dye (a note for the stylist).
     */
    public const ENGINE_ONLY_KEYS = ['previous_hair'];

    /**
     * The budget answers as AED price ranges, [from, below]; null is open.
     *
     * @var array<string, array{0: float|null, 1: float|null}>
     */
    public const BUDGETS = [
        'under_200' => [null, 200.0],
        '200_500' => [200.0, 500.0],
        '500_1000' => [500.0, 1000.0],
        'over_1000' => [1000.0, null],
    ];

    /**
     * In the order they are asked.
     *
     * @return array<string, array{short: string, group: string, question: string, hint: string|null, multiple: bool, options: array<string, string>}>
     */
    public static function questions(): array
    {
        // The shape of each question; its words (short label, question, hint,
        // option labels) are lang/<locale>/onboarding.php `quiz.<key>`.
        $shape = [
            'natural_hair' => ['group' => 'colour', 'multiple' => false, 'options' => ['1', '0']],
            'metal' => ['group' => 'colour', 'multiple' => false, 'options' => ['gold', 'silver', 'both']],
            'tanning' => ['group' => 'colour', 'multiple' => false, 'options' => ['burns', 'tans', 'both']],
            'clarity' => ['group' => 'colour', 'multiple' => false, 'options' => ['clear', 'soft', 'unsure']],
            'categories' => ['group' => 'shopping', 'multiple' => true, 'options' => ['upper_body', 'lower_body', 'dresses']],
            'size' => ['group' => 'shopping', 'multiple' => false, 'options' => ['XS', 'S', 'M', 'L', 'XL', 'XXL']],
            'budget' => ['group' => 'shopping', 'multiple' => false, 'options' => array_keys(self::BUDGETS)],
        ];

        $questions = [];
        foreach ($shape as $key => $question) {
            $words = (array) trans("onboarding.quiz.{$key}");
            $labels = (array) ($words['options'] ?? []);

            $options = [];
            foreach ($question['options'] as $value) {
                // A size is its own label in every language.
                $options[$value] = $labels[$value] ?? $value;
            }

            $questions[$key] = [
                'short' => (string) ($words['short'] ?? $key),
                'group' => $question['group'],
                'question' => (string) ($words['question'] ?? $key),
                'hint' => $words['hint'] ?? null,
                'multiple' => $question['multiple'],
                'options' => $options,
            ];
        }

        return $questions;
    }

    /**
     * Keep the known questions with a valid answer, typed: natural_hair as a
     * bool, the multiple-choice question as a list, the rest as strings.
     * Anything else is dropped rather than stored.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, bool|string|list<string>>
     */
    public static function normalise(array $input): array
    {
        $answers = [];

        foreach (self::questions() as $key => $question) {
            $value = $input[$key] ?? null;
            $allowed = array_map('strval', array_keys($question['options']));

            if ($question['multiple']) {
                $values = array_values(array_intersect($allowed, array_map('strval', (array) $value)));
                if ($values !== []) {
                    $answers[$key] = $values;
                }

                continue;
            }

            if ($value === null || $value === '' || ! in_array((string) (is_bool($value) ? (int) $value : $value), $allowed, true)) {
                continue;
            }

            $answers[$key] = $key === 'natural_hair' ? (bool) (int) $value : (string) $value;
        }

        return $answers;
    }

    /**
     * The member's answers in words, one row per question in the order they
     * are asked: its short name, the chosen option's label (several joined,
     * null when unanswered) and whether the colour engine reads it.
     *
     * @param  array<string, mixed>|null  $answers
     * @return list<array{key: string, short: string, answer: string|null, colour: bool}>
     */
    public static function summary(?array $answers): array
    {
        $rows = [];

        foreach (self::questions() as $key => $question) {
            $value = $answers[$key] ?? null;
            $values = is_bool($value) ? [$value ? '1' : '0'] : array_map('strval', (array) $value);
            $labels = array_values(array_filter(array_map(
                fn (string $option) => $question['options'][$option] ?? null,
                $values,
            )));

            $rows[] = [
                'key' => $key,
                'short' => $question['short'],
                'answer' => $labels === [] ? null : implode(', ', $labels),
                'colour' => $question['group'] === 'colour',
            ];
        }

        return $rows;
    }

    /**
     * The answers the colour engine is given, or null when there are none.
     *
     * @param  array<string, mixed>|null  $answers
     * @return array<string, bool|string>|null
     */
    public static function colourQuiz(?array $answers): ?array
    {
        $quiz = array_intersect_key($answers ?? [], array_flip([...self::COLOUR_KEYS, ...self::ENGINE_ONLY_KEYS]));

        return $quiz === [] ? null : $quiz;
    }
}

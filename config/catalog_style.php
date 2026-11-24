<?php

/*
|--------------------------------------------------------------------------
| Style / occasion keywords (StyleTagger)
|--------------------------------------------------------------------------
|
| What a piece is FOR, read from its own words, in English, Russian and
| Ukrainian: the Amazon.ae feed is titled in Russian, the Google feeds in
| English, the interface is Ukrainian. Case-insensitive. An English entry is
| a whole word, plural allowed ("prom" is not "promise", so list "sporty"
| beside "sport"); a Cyrillic entry is a STEM matched at the start of a word,
| for the case endings ("вечерн" is "вечернее" and "вечерний", not
| "предвечерний").
|
| Keyword parsing is imprecise, so it is kept honest:
|   - only words that name an occasion outright are here ("satin" or
|     "heels" are evening often enough, but not always — not listed);
|   - the title is read first, then the feed's category, then the
|     description; the first field that names exactly ONE occasion decides;
|   - a field naming two occasions ("for the office or an evening out") says
|     the piece is versatile, and that is what it gets: universal;
|   - nothing found means universal and no style. Never a guess.
|
*/

return [

    /*
    | Two kinds of words per style:
    |   - occasion: words that name what the piece is for ("evening",
    |     "деловой", "спорт") — read in the title, the feed category and the
    |     description;
    |   - garment: names of pieces typical of the style ("jeans", "пиджак") —
    |     read in the TITLE only, where they name the piece itself. A feed
    |     category ("Брюки и джинсы") is a bucket of several pieces, and a
    |     description names what to wear it WITH ("носите с джинсами").
    */
    'keywords' => [
        'evening' => [
            'occasion' => [
                'evening', 'cocktail', 'gala', 'party', 'prom', 'red carpet',
                'вечерн', 'вечерин', 'коктейльн', 'праздничн', 'выпускн',
                'вечірн', 'вечірк', 'святков', 'випускн',
            ],
            'garment' => [
                'gown', 'sequin', 'sequined', 'tuxedo', 'stiletto',
                'пайетк', 'смокинг', 'шпильк',
                'паєтк', 'смокінг', 'шпильц',
            ],
        ],
        'business' => [
            'occasion' => [
                'office', 'business', 'workwear', 'work wear',
                'делов', 'офис',
                'ділов', 'офіс',
            ],
            'garment' => ['blazer', 'пиджак', 'піджак'],
        ],
        'formal' => [
            'occasion' => [
                'formal',
                'строг', 'формальн',
                'суворий', 'сувора', 'суворе',
            ],
            'garment' => [],
        ],
        'sport' => [
            'occasion' => [
                'sport', 'sporty', 'sportswear', 'gym', 'athletic', 'workout', 'fitness', 'yoga', 'running', 'training', 'activewear',
                'спорт', 'фитнес', 'йога', 'йоги', 'тренировк', 'беговы',
                'фітнес', 'тренуван', 'бігов',
            ],
            'garment' => [],
        ],
        'casual' => [
            'occasion' => [
                'casual', 'everyday', 'weekend',
                'повседневн', 'прогул', 'отдых',
                'повсякденн', 'відпочин',
            ],
            'garment' => [
                'jeans', 'denim', 't-shirt', 'hoodie', 'sweatshirt', 'jogger', 'sneaker',
                'baseball cap', 'bucket hat',
                'джинс', 'футболк', 'худи', 'свитшот', 'толстовк', 'джоггер', 'кроссовк', 'кеды',
                'кепк', 'бейсболк', 'панам',
                'худі', 'світшот', 'джогер', 'кросівк', 'кеди',
            ],
        ],
    ],

    /*
    | Each style belongs to one occasion. Sport sits under casual: there is no
    | sport capsule, and sportswear does not go to the office or out at night.
    */
    'occasion_of' => [
        'evening' => 'evening',
        'business' => 'office',
        'formal' => 'office',
        'sport' => 'casual',
        'casual' => 'casual',
    ],

    /*
    | When one field names several styles of the SAME occasion (a "sport suit
    | with a hoodie"), the most specific style is kept: first in this list.
    */
    'style_priority' => ['evening', 'business', 'formal', 'sport', 'casual'],

];

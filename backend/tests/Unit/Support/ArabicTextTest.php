<?php

declare(strict_types=1);

use App\Support\ArabicText;
use Illuminate\Support\Facades\DB;

test('D32 normalisation folds spelling variants', function (string $input, string $expected): void {
    expect(ArabicText::normalize($input))->toBe($expected);
})->with([
    'alef forms' => ['أإآٱ', 'اااا'],
    'alef maqsura and taa marbuta' => ['مستوى ثانية', 'مستوي تانيه'],
    'colloquial tha, dhal, dha' => ['ثالث ذهب ظرف', 'تالت دهب ضرف'],
    'tashkeel and tatweel removed' => ['عُلُـــوم', 'علوم'],
    'latin lower-cased' => ['ADW-Sci', 'adw-sci'],
    'whitespace collapsed' => ["  علوم \t  أولى ", 'علوم اولي'],
]);

test('BR-P08 the colloquial query and the official label normalise to the same words', function (): void {
    expect(ArabicText::normalize('علوم تانية اعدادي الأضواء'))
        ->toBe(ArabicText::normalize('عُلوم ثانية إعدادي الاضواء'));
});

test('tokens are distinct, normalised and capped', function (): void {
    expect(ArabicText::tokens('علوم  علوم أولى'))->toBe(['علوم', 'اولي'])
        ->and(ArabicText::tokens('a b c d e', limit: 3))->toBe(['a', 'b', 'c'])
        ->and(ArabicText::tokens('   '))->toBe([]);
});

test('the SQL fold gives the same result as the PHP fold', function (string $input): void {
    $row = DB::selectOne('select '.ArabicText::sql('?').' as folded', [$input]);

    expect($row->folded)->toBe(ArabicText::normalize($input));
})->with([
    'school book name' => ['الأضواء عُلوم ثانية إعدادي'],
    'sku code' => ['ADW-SCI-P1'],
    'maqsura and tatweel' => ['مستـــوى'],
]);

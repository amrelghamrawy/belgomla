<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Arabic search normalisation (BR-P08 search, D32), shared by PHP and SQL so both sides of a
 * comparison are folded the same way:
 *  - tashkeel (U+064B..U+0652) and tatweel (U+0640) are removed,
 *  - alef forms أ إ آ ٱ → ا, ى → ي, ة → ه,
 *  - Egyptian colloquial spelling: ث → ت, ذ → د, ظ → ض ("تانية" finds "ثانية").
 *  - Latin is lower-cased.
 */
final class ArabicText
{
    private const array FOLD = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ى' => 'ي', 'ة' => 'ه',
        'ث' => 'ت', 'ذ' => 'د', 'ظ' => 'ض',
    ];

    private const string REMOVE = "\u{064B}\u{064C}\u{064D}\u{064E}\u{064F}\u{0650}\u{0651}\u{0652}\u{0640}";

    public static function normalize(string $text): string
    {
        $text = strtr(mb_strtolower($text), self::FOLD);
        $text = (string) preg_replace('/['.self::REMOVE.']/u', '', $text);

        return mb_trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Distinct normalised words of a search query, at most $limit of them.
     *
     * @return list<string>
     */
    public static function tokens(string $query, int $limit = 8): array
    {
        $words = array_filter(explode(' ', self::normalize($query)), fn (string $word): bool => $word !== '');

        return array_slice(array_values(array_unique($words)), 0, $limit);
    }

    /**
     * The same fold as normalize(), as a PostgreSQL expression over $sqlExpression.
     * translate() maps the i-th "from" character to the i-th "to" character and deletes the extras.
     */
    public static function sql(string $sqlExpression): string
    {
        $from = implode('', array_keys(self::FOLD)).self::REMOVE;
        $to = implode('', array_values(self::FOLD));

        return "translate(lower({$sqlExpression}), '{$from}', '{$to}')";
    }
}

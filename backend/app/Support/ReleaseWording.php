<?php

namespace App\Support;

use App\Models\ProjectTranche;

/**
 * Users read "release" where the data model says "tranche". Release names
 * and approval titles saved before the wording change still carry the old
 * word and an old number ("Fund release: Second tranche", "Tranche 1 —
 * Mobilization"), so stored text is passed through here before it is shown.
 * When the text belongs to a known release, its number reference is
 * replaced by that release's stored number (ProjectTranche::release_number),
 * so old text and new labels always agree. Only display text changes;
 * tables, columns, API fields and routes keep "tranche".
 */
final class ReleaseWording
{
    /** Ordinal tranche names the setup form used to generate. */
    private const ORDINALS = 'first|second|third|fourth|fifth|sixth|seventh|eighth|ninth|tenth|final|last';

    /** Ordinals that stand for a release number (final / last do not). */
    private const ORDINAL_NUMBERS = [
        'first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5,
        'sixth' => 6, 'seventh' => 7, 'eighth' => 8, 'ninth' => 9, 'tenth' => 10,
    ];

    /** "Second tranche", "Tranche 3", "Release Tranche 3", "Release 2"… */
    private const REFERENCE = '(?:release\s+)?(?:(?:' . self::ORDINALS . ')\s+(?:tranche|release)|tranche\s*\d+|release\s*\d+)(?=\W|$)';

    public static function label(int $number): string
    {
        return 'Release ' . $number;
    }

    /**
     * "tranches" -> "releases". With $release, a number reference becomes
     * its stored label: "Fund release: Second tranche" -> "Fund release:
     * Release 3" when that release is number 3. Without one, only the word
     * changes ("Tranche 3" -> "Release 3").
     */
    public static function apply(?string $text, ?ProjectTranche $release = null): ?string
    {
        if ($text === null) {
            return null;
        }

        if ($release !== null) {
            $label = $release->releaseLabel();
            $text = preg_replace('/\b' . self::REFERENCE . '/iu', $label, $text);
        }

        $text = preg_replace_callback('/\btranche(s?)\b/iu', function (array $m) {
            $word = 'release' . strtolower($m[1]);

            return ctype_upper($m[0][0]) ? ucfirst($word) : $word;
        }, $text);

        // "Release Tranche 3" became "Release Release 3".
        return preg_replace('/\b(release)\s+release\b/iu', '$1', $text);
    }

    /**
     * Update number references in a stored milestone after a renumbering:
     * "Upon liquidation of Release 3" -> "Upon liquidation of Release 2"
     * when old release 3 became 2. References to numbers not in $numbers are
     * left alone. The wording is normalized to "Release N".
     *
     * @param  array<int, int>  $numbers  old number => new number
     */
    public static function renumber(?string $text, array $numbers): ?string
    {
        if ($text === null || trim($text) === '' || $numbers === []) {
            return $text;
        }

        $text = preg_replace_callback(
            '/\b(?:release\s+)?(?:(' . self::ORDINALS . ')\s+(?:tranche|release)|(?:tranche|release)\s*(\d+))\b/iu',
            function (array $match) use ($numbers) {
                $ordinal = strtolower((string) ($match[1] ?? ''));
                $old = $ordinal !== ''
                    ? (self::ORDINAL_NUMBERS[$ordinal] ?? null)
                    : (int) ($match[2] ?? 0);

                return $old !== null && isset($numbers[$old])
                    ? self::label($numbers[$old])
                    : $match[0];
            },
            $text
        );

        return self::apply($text);
    }

    /**
     * What a stored release name says beyond its number, for example
     * "Mobilization" from "Tranche 1 — Mobilization"; null for a name that
     * is only a number ("Second tranche", "Tranche 4", "Release 2") or empty.
     */
    public static function milestone(?string $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        $rest = preg_replace('/^' . self::REFERENCE . '/iu', '', $name);
        // Separators left after the number: "—", "–", "-", ":", "·", "|", and
        // "???" where an old import lost the dash.
        $rest = trim(preg_replace('/^[\s\x{2014}\x{2013}\-:·|?]+/u', '', $rest));

        return $rest === '' ? null : self::apply($rest);
    }
}

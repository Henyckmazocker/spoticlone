<?php

declare(strict_types=1);

namespace App\Domain\Services;

/**
 * Name/genre normalisation used to match local library data against Last.fm.
 *
 * No intl/Normalizer in the container, so accents are removed with iconv transliteration.
 * Names written only in non-Latin scripts (e.g. Japanese) transliterate to nothing; for those we
 * keep the lowercased letters/digits so they still compare equal to themselves.
 */
final class Normalize
{
    public static function name(string $name): string
    {
        return self::normalize($name, true);
    }

    public static function genre(string $genre): string
    {
        return self::normalize($genre, false);
    }

    /** Two genres match when one contains the other and the shorter is at least 4 chars. */
    public static function genresMatch(string $a, string $b): bool
    {
        $a = self::genre($a);
        $b = self::genre($b);
        if ($a === '' || $b === '') {
            return false;
        }
        [$short, $long] = strlen($a) <= strlen($b) ? [$a, $b] : [$b, $a];
        return strlen($short) >= 4 && str_contains($long, $short);
    }

    private static function normalize(string $value, bool $stripThe): string
    {
        $lower = mb_strtolower(trim($value), 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        $ascii = is_string($ascii) ? strtolower($ascii) : '';
        if ($stripThe) {
            $ascii = (string) preg_replace('/^the\s+/', '', $ascii);
        }
        $out = (string) preg_replace('/[^a-z0-9]/', '', $ascii);
        if ($out !== '') {
            return $out;
        }
        // Non-Latin script: keep Unicode letters/digits so the value is still comparable.
        if ($stripThe) {
            $lower = (string) preg_replace('/^the\s+/u', '', $lower);
        }
        return (string) preg_replace('/[^\p{L}\p{N}]/u', '', $lower);
    }
}

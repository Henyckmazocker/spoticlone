<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Services\Normalize;
use PHPUnit\Framework\TestCase;

class NormalizeTest extends TestCase
{
    public function test_name_strips_accents_case_and_the(): void
    {
        $this->assertSame('rosalia', Normalize::name('ROSALÍA'));
        $this->assertSame(Normalize::name('Rosalía'), Normalize::name('ROSALÍA'));
        $this->assertSame('beatles', Normalize::name('The Beatles'));
        $this->assertSame('tylerthecreator', Normalize::name('Tyler, The Creator'));
        $this->assertSame(Normalize::name('tyler the creator'), Normalize::name('Tyler, The Creator'));
    }

    public function test_genre_variants_match(): void
    {
        $this->assertSame('hiphop', Normalize::genre('Hip-Hop'));
        $this->assertSame('hiphop', Normalize::genre('Hip Hop'));
        $this->assertTrue(Normalize::genresMatch('Hip-Hop', 'Hip Hop'));
        $this->assertTrue(Normalize::genresMatch('Contemporary Hip Hop', 'Hip-Hop'));
        $this->assertFalse(Normalize::genresMatch('Pop', 'Post-Punk'));
    }
}

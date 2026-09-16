<?php

namespace Tests\Unit;

use App\Support\Csv;
use App\Support\Like;
use App\Support\PlaceKey;
use App\Support\SafeUrl;
use PHPUnit\Framework\TestCase;

class SupportTest extends TestCase
{
    public function test_safe_url_only_allows_http_and_https(): void
    {
        $this->assertSame('https://example.com/a', SafeUrl::http('https://example.com/a'));
        $this->assertSame('http://example.com', SafeUrl::http('http://example.com'));
        $this->assertNull(SafeUrl::http('javascript:alert(1)'));
        $this->assertNull(SafeUrl::http('JAVASCRIPT:alert(1)'));
        $this->assertNull(SafeUrl::http('data:text/html,hi'));
        $this->assertNull(SafeUrl::http('//evil.example'));
        $this->assertNull(SafeUrl::http(null));
        $this->assertNull(SafeUrl::http(['https://x.example']));
        $this->assertSame('shop.example/contact', SafeUrl::short('https://www.shop.example/contact/'));
    }

    public function test_csv_cells_neutralise_formulas_and_join_lists(): void
    {
        $this->assertSame("'=1+1", Csv::cell('=1+1'));
        $this->assertSame("'+cmd", Csv::cell('+cmd'));
        $this->assertSame("'-2", Csv::cell('-2'));
        $this->assertSame("'@SUM(A1)", Csv::cell('@SUM(A1)'));
        $this->assertSame('a | b', Csv::cell(['a', 'b']));
        $this->assertSame('true', Csv::cell(true));
        $this->assertSame('', Csv::cell(null));
        $this->assertSame('plain', Csv::cell('plain'));
    }

    public function test_place_keys_fit_the_indexed_column(): void
    {
        $this->assertSame('0x1:0x2', PlaceKey::normalize('0x1:0x2'));
        $this->assertSame('https://maps/x', PlaceKey::normalize(null, 'https://maps/x'));
        $long = PlaceKey::normalize(str_repeat('a', 300));
        $this->assertSame(45, strlen($long));
        $this->assertSame($long, PlaceKey::normalize(str_repeat('a', 300)));
        $this->assertStringStartsWith('unknown-', PlaceKey::normalize(null));
    }

    public function test_like_escapes_wildcards(): void
    {
        $this->assertSame('%100\%\_off%', Like::contains('100%_off'));
    }
}

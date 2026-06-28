<?php

namespace Tests\Unit;

use App\Services\CityDetector;
use App\Services\GeocodingService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CityDetectorGeocodingTest extends TestCase
{
    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    public function test_detect_uses_reverse_geocode_cache_not_hardcoded_cities(): void
    {
        $lat = -37.6672;
        $lng = -72.5730;
        $roundedLat = round($lat, 2);
        $roundedLng = round($lng, 2);
        $dir = storage_path('app/geocode');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $cacheFile = "{$dir}/{$roundedLat}_{$roundedLng}.json";
        file_put_contents($cacheFile, json_encode([
            'city' => 'Santa Rosa',
            'region' => 'Araucanía',
            'country' => 'Chile',
        ]));

        $this->assertSame('Santa Rosa', CityDetector::detect($lat, $lng));
        $this->assertSame('Santa Rosa', GeocodingService::getCityName($lat, $lng));
    }

    public function test_detect_returns_fallback_when_geocode_has_no_city(): void
    {
        $lat = -50.0;
        $lng = -70.0;
        $roundedLat = round($lat, 2);
        $roundedLng = round($lng, 2);
        $cacheFile = storage_path("app/geocode/{$roundedLat}_{$roundedLng}.json");
        file_put_contents($cacheFile, json_encode(['city' => null, 'region' => null, 'country' => null]));

        Cache::forget(sprintf('geo_city_%s_%s', round($lat, 3), round($lng, 3)));

        $this->assertSame('Tu zona', CityDetector::detect($lat, $lng, 'Tu zona'));
    }
}

<?php

namespace App\Services;

use App\Models\TrendQuery;
use Illuminate\Support\Facades\Http;
use Throwable;

class TrendIngestionService
{
    /**
     * @return array<int, array{query:string, trend_score:int, locale:string, observed_at:string}>
     */
    public function fetchGoogleTrendsRuSports(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));

        try {
            $response = Http::timeout(15)
                ->accept('application/rss+xml, application/xml')
                ->get('https://trends.google.com/trending/rss', ['geo' => 'RU']);

            if (!$response->ok()) {
                return [];
            }

            $xml = @simplexml_load_string($response->body());
            if (!$xml || !isset($xml->channel->item)) {
                return [];
            }

            $sportsKeywords = [
                'футбол', 'матч', 'лига', 'кубок', 'спорт', 'спартак', 'динамо', 'зенит', 'цска', 'барселона',
                'ливерпуль', 'тоттенхэм', 'атлетико', 'бавария', 'галатасарай', 'хоккей', 'нхл', 'кхл',
                'баскетбол', 'nba', 'евролига', 'теннис', 'atp', 'wta', 'wimbledon', 'roland garros',
                'mma', 'ufc', 'bellator', 'бокс', 'boxing',
            ];

            $out = [];
            $rank = 0;
            foreach ($xml->channel->item as $item) {
                if ($rank >= $limit) {
                    break;
                }

                $title = trim((string) ($item->title ?? ''));
                if ($title === '') {
                    continue;
                }

                $normalized = mb_strtolower($title);
                $isSports = false;
                foreach ($sportsKeywords as $keyword) {
                    if (mb_stripos($normalized, $keyword) !== false) {
                        $isSports = true;
                        break;
                    }
                }

                // Also allow pair-like requests "A - B" even without explicit sport keyword.
                if (!$isSports && !preg_match('/\s[-–—]\s/u', $title)) {
                    continue;
                }

                $rank++;
                $out[] = [
                    'query' => $title,
                    'trend_score' => max(1, 120 - ($rank * 3)),
                    'locale' => 'ru-RU',
                    'observed_at' => now()->toDateTimeString(),
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Fallback source based on Yandex suggest API for sports seeds.
     *
     * @return array<int, array{query:string, trend_score:int, locale:string, observed_at:string}>
     */
    public function fetchYandexSportsFallback(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $seeds = [
            'футбол',
            'баскетбол',
            'теннис',
            'хоккей',
            'ufc',
            'бокс',
            'мма',
            'рпл',
            'нхл',
            'nba',
        ];

        $seen = [];
        $out = [];
        $rank = 0;

        foreach ($seeds as $seed) {
            if (count($out) >= $limit) {
                break;
            }

            $items = $this->fetchYandexSuggest($seed);
            foreach ($items as $item) {
                $query = trim($item);
                if ($query === '') {
                    continue;
                }

                $normalized = mb_strtolower($query);
                if (isset($seen[$normalized])) {
                    continue;
                }
                $seen[$normalized] = true;

                $rank++;
                $out[] = [
                    'query' => $query,
                    'trend_score' => max(1, 120 - ($rank * 2)),
                    'locale' => 'ru-RU',
                    'observed_at' => now()->toDateTimeString(),
                ];

                if (count($out) >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function fetchYandexSuggest(string $seed): array
    {
        try {
            $response = Http::timeout(12)
                ->acceptJson()
                ->get('https://suggest.yandex.ru/suggest-ya.cgi', [
                    'v' => 4,
                    'uil' => 'ru',
                    'part' => $seed,
                ]);

            if (!$response->ok()) {
                return [];
            }

            $json = $response->json();
            if (!is_array($json)) {
                return [];
            }

            $rawSuggestions = $json[1] ?? [];
            $flat = $this->flattenSuggestions($rawSuggestions);

            $sportsNeedles = [
                'футбол', 'баскетбол', 'теннис', 'хоккей', 'ufc', 'мма', 'бокс', 'бой', 'матч',
                'рпл', 'лига', 'нхл', 'кхл', 'nba', 'atp', 'wta',
            ];

            $out = [];
            foreach ($flat as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '') {
                    continue;
                }

                $lower = mb_strtolower($candidate);
                $isSports = false;
                foreach ($sportsNeedles as $needle) {
                    if (mb_stripos($lower, $needle) !== false) {
                        $isSports = true;
                        break;
                    }
                }

                if (!$isSports && !preg_match('/\s[-–—]\s/u', $candidate)) {
                    continue;
                }

                $out[] = $candidate;
            }

            return array_values(array_unique($out));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function flattenSuggestions(mixed $value): array
    {
        if (is_string($value)) {
            return [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            foreach ($this->flattenSuggestions($item) as $flat) {
                $out[] = $flat;
            }
        }

        return $out;
    }

    /**
     * @param array<int, array{query:string, trend_score:int, locale?:string, observed_at?:string}> $items
     */
    public function upsertTrendQueries(array $items, string $source = 'google_trends_rss'): int
    {
        $written = 0;
        $today = now()->toDateString();

        foreach ($items as $item) {
            $query = trim((string) ($item['query'] ?? ''));
            if ($query === '') {
                continue;
            }

            TrendQuery::query()->updateOrCreate(
                [
                    'source' => $source,
                    'query' => $query,
                    'observed_date' => $today,
                ],
                [
                    'locale' => (string) ($item['locale'] ?? 'ru-RU'),
                    'trend_score' => (int) ($item['trend_score'] ?? 0),
                    'observed_at' => (string) ($item['observed_at'] ?? now()->toDateTimeString()),
                ]
            );
            $written++;
        }

        return $written;
    }
}

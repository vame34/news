<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class SourceNewsAggregationService
{
    /**
     * @var array<int, array{key:string,url:string,source:string,discipline:string}>
     */
    private const FEEDS = [
        ['key' => 'espn_soccer', 'url' => 'https://www.espn.com/espn/rss/soccer/news', 'source' => 'ESPN', 'discipline' => 'football'],
        ['key' => 'espn_nba', 'url' => 'https://www.espn.com/espn/rss/nba/news', 'source' => 'ESPN', 'discipline' => 'basketball'],
        ['key' => 'espn_nhl', 'url' => 'https://www.espn.com/espn/rss/nhl/news', 'source' => 'ESPN', 'discipline' => 'hockey'],
        ['key' => 'espn_mma', 'url' => 'https://www.espn.com/espn/rss/mma/news', 'source' => 'ESPN', 'discipline' => 'mma-boxing'],
        ['key' => 'google_news_mma', 'url' => 'https://news.google.com/rss/search?q=ufc+OR+mma+OR+bellator+when:7d&hl=en-US&gl=US&ceid=US:en', 'source' => 'Google News', 'discipline' => 'mma-boxing'],
        ['key' => 'google_news_boxing', 'url' => 'https://news.google.com/rss/search?q=boxing+OR+boxer+OR+%D0%B1%D0%BE%D0%BA%D1%81+when:7d&hl=en-US&gl=US&ceid=US:en', 'source' => 'Google News', 'discipline' => 'mma-boxing'],
        ['key' => 'boxing247', 'url' => 'https://www.boxing247.com/feed/', 'source' => 'Boxing247', 'discipline' => 'mma-boxing'],
        ['key' => 'sports_ru_all', 'url' => 'https://www.sports.ru/rss/all_news.xml', 'source' => 'Sports.ru', 'discipline' => 'all'],
        ['key' => 'sport_express_all', 'url' => 'https://www.sport-express.ru/services/materials/news/se/', 'source' => 'Спорт-Экспресс', 'discipline' => 'all'],
        ['key' => 'sky_sports', 'url' => 'https://www.skysports.com/rss/11095', 'source' => 'Sky Sports', 'discipline' => 'all'],
    ];

    /**
     * @return array<int, array{title:string,url:string,description:string,published_at:?string,source:string,discipline:string,score:int}>
     */
    public function collectRelevant(string $query, ?string $discipline = null, int $limit = 6): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $discipline = $this->normalizeDiscipline((string) $discipline) ?? $this->inferDisciplineFromText($query) ?? 'football';
        $limit = max(1, min(20, $limit));
        $tokens = $this->buildSearchTokens($query);
        $items = $this->collectAllFeedItems($discipline);

        $scored = [];
        foreach ($items as $item) {
            $score = $this->scoreItem($item, $tokens, $discipline);
            if ($score <= 0) {
                continue;
            }

            $item['score'] = $score;
            $scored[] = $item;
        }

        usort($scored, function (array $a, array $b): int {
            $left = (int) ($a['score'] ?? 0);
            $right = (int) ($b['score'] ?? 0);
            if ($left !== $right) {
                return $right <=> $left;
            }

            $leftTs = strtotime((string) ($a['published_at'] ?? '')) ?: 0;
            $rightTs = strtotime((string) ($b['published_at'] ?? '')) ?: 0;
            return $rightTs <=> $leftTs;
        });

        $dedup = [];
        $seen = [];
        foreach ($scored as $row) {
            $fingerprint = md5(mb_strtolower(trim((string) ($row['title'] ?? ''))) . '|' . trim((string) ($row['url'] ?? '')));
            if (isset($seen[$fingerprint])) {
                continue;
            }
            $seen[$fingerprint] = true;
            $dedup[] = $row;
            if (count($dedup) >= $limit) {
                break;
            }
        }

        // If strict relevance produced too few candidates, return latest discipline items.
        if (count($dedup) < min(2, $limit)) {
            $fallback = $this->fallbackLatestByDiscipline($items, $discipline, $limit);
            foreach ($fallback as $row) {
                $fingerprint = md5(mb_strtolower(trim((string) ($row['title'] ?? ''))) . '|' . trim((string) ($row['url'] ?? '')));
                if (isset($seen[$fingerprint])) {
                    continue;
                }
                $seen[$fingerprint] = true;
                $dedup[] = $row;
                if (count($dedup) >= $limit) {
                    break;
                }
            }
        }

        return array_values($dedup);
    }

    /**
     * @return array<int, array{query:string,discipline:string,source:string}>
     */
    public function collectBackfillTopics(int $limit = 10): array
    {
        $limit = max(1, min(50, $limit));
        $items = $this->collectAllFeedItems('all');
        usort($items, function (array $a, array $b): int {
            $leftTs = strtotime((string) ($a['published_at'] ?? '')) ?: 0;
            $rightTs = strtotime((string) ($b['published_at'] ?? '')) ?: 0;
            return $rightTs <=> $leftTs;
        });

        $candidates = [];
        $seen = [];
        foreach ($items as $item) {
            $discipline = $this->normalizeDiscipline((string) ($item['discipline'] ?? '')) ?? $this->inferDisciplineFromText((string) ($item['title'] ?? ''));
            if ($discipline === null) {
                continue;
            }

            $query = trim((string) ($item['title'] ?? ''));
            $query = preg_replace('/\s+/u', ' ', $query) ?? $query;
            if ($query === '' || mb_strlen($query) < 12) {
                continue;
            }
            if (
                $discipline === 'mma-boxing'
                && !$this->hasExplicitCombatMarkerForBackfill(
                    $query,
                    (string) ($item['description'] ?? ''),
                    (string) ($item['source'] ?? '')
                )
            ) {
                continue;
            }
            if ($this->isScoreboardCodeTitle($query)) {
                continue;
            }
            if (preg_match('/\b(betting|odds|ставк|букмек)\b/ui', $query)) {
                continue;
            }

            $slugKey = Str::slug($query);
            if ($slugKey === '' || isset($seen[$slugKey])) {
                continue;
            }
            $seen[$slugKey] = true;

            $candidates[] = [
                'query' => $query,
                'discipline' => $discipline,
                'source' => (string) ($item['source'] ?? 'rss'),
            ];
        }

        $disciplineBuckets = [];
        foreach ($candidates as $candidate) {
            $disciplineBuckets[$candidate['discipline']][] = $candidate;
        }

        $disciplineOrder = ['mma-boxing', 'hockey', 'basketball', 'football', 'tennis'];
        $perDisciplineCap = max(1, min(3, (int) ceil($limit / max(1, count($disciplineOrder)))));

        $out = [];
        $picked = [];

        foreach ($disciplineOrder as $discipline) {
            $bucket = $disciplineBuckets[$discipline] ?? [];
            $taken = 0;
            foreach ($bucket as $candidate) {
                $slugKey = Str::slug((string) $candidate['query']);
                if ($slugKey === '' || isset($picked[$slugKey])) {
                    continue;
                }

                $picked[$slugKey] = true;
                $out[] = $candidate;
                $taken++;

                if (count($out) >= $limit || $taken >= $perDisciplineCap) {
                    break;
                }
            }

            if (count($out) >= $limit) {
                return $out;
            }
        }

        foreach ($candidates as $candidate) {
            $slugKey = Str::slug((string) $candidate['query']);
            if ($slugKey === '' || isset($picked[$slugKey])) {
                continue;
            }

            $picked[$slugKey] = true;
            $out[] = $candidate;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function isScoreboardCodeTitle(string $title): bool
    {
        $title = trim($title);
        if ($title === '') {
            return false;
        }

        // Examples: "CJB @ LAP", "NYK @ BOS", "ABC vs DEF".
        if (preg_match('/^[A-Z]{2,4}\s*(?:@|vs\.?|v\.?|[-–—])\s*[A-Z]{2,4}$/u', $title) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @return array<int, array{title:string,url:string,description:string,published_at:?string,source:string,discipline:string}>
     */
    private function collectAllFeedItems(string $discipline): array
    {
        $out = [];
        foreach (self::FEEDS as $feed) {
            if ($discipline !== 'all' && $feed['discipline'] !== 'all' && $feed['discipline'] !== $discipline) {
                continue;
            }

            foreach ($this->fetchFeed($feed) as $item) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array{key:string,url:string,source:string,discipline:string} $feed
     * @return array<int, array{title:string,url:string,description:string,published_at:?string,source:string,discipline:string}>
     */
    private function fetchFeed(array $feed): array
    {
        $cacheKey = 'source_feed:' . $feed['key'];
        return Cache::remember($cacheKey, now()->addMinutes(8), function () use ($feed): array {
            try {
                $response = Http::timeout(10)
                    ->accept('application/rss+xml, application/xml, text/xml')
                    ->get($feed['url']);

                if (!$response->ok()) {
                    return [];
                }

                $xml = @simplexml_load_string($response->body());
                if (!$xml || !isset($xml->channel->item)) {
                    return [];
                }

                $items = [];
                foreach ($xml->channel->item as $item) {
                    $title = trim((string) ($item->title ?? ''));
                    $url = trim((string) ($item->link ?? ''));
                    if ($title === '' || $url === '') {
                        continue;
                    }

                    $description = trim(strip_tags((string) ($item->description ?? '')));
                    $publishedRaw = trim((string) ($item->pubDate ?? ''));
                    $publishedAt = null;
                    if ($publishedRaw !== '') {
                        try {
                            $publishedAt = Carbon::parse($publishedRaw)->toDateTimeString();
                        } catch (Throwable) {
                            $publishedAt = null;
                        }
                    }

                    $itemDiscipline = $feed['discipline'] === 'all'
                        ? ($this->inferDisciplineFromText($title . ' ' . $description) ?? 'football')
                        : $feed['discipline'];

                    $items[] = [
                        'title' => $title,
                        'url' => $url,
                        'description' => $description,
                        'published_at' => $publishedAt,
                        'source' => $feed['source'],
                        'discipline' => $itemDiscipline,
                    ];

                    if (count($items) >= 40) {
                        break;
                    }
                }

                return $items;
            } catch (Throwable) {
                return [];
            }
        });
    }

    /**
     * @param array<int, string> $tokens
     * @param array{title:string,url:string,description:string,published_at:?string,source:string,discipline:string} $item
     */
    private function scoreItem(array $item, array $tokens, string $discipline): int
    {
        $title = mb_strtolower((string) ($item['title'] ?? ''));
        $description = mb_strtolower((string) ($item['description'] ?? ''));
        $haystack = $title . ' ' . $description;
        $score = 0;

        foreach ($tokens as $token) {
            if (mb_strlen($token) < 3) {
                continue;
            }

            if (mb_stripos($title, $token) !== false) {
                $score += 8;
                continue;
            }
            if (mb_stripos($description, $token) !== false) {
                $score += 4;
            }
        }

        if (($item['discipline'] ?? '') === $discipline) {
            $score += 6;
        }

        // Prefer fresh items.
        $publishedTs = strtotime((string) ($item['published_at'] ?? '')) ?: 0;
        if ($publishedTs > 0) {
            $ageHours = max(1, (int) floor((time() - $publishedTs) / 3600));
            if ($ageHours <= 6) {
                $score += 10;
            } elseif ($ageHours <= 24) {
                $score += 6;
            } elseif ($ageHours <= 72) {
                $score += 3;
            }
        }

        if ($score <= 0 && preg_match('/\b(vs\.?|[-–—])\b/u', $haystack)) {
            $score = 2;
        }

        return $score;
    }

    /**
     * @param array<int, array{title:string,url:string,description:string,published_at:?string,source:string,discipline:string}> $items
     * @return array<int, array{title:string,url:string,description:string,published_at:?string,source:string,discipline:string,score:int}>
     */
    private function fallbackLatestByDiscipline(array $items, string $discipline, int $limit): array
    {
        $filtered = array_values(array_filter($items, static fn (array $x): bool => ($x['discipline'] ?? '') === $discipline));
        usort($filtered, function (array $a, array $b): int {
            $leftTs = strtotime((string) ($a['published_at'] ?? '')) ?: 0;
            $rightTs = strtotime((string) ($b['published_at'] ?? '')) ?: 0;
            return $rightTs <=> $leftTs;
        });

        $out = [];
        foreach (array_slice($filtered, 0, $limit) as $row) {
            $row['score'] = 1;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private function buildSearchTokens(string $query): array
    {
        $raw = mb_strtolower($query);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $raw) ?: [];
        $parts = array_values(array_filter(array_map(static fn ($x): string => trim((string) $x), $parts)));

        $tokens = [];
        foreach ($parts as $part) {
            if (mb_strlen($part) < 3) {
                continue;
            }
            $tokens[$part] = true;

            foreach ($this->expandAliases($part) as $alias) {
                if (mb_strlen($alias) >= 3) {
                    $tokens[$alias] = true;
                }
            }
        }

        return array_keys($tokens);
    }

    /**
     * @return array<int, string>
     */
    private function expandAliases(string $token): array
    {
        $map = [
            'россия' => ['russia'],
            'франция' => ['france'],
            'бразилия' => ['brazil'],
            'англия' => ['england'],
            'испания' => ['spain'],
            'германия' => ['germany'],
            'италия' => ['italy'],
            'португалия' => ['portugal'],
            'аргентина' => ['argentina'],
            'спартак' => ['spartak'],
            'динамо' => ['dynamo'],
            'зенит' => ['zenit'],
            'цска' => ['cska'],
            'локомотив' => ['lokomotiv'],
            'реал' => ['real', 'madrid'],
            'барселона' => ['barcelona'],
            'челси' => ['chelsea'],
            'арсенал' => ['arsenal'],
            'манчестер' => ['manchester'],
            'хорнетс' => ['hornets'],
            'никс' => ['knicks'],
            'лейкерс' => ['lakers'],
            'клипперс' => ['clippers'],
            'адесанья' => ['adesanya'],
            'макгрегор' => ['mcgregor'],
            'волкановски' => ['volkanovski'],
            'усик' => ['usyk'],
            'джошуа' => ['joshua'],
        ];

        return $map[$token] ?? [];
    }

    private function normalizeDiscipline(string $raw): ?string
    {
        $value = trim(mb_strtolower($raw));
        if ($value === '') {
            return null;
        }

        return match ($value) {
            'football', 'футбол', 'soccer' => 'football',
            'basketball', 'баскетбол', 'nba' => 'basketball',
            'tennis', 'теннис', 'atp', 'wta' => 'tennis',
            'hockey', 'хоккей', 'nhl', 'khl', 'нхл', 'кхл' => 'hockey',
            'mma-boxing', 'mma', 'boxing', 'бокс', 'мма', 'mma/boxing', 'мма/бокс' => 'mma-boxing',
            'all' => 'all',
            default => null,
        };
    }

    private function inferDisciplineFromText(string $text): ?string
    {
        $pool = mb_strtolower($text);

        foreach ([
            'фигурн',
            'фигурному катанию',
            'произвольн',
            'коротк',
            'ледов',
            'гран-при',
            'биатлон',
            'лыжн',
            'плаван',
            'ватерпол',
        ] as $negativeNeedle) {
            if (mb_stripos($pool, $negativeNeedle) !== false) {
                return null;
            }
        }

        foreach ([
            'football' => ['футбол', 'soccer', 'football', 'premier league', 'liga', 'champions league', 'зенит', 'спартак', 'динамо', 'арсенал', 'chelsea', 'real madrid', 'barcelona'],
            'basketball' => ['баскетбол', 'basketball', 'nba', 'ncaa', 'euroleague', 'hornets', 'knicks', 'lakers', 'celtics'],
            'tennis' => ['теннис', 'tennis', 'atp', 'wta', 'wimbledon', 'roland garros', 'australian open', 'us open'],
            'hockey' => ['хоккей', 'hockey', 'nhl', 'khl', 'stanley cup', 'ovechkin', 'ovetchkin', 'капиталз', 'кхл'],
            'mma-boxing' => ['mma', 'ufc', 'boxing', 'бокс', 'мма', 'fight night', 'bellator'],
        ] as $discipline => $needles) {
            foreach ($needles as $needle) {
                if (mb_stripos($pool, $needle) !== false) {
                    return $discipline;
                }
            }
        }

        return null;
    }

    private function hasExplicitCombatMarker(string $text): bool
    {
        $pool = mb_strtolower($text);
        foreach (['ufc', 'mma', 'bellator', 'boxing', 'бокс', 'мма', 'fight night', 'бой', 'boxer'] as $needle) {
            if (mb_stripos($pool, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function hasExplicitCombatMarkerForBackfill(string $title, string $description, string $source): bool
    {
        $source = trim(mb_strtolower($source));
        $isDedicatedCombatSource = in_array($source, ['espn', 'google news', 'boxing247'], true);
        $titleOnly = $this->hasExplicitCombatMarker($title);
        if ($titleOnly) {
            return true;
        }

        if (!$isDedicatedCombatSource) {
            return false;
        }

        return $this->hasExplicitCombatMarker($title . ' ' . $description);
    }
}

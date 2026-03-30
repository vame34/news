<?php

use App\Models\News;
use App\Models\SportMatch;
use App\Models\Team;
use App\Services\SportRadarService;
use App\Services\PriorityContentService;
use App\Services\TrendIngestionService;
use App\Services\EventIngestionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('sport-radar:db-bootstrap', function () {
    DB::statement('DROP SCHEMA IF EXISTS public CASCADE');
    DB::statement('CREATE SCHEMA public');
    DB::statement('GRANT ALL ON SCHEMA public TO public');

    Artisan::call('migrate', ['--force' => true]);
    Artisan::call('db:seed', ['--force' => true]);
    $this->info('database bootstrapped');
})->purpose('Recreate schema and seed SportRadar data');

Artisan::command('sport-radar:auto-news', function (SportRadarService $store) {
    $created = $store->generateAutoNews();
    $this->info("auto news created: {$created}");
})->purpose('Generate auto news items');

Artisan::command('sport-radar:validate-news', function () {
    $bad = News::query()
        ->where(function ($q) {
            $q->whereRaw("LOWER(title) LIKE '%ставк%'")
                ->orWhereRaw("LOWER(title) LIKE '%букмек%'")
                ->orWhereRaw("LOWER(title) LIKE '%odds%'")
                ->orWhereRaw("LOWER(excerpt) LIKE '%ставк%'")
                ->orWhereRaw("LOWER(excerpt) LIKE '%букмек%'")
                ->orWhereRaw("LOWER(excerpt) LIKE '%odds%'");
        })
        ->count();

    if ($bad > 0) {
        $this->error("policy violation count: {$bad}");
        return 1;
    }

    $this->info('news policy ok');
    return 0;
})->purpose('Validate no betting language in news');

Artisan::command('sport-radar:seo-reindex', function (SportRadarService $service) {
    $count = $service->queueReindexAllPages();
    $this->info("queued reindex jobs: {$count}");
})->purpose('Queue SEO reindex for all content pages');

Artisan::command('sport-radar:process-reindex {--loop} {--sleep=3}', function (SportRadarService $service) {
    $loop = (bool) $this->option('loop');
    $sleep = max(1, (int) $this->option('sleep'));

    do {
        $result = $service->processNextReindexJob();
        if ($result === null) {
            if (!$loop) {
                $this->info('no queued reindex jobs');
                return 0;
            }
            sleep($sleep);
            continue;
        }

        $this->info(sprintf('reindex job #%d -> %s', (int) $result['id'], (string) $result['status']));
    } while ($loop);

    return 0;
})->purpose('Process queued reindex jobs');

Artisan::command('sport-radar:sync-trends {--limit=30}', function (TrendIngestionService $trends) {
    $limit = max(1, (int) $this->option('limit'));
    $items = $trends->fetchGoogleTrendsRuSports($limit);
    $source = 'google_trends_rss';

    if ($items === []) {
        $items = $trends->fetchYandexSportsFallback($limit);
        $source = 'yandex_suggest_fallback';
    }

    $saved = $trends->upsertTrendQueries($items, $source);
    $this->info("trend queries fetched: " . count($items) . ", source: {$source}, saved: {$saved}");
})->purpose('Fetch and store hot sports queries (Google Trends, fallback: Yandex suggest)');

Artisan::command('sport-radar:sync-events {--days=5} {--limit=80}', function (EventIngestionService $events) {
    $days = max(1, (int) $this->option('days'));
    $limit = max(5, (int) $this->option('limit'));
    $saved = $events->syncUpcomingEvents($days, $limit);
    $this->info("upcoming events synced: {$saved}");
})->purpose('Sync nearest football/basketball/tennis/mma events from ESPN into matches table');

Artisan::command('sport-radar:queue-priority-news {--horizon=72} {--limit=50}', function (PriorityContentService $pipeline) {
    $horizon = max(6, (int) $this->option('horizon'));
    $limit = max(1, (int) $this->option('limit'));
    $queued = $pipeline->queueUpcomingMatchPreviews($horizon, $limit);
    $this->info("priority jobs queued: {$queued}");
})->purpose('Queue high-priority upcoming match pages from trend+event score');

Artisan::command('sport-radar:process-priority-news {--limit=5}', function (PriorityContentService $pipeline, \App\Services\DeepSeekService $deepSeek) {
    $limit = max(1, (int) $this->option('limit'));
    $stats = $pipeline->processQueue($deepSeek, $limit);
    $this->info(sprintf(
        'priority jobs processed: done=%d skipped=%d failed=%d',
        (int) ($stats['done'] ?? 0),
        (int) ($stats['skipped'] ?? 0),
        (int) ($stats['failed'] ?? 0)
    ));
})->purpose('Generate and publish pages from priority queue');

Artisan::command('sport-radar:normalize-legacy-names', function () {
    $dictionary = [
        'China' => 'Китай',
        'Curacao' => 'Кюрасао',
        'New Zealand' => 'Новая Зеландия',
        'Finland' => 'Финляндия',
        'Russia' => 'Россия',
        'Nicaragua' => 'Никарагуа',
        'IR Iran' => 'Иран',
        'Iran' => 'Иран',
        'Nigeria' => 'Нигерия',
        'South Africa' => 'ЮАР',
        'Panama' => 'Панама',
        'Norway' => 'Норвегия',
        'Armenia' => 'Армения',
        'Lithuania' => 'Литва',
        'Montenegro' => 'Черногория',
        'Andorra' => 'Андорра',
        'Brazil' => 'Бразилия',
        'France' => 'Франция',
        'Germany' => 'Германия',
        'Spain' => 'Испания',
        'Italy' => 'Италия',
        'Portugal' => 'Португалия',
        'Argentina' => 'Аргентина',
        'England' => 'Англия',
        'Netherlands' => 'Нидерланды',
        'Belgium' => 'Бельгия',
        'Croatia' => 'Хорватия',
        'Serbia' => 'Сербия',
        'Poland' => 'Польша',
        'Ukraine' => 'Украина',
        'Solomon Islands' => 'Соломоновы Острова',
    ];

    $transliterateWord = static function (string $word): string {
        $lower = mb_strtolower($word);
        if (preg_match('/^[a-z][a-z\'-]*$/', $lower) !== 1) {
            return $word;
        }

        $digraphs = [
            'shch' => 'щ', 'sch' => 'щ', 'zh' => 'ж', 'kh' => 'х', 'ts' => 'ц',
            'ch' => 'ч', 'sh' => 'ш', 'ya' => 'я', 'yu' => 'ю', 'yo' => 'ё',
            'jo' => 'ё', 'je' => 'е', 'ye' => 'е', 'ph' => 'ф', 'th' => 'т', 'qu' => 'кв',
        ];
        foreach ($digraphs as $latin => $ru) {
            $lower = str_replace($latin, $ru, $lower);
        }

        $chars = [
            'a' => 'а', 'b' => 'б', 'c' => 'к', 'd' => 'д', 'e' => 'е', 'f' => 'ф',
            'g' => 'г', 'h' => 'х', 'i' => 'и', 'j' => 'й', 'k' => 'к', 'l' => 'л',
            'm' => 'м', 'n' => 'н', 'o' => 'о', 'p' => 'п', 'q' => 'к', 'r' => 'р',
            's' => 'с', 't' => 'т', 'u' => 'у', 'v' => 'в', 'w' => 'в', 'x' => 'кс',
            'y' => 'й', 'z' => 'з',
        ];
        $result = strtr($lower, $chars);

        if (preg_match('/^[A-Z]/', $word) === 1) {
            $first = mb_substr($result, 0, 1);
            $rest = mb_substr($result, 1);
            $result = mb_strtoupper($first) . $rest;
        }

        return $result;
    };

    $transliteratePhrase = static function (string $text) use ($transliterateWord): string {
        $parts = preg_split('/(\s+|[-–—()])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) {
            return $text;
        }
        $out = '';
        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^\s+$/u', $part)) {
                $out .= $part;
                continue;
            }
            if (preg_match('/^[-–—()]$/u', $part) === 1) {
                $out .= $part;
                continue;
            }
            $out .= $transliterateWord($part);
        }
        return $out;
    };

    $teamUpdated = 0;
    $teams = Team::query()->get(['id', 'name']);
    foreach ($teams as $team) {
        $name = trim((string) $team->name);
        if ($name === '') {
            continue;
        }
        $normalized = $dictionary[$name] ?? $name;
        if (preg_match('/[A-Za-z]/u', $normalized) === 1) {
            $normalized = $transliteratePhrase($normalized);
        }

        if ($normalized !== $name) {
            Team::query()->where('id', (int) $team->id)->update(['name' => $normalized]);
            $teamUpdated++;
        }
    }

    $matchUpdated = 0;
    $matches = SportMatch::query()->get(['id', 'where_to_watch']);
    foreach ($matches as $match) {
        $watch = json_decode((string) $match->where_to_watch, true);
        if (!is_array($watch)) {
            continue;
        }
        $filtered = [];
        foreach ($watch as $item) {
            $name = '';
            if (is_string($item)) {
                $name = trim($item);
            } elseif (is_array($item)) {
                $name = trim((string) ($item['name'] ?? ''));
            }

            if ($name === '') {
                continue;
            }
            if (preg_match('/^[A-Z]{2,4}\s*(?:@|vs\.?|v\.?|[-–—])\s*[A-Z]{2,4}$/u', $name) === 1) {
                continue;
            }
            $filtered[] = ['name' => $name, 'url' => '#'];
        }

        if ($filtered === []) {
            $filtered[] = ['name' => 'Официальная трансляция', 'url' => '#'];
        }

        SportMatch::query()->where('id', (int) $match->id)->update([
            'where_to_watch' => json_encode(array_slice($filtered, 0, 3), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        $matchUpdated++;
    }

    $this->info("teams normalized: {$teamUpdated}, match watch cleaned: {$matchUpdated}");
})->purpose('Normalize legacy team names to Russian and remove code-like watch labels');

Schedule::command('sport-radar:sync-trends --limit=40')->everyThirtyMinutes();
Schedule::command('sport-radar:sync-events --days=5 --limit=80')->everyThirtyMinutes();
Schedule::command('sport-radar:queue-priority-news --horizon=72 --limit=80')->everyFifteenMinutes();
Schedule::command('sport-radar:process-priority-news --limit=5')->everyMinute();
Schedule::command('sport-radar:seo-reindex')->dailyAt('02:15');
Schedule::command('sport-radar:process-reindex')->everyMinute();

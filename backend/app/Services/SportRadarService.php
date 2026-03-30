<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\AdminAuthState;
use App\Models\ContentPage;
use App\Models\ContentVersion;
use App\Models\League;
use App\Models\News;
use App\Models\ProviderCredential;
use App\Models\ReindexJob;
use App\Models\SeoMeta;
use App\Models\SportMatch;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SportRadarService
{
    private const SITE_NAME = 'РадарАрена';

    public function authState(): array
    {
        $row = AdminAuthState::query()->where('id', 1)->first();
        if (!$row) {
            return ['failed_attempts' => 0, 'lock_until' => 0];
        }

        return [
            'failed_attempts' => (int) $row->failed_attempts,
            'lock_until' => (int) $row->lock_until,
        ];
    }

    public function saveAuthState(array $state): void
    {
        AdminAuthState::query()->updateOrInsert(
            ['id' => 1],
            [
                'failed_attempts' => (int) ($state['failed_attempts'] ?? 0),
                'lock_until' => (int) ($state['lock_until'] ?? 0),
            ]
        );
    }

    public function addAudit(string $action, array $payload, string $actor, string $ip): void
    {
        AdminAuditLog::query()->insert([
            'actor' => $actor,
            'action' => $action,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ip' => $ip,
            'created_at' => now(),
        ]);
    }

    public function auditLogs(): array
    {
        return AdminAuditLog::query()
            ->orderByDesc('created_at')
            ->limit(500)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public function contentVersionsByPage(int $pageId): array
    {
        return ContentVersion::query()
            ->where('page_id', $pageId)
            ->orderByDesc('version')
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public function recentReindexJobs(): array
    {
        return ReindexJob::query()
            ->orderByDesc('queued_at')
            ->limit(100)
            ->get()
            ->map(static fn ($row): array => (array) $row)
            ->all();
    }

    public function matches(): array
    {
        $rows = SportMatch::query()->from('matches as m')
            ->join('leagues as l', 'l.id', '=', 'm.league_id')
            ->join('teams as ht', 'ht.id', '=', 'm.home_team_id')
            ->join('teams as at', 'at.id', '=', 'm.away_team_id')
            ->orderBy('m.kickoff_at')
            ->get([
                'm.id',
                'm.slug',
                'm.kickoff_at',
                'm.status',
                'l.slug as league_slug',
                'l.name as league_name',
                'l.sport as league_sport',
                'ht.slug as home_slug',
                'ht.name as home_name',
                'at.slug as away_slug',
                'at.name as away_name',
                'm.analysis',
                'm.where_to_watch',
            ]);

        $items = $rows->map(function ($row): array {
            $watch = json_decode((string) $row->where_to_watch, true);
            $leagueName = $this->normalizeLeagueDisplayName((string) $row->league_name, (string) $row->league_slug);
            $homeName = $this->normalizeEntityDisplayName((string) $row->home_name, (string) $row->home_slug);
            $awayName = $this->normalizeEntityDisplayName((string) $row->away_name, (string) $row->away_slug);

            return [
                'id' => (int) $row->id,
                'slug' => (string) $row->slug,
                'sport' => (string) ($row->league_sport ?: 'football'),
                'league' => [
                    'slug' => (string) $row->league_slug,
                    'name' => $leagueName,
                ],
                'home_team' => [
                    'slug' => (string) $row->home_slug,
                    'name' => $homeName,
                ],
                'away_team' => [
                    'slug' => (string) $row->away_slug,
                    'name' => $awayName,
                ],
                'kickoff_at' => date(DATE_ATOM, strtotime((string) $row->kickoff_at)),
                'status' => (string) $row->status,
                'score' => '-',
                'analysis' => (string) $row->analysis,
                'where_to_watch' => is_array($watch) ? $watch : [],
            ];
        })->all();

        return $this->deduplicateVisibleMatches($items);
    }

    public function matchBySlug(string $slug): ?array
    {
        foreach ($this->matches() as $match) {
            if (($match['slug'] ?? '') === $slug) {
                return $match;
            }
        }

        return null;
    }

    public function publishedMatches(): array
    {
        $publishedSlugs = ContentPage::query()
            ->where('entity_type', 'match')
            ->where('status', 'published')
            ->pluck('entity_slug')
            ->all();

        if ($publishedSlugs === []) {
            return [];
        }

        $visible = array_fill_keys(array_map(static fn ($slug): string => (string) $slug, $publishedSlugs), true);

        return array_values(array_filter(
            $this->matches(),
            static fn (array $match): bool => isset($visible[(string) ($match['slug'] ?? '')])
        ));
    }

    public function publishedMatchBySlug(string $slug): ?array
    {
        $page = $this->contentPageByEntity('match', $slug);
        if ($page === null || (string) ($page['status'] ?? '') !== 'published') {
            return null;
        }

        return $this->matchBySlug($slug);
    }

    public function news(): array
    {
        $items = News::query()
            ->orderByDesc('published_at')
            ->get()
            ->map(function ($row): ?array {
                $slug = (string) $row->slug;
                if ($this->containsStaleHistoricalYear((string) $row->title, (string) ($row->body ?: ''))) {
                    return null;
                }

                return [
                    'id' => (int) $row->id,
                    'slug' => $slug,
                    'title' => (string) $row->title,
                    'excerpt' => (string) $row->excerpt,
                    'body' => (string) ($row->body ?: $row->excerpt),
                    'published_at' => date(DATE_ATOM, strtotime((string) $row->published_at)),
                    'league_slug' => (string) $row->league_slug,
                    'team_slug' => (string) $row->team_slug,
                    'image_url' => null,
                    'image_alt' => null,
                    'image_width' => null,
                    'image_height' => null,
                ];
            })->filter()->values()->all();

        return $this->deduplicateVisibleNews($items);
    }

    public function newsBySlug(string $slug): ?array
    {
        foreach ($this->news() as $item) {
            if (($item['slug'] ?? '') === $slug) {
                return $item;
            }
        }

        return null;
    }

    public function leagues(): array
    {
        return League::query()->orderBy('id')->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'slug' => (string) $row->slug,
                'name' => (string) $row->name,
                'sport' => (string) $row->sport,
            ])->all();
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateVisibleMatches(array $items): array
    {
        $seen = [];
        $out = [];

        foreach ($items as $item) {
            $home = (string) data_get($item, 'home_team.name', '');
            $away = (string) data_get($item, 'away_team.name', '');
            if (!$this->isVisibleEntityName($home) || !$this->isVisibleEntityName($away)) {
                continue;
            }

            $date = substr((string) ($item['kickoff_at'] ?? ''), 0, 10);
            $key = implode('|', [
                $date,
                $this->canonicalEntityKey($home),
                $this->canonicalEntityKey($away),
            ]);

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateVisibleNews(array $items): array
    {
        $seenKeys = [];
        $out = [];

        foreach ($items as $item) {
            $normalized = $this->normalizeVisibleNewsItem($item);
            if ($normalized === null) {
                continue;
            }

            $title = (string) ($normalized['title'] ?? '');
            $body = (string) ($normalized['body'] ?? '');

            $date = substr((string) ($normalized['published_at'] ?? ''), 0, 10);
            $canonicalTitle = $this->canonicalHeadlineKey($title);
            $duplicateKey = $date . '|' . $canonicalTitle;
            if ($canonicalTitle !== '' && isset($seenKeys[$duplicateKey])) {
                continue;
            }

            $isNearDuplicate = false;
            foreach ($out as $existing) {
                if (substr((string) ($existing['published_at'] ?? ''), 0, 10) !== $date) {
                    continue;
                }

                if ($this->headlineSimilarity((string) ($existing['title'] ?? ''), $title) >= 0.84) {
                    $isNearDuplicate = true;
                    break;
                }
            }

            if ($isNearDuplicate) {
                continue;
            }

            if ($canonicalTitle !== '') {
                $seenKeys[$duplicateKey] = true;
            }
            $out[] = $normalized;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function normalizeVisibleNewsItem(array $item): ?array
    {
        $title = trim((string) ($item['title'] ?? ''));
        $body = trim((string) ($item['body'] ?? ''));
        $excerpt = trim((string) ($item['excerpt'] ?? ''));

        if ($title === '') {
            return null;
        }

        $item['title'] = preg_replace('/\bvs\.?\b/iu', '—', $title) ?? $title;
        $item['excerpt'] = preg_replace('/\bvs\.?\b/iu', '—', $excerpt) ?? $excerpt;
        $item['body'] = preg_replace('/\bvs\.?\b/iu', '—', $body) ?? $body;

        return $item;
    }

    private function isVisibleEntityName(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/\b[A-Z]{2,4}\s*(?:@|vs\.?|v\.?|[-–—])\s*[A-Z]{2,4}\b/u', $value) === 1) {
            return false;
        }

        return true;
    }

    private function canonicalEntityKey(string $text): string
    {
        $ascii = Str::ascii(mb_strtolower(trim($text)));
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';
        $ascii = preg_replace('/\b(fc|fk|cf|club|sc|afc|wfc|women|u21|u20|u19)\b/', ' ', $ascii) ?? $ascii;
        $ascii = preg_replace('/\s+/', ' ', trim($ascii)) ?? trim($ascii);

        return $ascii;
    }

    private function canonicalHeadlineKey(string $headline): string
    {
        $headline = $this->canonicalEntityKey($headline);
        $headline = preg_replace('/\b(klyuchevoi|reshaiushchii|match|borbe|plei off|liga|chempionat|turnir|sezon|glavnoe|chto|dalshe)\b/', ' ', $headline) ?? $headline;
        $headline = preg_replace('/\s+/', ' ', trim($headline)) ?? trim($headline);

        return $headline;
    }

    private function headlineSimilarity(string $left, string $right): float
    {
        $a = array_values(array_filter(explode(' ', $this->canonicalHeadlineKey($left))));
        $b = array_values(array_filter(explode(' ', $this->canonicalHeadlineKey($right))));
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));
        $intersection = count(array_intersect($a, $b));
        $union = count(array_unique(array_merge($a, $b)));

        return $union > 0 ? ($intersection / $union) : 0.0;
    }

    private function normalizeEntityDisplayName(string $name, string $slug): string
    {
        $name = trim($name);
        if ($name === '') {
            return $this->fallbackDisplayNameFromSlug($slug);
        }

        $directFixes = [
            'Булгариа' => 'Болгария',
            'Брисбане Роар' => 'Брисбен Роар',
            'Перт Глорй' => 'Перт Глори',
            'Аустралиа' => 'Австралия',
            'Камероон' => 'Камерун',
            'Венезуела' => 'Венесуэла',
            'Азербаийан U21' => 'Азербайджан U21',
            'Португал U21' => 'Португалия U21',
            'Гуинеа' => 'Гвинея',
            'Сомалиа' => 'Сомали',
            'Мауритиус' => 'Маврикий',
            'Индонесиа' => 'Индонезия',
            'St. Китц анд Невис' => 'Сент-Китс и Невис',
            'Либериа' => 'Либерия',
            'Брисбане' => 'Брисбен',
        ];

        if (isset($directFixes[$name])) {
            return $directFixes[$name];
        }

        if (!$this->looksSuspiciousDisplayName($name)) {
            return $name;
        }

        $fallback = $this->fallbackDisplayNameFromSlug($slug);

        return $fallback !== '' ? $fallback : $name;
    }

    private function normalizeLeagueDisplayName(string $name, string $slug): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Футбол';
        }

        if (preg_match('/^[A-Z]{2,4}\s*@\s*[A-Z]{2,4}$/', $name) === 1) {
            return 'Футбол';
        }

        if ($this->looksSuspiciousDisplayName($name)) {
            $fallback = $this->fallbackDisplayNameFromSlug($slug);
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return $name;
    }

    private function looksSuspiciousDisplayName(string $name): bool
    {
        if (preg_match('/(?:\p{Latin}.*\p{Cyrillic}|\p{Cyrillic}.*\p{Latin})/u', $name) === 1) {
            return true;
        }

        foreach (['анд', 'глорй', 'бане', 'мауритиус', 'аустралиа', 'венезуела', 'индонесиа', 'азербаийан', 'гуинеа', 'булгариа', 'камероон'] as $needle) {
            if (mb_stripos($name, $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    private function fallbackDisplayNameFromSlug(string $slug): string
    {
        $slug = trim($slug);
        if ($slug === '') {
            return '';
        }

        $tail = $slug;
        if (str_contains($tail, 'football-')) {
            $tail = (string) Str::afterLast($tail, 'football-');
        }

        $normalized = trim(str_replace('-', ' ', $tail));
        if ($normalized === '') {
            return '';
        }

        $map = [
            'new zealand' => 'Новая Зеландия',
            'finland' => 'Финляндия',
            'china' => 'Китай',
            'curacao' => 'Кюрасао',
            'solomon islands' => 'Соломоновы Острова',
            'bulgaria' => 'Болгария',
            'brisbane roar' => 'Брисбен Роар',
            'perth glory' => 'Перт Глори',
            'australia' => 'Австралия',
            'cameroon' => 'Камерун',
            'vissel kobe' => 'Виссел Кобе',
            'sanfrecce hiroshima' => 'Санфречче Хиросима',
            'venezuela' => 'Венесуэла',
            'trinidad and tobago' => 'Тринидад и Тобаго',
            'azerbaijan u21' => 'Азербайджан U21',
            'portugal u21' => 'Португалия U21',
            'ir iran' => 'Иран',
            'nigeria' => 'Нигерия',
            'togo' => 'Того',
            'guinea' => 'Гвинея',
            'somalia' => 'Сомали',
            'mauritius' => 'Маврикий',
            'indonesia' => 'Индонезия',
            'st kitts and nevis' => 'Сент-Китс и Невис',
            'benin' => 'Бенин',
            'liberia' => 'Либерия',
            'chad' => 'Чад',
            'burundi' => 'Бурунди',
            'uzbekistan' => 'Узбекистан',
            'gabon' => 'Габон',
        ];

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        return Str::title($normalized);
    }

    public function teams(): array
    {
        return Team::query()->orderBy('id')->get()
            ->map(static fn ($row): array => [
                'id' => (int) $row->id,
                'slug' => (string) $row->slug,
                'name' => (string) $row->name,
                'league_id' => (int) $row->league_id,
            ])->all();
    }

    public function leagueBySlug(string $slug): ?array
    {
        foreach ($this->leagues() as $league) {
            if (($league['slug'] ?? '') === $slug) {
                return $league;
            }
        }

        return null;
    }

    public function teamBySlug(string $slug): ?array
    {
        foreach ($this->teams() as $team) {
            if (($team['slug'] ?? '') === $slug) {
                return $team;
            }
        }

        return null;
    }

    public function seoMeta(): array
    {
        return SeoMeta::query()->orderByDesc('id')->get()->map(
            static fn ($row): array => (array) $row
        )->all();
    }

    public function findSeo(string $entityType, string $entitySlug): ?array
    {
        $row = SeoMeta::query()
            ->where('entity_type', $entityType)
            ->where('entity_slug', $entitySlug)
            ->first();

        return $row ? (array) $row : null;
    }

    public function credentials(): array
    {
        return ProviderCredential::query()->orderByDesc('id')->get()
            ->map(static function ($row): array {
                $data = (array) $row;
                $data['id'] = (int) $row->id;
                $data['is_active'] = (bool) $row->is_active;
                return $data;
            })->all();
    }

    public function activeCredential(string $provider): ?array
    {
        $row = ProviderCredential::query()
            ->where('provider', $provider)
            ->where('is_active', true)
            ->orderByDesc('id')
            ->first();

        if (!$row) {
            return null;
        }

        $encoded = (string) ($row->secret_encrypted ?? '');

        return [
            'id' => (int) $row->id,
            'provider' => (string) $row->provider,
            'label' => (string) $row->label,
            'secret' => base64_decode($encoded, true) ?: '',
        ];
    }

    public function rotateCredential(string $label, string $secret): int
    {
        return DB::transaction(static function () use ($label, $secret): int {
            ProviderCredential::query()
                ->where('provider', 'deepseek')
                ->update(['is_active' => false, 'updated_at' => now()]);

            return (int) ProviderCredential::query()->insertGetId([
                'provider' => 'deepseek',
                'label' => $label,
                'secret_encrypted' => base64_encode($secret),
                'is_active' => true,
                'last_validated_at' => now(),
                'last_validation_status' => 'ok',
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    public function upsertSeo(array $data): void
    {
        $exists = $this->findSeo((string) $data['entity_type'], (string) $data['entity_slug']);
        if ($exists !== null) {
            SeoMeta::query()->where('id', (int) $exists['id'])->update([
                'title' => (string) $data['title'],
                'description' => (string) $data['description'],
                'h1' => (string) $data['h1'],
                'canonical' => (string) $data['canonical'],
                'robots' => (string) $data['robots'],
                'updated_at' => now(),
            ]);
            return;
        }

        SeoMeta::query()->insert([
            'entity_type' => (string) $data['entity_type'],
            'entity_slug' => (string) $data['entity_slug'],
            'title' => (string) $data['title'],
            'description' => (string) $data['description'],
            'h1' => (string) $data['h1'],
            'canonical' => (string) $data['canonical'],
            'robots' => (string) $data['robots'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function contentPages(): array
    {
        return ContentPage::query()->orderBy('id')->get()->map(
            static fn ($row): array => (array) $row
        )->all();
    }

    public function contentPageByEntity(string $entityType, string $entitySlug): ?array
    {
        $row = ContentPage::query()
            ->where('entity_type', $entityType)
            ->where('entity_slug', $entitySlug)
            ->first();

        return $row ? (array) $row : null;
    }

    public function publishContent(int $pageId, string $title, string $body): void
    {
        DB::transaction(static function () use ($pageId, $title, $body): void {
            $currentVersion = (int) ContentPage::query()->where('id', $pageId)->value('version');
            $newVersion = $currentVersion + 1;

            ContentPage::query()->where('id', $pageId)->update([
                'title' => $title,
                'body' => $body,
                'status' => 'published',
                'version' => $newVersion,
                'updated_at' => now(),
            ]);

            ContentVersion::query()->insert([
                'page_id' => $pageId,
                'version' => $newVersion,
                'title' => $title,
                'body' => $body,
                'created_at' => now(),
            ]);
        });
    }

    public function rollbackContent(int $pageId, int $version): bool
    {
        $target = ContentVersion::query()
            ->where('page_id', $pageId)
            ->where('version', $version)
            ->first();

        if (!$target) {
            return false;
        }

        ContentPage::query()->where('id', $pageId)->update([
            'title' => (string) $target->title,
            'body' => (string) $target->body,
            'version' => $version,
            'status' => 'published',
            'updated_at' => now(),
        ]);

        return true;
    }

    public function queueReindex(int $pageId): int
    {
        return (int) ReindexJob::query()->insertGetId([
            'page_id' => $pageId,
            'status' => 'queued',
            'queued_at' => now(),
            'attempts' => 0,
        ]);
    }

    public function queueReindexAllPages(): int
    {
        $ids = ContentPage::query()->pluck('id')->all();
        if ($ids === []) {
            return 0;
        }

        $payload = [];
        $now = now();
        foreach ($ids as $id) {
            $payload[] = [
                'page_id' => (int) $id,
                'status' => 'queued',
                'queued_at' => $now,
                'attempts' => 0,
            ];
        }

        ReindexJob::query()->insert($payload);

        return count($payload);
    }

    public function processNextReindexJob(): ?array
    {
        $job = DB::transaction(function () {
            $row = ReindexJob::query()
                ->where('status', 'queued')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$row) {
                return null;
            }

            ReindexJob::query()
                ->where('id', (int) $row->id)
                ->update([
                    'status' => 'processing',
                    'processing_started_at' => now(),
                    'attempts' => ((int) ($row->attempts ?? 0)) + 1,
                    'error_message' => null,
                ]);

            return (array) $row;
        });

        if ($job === null) {
            return null;
        }

        try {
            // Placeholder for actual search index update side effects.
            ReindexJob::query()
                ->where('id', (int) $job['id'])
                ->update([
                    'status' => 'done',
                    'finished_at' => now(),
                    'error_message' => null,
                ]);

            return ['id' => (int) $job['id'], 'status' => 'done'];
        } catch (\Throwable $e) {
            ReindexJob::query()
                ->where('id', (int) $job['id'])
                ->update([
                    'status' => 'failed',
                    'finished_at' => now(),
                    'error_message' => mb_strcut($e->getMessage(), 0, 500),
                ]);

            return ['id' => (int) $job['id'], 'status' => 'failed'];
        }
    }

    public function findReindexJob(int $id): ?array
    {
        $row = ReindexJob::query()->where('id', $id)->first();
        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'page_id' => (int) $row->page_id,
            'status' => (string) $row->status,
            'attempts' => (int) ($row->attempts ?? 0),
            'queued_at' => (string) $row->queued_at,
            'processing_started_at' => $row->processing_started_at ? (string) $row->processing_started_at : null,
            'finished_at' => $row->finished_at ? (string) $row->finished_at : null,
            'error_message' => $row->error_message ? (string) $row->error_message : null,
        ];
    }

    public function generateAutoNews(): int
    {
        $matches = $this->matches();
        $created = 0;

        foreach ($matches as $match) {
            $slug = 'auto-' . (string) ($match['slug'] ?? '');
            $title = sprintf('%s — %s: ключевые факторы перед матчем', $match['home_team']['name'], $match['away_team']['name']);
            $analysis = (string) ($match['analysis'] ?? 'Краткий редакционный разбор на базе структурированных спортивных данных.');
            $excerpt = mb_strimwidth($analysis, 0, 180, '...');
            $body = $this->buildExpandedMatchArticle($match, $title, $analysis, 'preview');

            $exists = News::query()->where('slug', $slug)->exists();
            News::query()->updateOrInsert(
                ['slug' => $slug],
                [
                    'title' => $title,
                    'excerpt' => $excerpt,
                    'body' => $body,
                    'published_at' => now(),
                    'league_slug' => $match['league']['slug'],
                    'team_slug' => $match['home_team']['slug'],
                ]
            );
            if (!$exists) {
                $created++;
            }

            $this->syncGeneratedMatchCoverage($match, $title, $analysis, $body, [
                ['q' => 'Когда стартует матч?', 'a' => date('d.m.Y H:i', strtotime((string) $match['kickoff_at']))],
                ['q' => 'Где смотреть игру?', 'a' => $this->watchChannelsText($match['where_to_watch'] ?? []) ?: 'Информация уточняется'],
            ], $slug);
        }

        $this->syncReferencePages();

        return $created;
    }

    public function syncGeneratedMatchCoverage(array $match, string $headline, string $analysis, string $bodyMarkdown = '', array $faq = [], ?string $newsSlug = null, bool $queueReindex = true): void
    {
        $watch = $this->watchChannelsText($match['where_to_watch'] ?? []);
        $body = trim($bodyMarkdown);
        if ($body === '') {
            $body = $this->buildExpandedMatchArticle($match, $headline, $analysis, 'preview');
        }

        if ($watch !== '' && !str_contains(mb_strtolower($body), mb_strtolower($watch))) {
            $body = rtrim($body) . "\n\n## Где смотреть\nТрансляция: {$watch}.";
        }

        $page = $this->upsertContentPage(
            'match',
            (string) $match['slug'],
            $headline,
            $body
        );

        $canonical = $this->siteUrl('/match/' . $match['slug']);
        $description = mb_strimwidth($analysis, 0, 155, '...');
        $this->upsertSeoRecord(
            'match',
            (string) $match['slug'],
            $headline . ' | ' . self::SITE_NAME,
            $description,
            $headline,
            $canonical
        );

        if ($queueReindex && $page['changed']) {
            $this->queueReindex((int) $page['id']);
        }

        $this->syncLeaguePage((string) $match['league']['slug'], $queueReindex);
        $this->syncTeamPage((string) $match['home_team']['slug'], $queueReindex);
        $this->syncTeamPage((string) $match['away_team']['slug'], $queueReindex);
    }

    public function syncReferencePages(bool $queueReindex = true): void
    {
        foreach ($this->matches() as $match) {
            $this->syncLeaguePage((string) $match['league']['slug'], $queueReindex);
            $this->syncTeamPage((string) $match['home_team']['slug'], $queueReindex);
            $this->syncTeamPage((string) $match['away_team']['slug'], $queueReindex);
        }
    }

    /**
     * @return array{id:int, changed:bool}
     */
    private function upsertContentPage(string $entityType, string $entitySlug, string $title, string $body): array
    {
        $existing = $this->contentPageByEntity($entityType, $entitySlug);
        if ($existing === null) {
            $pageId = (int) ContentPage::query()->insertGetId([
                'entity_type' => $entityType,
                'entity_slug' => $entitySlug,
                'status' => 'published',
                'version' => 1,
                'title' => $title,
                'body' => $body,
                'updated_at' => now(),
            ]);

            ContentVersion::query()->insert([
                'page_id' => $pageId,
                'version' => 1,
                'title' => $title,
                'body' => $body,
                'created_at' => now(),
            ]);

            return ['id' => $pageId, 'changed' => true];
        }

        if (
            (string) ($existing['title'] ?? '') === $title &&
            (string) ($existing['body'] ?? '') === $body &&
            (string) ($existing['status'] ?? '') === 'published'
        ) {
            return ['id' => (int) $existing['id'], 'changed' => false];
        }

        $newVersion = ((int) ($existing['version'] ?? 0)) + 1;
        ContentPage::query()->where('id', (int) $existing['id'])->update([
            'status' => 'published',
            'version' => $newVersion,
            'title' => $title,
            'body' => $body,
            'updated_at' => now(),
        ]);

        ContentVersion::query()->insert([
            'page_id' => (int) $existing['id'],
            'version' => $newVersion,
            'title' => $title,
            'body' => $body,
            'created_at' => now(),
        ]);

        return ['id' => (int) $existing['id'], 'changed' => true];
    }

    private function upsertSeoRecord(
        string $entityType,
        string $entitySlug,
        string $title,
        string $description,
        string $h1,
        string $canonical,
        string $robots = 'index,follow'
    ): void {
        $exists = $this->findSeo($entityType, $entitySlug);
        $payload = [
            'title' => $title,
            'description' => $description,
            'h1' => $h1,
            'canonical' => $canonical,
            'robots' => $robots,
            'updated_at' => now(),
        ];

        if ($exists === null) {
            SeoMeta::query()->insert(array_merge([
                'entity_type' => $entityType,
                'entity_slug' => $entitySlug,
                'created_at' => now(),
            ], $payload));
            return;
        }

        SeoMeta::query()->where('id', (int) $exists['id'])->update($payload);
    }

    private function syncLeaguePage(string $leagueSlug, bool $queueReindex = true): void
    {
        $league = $this->leagueBySlug($leagueSlug);
        if ($league === null) {
            return;
        }

        $matches = array_values(array_filter(
            $this->matches(),
            static fn (array $match): bool => ($match['league']['slug'] ?? '') === $leagueSlug
        ));

        $bodyParts = [
            sprintf('%s: расписание, контекст и ключевые матчи ближайшего периода.', $league['name']),
        ];

        foreach (array_slice($matches, 0, 6) as $match) {
            $bodyParts[] = sprintf(
                '%s — %s, %s.',
                $match['home_team']['name'],
                $match['away_team']['name'],
                date('d.m.Y H:i', strtotime((string) $match['kickoff_at']))
            );
        }

        $page = $this->upsertContentPage(
            'league',
            $leagueSlug,
            $league['name'] . ': обзор турнира',
            implode("\n\n", $bodyParts)
        );

        $this->upsertSeoRecord(
            'league',
            $leagueSlug,
            $league['name'] . ': календарь и материалы | ' . self::SITE_NAME,
            'Свежие матчи, новости и редакционные материалы по турниру ' . $league['name'] . '.',
            $league['name'] . ': обзор турнира',
            $this->siteUrl('/league/' . $leagueSlug)
        );

        if ($queueReindex && $page['changed']) {
            $this->queueReindex((int) $page['id']);
        }
    }

    private function syncTeamPage(string $teamSlug, bool $queueReindex = true): void
    {
        $team = $this->teamBySlug($teamSlug);
        if ($team === null) {
            return;
        }

        $matches = array_values(array_filter(
            $this->matches(),
            static fn (array $match): bool => ($match['home_team']['slug'] ?? '') === $teamSlug || ($match['away_team']['slug'] ?? '') === $teamSlug
        ));

        $bodyParts = [
            sprintf('%s: ближайшие матчи, форма и редакционный контекст.', $team['name']),
        ];

        foreach (array_slice($matches, 0, 6) as $match) {
            $opponent = ($match['home_team']['slug'] ?? '') === $teamSlug ? $match['away_team']['name'] : $match['home_team']['name'];
            $bodyParts[] = sprintf(
                'Ближайшая игра против %s пройдет %s в турнире %s.',
                $opponent,
                date('d.m.Y H:i', strtotime((string) $match['kickoff_at'])),
                $match['league']['name']
            );
        }

        $page = $this->upsertContentPage(
            'team',
            $teamSlug,
            $team['name'] . ': матчи и новости',
            implode("\n\n", $bodyParts)
        );

        $this->upsertSeoRecord(
            'team',
            $teamSlug,
            $team['name'] . ': матчи, форма и новости | ' . self::SITE_NAME,
            'Свежая информация по команде ' . $team['name'] . ': расписание, обзоры и материалы.',
            $team['name'] . ': матчи и новости',
            $this->siteUrl('/team/' . $teamSlug)
        );

        if ($queueReindex && $page['changed']) {
            $this->queueReindex((int) $page['id']);
        }
    }

    /**
     * @param array<string,mixed> $match
     */
    public function buildExpandedMatchArticle(array $match, string $headline, string $analysis, string $stage = 'preview'): string
    {
        $kickoff = date('d.m.Y H:i', strtotime((string) $match['kickoff_at']));
        $watch = $this->watchChannelsText($match['where_to_watch'] ?? []);
        $league = (string) ($match['league']['name'] ?? '');
        $home = (string) ($match['home_team']['name'] ?? '');
        $away = (string) ($match['away_team']['name'] ?? '');
        $sport = (string) ($match['sport'] ?? 'football');
        $event = $sport === 'mma-boxing' ? 'бой' : 'матч';
        $eventGenitive = $sport === 'mma-boxing' ? 'боя' : 'матча';
        $eventInstrumental = $sport === 'mma-boxing' ? 'боем' : 'матчем';
        $eventTitle = $sport === 'mma-boxing' ? 'Бой' : 'Матч';
        $analysis = $this->sanitizeMatchAnalysis($analysis, $sport);
        $prediction = $this->buildFallbackPrediction($match);

        $stageLead = match ($stage) {
            'post' => "{$eventGenitive} {$home} — {$away} уже завершился, и теперь ключевой вопрос в том, как этот результат меняет турнирный контекст и ближайшие решения сторон.",
            'follow' => "После {$eventGenitive} {$home} — {$away} у сторон появился новый контекст на ближайший отрезок, и его важно разбирать через факты, а не через общие формулы.",
            'lineup' => "До начала {$eventGenitive} {$home} — {$away} остается минимум времени, поэтому в фокусе стартовые сочетания, кадровые ограничения и ранний игровой ритм.",
            default => "{$eventTitle} {$home} — {$away} пройдет {$kickoff} в турнире {$league}, и сейчас важнее всего понять подтверждённые вводные и вероятный сценарий встречи.",
        };

        $blocks = [
            "## Что важно перед {$eventInstrumental}\n{$stageLead} {$analysis}",
            "## Контекст и подтверждённые данные\n{$home} и {$away} выходят на {$event} в рамках {$league}. В карточке события уже подтверждены турнир, время начала и базовая информация о трансляции. Здесь важнее не выдумывать скрытые детали, а фиксировать то, что действительно известно перед стартом.",
            "## Ключевой риск и точка давления\nГлавный риск перед этой парой обычно связан не с абстрактной формой, а с тем, кто быстрее навяжет удобный ритм и заставит соперника менять исходный план. Если одна из сторон рано заберёт контроль над центром поля, темпом обмена ударами или дистанцией эпизода, второй придётся перестраиваться уже по ходу {$eventGenitive}.",
            "## Прогноз\n{$prediction}",
            $watch !== '' ? "## Где смотреть\nТрансляция: {$watch}." : null,
        ];

        return trim(implode("\n\n", array_filter($blocks)));
    }

    private function sanitizeMatchAnalysis(string $analysis, string $sport): string
    {
        $analysis = trim($analysis);
        if ($analysis === '') {
            return $sport === 'mma-boxing'
                ? 'Сейчас важны подтверждённые данные по бою, составу карда и времени начала без выдуманных деталей.'
                : 'Сейчас важны подтверждённые данные по матчу, составам и времени начала без выдуманных деталей.';
        }

        $legacy = [
            'Редакционный анонс ближайшего матча/боя. Подробный разбор будет опубликован перед началом события.',
            'Редакционная карточка ближайшего матча. Здесь публикуются только подтверждённые данные по событию без выдуманного прогноза.',
            'Редакционная карточка ближайшего боя. Здесь публикуются только подтверждённые данные по событию без выдуманного прогноза.',
        ];

        if (in_array($analysis, $legacy, true)) {
            return $sport === 'mma-boxing'
                ? 'По этому бою пока доступны базовые подтверждённые данные: участники, турнир и время начала.'
                : 'По этому матчу пока доступны базовые подтверждённые данные: участники, турнир и время начала.';
        }

        return $analysis;
    }

    /**
     * @param array<string,mixed> $match
     */
    private function buildFallbackPrediction(array $match): string
    {
        $sport = (string) ($match['sport'] ?? 'football');
        $home = (string) data_get($match, 'home_team.name', 'Первая сторона');
        $away = (string) data_get($match, 'away_team.name', 'Вторая сторона');

        return match ($sport) {
            'basketball' => "Базовый прогноз: {$home} выглядит немного ближе к победе за счёт статуса первой стороны в паре, но матч скорее останется в пределах одной-двух результативных атак до концовки.",
            'hockey' => "Базовый прогноз: {$home} ближе к победе в основное время, но сценарий с плотным счётом вроде 3:2 тоже выглядит реалистично.",
            'tennis' => "Базовый прогноз: {$home} выглядит ближе к победе, а наиболее вероятный исход — матч без затяжного провала по ходу встречи и победа в двух партиях или в решающем сете.",
            'mma-boxing' => "Базовый прогноз: {$home} выглядит чуть ближе к победе, а наиболее реалистичный исход — успешная работа на очки или победа решением судей, а не быстрый досрочный финиш.",
            default => "Базовый прогноз: матч ближе к осторожному сценарию, где {$home} имеет небольшое преимущество, а самый правдоподобный счёт — ничья 1:1 или победа {$home} в один мяч.",
        };
    }

    private function siteUrl(string $path): string
    {
        $base = rtrim((string) env('NEXT_PUBLIC_SITE_URL', env('APP_URL', 'http://localhost')), '/');
        return $base . $path;
    }

    /**
     * @param mixed $watch
     */
    private function watchChannelsText(mixed $watch): string
    {
        if (!is_array($watch)) {
            return '';
        }

        $out = [];
        foreach ($watch as $entry) {
            if (is_string($entry)) {
                $name = trim($entry);
                if ($name !== '') {
                    $out[] = $name;
                }
                continue;
            }

            if (is_array($entry)) {
                $name = trim((string) ($entry['name'] ?? ''));
                if ($name !== '') {
                    $out[] = $name;
                }
            }
        }

        return implode(', ', array_values(array_unique($out)));
    }

    private function containsStaleHistoricalYear(string $title, string $body): bool
    {
        $content = $title . "\n" . $body;
        if (!preg_match_all('/\b(20\d{2})\b/u', $content, $m)) {
            return false;
        }

        $currentYear = (int) now()->format('Y');
        foreach (array_unique(array_map('intval', $m[1] ?? [])) as $year) {
            if ($year <= $currentYear - 2) {
                return true;
            }
        }

        return false;
    }
}

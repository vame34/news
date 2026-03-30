<?php

namespace App\Services;

use App\Models\ContentGenerationQueue;
use App\Models\News;
use App\Models\SeoMeta;
use App\Models\SportMatch;
use App\Models\TrendQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PriorityContentService
{
    private const STAGE_PREVIEW_24H = 'match_preview_t24';
    private const STAGE_PREVIEW_1H = 'match_preview_t1';
    private const STAGE_POST_MATCH = 'match_post_match';
    private const STAGE_FOLLOW_UP = 'match_followup';

    public function queueUpcomingMatchPreviews(int $horizonHours = 72, int $limit = 50): int
    {
        $horizonHours = max(6, min(168, $horizonHours));
        $limit = max(1, min(200, $limit));
        $deepSeek = app(DeepSeekService::class);
        if (!$deepSeek->hasBudgetCapacity()) {
            return 0;
        }

        $now = now();
        $horizon = now()->copy()->addHours($horizonHours);
        $historyWindow = now()->copy()->subHours(18);
        $today = now()->toDateString();

        $trends = TrendQuery::query()
            ->where('observed_date', $today)
            ->orderByDesc('trend_score')
            ->limit(200)
            ->get(['query', 'trend_score'])
            ->map(static fn ($x): array => ['query' => (string) $x->query, 'trend_score' => (int) $x->trend_score])
            ->all();

        $matches = SportMatch::query()->from('matches as m')
            ->join('leagues as l', 'l.id', '=', 'm.league_id')
            ->join('teams as ht', 'ht.id', '=', 'm.home_team_id')
            ->join('teams as at', 'at.id', '=', 'm.away_team_id')
            ->whereBetween('m.kickoff_at', [$historyWindow, $horizon])
            ->orderBy('m.kickoff_at')
            ->limit($limit)
            ->get([
                'm.id',
                'm.slug',
                'm.kickoff_at',
                'l.slug as league_slug',
                'l.name as league_name',
                'ht.name as home_name',
                'at.name as away_name',
                'ht.slug as home_slug',
            ]);

        $queued = 0;
        foreach ($matches as $match) {
            $kickoff = now()->parse((string) $match->kickoff_at);
            $hoursToKickoff = max(0, (int) now()->diffInHours($kickoff, false));

            $trendData = $this->matchTrendHits(
                (string) $match->home_name,
                (string) $match->away_name,
                (string) $match->league_name,
                $trends
            );

            $payload = [
                'kickoff_at' => (string) $match->kickoff_at,
                'league' => (string) $match->league_name,
                'home' => (string) $match->home_name,
                'away' => (string) $match->away_name,
            ];

            $queued += $this->queueMatchStageJob(
                (int) $match->id,
                (string) $match->slug,
                self::STAGE_PREVIEW_24H,
                $kickoff->copy()->subHours(24),
                (string) $match->league_slug,
                $hoursToKickoff,
                $trendData,
                $payload
            );

            $queued += $this->queueMatchStageJob(
                (int) $match->id,
                (string) $match->slug,
                self::STAGE_PREVIEW_1H,
                $kickoff->copy()->subHour(),
                (string) $match->league_slug,
                $hoursToKickoff,
                $trendData,
                $payload
            );

            $hoursSinceKickoff = max(0, (int) $kickoff->diffInHours($now, false));
            $queued += $this->queueMatchStageJob(
                (int) $match->id,
                (string) $match->slug,
                self::STAGE_POST_MATCH,
                $kickoff->copy()->addMinutes(15),
                (string) $match->league_slug,
                $hoursSinceKickoff,
                $trendData,
                $payload
            );

            $queued += $this->queueMatchStageJob(
                (int) $match->id,
                (string) $match->slug,
                self::STAGE_FOLLOW_UP,
                $kickoff->copy()->addHours(12),
                (string) $match->league_slug,
                $hoursSinceKickoff,
                $trendData,
                $payload
            );
        }

        $queued += $this->queueStandaloneTrendTopics($trends);
        $queued += $this->queueSourceBackfillTopics(12);

        return $queued;
    }

    public function processQueue(DeepSeekService $deepSeek, int $limit = 5): array
    {
        $limit = max(1, min(50, $limit));
        $done = 0;
        $skipped = 0;
        $failed = 0;
        $store = app(SportRadarService::class);

        for ($i = 0; $i < $limit; $i++) {
            $job = $this->claimNextJob();
            if ($job === null) {
                break;
            }

            if (($job['entity_type'] ?? '') === 'trend_topic') {
                $result = $this->publishTrendTopic((int) $job['id'], (array) json_decode((string) ($job['payload_json'] ?? '{}'), true));
                if ($result === 'done') {
                    $done++;
                } elseif ($result === 'skipped') {
                    $skipped++;
                } else {
                    $failed++;
                }
                continue;
            }

            $stage = (string) ($job['entity_type'] ?? '');
            $match = $this->matchForJob((int) $job['match_id']);
            if ($match === null) {
                $this->finishJob((int) $job['id'], 'failed', 'match_not_found');
                $failed++;
                continue;
            }

            $kickoff = now()->parse((string) $match['kickoff_at']);
            if (in_array($stage, [self::STAGE_PREVIEW_24H, self::STAGE_PREVIEW_1H], true) && $kickoff->lessThanOrEqualTo(now())) {
                $this->finishJob((int) $job['id'], 'skipped', 'past_event');
                $skipped++;
                continue;
            }

            $ai = $deepSeek->generateMatchArticle($match);
            $strictDeepSeek = filter_var((string) env('DEEPSEEK_STRICT_MODE', 'true'), FILTER_VALIDATE_BOOL);
            if ($strictDeepSeek && !($ai['ok'] ?? false)) {
                $this->finishJob((int) $job['id'], 'failed', 'deepseek_required; stage=' . $stage . '; reason=' . (string) ($ai['reason'] ?? 'unknown'));
                $failed++;
                continue;
            }

            $headline = trim((string) data_get($ai, 'data.headline', ''));
            $analysis = trim((string) data_get($ai, 'data.analysis', ''));

            if ($headline === '' || $analysis === '') {
                $this->finishJob((int) $job['id'], 'failed', 'deepseek_incomplete_payload; stage=' . $stage);
                $failed++;
                continue;
            }

            $headline = $this->normalizeHeadlineToRussian($headline, $match);
            if ($this->isCodeLikeHeadline($headline)) {
                $this->finishJob((int) $job['id'], 'failed', 'code_like_headline; stage=' . $stage);
                $failed++;
                continue;
            }

            $slug = $this->buildNewsSlug((string) $match['slug'], $stage);
            $excerpt = mb_strimwidth(trim($analysis), 0, 190, '...');
            $body = trim((string) data_get($ai, 'data.body_markdown', ''));
            if ($body === '') {
                $this->finishJob((int) $job['id'], 'failed', 'deepseek_missing_body; stage=' . $stage);
                $failed++;
                continue;
            }

            $qualityIssue = $this->evaluateContentQuality(
                $headline,
                $body,
                [
                    (string) data_get($match, 'home_team.name', ''),
                    (string) data_get($match, 'away_team.name', ''),
                    (string) data_get($match, 'league.name', ''),
                ]
            );
            if ($qualityIssue !== null) {
                $this->finishJob((int) $job['id'], 'failed', 'content_quality_failed; stage=' . $stage . '; reason=' . $qualityIssue);
                $failed++;
                continue;
            }
            $matchQuery = trim(implode(' ', array_filter([
                (string) data_get($match, 'home_team.name', ''),
                (string) data_get($match, 'away_team.name', ''),
                (string) data_get($match, 'league.name', ''),
                date('Y', strtotime((string) data_get($match, 'kickoff_at', now()->toDateTimeString()))),
            ])));
            if ($this->hasStaleYearsForCurrentTopic($headline, $body, $matchQuery)) {
                $this->finishJob((int) $job['id'], 'failed', 'stale_year_detected; stage=' . $stage);
                $failed++;
                continue;
            }
            News::query()->updateOrInsert(
                ['slug' => $slug],
                [
                    'title' => $headline,
                    'excerpt' => $excerpt,
                    'body' => $body,
                    'published_at' => now(),
                    'league_slug' => (string) $match['league']['slug'],
                    'team_slug' => (string) $match['home_team']['slug'],
                ]
            );
            $discipline = $this->normalizeDiscipline((string) data_get($ai, 'data.discipline', (string) ($match['league']['sport'] ?? 'football'))) ?? 'football';

            $this->upsertNewsSeo(
                $slug,
                trim((string) data_get($ai, 'data.seo_title', '')) ?: mb_strimwidth($headline . ' | РадарАрена', 0, 68, ''),
                trim((string) data_get($ai, 'data.seo_description', '')) ?: mb_strimwidth($excerpt, 0, 158, ''),
                $headline
            );

            $store->syncGeneratedMatchCoverage(
                $match,
                $headline,
                $analysis,
                $body,
                is_array(data_get($ai, 'data.faq')) ? data_get($ai, 'data.faq') : [],
                $slug
            );

            $source = ($ai['ok'] ?? false) ? 'deepseek' : 'template';
            $reason = ($ai['ok'] ?? false) ? 'published' : ('fallback:' . (string) ($ai['reason'] ?? 'unknown'));
            $this->finishJob((int) $job['id'], 'done', $reason . '; stage=' . $stage . '; source=' . $source . '; slug=' . $slug);
            $done++;
        }

        return ['done' => $done, 'skipped' => $skipped, 'failed' => $failed];
    }

    private function claimNextJob(): ?array
    {
        return DB::transaction(function (): ?array {
            $row = ContentGenerationQueue::query()
                ->where('status', 'queued')
                ->where('scheduled_for', '<=', now())
                ->orderByDesc('priority_score')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (!$row) {
                return null;
            }

            ContentGenerationQueue::query()
                ->where('id', (int) $row->id)
                ->update([
                    'status' => 'processing',
                    'processing_started_at' => now(),
                    'updated_at' => now(),
                ]);

            return (array) $row;
        });
    }

    private function finishJob(int $id, string $status, string $message): void
    {
        ContentGenerationQueue::query()
            ->where('id', $id)
            ->update([
                'status' => $status,
                'processed_at' => now(),
                'result_message' => mb_strcut($message, 0, 1000),
                'updated_at' => now(),
            ]);
    }

    private function buildPriorityScore(string $leagueSlug, int $hoursMetric, int $trendScore, string $stage): int
    {
        $base = 25;
        $leagueBoost = in_array($leagueSlug, ['rpl', 'epl', 'ucl', 'laliga', 'serie-a', 'bundesliga'], true) ? 35 : 15;
        $timeBoost = max(0, 60 - min(60, $hoursMetric));
        $trendBoost = min(80, max(0, $trendScore));
        $stageBoost = match ($stage) {
            self::STAGE_POST_MATCH => 45,
            self::STAGE_PREVIEW_1H => 35,
            self::STAGE_FOLLOW_UP => 25,
            default => 20,
        };

        return $base + $leagueBoost + $timeBoost + $trendBoost + $stageBoost;
    }

    /**
     * @param array<int, array{query:string, trend_score:int}> $trends
     * @return array{trend_score:int, hits:array<int, array{query:string, trend_score:int}>}
     */
    private function matchTrendHits(string $home, string $away, string $league, array $trends): array
    {
        $hits = [];
        $score = 0;

        foreach ($trends as $trend) {
            $query = mb_strtolower($trend['query']);
            $homeHit = mb_stripos($query, mb_strtolower($home)) !== false;
            $awayHit = mb_stripos($query, mb_strtolower($away)) !== false;
            $leagueHit = mb_stripos($query, mb_strtolower($league)) !== false;

            if (($homeHit && $awayHit) || $leagueHit) {
                $hits[] = $trend;
                $score += (int) $trend['trend_score'];
            }
        }

        return [
            'trend_score' => min(80, $score),
            'hits' => array_slice($hits, 0, 10),
        ];
    }

    private function matchForJob(int $matchId): ?array
    {
        $store = app(SportRadarService::class);
        foreach ($store->matches() as $match) {
            if ((int) ($match['id'] ?? 0) === $matchId) {
                return $match;
            }
        }

        return null;
    }

    private function buildNewsSlug(string $matchSlug, string $stage): string
    {
        $prefix = match ($stage) {
            self::STAGE_PREVIEW_1H => 'lineup-update-',
            self::STAGE_POST_MATCH => 'result-',
            self::STAGE_FOLLOW_UP => 'analysis-',
            default => 'preview-',
        };

        return $prefix . $matchSlug;
    }

    /**
     * @param array{trend_score:int,hits:array<int,array{query:string,trend_score:int}>} $trendData
     * @param array<string,mixed> $payload
     */
    private function queueMatchStageJob(
        int $matchId,
        string $matchSlug,
        string $stage,
        \Illuminate\Support\Carbon $scheduledFor,
        string $leagueSlug,
        int $hoursMetric,
        array $trendData,
        array $payload
    ): int {
        $kickoff = now()->parse((string) ($payload['kickoff_at'] ?? now()->toDateTimeString()));

        if (in_array($stage, [self::STAGE_PREVIEW_24H, self::STAGE_PREVIEW_1H], true) && $kickoff->lessThanOrEqualTo(now())) {
            return 0;
        }

        if (in_array($stage, [self::STAGE_POST_MATCH, self::STAGE_FOLLOW_UP], true) && $kickoff->greaterThan(now()->copy()->addHours(2))) {
            return 0;
        }

        $entitySlug = $matchSlug . ':' . $stage;
        $alreadyExists = ContentGenerationQueue::query()
            ->where('entity_type', $stage)
            ->where('entity_slug', $entitySlug)
            ->whereIn('status', ['queued', 'processing', 'done', 'skipped'])
            ->exists();

        if ($alreadyExists) {
            return 0;
        }

        ContentGenerationQueue::query()->insert([
            'match_id' => $matchId,
            'entity_type' => $stage,
            'entity_slug' => $entitySlug,
            'priority_score' => $this->buildPriorityScore($leagueSlug, $hoursMetric, (int) $trendData['trend_score'], $stage),
            'status' => 'queued',
            'trend_hits_json' => json_encode($trendData['hits'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'payload_json' => json_encode($payload + ['stage' => $stage], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'scheduled_for' => $scheduledFor->lessThan(now()) ? now() : $scheduledFor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return 1;
    }

    /**
     * @param array<string,mixed> $match
     */
    private function fallbackHeadline(string $stage, array $match): string
    {
        return match ($stage) {
            self::STAGE_PREVIEW_1H => sprintf('%s — %s: что изменилось перед стартом', $match['home_team']['name'], $match['away_team']['name']),
            self::STAGE_POST_MATCH => sprintf('%s — %s: как результат меняет расклад', $match['home_team']['name'], $match['away_team']['name']),
            self::STAGE_FOLLOW_UP => sprintf('%s — %s: последствия результата и следующий шаг', $match['home_team']['name'], $match['away_team']['name']),
            default => sprintf('%s — %s: главное перед игрой', $match['home_team']['name'], $match['away_team']['name']),
        };
    }

    private function fallbackAnalysis(string $stage): string
    {
        return match ($stage) {
            self::STAGE_PREVIEW_1H => 'Короткий апдейт перед стартом: составы, потери, свежая форма и один ключевой риск для каждой стороны.',
            self::STAGE_POST_MATCH => 'Оперативный разбор: счет, решающие эпизоды, статистический перекос и влияние результата на таблицу.',
            self::STAGE_FOLLOW_UP => 'Продолжение темы: что изменилось после результата, кто оказался под давлением и чего ждать дальше.',
            default => 'Предматчевый разбор с упором на форму, таблицу, кадровые нюансы и конкретные факторы, которые уже влияют на сценарий игры.',
        };
    }

    /**
     * @param array<string,mixed> $match
     */
    private function stageContextLine(string $stage, array $match): string
    {
        return match ($stage) {
            self::STAGE_PREVIEW_1H => sprintf(
                'До матча %s — %s остается около часа. Турнир: %s.',
                $match['home_team']['name'],
                $match['away_team']['name'],
                $match['league']['name']
            ),
            self::STAGE_POST_MATCH => sprintf(
                'Матч %s — %s завершен. Турнир: %s.',
                $match['home_team']['name'],
                $match['away_team']['name'],
                $match['league']['name']
            ),
            self::STAGE_FOLLOW_UP => sprintf(
                'Продолжение темы по матчу %s — %s и его последствиям в турнире %s.',
                $match['home_team']['name'],
                $match['away_team']['name'],
                $match['league']['name']
            ),
            default => sprintf(
                'Матч %s — %s пройдет %s. Турнир: %s.',
                $match['home_team']['name'],
                $match['away_team']['name'],
                date('d.m.Y H:i', strtotime((string) $match['kickoff_at'])),
                $match['league']['name']
            ),
        };
    }

    /**
     * @param array<int, array{query:string, trend_score:int}> $trends
     */
    private function queueStandaloneTrendTopics(array $trends): int
    {
        $queued = 0;
        $limit = 10;

        foreach ($trends as $trend) {
            if ($queued >= $limit) {
                break;
            }

            $query = trim((string) ($trend['query'] ?? ''));
            if ($query === '' || $this->isTrendQueryTooGeneric($query)) {
                continue;
            }

            $discipline = $this->inferDisciplineFromText($query);
            if ($discipline === null) {
                continue;
            }

            $entitySlug = 'trend-' . Str::slug($query);
            if ($entitySlug === 'trend-') {
                continue;
            }

            $alreadyQueued = ContentGenerationQueue::query()
                ->where('entity_type', 'trend_topic')
                ->where('entity_slug', $entitySlug)
                ->whereIn('status', ['queued', 'processing'])
                ->exists();

            if ($alreadyQueued) {
                continue;
            }

            ContentGenerationQueue::query()->insert([
                'match_id' => null,
                'entity_type' => 'trend_topic',
                'entity_slug' => $entitySlug,
                'priority_score' => 60 + (int) ($trend['trend_score'] ?? 0) + $this->disciplinePriorityBoost($discipline),
                'status' => 'queued',
                'trend_hits_json' => json_encode([$trend], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'payload_json' => json_encode(['query' => $query, 'discipline' => $discipline], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'scheduled_for' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $queued++;
        }

        return $queued;
    }

    private function queueSourceBackfillTopics(int $limit = 10): int
    {
        $limit = max(1, min(30, $limit));
        $aggregator = app(SourceNewsAggregationService::class);
        $topics = $aggregator->collectBackfillTopics($limit);
        $queued = 0;

        foreach ($topics as $topic) {
            $query = trim((string) ($topic['query'] ?? ''));
            if ($query === '' || $this->isTrendQueryTooGeneric($query)) {
                continue;
            }

            $discipline = $this->normalizeDiscipline((string) ($topic['discipline'] ?? '')) ?? $this->inferDisciplineFromText($query);
            if ($discipline === null) {
                continue;
            }

            $entitySlug = 'trend-' . Str::slug($query);
            if ($entitySlug === 'trend-') {
                continue;
            }

            $exists = ContentGenerationQueue::query()
                ->where('entity_type', 'trend_topic')
                ->where('entity_slug', $entitySlug)
                ->whereIn('status', ['queued', 'processing', 'done'])
                ->exists();
            if ($exists) {
                continue;
            }

            ContentGenerationQueue::query()->insert([
                'match_id' => null,
                'entity_type' => 'trend_topic',
                'entity_slug' => $entitySlug,
                'priority_score' => 35 + $this->disciplinePriorityBoost($discipline),
                'status' => 'queued',
                'trend_hits_json' => json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'payload_json' => json_encode([
                    'query' => $query,
                    'discipline' => $discipline,
                    'seed_source' => (string) ($topic['source'] ?? 'rss'),
                    'origin' => 'source_backfill',
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'scheduled_for' => now()->addMinutes(5),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $queued++;
        }

        return $queued;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function publishTrendTopic(int $jobId, array $payload): string
    {
        $query = trim((string) ($payload['query'] ?? ''));
        if ($query === '') {
            $this->finishJob($jobId, 'failed', 'empty_trend_query');
            return 'failed';
        }
        if ($this->isTrendQueryTooGeneric($query)) {
            $this->finishJob($jobId, 'failed', 'generic_trend_query; trend_query=' . $query);
            return 'failed';
        }
        $discipline = $this->normalizeDiscipline((string) ($payload['discipline'] ?? '')) ?? $this->inferDisciplineFromText($query) ?? 'football';

        $slug = 'trend-' . Str::slug($query);
        if ($slug === 'trend-') {
            $this->finishJob($jobId, 'failed', 'invalid_trend_slug');
            return 'failed';
        }

        $aggregator = app(SourceNewsAggregationService::class);
        $sourceContext = $aggregator->collectRelevant($query, $discipline, 8);
        $events = $this->upcomingEventHints($discipline, $query, 6);

        $deepSeek = app(DeepSeekService::class);
        $ai = $deepSeek->generateTrendArticle($query, $discipline, $sourceContext, $events);
        $strictDeepSeek = filter_var((string) env('DEEPSEEK_STRICT_MODE', 'true'), FILTER_VALIDATE_BOOL);
        $strictSourceContext = filter_var((string) env('TREND_REQUIRE_SOURCE_CONTEXT', 'true'), FILTER_VALIDATE_BOOL);
        if ($strictSourceContext && count($sourceContext) < 2) {
            $this->finishJob($jobId, 'failed', 'missing_source_context; trend_query=' . $query . '; count=' . count($sourceContext));
            return 'failed';
        }
        if ($strictDeepSeek && !($ai['ok'] ?? false)) {
            $this->finishJob($jobId, 'failed', 'deepseek_required; trend_query=' . $query . '; reason=' . (string) ($ai['reason'] ?? 'unknown'));
            return 'failed';
        }

        $aiOk = (bool) ($ai['ok'] ?? false);
        $headline = trim((string) data_get($ai, 'data.headline', ''));
        $excerpt = trim((string) data_get($ai, 'data.excerpt', ''));
        $body = trim((string) data_get($ai, 'data.body_markdown', ''));
        $seoTitle = trim((string) data_get($ai, 'data.seo_title', ''));
        $seoDescription = trim((string) data_get($ai, 'data.seo_description', ''));
        $discipline = $this->normalizeDiscipline((string) data_get($ai, 'data.discipline', $discipline)) ?? $discipline;

        if ($headline === '' || $body === '') {
            $this->finishJob($jobId, 'failed', 'deepseek_incomplete_payload; trend_query=' . $query);
            return 'failed';
        }
        $headline = $this->normalizeHeadlineToRussian($headline, null, $query);
        if ($this->isCodeLikeHeadline($headline)) {
            $this->finishJob($jobId, 'failed', 'code_like_headline; trend_query=' . $query);
            return 'failed';
        }
        $qualityIssue = $this->evaluateContentQuality($headline, $body, [$query]);
        if ($qualityIssue !== null) {
            $this->finishJob($jobId, 'failed', 'content_quality_failed; trend_query=' . $query . '; reason=' . $qualityIssue);
            return 'failed';
        }
        if ($this->hasStaleYearsForCurrentTopic($headline, $body, $query)) {
            $this->finishJob($jobId, 'failed', 'stale_year_detected; trend_query=' . $query);
            return 'failed';
        }
        if ($excerpt === '') {
            $excerpt = mb_strimwidth(strip_tags(str_replace(["\n", '#', '*'], ' ', $body)), 0, 210, '...');
        }
        if ($seoTitle === '') {
            $seoTitle = mb_strimwidth($headline . ' | РадарАрена', 0, 68, '');
        }
        if ($seoDescription === '') {
            $seoDescription = mb_strimwidth($excerpt, 0, 158, '');
        }

        $leagueSlug = $this->disciplineToLeagueSlug($discipline);
        News::query()->updateOrInsert(
            ['slug' => $slug],
            [
                'title' => $headline,
                'excerpt' => $excerpt,
                'body' => $body,
                'published_at' => now(),
                'league_slug' => $leagueSlug,
                'team_slug' => $discipline,
            ]
        );
        $this->upsertNewsSeo($slug, $seoTitle, $seoDescription, $headline);

        $this->finishJob($jobId, 'done', 'published trend topic; slug=' . $slug . '; source=' . ($aiOk ? 'deepseek' : 'fallback'));
        return 'done';
    }

    /**
     * @return array<int, array{match:string,league:string,kickoff_at:string}>
     */
    private function upcomingEventHints(string $discipline, string $query, int $limit = 6): array
    {
        $limit = max(1, min(20, $limit));
        $sport = match ($discipline) {
            'mma-boxing' => 'mma',
            default => $discipline,
        };

        $rows = SportMatch::query()->from('matches as m')
            ->join('leagues as l', 'l.id', '=', 'm.league_id')
            ->join('teams as ht', 'ht.id', '=', 'm.home_team_id')
            ->join('teams as at', 'at.id', '=', 'm.away_team_id')
            ->where('l.sport', $sport)
            ->whereBetween('m.kickoff_at', [now()->subHours(4), now()->addDays(6)])
            ->orderBy('m.kickoff_at')
            ->limit($limit * 2)
            ->get([
                'ht.name as home_name',
                'at.name as away_name',
                'l.name as league_name',
                'm.kickoff_at',
            ]);

        $queryLower = mb_strtolower($query);
        $hints = [];
        foreach ($rows as $row) {
            $hit = mb_stripos($queryLower, mb_strtolower((string) $row->home_name)) !== false
                || mb_stripos($queryLower, mb_strtolower((string) $row->away_name)) !== false
                || mb_stripos($queryLower, mb_strtolower((string) $row->league_name)) !== false;
            if (!$hit && !preg_match('/\s[-–—]\s/u', $query)) {
                continue;
            }

            $hints[] = [
                'match' => trim((string) $row->home_name . ' — ' . (string) $row->away_name),
                'league' => (string) $row->league_name,
                'kickoff_at' => (string) $row->kickoff_at,
            ];

            if (count($hints) >= $limit) {
                break;
            }
        }

        if ($hints === []) {
            foreach ($rows->take($limit) as $row) {
                $hints[] = [
                    'match' => trim((string) $row->home_name . ' — ' . (string) $row->away_name),
                    'league' => (string) $row->league_name,
                    'kickoff_at' => (string) $row->kickoff_at,
                ];
            }
        }

        return $hints;
    }

    private function mapStageToArticleType(string $stage): string
    {
        return match ($stage) {
            self::STAGE_POST_MATCH => 'post',
            self::STAGE_FOLLOW_UP => 'follow',
            self::STAGE_PREVIEW_1H => 'lineup',
            default => 'preview',
        };
    }

    /**
     * Returns null if content passes quality gate; otherwise returns failure reason.
     *
     * @param array<int, string> $requiredNeedles
     */
    private function evaluateContentQuality(string $headline, string $body, array $requiredNeedles = []): ?string
    {
        $headlineIssue = $this->evaluateHeadlineQuality($headline);
        if ($headlineIssue !== null) {
            return $headlineIssue;
        }

        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags(str_replace(['#', '*', '-', "\r"], ' ', $body))) ?? '');
        if (mb_strlen($plain) < 1300) {
            return 'too_short';
        }

        $h2Count = preg_match_all('/^##\s+/m', $body);
        if ($h2Count < 2) {
            return 'missing_structure';
        }

        $numberCount = preg_match_all('/\b\d+([.,]\d+)?\b/u', $plain);
        if ($numberCount < 4) {
            return 'low_fact_density';
        }

        $clichePool = [
            'матч обещает быть интересным',
            'все может решить мотивация',
            'команды покажут борьбу',
            'нас ждет яркий матч',
            'будет непросто',
            'трудно предсказать исход',
        ];
        $lower = mb_strtolower($plain);
        foreach ($clichePool as $cliche) {
            if (mb_stripos($lower, $cliche) !== false) {
                return 'template_cliche';
            }
        }

        $words = preg_split('/\s+/u', $lower) ?: [];
        $words = array_values(array_filter($words, static fn ($x): bool => mb_strlen((string) $x) >= 4));
        if (count($words) > 20) {
            $unique = count(array_unique($words));
            $ratio = $unique / max(1, count($words));
            if ($ratio < 0.38) {
                return 'low_uniqueness';
            }
        }

        foreach ($requiredNeedles as $needle) {
            $needle = trim((string) $needle);
            if ($needle === '') {
                continue;
            }
            if (!$this->hasRequiredContext($lower, $needle)) {
                return 'missing_context:' . $needle;
            }
        }

        if (trim($headline) === '') {
            return 'empty_headline';
        }

        return null;
    }

    private function hasRequiredContext(string $haystackLower, string $needle): bool
    {
        $variants = [trim(mb_strtolower($needle))];
        $transliterated = trim(mb_strtolower($this->transliteratePhraseToRussian($needle)));
        if ($transliterated !== '') {
            $variants[] = $transliterated;
        }

        foreach ($variants as $variant) {
            if ($variant !== '' && mb_stripos($haystackLower, $variant) !== false) {
                return true;
            }
        }

        $tokens = [];
        foreach ($variants as $variant) {
            $parts = preg_split('/[^\p{L}\p{N}]+/u', $variant) ?: [];
            foreach ($parts as $part) {
                $part = trim($part);
                if (mb_strlen($part) >= 4) {
                    $tokens[$part] = true;
                }
            }
        }

        if ($tokens === []) {
            return true;
        }

        $matched = 0;
        foreach (array_keys($tokens) as $token) {
            if (mb_stripos($haystackLower, $token) !== false) {
                $matched++;
            }
        }

        $requiredMatches = min(2, count($tokens));
        return $matched >= max(1, $requiredMatches);
    }

    private function evaluateHeadlineQuality(string $headline): ?string
    {
        $headline = trim($headline);
        if ($headline === '') {
            return 'empty_headline';
        }

        $length = mb_strlen($headline);
        if ($length < 24) {
            return 'headline_too_short';
        }
        if ($length > 140) {
            return 'headline_too_long';
        }

        if (preg_match('/\b[A-Z]{2,4}\s*(?:@|vs\.?|v\.?|[-–—])\s*[A-Z]{2,4}\b/u', $headline) === 1) {
            return 'headline_contains_scoreboard_code';
        }

        if (preg_match('/\b[A-Z]{2,4}\s*@\s*[A-Z]{2,4}\b/u', $headline) === 1) {
            return 'headline_contains_league_code';
        }

        if (preg_match('/\bvs\.?\b/iu', $headline) === 1) {
            return 'headline_contains_vs';
        }

        return null;
    }

    private function isTrendQueryTooGeneric(string $query): bool
    {
        $normalized = trim(mb_strtolower($query));
        if ($normalized === '') {
            return true;
        }

        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $tokens = array_values(array_filter(
            preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [],
            static fn (string $token): bool => $token !== ''
        ));

        $genericPhrases = [
            'футбол',
            'футбол онлайн',
            'футбол сегодня',
            'футбол россии',
            'футбол россии премьер лига',
            'премьер лига',
            'рпл',
            'хоккей',
            'хоккей сегодня',
            'баскетбол',
            'баскетбол сегодня',
            'теннис',
            'теннис сегодня',
            'мма',
            'бокс',
            'ufc',
            'онлайн трансляция',
            'результаты матчей',
            'сегодняшние матчи',
        ];

        if (in_array($normalized, $genericPhrases, true)) {
            return true;
        }

        $genericTokens = [
            'футбол', 'хоккей', 'баскетбол', 'теннис', 'мма', 'бокс', 'ufc', 'онлайн',
            'сегодня', 'результаты', 'матчей', 'матчи', 'лига', 'лиги', 'премьер',
            'клуб', 'клуба', 'сборная', 'спорт', 'sports', 'news',
        ];

        if (count($tokens) <= 3) {
            $allGeneric = true;
            foreach ($tokens as $token) {
                if (!in_array($token, $genericTokens, true)) {
                    $allGeneric = false;
                    break;
                }
            }

            if ($allGeneric) {
                return true;
            }
        }

        return false;
    }

    private function upsertNewsSeo(string $newsSlug, string $title, string $description, string $h1): void
    {
        $exists = SeoMeta::query()
            ->where('entity_type', 'news')
            ->where('entity_slug', $newsSlug)
            ->first();

        $payload = [
            'title' => $title,
            'description' => $description,
            'h1' => $h1,
            'canonical' => 'https://radararena.ru/news/' . $newsSlug,
            'robots' => 'index,follow',
            'updated_at' => now(),
        ];

        if ($exists) {
            SeoMeta::query()->where('id', (int) $exists->id)->update($payload);
            return;
        }

        SeoMeta::query()->insert(array_merge($payload, [
            'entity_type' => 'news',
            'entity_slug' => $newsSlug,
            'created_at' => now(),
        ]));
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
            'tennis', 'теннис' => 'tennis',
            'hockey', 'хоккей', 'ice-hockey', 'nhl', 'khl' => 'hockey',
            'mma-boxing', 'mma', 'boxing', 'бокс', 'мма', 'мма/бокс', 'mma/boxing' => 'mma-boxing',
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
            'football' => ['футбол', 'football', 'soccer', 'рпл', 'апл', 'лига чемпионов', 'зенит', 'спартак', 'динамо', 'челси', 'арсенал'],
            'basketball' => ['баскетбол', 'basketball', 'nba', 'euroleague', 'евролига', 'knicks', 'hornets', 'lakers', 'celtics'],
            'tennis' => ['теннис', 'tennis', 'atp', 'wta', 'wimbledon', 'roland garros', 'australian open', 'us open'],
            'hockey' => ['хоккей', 'hockey', 'nhl', 'khl', 'нхл', 'кхл', 'stanley cup'],
            'mma-boxing' => ['mma', 'ufc', 'bellator', 'бокс', 'boxing', 'мма', 'fight night'],
        ] as $discipline => $needles) {
            foreach ($needles as $needle) {
                if (mb_stripos($pool, $needle) !== false) {
                    return $discipline;
                }
            }
        }

        return null;
    }

    private function disciplineToLeagueSlug(string $discipline): string
    {
        return match ($discipline) {
            'basketball' => 'basketball',
            'tennis' => 'tennis',
            'hockey' => 'hockey',
            'mma-boxing' => 'mma-boxing',
            default => 'football',
        };
    }

    private function disciplineLabel(string $discipline): string
    {
        return match ($discipline) {
            'basketball' => 'Баскетбол',
            'tennis' => 'Теннис',
            'hockey' => 'Хоккей',
            'mma-boxing' => 'ММА/бокс',
            default => 'Футбол',
        };
    }

    private function disciplinePriorityBoost(string $discipline): int
    {
        return match ($discipline) {
            'football' => 20,
            'hockey' => 16,
            'basketball' => 14,
            'tennis' => 12,
            'mma-boxing' => 12,
            default => 8,
        };
    }

    private function fallbackTrendHeadline(string $query, string $discipline): string
    {
        return sprintf('%s: главный сюжет и его последствия', $query);
    }

    private function fallbackTrendBody(string $query, string $discipline): string
    {
        $label = $this->disciplineLabel($discipline);

        return implode("\n\n", [
            '# ' . $this->fallbackTrendHeadline($query, $discipline),
            "## Что произошло\nТема «{$query}» попала в повестку по дисциплине {$label}. Редакция фиксирует главный факт, подтвержденный контекст и его значение для ближайших событий.",
            "## Контекст\nСюжет важен не сам по себе, а из-за конкретных последствий: положения в турнире, формы лидеров, кадровой ситуации и ближайшего календаря.",
            "## Ключевые факты\n- Подтвержденные данные и цифры по теме.\n- Последствия для участников и ближайших матчей.\n- Реакция рынка, лиги или команд, если она уже зафиксирована.",
            "## Что дальше\nМатериал обновляется по мере появления новых подтвержденных деталей, а не ради общего фона.",
        ]);
    }

    private function hasStaleYearsForCurrentTopic(string $headline, string $body, string $query): bool
    {
        $content = $headline . "\n" . $body;
        preg_match_all('/\b(20\d{2})\b/u', $content, $m);
        $years = array_map('intval', $m[1] ?? []);
        if ($years === []) {
            return false;
        }

        $queryYears = [];
        preg_match_all('/\b(20\d{2})\b/u', $query, $q);
        foreach (($q[1] ?? []) as $y) {
            $queryYears[(int) $y] = true;
        }

        $currentYear = (int) now()->format('Y');
        foreach (array_unique($years) as $year) {
            if ($year <= $currentYear - 2 && !isset($queryYears[$year])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string,mixed>|null $match
     */
    private function normalizeHeadlineToRussian(string $headline, ?array $match = null, ?string $query = null): string
    {
        $out = trim($headline);
        if ($out === '') {
            return $out;
        }

        $out = preg_replace('/\s*@\s*/u', ' — ', $out) ?? $out;
        $out = preg_replace('/\s+vs\.?\s+/iu', ' — ', $out) ?? $out;
        $out = preg_replace('/\s+v\.?\s+/iu', ' — ', $out) ?? $out;

        if (is_array($match)) {
            $home = trim((string) data_get($match, 'home_team.name', ''));
            $away = trim((string) data_get($match, 'away_team.name', ''));
            $league = trim((string) data_get($match, 'league.name', ''));

            foreach ([$home, $away, $league] as $entity) {
                if ($entity === '') {
                    continue;
                }
                $ru = $this->transliteratePhraseToRussian($entity);
                if ($ru === '') {
                    continue;
                }
                $out = preg_replace('/\b' . preg_quote($entity, '/') . '\b/iu', $ru, $out) ?? $out;
            }
        }

        if ($query !== null && trim($query) !== '') {
            $out = $this->replaceQueryAlias($out, $query);
        }

        return preg_replace('/\s+/u', ' ', trim($out)) ?? trim($out);
    }

    private function isCodeLikeHeadline(string $headline): bool
    {
        $title = trim($headline);
        if ($title === '') {
            return false;
        }

        if (preg_match('/^[A-Z]{2,4}\s*(?:@|vs\.?|v\.?|[-–—])\s*[A-Z]{2,4}$/u', $title) === 1) {
            return true;
        }

        return false;
    }

    private function replaceQueryAlias(string $headline, string $query): string
    {
        $q = trim($query);
        if ($q === '') {
            return $headline;
        }

        $latin = preg_match('/[A-Za-z]/u', $q) === 1;
        if (!$latin) {
            return $headline;
        }

        $ru = $this->transliteratePhraseToRussian($q);
        if ($ru === '') {
            return $headline;
        }

        $replaced = preg_replace('/\b' . preg_quote($q, '/') . '\b/iu', $ru, $headline);
        return is_string($replaced) ? $replaced : $headline;
    }

    private function transliteratePhraseToRussian(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $words = preg_split('/(\s+|[-–—])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($words)) {
            return $text;
        }

        $out = '';
        foreach ($words as $chunk) {
            if ($chunk === '' || preg_match('/^\s+$/u', $chunk)) {
                $out .= $chunk;
                continue;
            }
            if (preg_match('/^[-–—]$/u', $chunk)) {
                $out .= $chunk;
                continue;
            }

            $out .= $this->transliterateWordToRussian($chunk);
        }

        return $out;
    }

    private function transliterateWordToRussian(string $word): string
    {
        $lower = mb_strtolower($word);
        if (preg_match('/^[a-z][a-z\'-]*$/', $lower) !== 1) {
            return $word;
        }

        $dictionary = [
            'town' => 'таун',
            'county' => 'каунти',
            'city' => 'сити',
            'united' => 'юнайтед',
            'rovers' => 'роверс',
            'athletic' => 'атлетик',
            'sporting' => 'спортинг',
            'deportivo' => 'депортиво',
            'real' => 'реал',
            'club' => 'клуб',
            'fc' => 'ФК',
            'cf' => 'CF',
            'afc' => 'AFC',
        ];
        if (isset($dictionary[$lower])) {
            return $dictionary[$lower];
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

        $result = preg_replace('/оуй$/u', 'оуи', $result) ?? $result;
        $result = preg_replace('/тй$/u', 'ти', $result) ?? $result;
        $result = preg_replace('/ей$/u', 'и', $result) ?? $result;
        $result = preg_replace('/([бвгджзклмнпрстфхцчшщ])й$/u', '$1и', $result) ?? $result;
        $result = preg_replace('/роу$/u', 'роу', $result) ?? $result;

        if (preg_match('/^[A-Z]/', $word) === 1) {
            $first = mb_substr($result, 0, 1);
            $rest = mb_substr($result, 1);
            $result = mb_strtoupper($first) . $rest;
        }

        return $result;
    }
}

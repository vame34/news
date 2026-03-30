<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DeepSeekService;
use App\Services\SportRadarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InternalApiController extends Controller
{
    public function generateMatch(int $id, SportRadarService $service, DeepSeekService $deepSeek): JsonResponse
    {
        $match = null;
        foreach ($service->matches() as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                $match = $item;
                break;
            }
        }

        if ($match === null) {
            return response()->json(['error' => 'Match not found'], 404);
        }

        $fallback = [
            'match_id' => $id,
            'headline' => sprintf('Разбор матча %s — %s', $match['home_team']['name'], $match['away_team']['name']),
            'analysis' => 'Редакционный новостной разбор на основе структурированных фактов.',
            'faq' => [
                ['q' => 'Во сколько матч?', 'a' => date('d.m.Y H:i', strtotime((string) $match['kickoff_at']))],
                ['q' => 'Где смотреть?', 'a' => implode(', ', $match['where_to_watch'])],
            ],
            'generated_at' => now()->toAtomString(),
        ];

        $ai = $deepSeek->generateMatchArticle($match);
        if (($ai['ok'] ?? false) === true) {
            $generated = [
                'match_id' => $id,
                'headline' => (string) data_get($ai, 'data.headline', $fallback['headline']),
                'analysis' => (string) data_get($ai, 'data.analysis', $fallback['analysis']),
                'faq' => is_array(data_get($ai, 'data.faq')) ? data_get($ai, 'data.faq') : $fallback['faq'],
                'generated_at' => now()->toAtomString(),
                'source' => 'deepseek',
            ];
            return response()->json(['ok' => true, 'data' => $generated]);
        }

        $fallback['source'] = 'template';
        $fallback['fallback_reason'] = (string) ($ai['reason'] ?? 'unknown');

        return response()->json(['ok' => true, 'data' => $fallback]);
    }

    public function reindex(int $id, SportRadarService $service): JsonResponse
    {
        $jobId = $service->queueReindex($id);
        return response()->json(['ok' => true, 'message' => 'reindex queued', 'job_id' => $jobId]);
    }

    public function reindexAll(SportRadarService $service): JsonResponse
    {
        $count = $service->queueReindexAllPages();
        return response()->json(['ok' => true, 'message' => 'bulk reindex queued', 'queued_count' => $count]);
    }

    public function reindexJobStatus(int $id, SportRadarService $service): JsonResponse
    {
        $job = $service->findReindexJob($id);
        if ($job === null) {
            return response()->json(['error' => 'Job not found'], 404);
        }

        return response()->json(['ok' => true, 'data' => $job]);
    }

    public function publishContent(int $id, Request $request, SportRadarService $service): JsonResponse
    {
        $page = null;
        foreach ($service->contentPages() as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                $page = $item;
                break;
            }
        }

        if ($page === null) {
            return response()->json(['error' => 'Content page not found'], 404);
        }

        $title = (string) ($request->input('title') ?: $page['title']);
        $body = (string) ($request->input('body') ?: $page['body']);

        $service->publishContent($id, $title, $body);

        return response()->json(['ok' => true, 'message' => 'page published']);
    }

    public function rollbackContent(int $pageId, int $version, SportRadarService $service): JsonResponse
    {
        if (!$service->rollbackContent($pageId, $version)) {
            return response()->json(['error' => 'Version not found'], 404);
        }

        return response()->json(['ok' => true, 'message' => 'rollback complete']);
    }

    public function deepseekCredentials(SportRadarService $service): JsonResponse
    {
        $masked = array_map(static function (array $item): array {
            $raw = (string) ($item['secret_encrypted'] ?? '');
            $tail = $raw === '' ? 'unset' : substr($raw, -4);
            $item['secret_masked'] = '****' . $tail;
            unset($item['secret_encrypted']);
            return $item;
        }, $service->credentials());

        return response()->json(['data' => $masked]);
    }

    public function rotateDeepseekCredential(Request $request, SportRadarService $service): JsonResponse
    {
        $secret = (string) $request->input('secret', '');
        if ($secret === '') {
            return response()->json(['error' => 'secret is required'], 422);
        }

        $id = $service->rotateCredential((string) $request->input('label', 'rotated'), $secret);

        return response()->json(['ok' => true, 'id' => $id]);
    }

    public function seoList(SportRadarService $service): JsonResponse
    {
        return response()->json(['data' => $service->seoMeta()]);
    }

    public function upsertSeo(Request $request, SportRadarService $service): JsonResponse
    {
        $slug = (string) $request->input('entity_slug', '');
        if ($slug === '') {
            return response()->json(['error' => 'entity_slug is required'], 422);
        }

        $service->upsertSeo([
            'entity_type' => (string) $request->input('entity_type', 'match'),
            'entity_slug' => $slug,
            'title' => (string) $request->input('title', ''),
            'description' => (string) $request->input('description', ''),
            'h1' => (string) $request->input('h1', ''),
            'canonical' => (string) $request->input('canonical', ''),
            'robots' => (string) $request->input('robots', 'index,follow'),
        ]);

        return response()->json(['ok' => true]);
    }

}

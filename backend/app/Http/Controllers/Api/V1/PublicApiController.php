<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SportRadarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicApiController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'sport-radar-backend',
            'time' => now()->toAtomString(),
            'queue_lag' => 0,
            'deepseek_cap_state' => 'normal',
        ]);
    }

    public function matches(SportRadarService $service): JsonResponse
    {
        return response()->json(['data' => $service->publishedMatches()]);
    }

    public function matchBySlug(string $slug, SportRadarService $service): JsonResponse
    {
        $match = $service->publishedMatchBySlug($slug);

        if ($match === null) {
            return response()->json(['error' => 'Match not found'], 404);
        }

        return response()->json([
            'data' => $match,
            'page' => $service->contentPageByEntity('match', $slug),
            'seo' => $service->findSeo('match', $slug),
        ]);
    }

    public function leagueBySlug(string $slug, SportRadarService $service): JsonResponse
    {
        $league = $service->leagueBySlug($slug);
        if ($league === null) {
            return response()->json(['error' => 'League not found'], 404);
        }

        $matches = array_values(array_filter(
            $service->matches(),
            static fn (array $x): bool => ($x['league']['slug'] ?? '') === $slug
        ));

        return response()->json([
            'data' => $league,
            'matches' => $matches,
            'page' => $service->contentPageByEntity('league', $slug),
            'seo' => $service->findSeo('league', $slug),
        ]);
    }

    public function teamBySlug(string $slug, SportRadarService $service): JsonResponse
    {
        $team = $service->teamBySlug($slug);
        if ($team === null) {
            return response()->json(['error' => 'Team not found'], 404);
        }

        $matches = array_values(array_filter(
            $service->matches(),
            static fn (array $x): bool => ($x['home_team']['slug'] ?? '') === $slug || ($x['away_team']['slug'] ?? '') === $slug
        ));

        return response()->json([
            'data' => $team,
            'matches' => $matches,
            'page' => $service->contentPageByEntity('team', $slug),
            'seo' => $service->findSeo('team', $slug),
        ]);
    }

    public function news(SportRadarService $service): JsonResponse
    {
        return response()->json(['data' => $service->news()]);
    }

    public function newsBySlug(string $slug, SportRadarService $service): JsonResponse
    {
        $item = $service->newsBySlug($slug);
        if ($item === null) {
            return response()->json(['error' => 'News not found'], 404);
        }

        return response()->json(['data' => $item]);
    }

    public function seo(string $entityType, string $entitySlug, SportRadarService $service): JsonResponse
    {
        $seo = $service->findSeo($entityType, $entitySlug);
        if ($seo === null) {
            return response()->json(['error' => 'SEO not found'], 404);
        }

        return response()->json(['data' => $seo]);
    }

    public function contentPages(SportRadarService $service): JsonResponse
    {
        return response()->json(['data' => $service->contentPages()]);
    }

    public function contentPage(string $entityType, string $entitySlug, SportRadarService $service): JsonResponse
    {
        $page = $service->contentPageByEntity($entityType, $entitySlug);
        if ($page === null) {
            return response()->json(['error' => 'Content page not found'], 404);
        }

        return response()->json(['data' => $page]);
    }

    public function eventsClick(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'payload' => $request->json()->all(),
        ]);
    }
}

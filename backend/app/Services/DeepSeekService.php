<?php

namespace App\Services;

use App\Models\AiGenerationLog;
use Illuminate\Support\Facades\Http;
use Throwable;

class DeepSeekService
{
    public function __construct(private readonly SportRadarService $store)
    {
    }

    public function generateMatchArticle(array $match): array
    {
        $estimatedTokens = 900;
        if (!$this->canSpend($estimatedTokens)) {
            $this->logUsage('blocked', 0, 0, 0, 'budget cap reached');
            return ['ok' => false, 'reason' => 'budget_cap'];
        }

        $apiKey = $this->resolveApiKey();
        if ($apiKey === null || $apiKey === '') {
            $this->logUsage('blocked', 0, 0, 0, 'missing deepseek api key');
            return ['ok' => false, 'reason' => 'missing_key'];
        }

        $systemPrompt = 'Ты спортивный редактор. Нельзя писать воду и шаблонные фразы. Нужны проверяемые факты из входных данных, конкретные цифры, имена, даты, турнирный контекст. Пиши только на русском языке. Запрещены служебные коды матчей вида "ABC @ XYZ". Запрещены ставки, коэффициенты, букмекеры. Заголовок и текст должны быть естественными по-русски: без "vs", без смешения кириллицы и латиницы, без кривой транслитерации и без пустых анонсов расписания. Не делай новость из голого факта, что одна команда принимает другую или готовится к матчу. Запрещены заголовочные шаблоны вроде "ключевая встреча", "ключевая битва", "решающий матч", "в календаре лиги", "за позиции в турнире", "может определить расстановку сил", "проверка амбиций" и другие универсальные формулы, которые подходят почти к любому матчу. Заголовок должен отвечать на вопрос "что нового и почему это важно именно в этой паре сейчас". Если во входных данных нет сильного новостного повода, лучше выдели конкретный сюжет: турнирный риск, серию, кадровую проблему, форму лидера, недавний результат, статистический перекос. Используй русские формы имен и названий команд; если не уверен в транслитерации, выбирай простую общеупотребительную форму и не выдумывай редкие русификации. Текст должен быть собран как нормальная новость, а не как SEO-заглушка: в первых двух разделах сразу дай конкретику, а не общий пафос. В body_markdown обязательно ровно 4 H2-раздела с содержательными абзацами под каждым заголовком. Последний раздел обязан быть редакционным прогнозом или вероятным сценарием матча/боя без коэффициентов, без точного счёта и без обещаний будущих обновлений. Ответ строго JSON: {"headline":"...","analysis":"...","body_markdown":"...","seo_title":"...","seo_description":"...","discipline":"football|basketball|tennis|hockey|mma-boxing","faq":[{"q":"...","a":"..."}]}.';
        $userPrompt = json_encode([
            'task' => 'Сгенерируй полноценную спортивную новость по матчу',
            'requirements' => [
                'body_format' => 'markdown',
                'body_min_length_chars' => 2000,
                'sections' => 'ровно 4 H2-раздела с разными, конкретными подзаголовками под этот матч или бой; не использовать один и тот же набор H2 для всех материалов',
                'seo' => true,
                'must_include' => [
                    'минимум 4 конкретных факта/числа',
                    'без клише: "матч обещает быть интересным", "все может решить мотивация", "команды покажут борьбу"',
                    'обязательное редакционное суждение: почему событие важно именно сейчас',
                    'заголовок только на русском языке, без латиницы в названиях команд и без конструкции "vs"',
                    'не использовать шаблонные заголовки про "ключевую встречу", "битву за позиции", "календарь лиги", "проверку амбиций", "расстановку сил" или голый анонс пары без реального повода',
                    'в заголовке должен быть один конкретный угол новости: серия, турнирное давление, форма лидера, травма, кадровая потеря, недавний счет, редкая статистика или важное последствие результата',
                    'не использовать кривую транслитерацию; если русская форма сомнительна, упрощай, а не выдумывай',
                    'текст должен содержать минимум 2 числовых факта уже в первых двух разделах',
                    'каждый из 4 H2-разделов обязан содержать минимум один полноценный абзац на 2-4 предложения, а не одну общую фразу',
                    'подзаголовки H2 должны быть конкретными и разными по теме, нельзя механически повторять одни и те же названия вроде "Контекст и форма", "Ключевые факторы", "Что дальше" в каждом материале',
                    'в первых двух разделах обязательно назвать обе команды и турнир естественно по-русски',
                    'минимум один абзац должен объяснять не только форму, но и конкретное последствие результата: место в таблице, серия, давление на тренера, кадровый риск или турнирный шанс',
                    'последний раздел должен содержать обычный понятный прогноз: кто ближе к победе, возможная ничья, примерный счёт или победа по очкам/решением судей в зависимости от спорта',
                    'не писать абстрактный сценарий без вывода; в последнем разделе нужен прямой прогноз человеческим языком',
                    'без ставок, коэффициентов и букмекерских формулировок',
                ],
                'bad_headline_examples' => [
                    'Барров принимает Бромлей в матче, который может определить расстановку сил в нижней части таблицы',
                    'Спортиво Амелиано принимает Либертад в матче, который может определить расстановку сил в лиге',
                    'Харрогате Товн против Нотц Коунтй: проверка амбиций в преддверии финиша сезона',
                ],
                'good_headline_shape' => [
                    'один конкретный сюжет вместо общего пафоса',
                    'понятный турнирный или кадровый контекст',
                    'без универсальных фраз, которые можно переставить на другой матч',
                    'естественные русские названия команд без искусственной транслитерации',
                ],
            ],
            'match' => $match,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';

        try {
            $chat = $this->requestJsonChat($apiKey, $systemPrompt, $userPrompt, 0.3, 90);
            if (!($chat['ok'] ?? false)) {
                $this->logUsage('error', 0, 0, 0, (string) ($chat['error'] ?? 'transport_error'));
                return ['ok' => false, 'reason' => 'transport_error'];
            }

            $content = (string) ($chat['content'] ?? '');
            $usage = (array) ($chat['usage'] ?? []);
            $parsed = $this->parseJsonContent($content);
            if ($parsed === null) {
                $this->logUsage('error', (int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0), (int) ($usage['total_tokens'] ?? 0), 'invalid json content');
                return ['ok' => false, 'reason' => 'invalid_payload'];
            }

            $this->logUsage(
                'success',
                (int) ($usage['prompt_tokens'] ?? 0),
                (int) ($usage['completion_tokens'] ?? 0),
                (int) ($usage['total_tokens'] ?? 0)
            );

            return [
                'ok' => true,
                'source' => 'deepseek',
                'data' => [
                    'headline' => (string) ($parsed['headline'] ?? ''),
                    'analysis' => (string) ($parsed['analysis'] ?? ''),
                    'body_markdown' => (string) ($parsed['body_markdown'] ?? ''),
                    'seo_title' => (string) ($parsed['seo_title'] ?? ''),
                    'seo_description' => (string) ($parsed['seo_description'] ?? ''),
                    'discipline' => (string) ($parsed['discipline'] ?? ''),
                    'faq' => is_array($parsed['faq'] ?? null) ? $parsed['faq'] : [],
                ],
            ];
        } catch (Throwable $e) {
            $this->logUsage('error', 0, 0, 0, mb_strcut($e->getMessage(), 0, 500));
            return ['ok' => false, 'reason' => 'transport_error'];
        }
    }

    public function hasBudgetCapacity(int $estimatedTokens = 1): bool
    {
        return $this->canSpend(max(1, $estimatedTokens));
    }

    /**
     * @param array<int, array{title?:string,url?:string,description?:string,published_at?:?string,source?:string,discipline?:string,score?:int}> $sourceContext
     * @param array<int, array<string,mixed>> $upcomingEvents
     */
    public function generateTrendArticle(
        string $query,
        ?string $discipline = null,
        array $sourceContext = [],
        array $upcomingEvents = []
    ): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['ok' => false, 'reason' => 'empty_query'];
        }

        $estimatedTokens = 1500;
        if (!$this->canSpend($estimatedTokens)) {
            $this->logUsage('blocked', 0, 0, 0, 'budget cap reached');
            return ['ok' => false, 'reason' => 'budget_cap'];
        }

        $apiKey = $this->resolveApiKey();
        if ($apiKey === null || $apiKey === '') {
            $this->logUsage('blocked', 0, 0, 0, 'missing deepseek api key');
            return ['ok' => false, 'reason' => 'missing_key'];
        }

        $promptPayload = [
            'task' => 'Сформируй полноценную SEO-новость на русском языке по спортивному инфоповоду.',
            'input' => [
                'query' => $query,
                'discipline_hint' => $discipline,
                'source_context' => array_values(array_map(
                    static fn (array $x): array => [
                        'source' => (string) ($x['source'] ?? ''),
                        'title' => (string) ($x['title'] ?? ''),
                        'summary' => (string) ($x['description'] ?? ''),
                        'published_at' => (string) ($x['published_at'] ?? ''),
                        'url' => (string) ($x['url'] ?? ''),
                    ],
                    array_slice($sourceContext, 0, 8)
                )),
                'upcoming_events' => array_values(array_slice($upcomingEvents, 0, 8)),
            ],
            'requirements' => [
                'text_style' => 'новостной, конкретный, без воды и клише, без ставок/коэффициентов/букмекеров',
                'body_format' => 'markdown с подзаголовками H2, списками и абзацами',
                'body_min_length_chars' => 2200,
                'must_be_real_news' => true,
                'must_include_sections' => 'ровно 4 H2-раздела, но их названия должны подбираться под конкретный сюжет и не повторяться шаблонно от новости к новости',
                'must_include_source_based_facts' => 'минимум 4 факта должны опираться на source_context; источник и дату упоминай только там, где это действительно нужно, без повтора одной и той же даты или формулы "от <дата>" в каждом абзаце',
                'allowed_disciplines' => ['football', 'basketball', 'tennis', 'hockey', 'mma-boxing'],
                'hard_restrictions' => [
                    'минимум 5 проверяемых фактов (даты, счет, турнир, участники, статистика)',
                    'запрещены общие фразы без новых фактов',
                    'обязательно объяснить, почему это важно для аудитории сейчас',
                ],
            ],
            'output_json_schema' => [
                'headline' => 'string',
                'excerpt' => 'string <= 220 chars',
                'body_markdown' => 'string',
                'seo_title' => 'string <= 70 chars',
                'seo_description' => 'string <= 160 chars',
                'discipline' => 'one of football|basketball|tennis|hockey|mma-boxing',
            ],
        ];

        try {
            $systemPrompt = 'Ты спортивный редактор. Пиши содержательные и структурированные материалы без ставок и без выдуманных фактов. Пиши только на русском языке и не используй служебные коды матчей (например, "ABC @ XYZ"). Заголовок должен быть полностью русскоязычным: без латиницы в названиях, без "vs", без кривой транслитерации и без канцелярских шаблонов. Нельзя строить новость как пустой анонс пары или расписания. Запрещены конструкции вроде "команда готовится к матчу <дата>", "команда принимает соперника", "ключевая встреча", "ключевая битва", "решающий матч", "в календаре лиги", "за позиции в турнире", "проверка амбиций", "может определить расстановку сил", если за ними не стоит конкретный новый факт. Заголовок должен выделять главный новый факт или конкретный конфликт темы: рекорд, серия, заявление, травма, спорное решение, турнирное последствие, кадровый риск, сильный статистический перекос. Обязательно опирайся на source_context: если данных недостаточно, честно ограничь выводы и не придумывай детали. Текст обязан быть фактологичным и проверяемым, без клише и пустых абзацев. Первые два раздела должны сразу давать новые факты, цифры и последствия, а не разогрев в общих словах. Не повторяй одну и ту же дату, источник или конструкцию вида "по данным ... от <дата>" в каждом абзаце: атрибуцию вставляй редко и естественно. В body_markdown обязательно ровно 4 H2-раздела, и в каждом должен быть содержательный абзац. Названия H2 должны быть разными и привязанными к конкретному сюжету; нельзя повторять один и тот же набор подзаголовков в каждой новости. Ответ только JSON.';
            $userPrompt = json_encode($promptPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $query;
            $chat = $this->requestJsonChat($apiKey, $systemPrompt, $userPrompt, 0.35, 120);
            if (!($chat['ok'] ?? false)) {
                $this->logUsage('error', 0, 0, 0, (string) ($chat['error'] ?? 'transport_error'));
                return ['ok' => false, 'reason' => 'transport_error'];
            }

            $content = (string) ($chat['content'] ?? '');
            $usage = (array) ($chat['usage'] ?? []);
            $parsed = $this->parseJsonContent($content);
            if ($parsed === null) {
                $this->logUsage('error', (int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0), (int) ($usage['total_tokens'] ?? 0), 'invalid json content');
                return ['ok' => false, 'reason' => 'invalid_payload'];
            }

            $resolvedDiscipline = $this->normalizeDiscipline((string) ($parsed['discipline'] ?? ''));
            if ($resolvedDiscipline === null) {
                $resolvedDiscipline = $this->normalizeDiscipline((string) $discipline);
            }
            if ($resolvedDiscipline === null) {
                $resolvedDiscipline = 'football';
            }

            $headline = trim((string) ($parsed['headline'] ?? $parsed['title'] ?? $parsed['h1'] ?? ''));
            $excerpt = trim((string) ($parsed['excerpt'] ?? $parsed['summary'] ?? $parsed['lead'] ?? ''));
            $body = trim((string) ($parsed['body_markdown'] ?? $parsed['body'] ?? $parsed['content'] ?? $parsed['article'] ?? $parsed['text'] ?? ''));
            $seoTitle = trim((string) ($parsed['seo_title'] ?? $parsed['meta_title'] ?? ''));
            $seoDescription = trim((string) ($parsed['seo_description'] ?? $parsed['meta_description'] ?? ''));
            if ($headline === '' && $query !== '') {
                $headline = $query . ': главное сейчас';
            }
            if ($body === '' && $excerpt !== '') {
                $body = "## Что произошло\n{$excerpt}";
            }
            if ($headline === '' || $body === '') {
                $this->logUsage('error', (int) ($usage['prompt_tokens'] ?? 0), (int) ($usage['completion_tokens'] ?? 0), (int) ($usage['total_tokens'] ?? 0), 'empty headline/body');
                return ['ok' => false, 'reason' => 'invalid_payload'];
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
            $this->logUsage(
                'success',
                (int) ($usage['prompt_tokens'] ?? 0),
                (int) ($usage['completion_tokens'] ?? 0),
                (int) ($usage['total_tokens'] ?? 0)
            );

            return [
                'ok' => true,
                'source' => 'deepseek',
                'data' => [
                    'headline' => $headline,
                    'excerpt' => $excerpt,
                    'body_markdown' => $body,
                    'seo_title' => $seoTitle,
                    'seo_description' => $seoDescription,
                    'discipline' => $resolvedDiscipline,
                ],
            ];
        } catch (Throwable $e) {
            $this->logUsage('error', 0, 0, 0, mb_strcut($e->getMessage(), 0, 500));
            return ['ok' => false, 'reason' => 'transport_error'];
        }
    }

    private function decodeResponse(array|string $response): array
    {
        if (is_array($response)) {
            $content = (string) data_get($response, 'choices.0.message.content', '');
            if ($content !== '') {
                return [
                    'content' => $content,
                    'usage' => (array) data_get($response, 'usage', []),
                ];
            }

            return [
                'content' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
                'usage' => [],
            ];
        }

        $raw = trim($response);
        $json = json_decode($raw, true);
        if (is_array($json)) {
            $content = (string) data_get($json, 'choices.0.message.content', '');
            if ($content !== '') {
                return [
                    'content' => $content,
                    'usage' => (array) data_get($json, 'usage', []),
                ];
            }
        }

        return [
            'content' => $raw,
            'usage' => [],
        ];
    }

    /**
     * @return array{ok:bool,content?:string,usage?:array<string,mixed>,error?:string}
     */
    private function requestJsonChat(string $apiKey, string $systemPrompt, string $userPrompt, float $temperature, int $timeout): array
    {
        $model = (string) env('DEEPSEEK_MODEL', 'deepseek-chat');
        $baseUrl = rtrim((string) env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'), '/');

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(10)
                ->retry(2, 1000)
                ->withOptions([
                    'curl' => [
                        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                    ],
                ])
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post($baseUrl . '/chat/completions', [
                    'model' => $model,
                    'temperature' => $temperature,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]);

            if (!$response->ok()) {
                return ['ok' => false, 'error' => 'http_' . $response->status() . ':' . mb_strcut($response->body(), 0, 300)];
            }

            $json = $response->json();
            if (!is_array($json)) {
                return ['ok' => false, 'error' => 'non_json_response'];
            }

            return [
                'ok' => true,
                'content' => (string) data_get($json, 'choices.0.message.content', ''),
                'usage' => (array) data_get($json, 'usage', []),
            ];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => mb_strcut($e->getMessage(), 0, 300)];
        }
    }

    private function resolveApiKey(): ?string
    {
        $credential = $this->store->activeCredential('deepseek');
        if ($credential !== null) {
            return (string) $credential['secret'];
        }

        $envKey = (string) env('DEEPSEEK_API_KEY', '');
        return $envKey === '' ? null : $envKey;
    }

    private function canSpend(int $estimatedTokens): bool
    {
        $dailyRequestLimit = (int) env('DEEPSEEK_DAILY_REQUEST_LIMIT', 1500);
        $dailyTokenLimit = (int) env('DEEPSEEK_DAILY_TOKEN_LIMIT', 2000000);
        $dailyUsdLimit = (float) env('DEEPSEEK_DAILY_USD_LIMIT', 0);
        $effectiveUsdPer1kTokens = (float) env('DEEPSEEK_EFFECTIVE_USD_PER_1K_TOKENS', 0);

        $today = now()->startOfDay();

        $stats = AiGenerationLog::query()
            ->where('provider', 'deepseek')
            ->where('status', 'success')
            ->where('created_at', '>=', $today)
            ->selectRaw('COUNT(*) as requests, COALESCE(SUM(total_tokens), 0) as tokens')
            ->first();

        $requests = (int) ($stats->requests ?? 0);
        $tokens = (int) ($stats->tokens ?? 0);

        if ($dailyRequestLimit > 0 && $requests >= $dailyRequestLimit) {
            return false;
        }

        if ($dailyTokenLimit <= 0) {
            if ($dailyUsdLimit > 0 && $effectiveUsdPer1kTokens > 0) {
                $spentUsd = ($tokens / 1000) * $effectiveUsdPer1kTokens;
                $estimatedUsd = ($estimatedTokens / 1000) * $effectiveUsdPer1kTokens;
                return ($spentUsd + $estimatedUsd) <= $dailyUsdLimit;
            }

            return true;
        }

        if (($tokens + $estimatedTokens) > $dailyTokenLimit) {
            return false;
        }

        if ($dailyUsdLimit > 0 && $effectiveUsdPer1kTokens > 0) {
            $spentUsd = ($tokens / 1000) * $effectiveUsdPer1kTokens;
            $estimatedUsd = ($estimatedTokens / 1000) * $effectiveUsdPer1kTokens;
            return ($spentUsd + $estimatedUsd) <= $dailyUsdLimit;
        }

        return true;
    }

    private function parseJsonContent(string $content): ?array
    {
        $raw = trim($content);
        if ($raw === '') {
            return null;
        }

        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```json\s*/', '', $raw) ?? $raw;
            $raw = preg_replace('/^```\s*/', '', $raw) ?? $raw;
            $raw = preg_replace('/```$/', '', $raw) ?? $raw;
            $raw = trim($raw);
        }

        $data = json_decode($raw, true);
        if (is_array($data)) {
            return $data;
        }

        if (preg_match('/\{(?:[^{}]|(?R))*\}/s', $raw, $m) === 1) {
            $data = json_decode((string) $m[0], true);
            if (is_array($data)) {
                return $data;
            }
        }

        return null;
    }

    private function logUsage(string $status, int $promptTokens, int $completionTokens, int $totalTokens, ?string $error = null): void
    {
        AiGenerationLog::query()->create([
            'provider' => 'deepseek',
            'model' => (string) env('DEEPSEEK_MODEL', 'deepseek-chat'),
            'status' => $status,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $totalTokens,
            'error_message' => $error,
            'created_at' => now(),
        ]);
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
}

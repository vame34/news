<?php

namespace App\Services;

use App\Models\League;
use App\Models\SportMatch;
use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class EventIngestionService
{
    /**
     * Pulls nearest events from ESPN scoreboards and upserts them into matches table.
     */
    public function syncUpcomingEvents(int $daysAhead = 5, int $perSportLimit = 80): int
    {
        $daysAhead = max(1, min(14, $daysAhead));
        $perSportLimit = max(5, min(300, $perSportLimit));

        $sports = [
            ['discipline' => 'football', 'path' => 'soccer/all/scoreboard'],
            ['discipline' => 'basketball', 'path' => 'basketball/nba/scoreboard'],
            ['discipline' => 'tennis', 'path' => 'tennis/atp/scoreboard'],
            ['discipline' => 'mma-boxing', 'path' => 'mma/ufc/scoreboard'],
        ];

        $written = 0;
        foreach ($sports as $sport) {
            $written += $this->syncSport(
                (string) $sport['discipline'],
                (string) $sport['path'],
                $daysAhead,
                $perSportLimit
            );
        }

        return $written;
    }

    private function syncSport(string $discipline, string $path, int $daysAhead, int $limit): int
    {
        $base = 'https://site.api.espn.com/apis/site/v2/sports/';
        $eventsById = [];

        for ($offset = 0; $offset <= $daysAhead; $offset++) {
            $date = now()->copy()->addDays($offset);
            $dateParam = $date->format('Ymd');

            try {
                $response = Http::timeout(15)->acceptJson()->get($base . $path, [
                    'dates' => $dateParam,
                    'limit' => 300,
                ]);
            } catch (Throwable) {
                continue;
            }

            if (!$response->ok()) {
                continue;
            }

            $json = $response->json();
            if (!is_array($json)) {
                continue;
            }

            $events = $json['events'] ?? [];
            if (!is_array($events)) {
                continue;
            }

            foreach ($events as $event) {
                if (!is_array($event)) {
                    continue;
                }

                $id = trim((string) ($event['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $eventsById[$id] = $event;
            }
        }

        $written = 0;
        foreach (array_slice(array_values($eventsById), 0, $limit) as $event) {
            $competition = $event['competitions'][0] ?? null;
            if (!is_array($competition)) {
                continue;
            }

            $teams = [];
            foreach (($competition['competitors'] ?? []) as $competitor) {
                if (!is_array($competitor)) {
                    continue;
                }
                $name = trim((string) data_get($competitor, 'team.displayName', ''));
                if ($name !== '') {
                    $teams[] = $this->normalizeTeamNameRu($name);
                }
            }

            if (count($teams) < 2) {
                continue;
            }

            $kickoffRaw = (string) ($competition['date'] ?? $event['date'] ?? '');
            if ($kickoffRaw === '') {
                continue;
            }
            $kickoff = now()->parse($kickoffRaw);

            $leagueName = trim((string) data_get($competition, 'league.name', ''));
            if ($leagueName === '') {
                $leagueName = trim((string) data_get($event, 'shortName', $discipline));
            }

            $leagueId = $this->upsertLeague($discipline, $leagueName);
            $homeTeamId = $this->upsertTeam($leagueId, $teams[0]);
            $awayTeamId = $this->upsertTeam($leagueId, $teams[1]);

            $slug = $this->buildMatchSlug($discipline, $teams[0], $teams[1], $kickoff);
            $status = mb_strtolower(trim((string) data_get($competition, 'status.type.description', 'scheduled')));
            $watch = $this->extractBroadcasts($competition);

            $analysis = $discipline === 'mma-boxing'
                ? 'Редакционная карточка ближайшего боя. Здесь публикуются только подтверждённые данные по событию без выдуманного прогноза.'
                : 'Редакционная карточка ближайшего матча. Здесь публикуются только подтверждённые данные по событию без выдуманного прогноза.';

            SportMatch::query()->updateOrCreate(
                ['slug' => $slug],
                [
                    'league_id' => $leagueId,
                    'home_team_id' => $homeTeamId,
                    'away_team_id' => $awayTeamId,
                    'kickoff_at' => $kickoff,
                    'status' => $status === '' ? 'scheduled' : $status,
                    'analysis' => $analysis,
                    'where_to_watch' => json_encode($watch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]
            );
            $written++;
        }

        return $written;
    }

    private function upsertLeague(string $discipline, string $name): int
    {
        $base = Str::slug($name);
        if ($base === '') {
            $base = Str::slug($discipline) ?: 'league';
        }
        $slug = Str::limit($base . '-' . Str::slug($discipline), 120, '');

        League::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'sport' => $discipline,
            ]
        );

        return (int) League::query()->where('slug', $slug)->value('id');
    }

    private function upsertTeam(int $leagueId, string $name): int
    {
        $canonicalIncoming = $this->canonicalEntityKey($name);
        $existing = Team::query()
            ->where('league_id', $leagueId)
            ->get(['id', 'name', 'slug'])
            ->first(function ($row) use ($name, $canonicalIncoming) {
                $existingName = trim((string) ($row->name ?? ''));
                if ($existingName !== '' && mb_strtolower($existingName) === mb_strtolower($name)) {
                    return true;
                }

                return $canonicalIncoming !== '' && $this->canonicalEntityKey($existingName) === $canonicalIncoming;
            });

        if ($existing) {
            $currentName = trim((string) ($existing->name ?? ''));
            if ($this->shouldPreferIncomingName($name, $currentName)) {
                Team::query()->whereKey((int) $existing->id)->update([
                    'name' => $name,
                ]);
            }

            return (int) $existing->id;
        }

        $leagueSlug = (string) League::query()->whereKey($leagueId)->value('slug');
        $slugBase = Str::slug($name);
        if ($slugBase === '') {
            $slugBase = 'team';
        }
        $slug = Str::limit($leagueSlug . '-' . $slugBase, 160, '');

        Team::query()->updateOrCreate(
            ['slug' => $slug],
            [
                'name' => $name,
                'league_id' => $leagueId,
            ]
        );

        return (int) Team::query()->where('slug', $slug)->value('id');
    }

    private function buildMatchSlug(string $discipline, string $home, string $away, \Illuminate\Support\Carbon $kickoff): string
    {
        $left = Str::slug($home) ?: 'home';
        $right = Str::slug($away) ?: 'away';
        $date = $kickoff->toDateString();
        return Str::limit("espn-{$discipline}-{$left}-{$right}-{$date}", 190, '');
    }

    /**
     * @return array<int, array{name:string,url:string}>
     */
    private function extractBroadcasts(array $competition): array
    {
        $out = [];
        foreach (($competition['broadcasts'] ?? []) as $broadcast) {
            if (!is_array($broadcast)) {
                continue;
            }
            $name = trim((string) ($broadcast['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ($this->isCodeLikeBroadcast($name)) {
                continue;
            }
            $out[] = ['name' => $name, 'url' => '#'];
        }

        return array_slice($out, 0, 3);
    }

    private function isCodeLikeBroadcast(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return false;
        }

        if (preg_match('/^[A-Z]{2,4}\s*(?:@|vs\.?|v\.?|[-–—])\s*[A-Z]{2,4}$/u', $value) === 1) {
            return true;
        }

        return false;
    }

    private function normalizeTeamNameRu(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return $name;
        }

        $dict = [
            'China' => 'Китай',
            'Curacao' => 'Кюрасао',
            'New Zealand' => 'Новая Зеландия',
            'Finland' => 'Финляндия',
            'Russia' => 'Россия',
            'Nicaragua' => 'Никарагуа',
            'Iran' => 'Иран',
            'IR Iran' => 'Иран',
            'Nigeria' => 'Нигерия',
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
            'Montenegro' => 'Черногория',
            'South Africa' => 'ЮАР',
            'Panama' => 'Панама',
            'Norway' => 'Норвегия',
            'Armenia' => 'Армения',
            'Lithuania' => 'Литва',
            'Andorra' => 'Андорра',
            'Solomon Islands' => 'Соломоновы Острова',
            'Bulgaria' => 'Болгария',
            'Brisbane Roar' => 'Брисбен Роар',
            'Perth Glory' => 'Перт Глори',
        ];

        if (isset($dict[$name])) {
            return $dict[$name];
        }

        if (preg_match('/[A-Za-z]/u', $name) !== 1) {
            return $name;
        }

        return $name;
    }

    private function transliteratePhraseToRussian(string $text): string
    {
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
            $out .= $this->transliterateWordToRussian($part);
        }

        return $out;
    }

    private function transliterateWordToRussian(string $word): string
    {
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
    }

    private function canonicalEntityKey(string $text): string
    {
        $ascii = Str::ascii(mb_strtolower(trim($text)));
        $ascii = preg_replace('/[^a-z0-9]+/', ' ', $ascii) ?? '';
        $ascii = preg_replace('/\b(fc|fk|cf|club|sc|afc|wfc|women|u21|u20|u19)\b/', ' ', $ascii) ?? $ascii;
        $ascii = preg_replace('/\s+/', ' ', trim($ascii)) ?? trim($ascii);

        return $ascii;
    }

    private function shouldPreferIncomingName(string $incoming, string $current): bool
    {
        if ($current === '') {
            return true;
        }

        $incomingHasLatin = preg_match('/[A-Za-z]/u', $incoming) === 1;
        $currentHasLatin = preg_match('/[A-Za-z]/u', $current) === 1;
        if ($incomingHasLatin !== $currentHasLatin) {
            return $incomingHasLatin;
        }

        return mb_strlen($incoming) < mb_strlen($current);
    }
}

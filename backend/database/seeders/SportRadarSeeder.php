<?php

namespace Database\Seeders;

use App\Services\SportRadarService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SportRadarSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('reindex_jobs')->truncate();
        DB::table('admin_audit_logs')->truncate();
        DB::table('content_versions')->truncate();
        DB::table('content_pages')->truncate();
        DB::table('provider_credentials')->truncate();
        DB::table('news')->truncate();
        DB::table('matches')->truncate();
        DB::table('teams')->truncate();
        DB::table('leagues')->truncate();
        DB::table('seo_meta')->truncate();
        DB::table('admin_auth_state')->truncate();
        DB::table('ai_generation_logs')->truncate();
        DB::table('content_generation_queue')->truncate();
        DB::table('trend_queries')->truncate();

        DB::table('leagues')->insert([
            ['id' => 1, 'slug' => 'rpl', 'name' => 'РПЛ', 'sport' => 'football'],
            ['id' => 2, 'slug' => 'epl', 'name' => 'АПЛ', 'sport' => 'football'],
        ]);

        DB::table('teams')->insert([
            ['id' => 1, 'slug' => 'spartak', 'name' => 'Спартак', 'league_id' => 1],
            ['id' => 2, 'slug' => 'dinamo', 'name' => 'Динамо', 'league_id' => 1],
            ['id' => 3, 'slug' => 'zenit', 'name' => 'Зенит', 'league_id' => 1],
            ['id' => 4, 'slug' => 'lokomotiv', 'name' => 'Локомотив', 'league_id' => 1],
            ['id' => 5, 'slug' => 'arsenal', 'name' => 'Арсенал', 'league_id' => 2],
            ['id' => 6, 'slug' => 'chelsea', 'name' => 'Челси', 'league_id' => 2],
        ]);

        DB::table('matches')->insert([
            [
                'id' => 1001,
                'slug' => 'spartak-dinamo-2026-03-21',
                'league_id' => 1,
                'home_team_id' => 1,
                'away_team_id' => 2,
                'kickoff_at' => '2026-03-21 16:00:00',
                'status' => 'scheduled',
                'analysis' => 'Матч с высоким темпом и акцентом на фланговые атаки.',
                'where_to_watch' => json_encode(['Матч ТВ', 'VK Видео'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 1002,
                'slug' => 'zenit-lokomotiv-2026-03-22',
                'league_id' => 1,
                'home_team_id' => 3,
                'away_team_id' => 4,
                'kickoff_at' => '2026-03-22 18:30:00',
                'status' => 'scheduled',
                'analysis' => 'Ключевым фактором станет контроль центра поля.',
                'where_to_watch' => json_encode(['Матч Премьер'], JSON_UNESCAPED_UNICODE),
            ],
            [
                'id' => 1003,
                'slug' => 'arsenal-chelsea-2026-03-23',
                'league_id' => 2,
                'home_team_id' => 5,
                'away_team_id' => 6,
                'kickoff_at' => '2026-03-23 20:00:00',
                'status' => 'scheduled',
                'analysis' => 'Ожидается интенсивный прессинг и быстрые переходы.',
                'where_to_watch' => json_encode(['Okko Спорт'], JSON_UNESCAPED_UNICODE),
            ],
        ]);

        DB::table('news')->insert([
            [
                'id' => 1,
                'slug' => 'spartak-dinamo-preview',
                'title' => 'Спартак — Динамо: превью центрального матча тура',
                'excerpt' => 'Разбираем форму команд, кадровую ситуацию и ключевые дуэли.',
                'body' => "Спартак — Динамо: превью центрального матча тура\n\nРазбираем форму команд, кадровую ситуацию и ключевые дуэли.\n\nМатериал подготовлен в редакционном формате на базе структурированных спортивных данных.",
                'published_at' => '2026-03-19 10:00:00',
                'league_slug' => 'rpl',
                'team_slug' => 'spartak',
            ],
            [
                'id' => 2,
                'slug' => 'zenit-lokomotiv-tactical-focus',
                'title' => 'Зенит — Локомотив: тактический фокус перед игрой',
                'excerpt' => 'На что повлияют схемы и где возникнут свободные зоны.',
                'body' => "Зенит — Локомотив: тактический фокус перед игрой\n\nНа что повлияют схемы и где возникнут свободные зоны.\n\nМатериал подготовлен в редакционном формате на базе структурированных спортивных данных.",
                'published_at' => '2026-03-19 11:00:00',
                'league_slug' => 'rpl',
                'team_slug' => 'zenit',
            ],
        ]);

        DB::table('provider_credentials')->insert([
            [
                'id' => 1,
                'provider' => 'deepseek',
                'label' => 'initial',
                'secret_encrypted' => base64_encode('demo-deepseek-key'),
                'is_active' => true,
                'last_validated_at' => now(),
                'last_validation_status' => 'ok',
                'created_by' => 1,
                'updated_by' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('content_pages')->insert([
            [
                'id' => 1,
                'entity_type' => 'match',
                'entity_slug' => 'spartak-dinamo-2026-03-21',
                'status' => 'published',
                'version' => 1,
                'title' => 'Спартак — Динамо',
                'body' => 'Базовый контент страницы матча.',
                'updated_at' => now(),
            ],
        ]);

        DB::table('content_versions')->insert([
            [
                'page_id' => 1,
                'version' => 1,
                'title' => 'Спартак — Динамо',
                'body' => 'Базовый контент страницы матча.',
                'created_at' => now(),
            ],
        ]);

        DB::table('seo_meta')->insert([
            [
                'entity_type' => 'match',
                'entity_slug' => 'spartak-dinamo-2026-03-21',
                'title' => 'Спартак — Динамо: анализ и прогноз матча',
                'description' => 'Подробный разбор матча Спартак — Динамо.',
                'h1' => 'Спартак — Динамо',
                'canonical' => 'https://radararena.ru/match/spartak-dinamo-2026-03-21',
                'robots' => 'index,follow',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('admin_auth_state')->insert([
            'id' => 1,
            'failed_attempts' => 0,
            'lock_until' => 0,
        ]);

        $this->syncSequences();
        app(SportRadarService::class)->syncReferencePages(false);
        $this->syncSequences();
    }

    private function syncSequences(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['leagues', 'teams', 'matches', 'news', 'provider_credentials', 'content_pages', 'content_versions', 'seo_meta', 'admin_audit_logs', 'reindex_jobs', 'ai_generation_logs', 'trend_queries', 'content_generation_queue'] as $table) {
            DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1), true)");
        }
    }
}

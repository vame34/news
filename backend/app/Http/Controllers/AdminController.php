<?php

namespace App\Http\Controllers;

use App\Services\SportRadarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function loginForm(Request $request): View
    {
        $state = app(SportRadarService::class)->authState();
        return view('admin.login', [
            'lockedUntil' => (int) $state['lock_until'],
            'error' => $request->session()->get('admin_error'),
        ]);
    }

    public function login(Request $request, SportRadarService $store): RedirectResponse
    {
        $state = $store->authState();
        $now = time();
        if ((int) $state['lock_until'] > $now) {
            $request->session()->flash('admin_error', 'Слишком много попыток. Попробуйте позже.');
            return redirect('/admin/login');
        }

        $password = (string) $request->input('password', '');
        $otp = (string) $request->input('otp', '');
        $expectedPassword = (string) env('ADMIN_PASSWORD', 'admin-dev-pass');
        $expectedOtp = (string) env('ADMIN_OTP_CODE', '123456');

        if ($password !== $expectedPassword || $otp !== $expectedOtp) {
            $failed = ((int) $state['failed_attempts']) + 1;
            $lockUntil = $failed >= 5 ? $now + 900 : 0;
            $store->saveAuthState([
                'failed_attempts' => $failed >= 5 ? 0 : $failed,
                'lock_until' => $lockUntil,
            ]);
            $request->session()->flash('admin_error', 'Неверные данные входа');
            return redirect('/admin/login');
        }

        $store->saveAuthState(['failed_attempts' => 0, 'lock_until' => 0]);
        $request->session()->put('admin_auth', true);
        $store->addAudit('admin.login', ['ok' => true], 'admin', (string) $request->ip());

        return redirect('/admin');
    }

    public function logout(Request $request, SportRadarService $store): RedirectResponse
    {
        $request->session()->forget('admin_auth');
        $store->addAudit('admin.logout', ['ok' => true], 'admin', (string) $request->ip());
        return redirect('/admin/login');
    }

    public function dashboard(SportRadarService $store): View
    {
        return view('admin.dashboard', [
            'matchesCount' => count($store->matches()),
            'newsCount' => count($store->news()),
            'jobs' => $store->recentReindexJobs(),
        ]);
    }

    public function credentials(SportRadarService $store): View
    {
        $creds = $store->credentials();
        $masked = array_map(static function (array $item): array {
            $raw = (string) ($item['secret_encrypted'] ?? '');
            $tail = $raw === '' ? 'unset' : substr($raw, -4);
            $item['secret_masked'] = '****' . $tail;
            return $item;
        }, $creds);

        return view('admin.credentials', ['credentials' => $masked]);
    }

    public function rotateCredentials(Request $request, SportRadarService $store): RedirectResponse
    {
        $secret = (string) $request->input('secret', '');
        $label = (string) $request->input('label', 'rotated');
        if ($secret === '') {
            return redirect('/admin/credentials')->with('error', 'Введите ключ');
        }

        $id = $store->rotateCredential($label, $secret);
        $store->addAudit('credentials.rotate', ['id' => $id, 'label' => $label], 'admin', (string) $request->ip());
        return redirect('/admin/credentials')->with('ok', 'Ключ обновлен');
    }

    public function content(SportRadarService $store): View
    {
        return view('admin.content', [
            'pages' => $store->contentPages(),
            'jobs' => $store->recentReindexJobs(),
        ]);
    }

    public function publishContent(int $id, Request $request, SportRadarService $store): RedirectResponse
    {
        $pages = $store->contentPages();
        $page = null;
        foreach ($pages as $item) {
            if ((int) ($item['id'] ?? 0) === $id) {
                $page = $item;
                break;
            }
        }

        if ($page === null) {
            return redirect('/admin/content')->with('error', 'Страница не найдена');
        }

        $title = (string) ($request->input('title') ?: $page['title']);
        $body = (string) ($request->input('body') ?: $page['body']);
        $store->publishContent($id, $title, $body);
        $store->addAudit('content.publish', ['page_id' => $id], 'admin', (string) $request->ip());
        return redirect('/admin/content')->with('ok', 'Опубликовано');
    }

    public function rollbackContent(int $pageId, int $version, Request $request, SportRadarService $store): RedirectResponse
    {
        if (!$store->rollbackContent($pageId, $version)) {
            return redirect('/admin/content')->with('error', 'Версия не найдена');
        }
        $store->addAudit('content.rollback', ['page_id' => $pageId, 'version' => $version], 'admin', (string) $request->ip());
        return redirect('/admin/content')->with('ok', 'Откат выполнен');
    }

    public function queueReindex(int $pageId, Request $request, SportRadarService $store): RedirectResponse
    {
        $jobId = $store->queueReindex($pageId);
        $store->addAudit('content.reindex', ['page_id' => $pageId, 'job_id' => $jobId], 'admin', (string) $request->ip());
        return redirect('/admin/content')->with('ok', 'Переиндексация поставлена в очередь');
    }

    public function seo(SportRadarService $store): View
    {
        return view('admin.seo', ['items' => $store->seoMeta()]);
    }

    public function saveSeo(Request $request, SportRadarService $store): RedirectResponse
    {
        $slug = (string) $request->input('entity_slug', '');
        if ($slug === '') {
            return redirect('/admin/seo')->with('error', 'entity_slug обязателен');
        }

        $payload = [
            'entity_type' => (string) $request->input('entity_type', 'match'),
            'entity_slug' => $slug,
            'title' => (string) $request->input('title', ''),
            'description' => (string) $request->input('description', ''),
            'h1' => (string) $request->input('h1', ''),
            'canonical' => (string) $request->input('canonical', ''),
            'robots' => (string) $request->input('robots', 'index,follow'),
        ];
        $store->upsertSeo($payload);
        $store->addAudit('seo.upsert', $payload, 'admin', (string) $request->ip());

        return redirect('/admin/seo')->with('ok', 'SEO обновлено');
    }

    public function audit(SportRadarService $store): View
    {
        return view('admin.audit', ['logs' => $store->auditLogs()]);
    }
}

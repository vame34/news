# RadarArena Platform

Продакшен-ориентированная спортивная редакционная платформа с автоматическим ingestion, генерацией контента, модерацией и zero-downtime деплоем.

Язык: Русский | [English](README.md)

## Публичный сайт

Текущий публичный адрес: https://radararena.ru/

## Что в репозитории

- `backend/` API на Laravel 11, админка, сервисы ingestion/generation, воркеры.
- `frontend/` Next.js App Router SSR сайт с SEO/schema metadata.
- `ops/` Docker Compose для локальной разработки и runtime (blue/green).
- `scripts/` bootstrap, smoke-тесты, CI-скрипты, deploy-автоматизация.
- `docs/` архитектура и технические заметки.

## Ключевые возможности

- Мультиспортивная лента и страницы матчей (футбол, хоккей, баскетбол, теннис, MMA/бокс).
- Полностью автоматическая генерация новостей (ingestion -> AI генерация -> валидация -> публикация) без ручного написания статей.
- Публичный JSON API для страниц фронтенда и SEO-сущностей.
- Аутентификация админки + OTP.
- Пайплайн генерации контента с quality-gates.
- Ограничения затрат на AI-генерацию.
- Blue/green деплой с health-check и rollback.

## Технологический стек

- Backend: PHP 8.3, Laravel 11, PostgreSQL 16, Redis 7
- Frontend: Next.js 14, React 18, TypeScript
- Runtime gateway: Nginx
- Оркестрация: Docker Compose

## Быстрый запуск (5 минут)

### 1. Требования

- Docker + Docker Compose
- GNU Make
- Node.js 20+ (для локальных frontend-команд вне контейнера)

### 2. Запуск одной командой

```bash
./scripts/dev-up.sh
```

Остановка:

```bash
./scripts/dev-down.sh
```

### 3. Доступ локально

- Frontend: `http://localhost:3000`
- Backend health: `http://localhost:9000/up`
- Admin: `http://localhost:9000/admin/login`
- Nginx gateway: `http://localhost:8080`

## Ручной локальный setup

```bash
cp .env.example .env
cp backend/.env.example backend/.env
cp frontend/.env.example frontend/.env.local
docker compose -f ops/docker-compose.yml up -d --build
make db-bootstrap
```

## Переменные окружения

### Root `.env` (docker/dev/runtime wiring)

- `APP_PORT` порт Nginx в dev.
- `BACKEND_PORT`, `FRONTEND_PORT` локально публикуемые порты.
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.
- `INTERNAL_API_BASE` внутренний URL backend для SSR-запросов.
- `NEXT_PUBLIC_API_BASE` API base для браузера.
- `ADMIN_PASSWORD`, `ADMIN_OTP_CODE` реквизиты админ-доступа.

### Backend `backend/.env`

- `DEEPSEEK_API_KEY` ключ AI-провайдера.
- `DEEPSEEK_DAILY_REQUEST_LIMIT`, `DEEPSEEK_DAILY_TOKEN_LIMIT`, `DEEPSEEK_DAILY_USD_LIMIT` лимиты затрат.
- `INTERNAL_API_TOKEN` внутренний токен reindex/generation API.

### Frontend `frontend/.env.local`

- `NEXT_PUBLIC_SITE_URL`
- `NEXT_PUBLIC_GOOGLE_SITE_VERIFICATION`

## Тесты и quality checks

Быстрый smoke API:

```bash
make smoke-api
```

Полный локальный quality gate:

```bash
make ci
```

Доступные таргеты:

- `make lint`
- `make test-backend-unit`
- `make test-backend-integration`
- `make test-frontend-unit`
- `make test-e2e-smoke`

## Runtime деплой (Blue/Green)

Runtime compose: `ops/docker-compose.runtime.yml`

Точка входа деплоя:

```bash
./scripts/zero-downtime-deploy.sh
```

Поток:

1. Сборка и запуск целевого backend/frontend слота.
2. Миграции на целевом слоте.
3. Проверка health целевого слота.
4. Переключение nginx upstream include на целевой слот.
5. Пересоздание nginx для обновления mounted upstream config.
6. Проверка public health и rollback при ошибке.

## Операционные заметки

- Runtime storage: только PostgreSQL.
- Новости сейчас в text-first режиме: рендер изображений для сгенерированных новостей отключен.
- JSON в `backend/storage/data/` используется только как seed/bootstrap источник.
- Юридические страницы доступны по `/legal/*`.

## Чеклист перед публикацией

- Не хранить реальные секреты в git.
- В репозитории держать только `.env.example`.
- Проверить `APP_DEBUG=false` в runtime env.
- Обязательно задать сильные значения для `INTERNAL_API_TOKEN`, `ADMIN_PASSWORD`, `ADMIN_OTP_CODE`, `DEEPSEEK_API_KEY`.
- Заполнить `NEXT_PUBLIC_SITE_URL` и verification-мета.

## Известные проблемы и ограничения

- Первый локальный `next build` может падать, если `.next` создан root-пользователем из Docker; нужно поправить ownership.
- Качество API/news зависит от доступности внешних источников (DeepSeek, RSS/API провайдеры).
- Blue/green деплой чувствителен к корректности nginx include/cert mounts на VPS.
- Набор тестов предполагает Docker-стек и свободные локальные порты.
- GitHub Actions пока нет, CI работает через скрипты (`make ci`, `scripts/ci-run.sh`).

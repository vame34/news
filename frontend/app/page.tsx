import type { Metadata } from "next";
import Link from "next/link";
import { JsonLd } from "../components/seo/JsonLd";
import { Footer } from "../components/ui/Footer";
import { Header } from "../components/ui/Header";
import { MatchCard } from "../components/ui/MatchCard";
import { NewsCard } from "../components/ui/NewsCard";
import { fetchMatches, fetchNews } from "../lib/api";
import { buildBreadcrumbSchema, buildCollectionPageSchema } from "../lib/seo";

export const dynamic = "force-dynamic";

const HOME_TITLE = "РадарАрена: спортивные новости и разбор матчей";
const HOME_DESCRIPTION =
  "РадарАрена публикует спортивные новости, прогнозы и редакционную аналитику по футболу, хоккею, баскетболу, теннису, ММА и боксу.";

export const metadata: Metadata = {
  title: HOME_TITLE,
  description: HOME_DESCRIPTION,
  alternates: {
    canonical: "https://radararena.ru/",
  },
  openGraph: {
    title: HOME_TITLE,
    description: HOME_DESCRIPTION,
    type: "website",
    url: "https://radararena.ru/",
  },
  twitter: {
    card: "summary_large_image",
    title: HOME_TITLE,
    description: HOME_DESCRIPTION,
  },
};

export default async function HomePage() {
  const [matches, news] = await Promise.all([fetchMatches(), fetchNews()]);
  const leadMatch = matches[0];
  const leadNews = news[0];
  const featuredItems = [
    ...matches.slice(0, 3).map((item) => ({ name: `${item.home_team.name} — ${item.away_team.name}`, path: `/match/${item.slug}` })),
    ...news.slice(0, 3).map((item) => ({ name: item.title, path: `/news/${item.slug}` })),
  ];

  return (
    <div className="page-shell">
      <Header />
      <main className="page">
        <JsonLd data={buildBreadcrumbSchema([{ name: "Главная", path: "/" }])} />
        <JsonLd
          data={buildCollectionPageSchema(HOME_TITLE, HOME_DESCRIPTION, "/", featuredItems)}
        />

        <section className="container hero-home">
          <div className="hero-home-main texture-overlay card">
            <div className="hero-home-content">
              <div className="eyebrow">Редакция РадарАрены • футбол, хоккей, баскетбол, теннис, ММА и бокс</div>
              <h1 className="hero-title font-heading">
                Спортивные <span className="slant-highlight">новости</span> и разбор матчей
              </h1>
              <p className="hero-summary">
                Актуальные новости спорта, прогнозы, форма команд и редакционные разборы по главным событиям дня без рекламного шума.
              </p>
              <div className="hero-actions">
                <Link className="btn btn-primary" href={leadMatch ? `/match/${leadMatch.slug}` : "/matches"}>
                  Смотреть матчи
                </Link>
                <Link className="btn btn-ghost btn-ghost-active" href="/news">
                  Последние новости
                </Link>
              </div>
            </div>
          </div>

          <div className="hero-home-side">
            <article className="card">
              <div className="eyebrow">Матч дня</div>
              <h2 className="section-title font-heading" style={{ marginTop: "1rem" }}>
                {leadMatch ? `${leadMatch.home_team.name} — ${leadMatch.away_team.name}` : "Свежие матчи скоро"}
              </h2>
              <p style={{ marginTop: "1rem", color: "var(--text-muted)" }}>
                {leadMatch
                  ? leadMatch.analysis
                  : "Как только данные появятся, здесь отобразится главный редакционный разбор матча."}
              </p>
              <div style={{ marginTop: "1.25rem" }}>
                <Link className="btn btn-trust btn-full" href={leadMatch ? `/match/${leadMatch.slug}` : "/matches"}>
                  Открыть страницу матча
                </Link>
              </div>
            </article>

            <article className="card surface-dark">
              <div className="eyebrow">Последний материал</div>
              <h2 className="section-title font-heading" style={{ marginTop: "1rem" }}>
                {leadNews ? leadNews.title : "Новости обновляются"}
              </h2>
              <p style={{ marginTop: "1rem", color: "rgba(255,255,255,0.72)" }}>
                {leadNews
                  ? leadNews.excerpt
                  : "Когда данные появятся, сюда подставится короткий анонс последней новости из редакционной ленты."}
              </p>
              <div style={{ marginTop: "1.25rem" }}>
                <Link className="btn btn-primary btn-full" href={leadNews ? `/news/${leadNews.slug}` : "/news"}>
                  Читать материал
                </Link>
              </div>
            </article>
          </div>
        </section>

        <section className="container section">
          <div className="section-head">
            <h2 className="section-title font-heading">Ближайшие матчи</h2>
            <Link href="/matches" className="section-link">
              Все прогнозы
            </Link>
          </div>
          <div className="grid two-cols">
            {matches.slice(0, 4).map((item) => (
              <MatchCard key={item.id} item={item} />
            ))}
          </div>
        </section>

        <section className="container section">
          <div className="section-head">
            <h2 className="section-title font-heading">Последние новости</h2>
            <Link href="/news" className="section-link">
              Вся лента
            </Link>
          </div>
          <div className="grid two-cols">
            {news.slice(0, 4).map((item) => (
              <NewsCard key={item.id} item={item} />
            ))}
          </div>
        </section>
      </main>
      <Footer />
    </div>
  );
}

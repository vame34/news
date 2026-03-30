import type { Metadata } from "next";
import { JsonLd } from "../../components/seo/JsonLd";
import { Footer } from "../../components/ui/Footer";
import { Header } from "../../components/ui/Header";
import { PaginatedMatchesGrid } from "../../components/ui/PaginatedMatchesGrid";
import { fetchMatches } from "../../lib/api";
import { buildBreadcrumbSchema, buildCollectionPageSchema } from "../../lib/seo";

export const dynamic = "force-dynamic";

export async function generateMetadata({ searchParams }: { searchParams?: { page?: string } }): Promise<Metadata> {
  const page = Number(searchParams?.page ?? "1");
  const safePage = Number.isFinite(page) && page > 1 ? Math.floor(page) : 1;
  const suffix = safePage > 1 ? `, страница ${safePage}` : "";
  const title = `Прогнозы и разбор матчей${suffix} | РадарАрена`;
  const description =
    safePage > 1
      ? `Архив предматчевых разборов и прогнозов РадарАрены, страница ${safePage}.`
      : "Прогнозы на матчи, форма команд, ключевые факторы и редакционная аналитика на РадарАрене.";
  const canonical = safePage > 1 ? `https://radararena.ru/matches?page=${safePage}` : "https://radararena.ru/matches";

  return {
    title,
    description,
    alternates: { canonical },
    openGraph: {
      title,
      description,
      type: "website",
      url: canonical,
    },
    twitter: {
      card: "summary_large_image",
      title,
      description,
    },
  };
}

export default async function MatchesPage({ searchParams }: { searchParams?: { page?: string } }) {
  const matches = await fetchMatches();
  const initialPage = Number(searchParams?.page ?? "1");
  const safePage = Number.isFinite(initialPage) && initialPage > 0 ? Math.floor(initialPage) : 1;
  const pageTitle = safePage > 1 ? `Прогнозы и разбор матчей, страница ${safePage}` : "Прогнозы и разбор матчей";
  const pageDescription =
    safePage > 1
      ? `Архив предматчевых разборов и прогнозов РадарАрены, страница ${safePage}.`
      : "Прогнозы на матчи, форма команд, ключевые факторы и редакционная аналитика на РадарАрене.";
  const pagePath = safePage > 1 ? `/matches?page=${safePage}` : "/matches";
  const pageItems = matches
    .slice((safePage - 1) * 12, safePage * 12)
    .map((item) => ({ name: `${item.home_team.name} — ${item.away_team.name}`, path: `/match/${item.slug}` }));

  return (
    <div className="page-shell">
      <Header />
      <main className="page">
        <JsonLd data={buildBreadcrumbSchema([{ name: "Главная", path: "/" }, { name: "Матчи", path: "/matches" }])} />
        <JsonLd data={buildCollectionPageSchema(pageTitle, pageDescription, pagePath, pageItems)} />
        <section className="container section">
          <div className="section-head">
            <h1>{pageTitle}</h1>
          </div>
          <PaginatedMatchesGrid items={matches} initialPage={safePage} />
        </section>
      </main>
      <Footer />
    </div>
  );
}

import type { Metadata } from "next";
import { JsonLd } from "../../components/seo/JsonLd";
import { Footer } from "../../components/ui/Footer";
import { Header } from "../../components/ui/Header";
import { PaginatedNewsGrid } from "../../components/ui/PaginatedNewsGrid";
import { fetchNews } from "../../lib/api";
import { buildBreadcrumbSchema, buildCollectionPageSchema } from "../../lib/seo";

export const dynamic = "force-dynamic";

export async function generateMetadata({ searchParams }: { searchParams?: { page?: string } }): Promise<Metadata> {
  const page = Number(searchParams?.page ?? "1");
  const safePage = Number.isFinite(page) && page > 1 ? Math.floor(page) : 1;
  const suffix = safePage > 1 ? `, страница ${safePage}` : "";
  const title = `Новости спорта${suffix} | РадарАрена`;
  const description =
    safePage > 1
      ? `Архив спортивных новостей и аналитики РадарАрены, страница ${safePage}.`
      : "Свежие спортивные новости, редакционные заметки и аналитика матчей на РадарАрене.";
  const canonical = safePage > 1 ? `https://radararena.ru/news?page=${safePage}` : "https://radararena.ru/news";

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

export default async function NewsPage({ searchParams }: { searchParams?: { page?: string } }) {
  const news = await fetchNews();
  const initialPage = Number(searchParams?.page ?? "1");
  const safePage = Number.isFinite(initialPage) && initialPage > 0 ? Math.floor(initialPage) : 1;
  const pageTitle = safePage > 1 ? `Новости спорта, страница ${safePage}` : "Новости спорта";
  const pageDescription =
    safePage > 1
      ? `Архив спортивных новостей и аналитики РадарАрены, страница ${safePage}.`
      : "Свежие спортивные новости, редакционные заметки и аналитика матчей на РадарАрене.";
  const pagePath = safePage > 1 ? `/news?page=${safePage}` : "/news";
  const pageItems = news
    .slice((safePage - 1) * 12, safePage * 12)
    .map((item) => ({ name: item.title, path: `/news/${item.slug}` }));

  return (
    <div className="page-shell">
      <Header />
      <main className="page">
        <JsonLd data={buildBreadcrumbSchema([{ name: "Главная", path: "/" }, { name: "Новости", path: "/news" }])} />
        <JsonLd data={buildCollectionPageSchema(pageTitle, pageDescription, pagePath, pageItems)} />
        <section className="container section">
          <div className="section-head">
            <h1>{pageTitle}</h1>
          </div>
          <PaginatedNewsGrid items={news} initialPage={safePage} />
        </section>
      </main>
      <Footer />
    </div>
  );
}

import type { Metadata } from "next";
import { Footer } from "../../../components/ui/Footer";
import { Header } from "../../../components/ui/Header";
import { MatchCard } from "../../../components/ui/MatchCard";
import { NewsCard } from "../../../components/ui/NewsCard";
import { fetchContentPage, fetchLeague, fetchNews, fetchSeo } from "../../../lib/api";

type Props = { params: { slug: string } };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const seo = await fetchSeo("league", params.slug);
  return {
    title: seo?.title ?? `${params.slug.toUpperCase()} | РадарАрена`,
    description: seo?.description ?? "Матчи и новости турнира",
  };
}

export default async function LeaguePage({ params }: Props) {
  const [{ league, matches }, page, news] = await Promise.all([
    fetchLeague(params.slug),
    fetchContentPage("league", params.slug),
    fetchNews(),
  ]);
  const relatedNews = news.filter((item) => item.league_slug === params.slug).slice(0, 4);

  if (!league) {
    return (
      <div className="page-shell">
        <Header />
        <main className="page">
          <section className="container section">
            <h1>Лига не найдена</h1>
          </section>
        </main>
        <Footer />
      </div>
    );
  }

  return (
    <div className="page-shell">
      <Header />
      <main className="page">
        <section className="container section">
          <h1>{page?.title ?? `Лига: ${league.name}`}</h1>
          {(page?.body ?? `${league.name}: актуальные матчи и редакционные материалы.`)
            .split("\n\n")
            .filter(Boolean)
            .map((paragraph, index) => (
              <p key={index}>{paragraph}</p>
            ))}
        </section>
        <section className="container section">
          <div className="section-head">
            <h2>Матчи лиги</h2>
          </div>
          <div className="grid two-cols">
            {matches.map((item) => (
              <MatchCard key={item.id} item={item} />
            ))}
          </div>
        </section>
        <section className="container section">
          <div className="section-head">
            <h2>Новости по турниру</h2>
          </div>
          <div className="grid two-cols">
            {relatedNews.map((item) => (
              <NewsCard key={item.id} item={item} />
            ))}
          </div>
        </section>
      </main>
      <Footer />
    </div>
  );
}

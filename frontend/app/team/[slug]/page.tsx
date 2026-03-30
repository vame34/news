import type { Metadata } from "next";
import { Footer } from "../../../components/ui/Footer";
import { Header } from "../../../components/ui/Header";
import { MatchCard } from "../../../components/ui/MatchCard";
import { NewsCard } from "../../../components/ui/NewsCard";
import { fetchContentPage, fetchNews, fetchSeo, fetchTeam } from "../../../lib/api";

type Props = { params: { slug: string } };

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const seo = await fetchSeo("team", params.slug);
  return {
    title: seo?.title ?? `${params.slug} | РадарАрена`,
    description: seo?.description ?? "Матчи и новости команды",
  };
}

export default async function TeamPage({ params }: Props) {
  const [{ team, matches }, page, news] = await Promise.all([
    fetchTeam(params.slug),
    fetchContentPage("team", params.slug),
    fetchNews(),
  ]);
  const relatedNews = news.filter((item) => item.team_slug === params.slug).slice(0, 4);

  if (!team) {
    return (
      <div className="page-shell">
        <Header />
        <main className="page">
          <section className="container section">
            <h1>Команда не найдена</h1>
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
          <h1>{page?.title ?? `Команда: ${team.name}`}</h1>
          {(page?.body ?? `${team.name}: ближайшие матчи и редакционные материалы.`)
            .split("\n\n")
            .filter(Boolean)
            .map((paragraph, index) => (
              <p key={index}>{paragraph}</p>
            ))}
        </section>
        <section className="container section">
          <div className="section-head">
            <h2>Ближайшие матчи</h2>
          </div>
          <div className="grid two-cols">
            {matches.map((item) => (
              <MatchCard key={item.id} item={item} />
            ))}
          </div>
        </section>
        <section className="container section">
          <div className="section-head">
            <h2>Новости команды</h2>
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

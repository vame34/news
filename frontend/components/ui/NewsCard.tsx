import type { NewsItem } from "../../lib/api";
import Link from "next/link";

function disciplineLabel(slug: string): string {
  const key = (slug || "").toLowerCase();
  if (["basketball", "nba", "euroleague"].includes(key)) return "Баскетбол";
  if (["tennis", "atp", "wta"].includes(key)) return "Теннис";
  if (["hockey", "nhl", "khl"].includes(key)) return "Хоккей";
  if (["mma-boxing", "mma", "boxing"].includes(key)) return "ММА/бокс";
  return "Футбол";
}

export function NewsCard({ item }: { item: NewsItem }) {
  return (
    <article className="card news-card">
      {item.image_url ? (
        <Link href={`/news/${item.slug}`} className="news-card-image-link">
          <img className="news-card-image" src={item.image_url} alt={item.image_alt ?? item.title} loading="lazy" />
        </Link>
      ) : null}
      <div className="news-meta-row">
        <span className="news-discipline-tag">{disciplineLabel(item.league_slug)}</span>
        <span className="news-time">{new Date(item.published_at).toLocaleString("ru-RU")}</span>
      </div>
      <h3 className="font-heading">
        <Link href={`/news/${item.slug}`}>{item.title}</Link>
      </h3>
      <p>{item.excerpt}</p>
      <Link href={`/news/${item.slug}`} className="section-link">
        Читать материал
      </Link>
    </article>
  );
}

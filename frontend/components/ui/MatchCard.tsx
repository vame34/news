import Link from "next/link";
import type { MatchItem } from "../../lib/api";

function channelLabel(channel: string | { name?: string; url?: string }): string {
  if (typeof channel === "string") {
    return channel;
  }
  return (channel?.name ?? "").trim();
}

function isCodeLikeChannel(value: string): boolean {
  const text = value.trim();
  if (!text) return false;
  return /^[A-Z]{2,4}\s*(?:@|vs\.?|v\.?|[-–—])\s*[A-Z]{2,4}$/u.test(text);
}

export function MatchCard({ item }: { item: MatchItem }) {
  const isLive = item.status.toLowerCase() === "live";
  const watch = item.where_to_watch
    .map(channelLabel)
    .filter((x) => x !== "" && !isCodeLikeChannel(x));

  return (
    <article className="card match-card">
      <div className="match-meta">
        <span>{item.league.name}</span>
        <span className={`badge ${isLive ? "badge-live" : "badge-upcoming"}`}>{isLive ? "Live" : "Скоро"}</span>
      </div>
      <h3 className="match-title">
        {item.home_team.name} — {item.away_team.name}
      </h3>
      <p className="match-time">{new Date(item.kickoff_at).toLocaleString("ru-RU")}</p>
      <p className="match-analysis">{item.analysis}</p>
      {watch.length > 0 ? (
        <div className="watch-list">
          {watch.slice(0, 3).map((channel) => (
            <span key={channel} className="watch-chip">
              {channel}
            </span>
          ))}
        </div>
      ) : null}
      <Link className="btn btn-ghost" href={`/match/${item.slug}`}>
        Читать разбор
      </Link>
    </article>
  );
}

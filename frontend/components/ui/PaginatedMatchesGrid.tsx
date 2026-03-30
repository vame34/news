"use client";

import Link from "next/link";
import { useMemo, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import type { MatchItem } from "../../lib/api";

type Props = {
  items: MatchItem[];
  initialPage: number;
  perPage?: number;
};

export function PaginatedMatchesGrid({ items, initialPage, perPage = 12 }: Props) {
  const totalPages = Math.max(1, Math.ceil(items.length / perPage));
  const [page, setPage] = useState(Math.min(Math.max(initialPage, 1), totalPages));
  const router = useRouter();
  const pathname = usePathname();
  const searchParams = useSearchParams();

  const pageItems = useMemo(() => {
    const from = (page - 1) * perPage;
    return items.slice(from, from + perPage);
  }, [items, page, perPage]);

  function updatePage(nextPage: number) {
    const clamped = Math.min(Math.max(nextPage, 1), totalPages);
    setPage(clamped);
    const params = new URLSearchParams(searchParams.toString());
    if (clamped === 1) params.delete("page");
    else params.set("page", String(clamped));
    const query = params.toString();
    router.replace(query ? `${pathname}?${query}` : pathname, { scroll: false });
  }

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

  return (
    <>
      <div className="grid two-cols">
        {pageItems.map((item) => {
          const isLive = item.status.toLowerCase() === "live";
          return (
            <article key={item.id} className="card match-card">
              <div className="match-meta">
                <span>{item.league.name}</span>
                <span className={`badge ${isLive ? "badge-live" : "badge-upcoming"}`}>{isLive ? "Live" : "Скоро"}</span>
              </div>
              <h3 className="match-title">
                {item.home_team.name} — {item.away_team.name}
              </h3>
              <p className="match-time">{new Date(item.kickoff_at).toLocaleString("ru-RU")}</p>
              <p className="match-analysis">{item.analysis}</p>
              {item.where_to_watch.length > 0 ? (
                <div className="watch-list">
                  {item.where_to_watch
                    .map(channelLabel)
                    .filter((x) => x !== "" && !isCodeLikeChannel(x))
                    .slice(0, 3)
                    .map((channel) => (
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
        })}
      </div>
      <div className="pagination">
        <button className="btn btn-ghost" onClick={() => updatePage(page - 1)} disabled={page <= 1}>
          Назад
        </button>
        <span className="pagination-meta">
          Страница {page} из {totalPages}
        </span>
        <button className="btn btn-ghost" onClick={() => updatePage(page + 1)} disabled={page >= totalPages}>
          Далее
        </button>
      </div>
    </>
  );
}

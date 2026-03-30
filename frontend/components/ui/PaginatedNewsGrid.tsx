"use client";

import { useMemo, useState } from "react";
import { usePathname, useRouter, useSearchParams } from "next/navigation";
import type { NewsItem } from "../../lib/api";
import { NewsCard } from "./NewsCard";

type Props = {
  items: NewsItem[];
  initialPage: number;
  perPage?: number;
};

export function PaginatedNewsGrid({ items, initialPage, perPage = 12 }: Props) {
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

  return (
    <>
      <div className="grid two-cols">
        {pageItems.map((item) => (
          <NewsCard key={item.id} item={item} />
        ))}
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

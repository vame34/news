import type { MetadataRoute } from "next";
import { fetchContentPages, fetchMatches, fetchNews } from "../lib/api";
import { siteUrl } from "../lib/seo";

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const [matches, news, pages] = await Promise.all([fetchMatches(), fetchNews(), fetchContentPages()]);

  const staticRoutes: MetadataRoute.Sitemap = [
    { url: siteUrl("/"), changeFrequency: "hourly", priority: 1 },
    { url: siteUrl("/matches"), changeFrequency: "hourly", priority: 0.9 },
    { url: siteUrl("/news"), changeFrequency: "hourly", priority: 0.9 },
  ];

  const matchRoutes: MetadataRoute.Sitemap = matches.map((m) => ({
    url: siteUrl(`/match/${m.slug}`),
    lastModified: new Date(m.kickoff_at),
    changeFrequency: "hourly",
    priority: 0.8,
  }));

  const newsRoutes: MetadataRoute.Sitemap = news.map((n) => ({
    url: siteUrl(`/news/${n.slug}`),
    lastModified: new Date(n.published_at),
    changeFrequency: "hourly",
    priority: 0.8,
  }));

  const derivedRoutes: MetadataRoute.Sitemap = [];
  for (const p of pages) {
    if (p.entity_type === "team") {
      derivedRoutes.push({
        url: siteUrl(`/team/${p.entity_slug}`),
        changeFrequency: "daily",
        priority: 0.7,
      });
      continue;
    }

    if (p.entity_type === "league") {
      derivedRoutes.push({
        url: siteUrl(`/league/${p.entity_slug}`),
        changeFrequency: "daily",
        priority: 0.7,
      });
    }
  }

  return [...staticRoutes, ...matchRoutes, ...newsRoutes, ...derivedRoutes];
}

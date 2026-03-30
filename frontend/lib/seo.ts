import type { MatchItem, NewsItem } from "./api";

function normalizeSiteUrl(raw?: string): string {
  const value = (raw ?? "").trim();
  if (value === "") return "https://radararena.ru";
  return value;
}

const SITE_URL = normalizeSiteUrl(process.env.NEXT_PUBLIC_SITE_URL);

export function siteUrl(path = ""): string {
  if (!path.startsWith("/")) {
    return `${SITE_URL}/${path}`;
  }
  return `${SITE_URL}${path}`;
}

export function buildOrganizationSchema() {
  return {
    "@context": "https://schema.org",
    "@type": "NewsMediaOrganization",
    name: "РадарАрена",
    url: siteUrl(),
    logo: {
      "@type": "ImageObject",
      url: siteUrl("/icon.svg"),
    },
    sameAs: [],
  };
}

export function buildWebSiteSchema() {
  return {
    "@context": "https://schema.org",
    "@type": "WebSite",
    name: "РадарАрена",
    url: siteUrl(),
    inLanguage: "ru-RU",
    publisher: {
      "@type": "Organization",
      name: "РадарАрена",
    },
  };
}

export function buildBreadcrumbSchema(items: Array<{ name: string; path: string }>) {
  return {
    "@context": "https://schema.org",
    "@type": "BreadcrumbList",
    itemListElement: items.map((item, index) => ({
      "@type": "ListItem",
      position: index + 1,
      name: item.name,
      item: siteUrl(item.path),
    })),
  };
}

export function buildCollectionPageSchema(
  name: string,
  description: string,
  path: string,
  items: Array<{ name: string; path: string }>
) {
  return {
    "@context": "https://schema.org",
    "@type": "CollectionPage",
    name,
    description,
    url: siteUrl(path),
    inLanguage: "ru-RU",
    isPartOf: {
      "@type": "WebSite",
      name: "РадарАрена",
      url: siteUrl(),
    },
    mainEntity: {
      "@type": "ItemList",
      itemListElement: items.map((item, index) => ({
        "@type": "ListItem",
        position: index + 1,
        url: siteUrl(item.path),
        name: item.name,
      })),
    },
  };
}

export function buildMatchSchema(match: MatchItem) {
  return {
    "@context": "https://schema.org",
    "@type": "SportsEvent",
    name: `${match.home_team.name} — ${match.away_team.name}`,
    startDate: match.kickoff_at,
    sport: "Football",
    eventStatus: "https://schema.org/EventScheduled",
    url: siteUrl(`/match/${match.slug}`),
    location: {
      "@type": "Place",
      name: match.league.name,
    },
    competitor: [
      { "@type": "SportsTeam", name: match.home_team.name },
      { "@type": "SportsTeam", name: match.away_team.name },
    ],
    organizer: {
      "@type": "Organization",
      name: "РадарАрена",
      url: siteUrl(),
    },
  };
}

export function buildNewsSchema(item: NewsItem) {
  const fallbackImage = siteUrl("/icon.svg");
  const image = item.image_url ?? fallbackImage;
  return {
    "@context": "https://schema.org",
    "@type": "NewsArticle",
    headline: item.title,
    datePublished: item.published_at,
    dateModified: item.published_at,
    description: item.excerpt,
    image: [image],
    inLanguage: "ru-RU",
    mainEntityOfPage: {
      "@type": "WebPage",
      "@id": siteUrl(`/news/${item.slug}`),
    },
    author: {
      "@type": "Organization",
      name: "РадарАрена",
    },
    publisher: {
      "@type": "Organization",
      name: "РадарАрена",
      logo: {
        "@type": "ImageObject",
        url: siteUrl("/icon.svg"),
      },
    },
  };
}

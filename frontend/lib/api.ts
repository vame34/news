export type MatchItem = {
  id: number;
  slug: string;
  sport: string;
  league: { slug: string; name: string };
  home_team: { slug: string; name: string };
  away_team: { slug: string; name: string };
  kickoff_at: string;
  status: string;
  analysis: string;
  where_to_watch: Array<string | { name?: string; url?: string }>;
};

export type NewsItem = {
  id: number;
  slug: string;
  title: string;
  excerpt: string;
  body: string;
  published_at: string;
  league_slug: string;
  team_slug: string;
  image_url?: string | null;
  image_alt?: string | null;
  image_width?: number | null;
  image_height?: number | null;
};

export type SeoMeta = {
  entity_type: string;
  entity_slug: string;
  title: string;
  description: string;
  h1: string;
  canonical: string;
  robots: string;
};

export type ContentPage = {
  id: number;
  entity_type: string;
  entity_slug: string;
  status: string;
  version: number;
  title: string;
  body: string;
  updated_at: string;
};

export type LeagueItem = {
  id: number;
  slug: string;
  name: string;
  sport: string;
};

export type TeamItem = {
  id: number;
  slug: string;
  name: string;
  league_id: number;
};

const PUBLIC_API_BASE = process.env.NEXT_PUBLIC_API_BASE ?? "/api";
const INTERNAL_API_BASE = process.env.INTERNAL_API_BASE ?? "http://backend:9000";

function resolveApiBase(): string {
  return typeof window === "undefined" ? INTERNAL_API_BASE : PUBLIC_API_BASE;
}

export async function fetchMatches(): Promise<MatchItem[]> {
  const apiBase = resolveApiBase();
  try {
    const res = await fetch(`${apiBase}/api/v1/matches`, { next: { revalidate: 60 } });
    if (!res.ok) return [];
    const json = await res.json();
    return Array.isArray(json?.data) ? json.data : [];
  } catch {
    return [];
  }
}

export async function fetchMatch(slug: string): Promise<MatchItem | null> {
  const apiBase = resolveApiBase();
  try {
    const res = await fetch(`${apiBase}/api/v1/matches/${slug}`, { next: { revalidate: 60 } });
    if (!res.ok) return null;
    const json = await res.json();
    return (json?.data as MatchItem) ?? null;
  } catch {
    return null;
  }
}

export async function fetchNews(): Promise<NewsItem[]> {
  const apiBase = resolveApiBase();
  try {
    const res = await fetch(`${apiBase}/api/v1/news`, { cache: "no-store" });
    if (!res.ok) return [];
    const json = await res.json();
    return Array.isArray(json?.data) ? json.data : [];
  } catch {
    return [];
  }
}

export async function fetchNewsArticle(slug: string): Promise<NewsItem | null> {
  const apiBase = resolveApiBase();
  try {
    const res = await fetch(`${apiBase}/api/v1/news/${slug}`, { cache: "no-store" });
    if (!res.ok) return null;
    const json = await res.json();
    return (json?.data as NewsItem) ?? null;
  } catch {
    return null;
  }
}

export async function fetchSeo(entityType: string, entitySlug: string): Promise<SeoMeta | null> {
  const apiBase = resolveApiBase();
  try {
    const res = await fetch(`${apiBase}/api/v1/seo/${entityType}/${entitySlug}`, { next: { revalidate: 300 } });
    if (!res.ok) return null;
    const json = await res.json();
    return (json?.data as SeoMeta) ?? null;
  } catch {
    return null;
  }
}

export async function fetchContentPages(): Promise<ContentPage[]> {
  const apiBase = resolveApiBase();
  try {
    const res = await fetch(`${apiBase}/api/v1/content/pages`, { next: { revalidate: 300 } });
    if (!res.ok) return [];
    const json = await res.json();
    return Array.isArray(json?.data) ? json.data : [];
  } catch {
    return [];
  }
}

export async function fetchContentPage(entityType: string, entitySlug: string): Promise<ContentPage | null> {
  const apiBase = resolveApiBase();
  try {
    const res = await fetch(`${apiBase}/api/v1/content/pages/${entityType}/${entitySlug}`, { next: { revalidate: 300 } });
    if (!res.ok) return null;
    const json = await res.json();
    return (json?.data as ContentPage) ?? null;
  } catch {
    return null;
  }
}

export async function fetchLeague(slug: string): Promise<{ league: LeagueItem | null; matches: MatchItem[] }> {
  const apiBase = resolveApiBase();
  try {
    const res = await fetch(`${apiBase}/api/v1/leagues/${slug}`, { next: { revalidate: 120 } });
    if (!res.ok) return { league: null, matches: [] };
    const json = await res.json();
    return {
      league: (json?.data as LeagueItem) ?? null,
      matches: Array.isArray(json?.matches) ? json.matches : [],
    };
  } catch {
    return { league: null, matches: [] };
  }
}

export async function fetchTeam(slug: string): Promise<{ team: TeamItem | null; matches: MatchItem[] }> {
  const apiBase = resolveApiBase();
  try {
    const res = await fetch(`${apiBase}/api/v1/teams/${slug}`, { next: { revalidate: 120 } });
    if (!res.ok) return { team: null, matches: [] };
    const json = await res.json();
    return {
      team: (json?.data as TeamItem) ?? null,
      matches: Array.isArray(json?.matches) ? json.matches : [],
    };
  } catch {
    return { team: null, matches: [] };
  }
}

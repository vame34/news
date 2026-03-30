import type { Metadata } from "next";
import Link from "next/link";
import { JsonLd } from "../../../components/seo/JsonLd";
import { Footer } from "../../../components/ui/Footer";
import { Header } from "../../../components/ui/Header";
import type { MatchItem } from "../../../lib/api";
import { fetchContentPage, fetchMatch, fetchNews, fetchSeo } from "../../../lib/api";
import { buildMatchSchema } from "../../../lib/seo";

type Props = { params: { slug: string } };

function formatDateLabel(date: string): string {
  return new Date(date).toLocaleString("ru-RU", {
    day: "2-digit",
    month: "long",
    hour: "2-digit",
    minute: "2-digit",
  });
}

type MarkdownBlock =
  | { type: "paragraph"; content: string }
  | { type: "list"; items: string[] };

type MarkdownSection = {
  heading: string;
  blocks: MarkdownBlock[];
};

function sanitizeMatchBody(body: string): string {
  return body
    .split("\n")
    .map((line) => line.trimEnd())
    .filter((line) => !/^(Вопрос:|Ответ:|Связанная новость:)/u.test(line.trim()))
    .filter((line) => line.trim() !== "Трансляция: Официальный транслятор лиги.")
    .join("\n")
    .trim();
}

function parseMarkdownSections(body: string): MarkdownSection[] {
  const lines = sanitizeMatchBody(body).split("\n");
  const sections: MarkdownSection[] = [];
  let current: MarkdownSection | null = null;
  let paragraphBuffer: string[] = [];
  let listBuffer: string[] = [];

  const flushParagraph = () => {
    if (!current || paragraphBuffer.length === 0) return;
    current.blocks.push({ type: "paragraph", content: paragraphBuffer.join(" ").trim() });
    paragraphBuffer = [];
  };

  const flushList = () => {
    if (!current || listBuffer.length === 0) return;
    current.blocks.push({ type: "list", items: [...listBuffer] });
    listBuffer = [];
  };

  for (const rawLine of lines) {
    const line = rawLine.trim();
    if (!line) {
      flushParagraph();
      flushList();
      continue;
    }

    if (line.startsWith("## ")) {
      flushParagraph();
      flushList();
      current = { heading: line.replace(/^##\s+/, "").trim(), blocks: [] };
      sections.push(current);
      continue;
    }

    if (!current) {
      current = { heading: "Что важно перед событием", blocks: [] };
      sections.push(current);
    }

    if (/^[-*]\s+/.test(line)) {
      flushParagraph();
      listBuffer.push(line.replace(/^[-*]\s+/, "").trim());
      continue;
    }

    flushList();
    paragraphBuffer.push(line);
  }

  flushParagraph();
  flushList();

  return sections.filter((section) => section.blocks.length > 0);
}

function isCombatSport(match: MatchItem): boolean {
  return match.sport === "mma-boxing";
}

function eventLabel(match: MatchItem): string {
  return isCombatSport(match) ? "бой" : "матч";
}

function eventLabelGenitive(match: MatchItem): string {
  return isCombatSport(match) ? "боя" : "матча";
}

function eventLabelInstrumental(match: MatchItem): string {
  return isCombatSport(match) ? "боем" : "матчем";
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

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const seo = await fetchSeo("match", params.slug);
  const title = seo?.title ?? `Разбор матча ${params.slug} | РадарАрена`;
  return {
    title,
    description: seo?.description ?? "Форма, H2H, ключевые факторы и где смотреть матч",
  };
}

export default async function MatchPage({ params }: Props) {
  const [match, news, page] = await Promise.all([
    fetchMatch(params.slug),
    fetchNews(),
    fetchContentPage("match", params.slug),
  ]);

  if (!match) {
    return (
      <div className="page-shell">
        <Header />
        <main className="page">
          <section className="container section">
            <article className="card">
              <h1 className="section-title font-heading">Матч не найден</h1>
            </article>
          </section>
        </main>
        <Footer />
      </div>
    );
  }

  const markdownSections = parseMarkdownSections(page?.body ?? match.analysis);
  const watchLabels = match.where_to_watch
    .map(channelLabel)
    .filter((x) => x !== "" && x !== "Официальный транслятор лиги" && !isCodeLikeChannel(x));
  const latestNews = news.slice(0, 5);
  const label = eventLabel(match);
  const labelGenitive = eventLabelGenitive(match);
  const labelInstrumental = eventLabelInstrumental(match);
  const mainQuestionAnswer = isCombatSport(match)
    ? "Здесь собраны подтверждённые данные о времени боя, турнире, трансляции и связанных новостях без выдуманной аналитики."
    : "Здесь собраны подтверждённые данные о времени матча, турнире, трансляции и связанных новостях без выдуманной аналитики.";

  return (
    <div className="page-shell">
      <Header />
      <JsonLd data={buildMatchSchema(match)} />

      <main className="page">
        <section className="match-hero texture-overlay">
          <div className="container match-hero-content">
            <div className="league-label">
              {match.league.name} • {match.status === "live" ? `${label[0].toUpperCase()}${label.slice(1)} идёт` : "Карточка события"}
            </div>
            <div className={`badge ${match.status === "live" ? "badge-live" : "badge-upcoming"}`}>
              {match.status === "live" ? "Сейчас в эфире" : formatDateLabel(match.kickoff_at)}
            </div>
            <div className="teams-display font-heading">
              <h1 className="team-name home">{match.home_team.name}</h1>
              <div className="vs-badge">VS</div>
              <h1 className="team-name away slant-highlight">{match.away_team.name}</h1>
            </div>
            <p className="match-subline">
              Редакционный матч-центр • Где смотреть: {watchLabels.slice(0, 3).join(" • ") || "трансляция уточняется"}
            </p>
          </div>
        </section>

        <div className="container page-grid">
          <article>
            <div className="breadcrumbs">Матч-центр &gt; {match.league.name} &gt; {match.home_team.name} - {match.away_team.name}</div>

            <h2 className="article-title font-heading">
              {match.home_team.name} — {match.away_team.name}: что важно перед {labelInstrumental}
            </h2>

            <section className="article-copy">
              {markdownSections.map((section, sectionIndex) => (
                <div key={`section-${sectionIndex}`}>
                  <h3 className="section-title font-heading">{section.heading}</h3>
                  {section.blocks.map((block, blockIndex) =>
                    block.type === "paragraph" ? (
                      <p key={`paragraph-${sectionIndex}-${blockIndex}`}>{block.content}</p>
                    ) : (
                      <ul key={`list-${sectionIndex}-${blockIndex}`} className="article-list">
                        {block.items.map((item, itemIndex) => (
                          <li key={`item-${sectionIndex}-${blockIndex}-${itemIndex}`}>{item}</li>
                        ))}
                      </ul>
                    ),
                  )}
                </div>
              ))}
            </section>

            <section className="card section">
              <h3 className="section-title font-heading" style={{ marginBottom: "1rem" }}>
                Проверенные данные по событию
              </h3>
              <table className="stats-table">
                <tbody>
                  <tr>
                    <td width="32%">Турнир</td>
                    <td>{match.league.name}</td>
                  </tr>
                  <tr>
                    <td>Начало</td>
                    <td>{formatDateLabel(match.kickoff_at)}</td>
                  </tr>
                  <tr>
                    <td>Статус</td>
                    <td>{match.status === "live" ? `${label[0].toUpperCase()}${label.slice(1)} идёт` : "Запланировано"}</td>
                  </tr>
                </tbody>
              </table>

              <h4 className="league-label" style={{ color: "var(--text-muted)", margin: "1.25rem 0 0.5rem" }}>
                Где смотреть {label}
              </h4>
              <div className="broadcaster-list">
                {(watchLabels.length > 0 ? watchLabels : ["Трансляция уточняется"]).map((channel) => (
                  <span key={channel} className="broadcaster-tag">
                    {channel}
                  </span>
                ))}
              </div>
            </section>

            <section className="faq-group">
              <h3 className="section-title font-heading">Частые вопросы</h3>
              <div className="faq-item">
                <div className="faq-q">Во сколько начало {labelGenitive}?</div>
                <div className="faq-a">{formatDateLabel(match.kickoff_at)}.</div>
              </div>
              <div className="faq-item">
                <div className="faq-q">Где посмотреть прямую трансляцию?</div>
                <div className="faq-a">
                  {(watchLabels.length > 0 ? watchLabels : ["Трансляция уточняется"]).join(", ")}.
                </div>
              </div>
              <div className="faq-item">
                <div className="faq-q">Что главное в этом разборе?</div>
                <div className="faq-a">{mainQuestionAnswer}</div>
              </div>
            </section>
          </article>

          <aside className="sidebar">
            <section className="card section">
              <h3 className="sidebar-title font-heading">Последние новости</h3>
              <ul className="news-list">
                {latestNews.map((item) => (
                  <li key={item.id}>
                    <Link href={`/news/${item.slug}`}>{item.title}</Link>
                  </li>
                ))}
              </ul>
            </section>
          </aside>
        </div>
      </main>

      <Footer />
    </div>
  );
}

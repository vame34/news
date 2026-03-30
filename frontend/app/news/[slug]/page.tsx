import type { Metadata } from "next";
import type { ReactNode } from "react";
import { JsonLd } from "../../../components/seo/JsonLd";
import { Footer } from "../../../components/ui/Footer";
import { Header } from "../../../components/ui/Header";
import { fetchNewsArticle } from "../../../lib/api";
import { buildNewsSchema } from "../../../lib/seo";

type BodyBlock =
  | { type: "h2"; text: string }
  | { type: "p"; text: string }
  | { type: "ul"; items: string[] }
  | { type: "ol"; items: string[] };

function renderInlineMarkdown(text: string): ReactNode[] {
  const nodes: ReactNode[] = [];
  const pattern = /\*\*(.+?)\*\*/g;
  let start = 0;
  let match: RegExpExecArray | null;

  while ((match = pattern.exec(text)) !== null) {
    const matchIndex = match.index;
    if (matchIndex > start) {
      nodes.push(text.slice(start, matchIndex));
    }
    nodes.push(<strong key={`bold-${matchIndex}`}>{match[1]}</strong>);
    start = matchIndex + match[0].length;
  }

  if (start < text.length) {
    nodes.push(text.slice(start));
  }

  if (nodes.length === 0) {
    nodes.push(text);
  }

  return nodes;
}

function parseArticleBody(raw: string, title: string): BodyBlock[] {
  const lines = raw.split("\n").map((line) => line.trim());
  const blocks: BodyBlock[] = [];
  let paragraph: string[] = [];
  let listItems: string[] = [];
  let orderedItems: string[] = [];

  const splitInlineOrderedList = (line: string): string[] => {
    const matches = [...line.matchAll(/\d+\.\s+/g)];
    if (matches.length < 2) {
      return [];
    }

    const firstIndex = matches[0].index ?? -1;
    if (firstIndex > 2) {
      return [];
    }

    const items: string[] = [];
    for (let i = 0; i < matches.length; i += 1) {
      const current = matches[i];
      const next = matches[i + 1];
      const start = (current.index ?? 0) + current[0].length;
      const end = next?.index ?? line.length;
      const item = line.slice(start, end).trim();
      if (item !== "") {
        items.push(item);
      }
    }

    return items;
  };

  const flushParagraph = () => {
    if (paragraph.length === 0) return;
    const text = paragraph.join(" ").trim();
    if (text !== "" && text !== title) {
      blocks.push({ type: "p", text });
    }
    paragraph = [];
  };

  const flushList = () => {
    if (listItems.length === 0) return;
    blocks.push({ type: "ul", items: listItems });
    listItems = [];
  };

  const flushOrdered = () => {
    if (orderedItems.length === 0) return;
    blocks.push({ type: "ol", items: orderedItems });
    orderedItems = [];
  };

  for (const line of lines) {
    if (line === "" || line === title || line === `# ${title}`) {
      flushParagraph();
      flushList();
      flushOrdered();
      continue;
    }

    if (line.startsWith("## ")) {
      flushParagraph();
      flushList();
      flushOrdered();
      blocks.push({ type: "h2", text: line.replace(/^##\s+/, "").trim() });
      continue;
    }

    if (line.startsWith("- ")) {
      flushParagraph();
      flushOrdered();
      listItems.push(line.replace(/^-+\s+/, "").trim());
      continue;
    }

    const inlineOrdered = splitInlineOrderedList(line);
    if (inlineOrdered.length > 0) {
      flushParagraph();
      flushList();
      flushOrdered();
      blocks.push({ type: "ol", items: inlineOrdered });
      continue;
    }

    if (/^\d+\.\s+/.test(line)) {
      flushParagraph();
      flushList();
      orderedItems.push(line.replace(/^\d+\.\s+/, "").trim());
      continue;
    }

    flushOrdered();
    flushList();
    paragraph.push(line.replace(/^#+\s*/, "").trim());
  }

  flushParagraph();
  flushList();
  flushOrdered();
  return blocks;
}

export async function generateMetadata({ params }: { params: { slug: string } }): Promise<Metadata> {
  const item = await fetchNewsArticle(params.slug);
  const title = item ? `${item.title} | РадарАрена` : "Новость | РадарАрена";
  const description = item?.excerpt ?? "Оперативная спортивная новость и аналитика матча";
  const canonical = `https://radararena.ru/news/${params.slug}`;
  const image = item?.image_url ?? "https://radararena.ru/icon.svg";
  return {
    title,
    description,
    alternates: { canonical },
    openGraph: {
      type: "article",
      url: canonical,
      title,
      description,
      siteName: "РадарАрена",
      locale: "ru_RU",
      images: [{ url: image, alt: item?.image_alt ?? item?.title ?? "РадарАрена" }],
      publishedTime: item?.published_at,
      modifiedTime: item?.published_at,
    },
    twitter: {
      card: "summary_large_image",
      title,
      description,
      images: [image],
    },
    robots: {
      index: true,
      follow: true,
    },
  };
}

export default async function NewsArticlePage({ params }: { params: { slug: string } }) {
  const item = await fetchNewsArticle(params.slug);

  if (!item) {
    return (
      <div className="page-shell">
        <Header />
        <main className="page">
          <section className="container section">
            <h1>Новость не найдена</h1>
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
        <JsonLd data={buildNewsSchema(item)} />
        <article className="container section">
          <h1 className="article-title font-heading">{item.title}</h1>
          <p className="news-time">{new Date(item.published_at).toLocaleString("ru-RU")}</p>
          {item.image_url ? (
            <figure className="news-article-figure">
              <img src={item.image_url} alt={item.image_alt ?? item.title} loading="eager" />
            </figure>
          ) : null}
          <div className="card article-copy">
            {parseArticleBody(item.body, item.title).map((block, index) => {
              if (block.type === "h2") {
                return <h2 key={index} className="news-article-subtitle">{renderInlineMarkdown(block.text)}</h2>;
              }
              if (block.type === "ul") {
                return (
                  <ul key={index} className="news-article-list">
                    {block.items.map((entry, i) => <li key={`${index}-${i}`}>{renderInlineMarkdown(entry)}</li>)}
                  </ul>
                );
              }
              if (block.type === "ol") {
                return (
                  <ol key={index} className="news-article-list">
                    {block.items.map((entry, i) => <li key={`${index}-${i}`}>{renderInlineMarkdown(entry)}</li>)}
                  </ol>
                );
              }
              return <p key={index}>{renderInlineMarkdown(block.text)}</p>;
            })}
          </div>
        </article>
      </main>
      <Footer />
    </div>
  );
}

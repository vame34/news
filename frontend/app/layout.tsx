import "../styles/globals.css";
import { Inter, Oswald } from "next/font/google";
import type { Metadata } from "next";
import type { ReactNode } from "react";
import { JsonLd } from "../components/seo/JsonLd";
import { buildOrganizationSchema, buildWebSiteSchema } from "../lib/seo";

const inter = Inter({
  subsets: ["latin", "cyrillic"],
  variable: "--font-body",
});

const oswald = Oswald({
  subsets: ["latin", "cyrillic"],
  variable: "--font-heading",
});

const rawSiteUrl = (process.env.NEXT_PUBLIC_SITE_URL ?? "").trim();
const normalizedSiteUrl = rawSiteUrl === "" ? "https://radararena.ru" : rawSiteUrl;
const googleSiteVerification =
  (process.env.NEXT_PUBLIC_GOOGLE_SITE_VERIFICATION ?? "").trim() || "Z6BK-4Uz_b0Gv0gAzLXjkBka6rWWvxsFjgs0GE4NEfQ";

export const metadata: Metadata = {
  metadataBase: new URL(normalizedSiteUrl),
  title: "РадарАрена",
  description: "Спортивные матчи, счет и аналитика",
  applicationName: "РадарАрена",
  verification: {
    google: googleSiteVerification,
    yandex: "5c2c7081500d015e",
  },
  openGraph: {
    type: "website",
    locale: "ru_RU",
    siteName: "РадарАрена",
    title: "РадарАрена",
    description: "Спортивные матчи, счет и аналитика",
    url: "/",
    images: [{ url: "/icon.svg", alt: "РадарАрена" }],
  },
  twitter: {
    card: "summary_large_image",
    title: "РадарАрена",
    description: "Спортивные матчи, счет и аналитика",
    images: ["/icon.svg"],
  },
  icons: {
    icon: "/icon.svg",
    shortcut: "/icon.svg",
    apple: "/icon.svg",
  },
};

export default function RootLayout({ children }: { children: ReactNode }) {
  return (
    <html lang="ru">
      <body className={`${inter.variable} ${oswald.variable}`}>
        <JsonLd data={buildOrganizationSchema()} />
        <JsonLd data={buildWebSiteSchema()} />
        {children}
      </body>
    </html>
  );
}

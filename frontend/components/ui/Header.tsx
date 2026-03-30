import Link from "next/link";

export function Header() {
  return (
    <header className="site-header">
      <div className="container header-inner">
        <Link href="/" className="brand-lockup">
          <div className="brand-title font-heading">
            РАДАР<span>АРЕНА</span>
          </div>
          <div className="brand-subtitle">Редакционная аналитика матчей, форм и спортивных трендов.</div>
        </Link>
        <nav className="nav">
          <Link href="/">Главная</Link>
          <Link href="/matches">Прогнозы</Link>
          <Link href="/news">Новости</Link>
        </nav>
      </div>
    </header>
  );
}

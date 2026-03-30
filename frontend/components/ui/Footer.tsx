import Link from "next/link";

export function Footer() {
  return (
    <footer className="site-footer">
      <div className="container footer-inner">
        <div className="footer-brand font-heading">
          РАДАР<span>АРЕНА</span>
        </div>
        <p className="footer-copy">© {new Date().getFullYear()} РадарАрена. Все права защищены.</p>
        <p className="footer-note">
          Редакционный спортивный ресурс. Все материалы публикуются в информационно-аналитических целях.
        </p>
        <nav className="footer-nav">
          <Link href="/legal/disclaimer">Отказ от ответственности</Link>
          <Link href="/legal/privacy">Политика конфиденциальности</Link>
          <Link href="/legal/cookies">Cookie</Link>
        </nav>
      </div>
    </footer>
  );
}

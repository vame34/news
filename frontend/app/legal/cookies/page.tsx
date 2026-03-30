import { Footer } from "../../../components/ui/Footer";
import { Header } from "../../../components/ui/Header";

export default function CookiesPage() {
  return (
    <div className="page-shell">
      <Header />
      <main className="page">
        <section className="container section card legal-content">
          <h1>Cookie и локальное хранение</h1>
          <p>Сайт может использовать cookie и аналогичные технологии для сохранения технических настроек и измерения производительности.</p>
          <p>Вы можете отключить cookie в настройках браузера, но это может ограничить работу отдельных функций.</p>
          <p>Продолжая пользоваться сайтом, вы подтверждаете согласие с использованием обязательных технических cookie.</p>
        </section>
      </main>
      <Footer />
    </div>
  );
}

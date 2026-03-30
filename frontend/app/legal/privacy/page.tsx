import { Footer } from "../../../components/ui/Footer";
import { Header } from "../../../components/ui/Header";

export default function PrivacyPage() {
  return (
    <div className="page-shell">
      <Header />
      <main className="page">
        <section className="container section card legal-content">
          <h1>Политика конфиденциальности</h1>
          <p>Мы обрабатываем технические данные посещений: IP-адрес, user-agent, URL страницы и источник перехода.</p>
          <p>Эти данные используются для безопасности, стабильности сервиса и агрегированной аналитики рекламы.</p>
          <p>Мы не продаем персональные данные третьим лицам и не используем скрытые методы деанонимизации пользователей.</p>
        </section>
      </main>
      <Footer />
    </div>
  );
}

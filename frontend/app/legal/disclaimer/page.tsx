import { Footer } from "../../../components/ui/Footer";
import { Header } from "../../../components/ui/Header";

export default function DisclaimerPage() {
  return (
    <div className="page-shell">
      <Header />
      <main className="page">
      <section className="container section card legal-content">
        <h1>Отказ от ответственности</h1>
        <p>РадарАрена публикует редакционные спортивные материалы, статистику и аналитические обзоры.</p>
        <p>Информация предоставляется в информационных целях и не является финансовой, юридической или иной профессиональной рекомендацией.</p>
        <p>Мы используем открытые и официальные источники данных, но не гарантируем абсолютную полноту и безошибочность каждой записи.</p>
      </section>
      </main>
      <Footer />
    </div>
  );
}

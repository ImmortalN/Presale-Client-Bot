# Presale Client Bot MVP (WordPress)

## Сторінки плагіна

- **Chat** — тренування з випадковим сценарієм.
- **Results** — останній розбір розмови.
- **Settings** — API key + моделі.

## REST endpoint-и

- `POST /wp-json/training/v1/start` — генерація випадкового сценарію.
- `POST /wp-json/training/v1/chat` — відповідь AI-клієнта.
- `POST /wp-json/training/v1/evaluate` — оцінювання діалогу.

## Що виправлено

- Сценарій більше не обирається вручну: генерується автоматично.
- Додано окремі підсторінки: Chat / Results / Settings.
- Додано fallback-моделі для roleplay у Settings (список через кому).
- Додано retry/fallback не лише для `No endpoints found`, а й для `429 rate-limited`.
- Якщо AI повернув сценарій не в чистому JSON, плагін намагається витягти JSON із тексту; якщо не вдається — використовує безпечний дефолтний сценарій.

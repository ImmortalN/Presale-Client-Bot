# Presale Client Bot MVP (WordPress)

## Страницы плагина

- **Chat** — тренировка с рандомным сценарием.
- **Results** — последний разбор разговора.
- **Settings** — API key + модели.

## REST endpoints

- `POST /wp-json/training/v1/start` — генерация рандомного сценария.
- `POST /wp-json/training/v1/chat` — ответ AI-клиента.
- `POST /wp-json/training/v1/evaluate` — оценка диалога.

## Что исправлено

- Сценарий больше не выбирается вручную: генерируется автоматически.
- Добавлены отдельные подстраницы: Chat / Results / Settings.
- Добавлены fallback-модели для roleplay в Settings (список через запятую).
- Добавлен retry/fallback не только при `No endpoints found`, но и при `429 rate-limited`.
- Если AI вернул сценарий не в чистом JSON, плагин пытается извлечь JSON из текста; если не удалось — использует безопасный дефолтный сценарий.

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
- Добавлен fallback на `meta-llama/llama-3.3-70b-instruct:free`, если OpenRouter возвращает `No endpoints found` для выбранной модели.

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
Минимальный MVP для тренировки presale-диалогов прямо в WP Admin.

## Что есть

- Admin page `Presale Training`.
- REST endpoint `POST /wp-json/training/v1/chat`.
- REST endpoint `POST /wp-json/training/v1/evaluate`.
- OpenRouter integration для roleplay и evaluation.

## Установка

1. Скопируйте `presale-training-mvp.php` в `wp-content/plugins/presale-training-mvp/`.
2. Активируйте плагин в WordPress.
3. Перейдите в `Presale Training` в админке.
4. Вставьте OpenRouter API key и сохраните.
5. Тестируйте чат и кнопку `Evaluate Conversation`.

## Дефолтные модели

- Roleplay: `deepseek/deepseek-chat-v3-0324:free`
- Evaluation: `google/gemini-2.5-pro`

Можно поменять прямо в настройках страницы.

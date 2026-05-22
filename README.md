# Presale Client Bot MVP (WordPress)

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

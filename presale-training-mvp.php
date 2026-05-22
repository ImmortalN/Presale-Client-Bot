<?php
/**
 * Plugin Name: Presale Training MVP
 * Description: WP admin chat trainer with OpenRouter roleplay and evaluation.
 * Version: 0.2.2
 * Author: Team
 */

if (!defined('ABSPATH')) {
    exit;
}

class Presale_Training_MVP {
    const OPTION_API_KEY = 'presale_training_openrouter_api_key';
    const OPTION_ROLEPLAY_MODEL = 'presale_training_roleplay_model';
    const OPTION_EVAL_MODEL = 'presale_training_eval_model';
    const OPTION_LAST_RESULT = 'presale_training_last_result';
    const OPTION_ROLEPLAY_FALLBACK_MODELS = 'presale_training_roleplay_fallback_models';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_admin_pages']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    public static function register_admin_pages() {
        add_menu_page('Presale Training', 'Presale Training', 'manage_options', 'presale-training-chat', [__CLASS__, 'render_chat_page'], 'dashicons-format-chat', 56);
        add_submenu_page('presale-training-chat', 'Chat', 'Chat', 'manage_options', 'presale-training-chat', [__CLASS__, 'render_chat_page']);
        add_submenu_page('presale-training-chat', 'Results', 'Results', 'manage_options', 'presale-training-results', [__CLASS__, 'render_results_page']);
        add_submenu_page('presale-training-chat', 'Settings', 'Settings', 'manage_options', 'presale-training-settings', [__CLASS__, 'render_settings_page']);
    }

    public static function register_settings() {
        register_setting('presale_training_settings', self::OPTION_API_KEY);
        register_setting('presale_training_settings', self::OPTION_ROLEPLAY_MODEL);
        register_setting('presale_training_settings', self::OPTION_EVAL_MODEL);
        register_setting('presale_training_settings', self::OPTION_ROLEPLAY_FALLBACK_MODELS);
    }

    public static function register_rest_routes() {
        register_rest_route('training/v1', '/start', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback' => [__CLASS__, 'handle_start_request'],
        ]);
        register_rest_route('training/v1', '/chat', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback' => [__CLASS__, 'handle_chat_request'],
        ]);
        register_rest_route('training/v1', '/evaluate', [
            'methods' => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback' => [__CLASS__, 'handle_evaluate_request'],
        ]);
    }

    public static function can_manage() {
        return current_user_can('manage_options');
    }

    // ====================== CORE API ======================

    private static function do_openrouter_request($payload, $api_key) {
        $url = 'https://openrouter.ai/api/v1/chat/completions';

        $headers = [
            'Authorization'      => 'Bearer ' . $api_key,
            'Content-Type'       => 'application/json',
            'HTTP-Referer'       => home_url(),
            'X-OpenRouter-Title' => 'Presale Training Plugin',
        ];

        $response = wp_remote_post($url, [
            'headers' => $headers,
            'body'    => wp_json_encode($payload),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) {
            return ['error' => $response->get_error_message()];
        }

        $body = wp_remote_retrieve_body($response);
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode($body, true);

        if ($code !== 200) {
            $error_msg = isset($data['error']['message']) ? $data['error']['message'] : $body;
            return ['error' => "HTTP $code: $error_msg"];
        }

        if (empty($data['choices'][0]['message']['content'])) {
            return ['error' => 'Empty response from model'];
        }

        return [
            'message' => $data['choices'][0]['message']['content'],
            'model'   => $data['model'] ?? $payload['model'],
        ];
    }

    private static function openrouter_chat($payload, $with_fallback = false) {
    $api_key = get_option(self::OPTION_API_KEY, '');
    if (empty($api_key)) {
        return ['error' => 'OpenRouter API key is not configured'];
    }

    $models = [$payload['model']];
    if ($with_fallback) {
        $models = array_merge($models, self::get_roleplay_fallback_models());
    }

    foreach ($models as $model) {
        $payload_copy = $payload;
        $payload_copy['model'] = $model;
        
        // ←←← КРИТИЧНОЕ ИСПРАВЛЕНИЕ
        if (!isset($payload_copy['max_tokens'])) {
            $payload_copy['max_tokens'] = 1200;     // сильно уменьшили
        }

        $result = self::do_openrouter_request($payload_copy, $api_key);

        if (isset($result['message'])) {
            error_log("Presale Training - SUCCESS with model: $model");
            return $result;
        }

        $error = $result['error'] ?? 'unknown error';
        error_log("Presale Training - FAILED model '$model': $error");
    }

    return ['error' => 'All models failed. Check error log.'];
}

    // ====================== HANDLERS ======================

    public static function handle_start_request() {
        $scenario_prompt = "Generate one realistic presale customer scenario for Crocoblock (Elementor/JetEngine based products).\n"
            . "Return ONLY valid JSON with these exact keys: customer_type, mood, use_case, concerns, first_message.\n"
            . "No explanations, no markdown, no code blocks.";

        $response = self::openrouter_chat([
            'model' => self::get_roleplay_model(),
            'messages' => [
                ['role' => 'user', 'content' => $scenario_prompt]
            ],
            'temperature' => 0.85,
            'max_tokens'  => 700,
        ], true);

        if (isset($response['error'])) {
            return new WP_REST_Response(['error' => $response['error']], 500);
        }

        $scenario = self::extract_scenario($response['message']);

        return rest_ensure_response([
            'scenario' => $scenario,
            'raw'      => $response['message']
        ]);
    }

    public static function handle_chat_request(WP_REST_Request $request) {
        $messages = $request->get_param('messages');
        $scenario = $request->get_param('scenario');

        if (!is_array($messages)) {
            return new WP_REST_Response(['error' => 'messages must be an array'], 400);
        }

        $scenario_text = is_array($scenario) 
            ? wp_json_encode($scenario, JSON_UNESCAPED_UNICODE) 
            : (string) $scenario;

        $system_prompt = "You are acting as a realistic potential customer interested in Crocoblock products.\n\n"
            . "Your behavior:\n"
            . "- behave naturally\n"
            . "- ask follow-up questions\n"
            . "- sometimes misunderstand explanations\n"
            . "- occasionally compare with competitors\n"
            . "- do not instantly agree\n"
            . "- avoid sounding like AI\n"
            . "- sometimes give vague answers\n"
            . "- express doubts naturally\n\n"
            . "Current scenario:\n" . $scenario_text;

        $payload_messages = array_merge([
            ['role' => 'system', 'content' => $system_prompt],
        ], $messages);

        $response = self::openrouter_chat([
            'model' => self::get_roleplay_model(),
            'messages' => $payload_messages,
            'temperature' => 0.8,
        ], true);

        return rest_ensure_response($response);
    }

    public static function handle_evaluate_request(WP_REST_Request $request) {
        $messages = $request->get_param('messages');

        if (!is_array($messages) || empty($messages)) {
            return new WP_REST_Response(['error' => 'messages must be a non-empty array'], 400);
        }

        $eval_prompt = "You are evaluating a presale agent.\n\n"
            . "Analyze the conversation and provide scores (1-10) for:\n"
            . "- clarity, empathy, discovery, objection handling, sales communication.\n"
            . "Then give strengths, weaknesses, missed opportunities and suggestions.";

        $response = self::openrouter_chat([
            'model' => self::get_eval_model(),
            'messages' => [
                ['role' => 'system', 'content' => $eval_prompt],
                ['role' => 'user', 'content' => wp_json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
            ],
            'temperature' => 0.3,
        ], false);

        if (isset($response['message'])) {
            update_option(self::OPTION_LAST_RESULT, [
                'created_at' => current_time('mysql'),
                'feedback'   => $response['message'],
                'messages'   => $messages,
            ], false);
        }

        return rest_ensure_response($response);
    }

    // ====================== HELPERS ======================

    private static function extract_scenario($text) {
        $scenario = self::try_parse_json_object($text);
        if (is_array($scenario)) return $scenario;

        if (preg_match('/\{.*\}/s', (string)$text, $matches)) {
            $scenario = self::try_parse_json_object($matches[0]);
            if (is_array($scenario)) return $scenario;
        }

        // Fallback
        return [
            'customer_type' => 'beginner WordPress user',
            'mood'          => 'curious but cautious',
            'use_case'      => 'wants to build a marketplace website',
            'concerns'      => 'worried about complexity and budget',
            'first_message' => 'Hi! I want to build a marketplace site with Crocoblock, but I\'m not sure how hard it will be. Can you help?',
        ];
    }

    private static function try_parse_json_object($text) {
        $text = trim((string)$text);
        $decoded = json_decode($text, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function get_roleplay_fallback_models() {
    $raw = (string) get_option(self::OPTION_ROLEPLAY_FALLBACK_MODELS, 
        'google/gemini-2.5-flash,openai/gpt-4o-mini,qwen/qwen2.5-32b-instruct:free');
    
    $models = array_filter(array_map('trim', explode(',', $raw)));
    return array_values(array_unique($models));
}

    private static function get_roleplay_model() {
        return get_option(self::OPTION_ROLEPLAY_MODEL, 'google/gemini-2.5-flash');
    }

    private static function get_eval_model() {
        return get_option(self::OPTION_EVAL_MODEL, 'google/gemini-2.5-pro');
    }

    public static function render_settings_page() {
        $api_key = esc_attr(get_option(self::OPTION_API_KEY, ''));
        $roleplay_model = esc_attr(self::get_roleplay_model());
        $eval_model = esc_attr(self::get_eval_model());
        $fallback_models = esc_attr(get_option(self::OPTION_ROLEPLAY_FALLBACK_MODELS, 'mistralai/mistral-small-3.1-24b-instruct:free,google/gemini-2.5-flash,openai/gpt-4.1-mini'));
        ?>
        <div class="wrap">
            <h1>Presale Training — Settings</h1>
            <form method="post" action="options.php" style="max-width: 820px; margin-top: 16px;">
                <?php settings_fields('presale_training_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_API_KEY); ?>">OpenRouter API Key</label></th>
                        <td><input type="password" id="<?php echo esc_attr(self::OPTION_API_KEY); ?>" name="<?php echo esc_attr(self::OPTION_API_KEY); ?>" value="<?php echo $api_key; ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_ROLEPLAY_MODEL); ?>">Roleplay Model</label></th>
                        <td><input type="text" id="<?php echo esc_attr(self::OPTION_ROLEPLAY_MODEL); ?>" name="<?php echo esc_attr(self::OPTION_ROLEPLAY_MODEL); ?>" value="<?php echo $roleplay_model; ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_ROLEPLAY_FALLBACK_MODELS); ?>">Roleplay Fallback Models</label></th>
                        <td>
                            <input type="text" id="<?php echo esc_attr(self::OPTION_ROLEPLAY_FALLBACK_MODELS); ?>" name="<?php echo esc_attr(self::OPTION_ROLEPLAY_FALLBACK_MODELS); ?>" value="<?php echo $fallback_models; ?>" class="regular-text" />
                            <p class="description">Comma-separated models used when roleplay model has no endpoint or is rate-limited.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_EVAL_MODEL); ?>">Evaluation Model</label></th>
                        <td><input type="text" id="<?php echo esc_attr(self::OPTION_EVAL_MODEL); ?>" name="<?php echo esc_attr(self::OPTION_EVAL_MODEL); ?>" value="<?php echo $eval_model; ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }

    public static function render_results_page() {
    global $wpdb;
    $results = $wpdb->get_results("
        SELECT * FROM {$wpdb->options} 
        WHERE option_name LIKE 'presale_training_result_%' 
        ORDER BY option_id DESC 
        LIMIT 30
    ");

    ?>
    <div class="wrap">
        <h1>Presale Training — Результаты</h1>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Дата</th>
                    <th>Пользователь</th>
                    <th>Общий балл</th>
                    <th>Clarity</th>
                    <th>Empathy</th>
                    <th>Discovery</th>
                    <th>Objection</th>
                    <th>Sales</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($results)) : ?>
                    <tr><td colspan="9">Пока нет оценённых разговоров.</td></tr>
                <?php else : ?>
                    <?php foreach ($results as $row) : 
                        $data = unserialize($row->option_value); 
                        $feedback = $data['feedback'] ?? '';
                        // Парсим баллы (простой способ)
                        preg_match_all('/(\w+).*?(\d+)\/10/', $feedback, $matches);
                        $scores = array_combine($matches[1] ?? [], $matches[2] ?? []);
                    ?>
                    <tr>
                        <td><?php echo esc_html($data['created_at']); ?></td>
                        <td><?php echo wp_get_current_user()->display_name; ?></td>
                        <td><strong><?php echo array_sum($scores) ?? '—'; ?>/50</strong></td>
                        <td><?php echo $scores['Clarity'] ?? '—'; ?></td>
                        <td><?php echo $scores['Empathy'] ?? '—'; ?></td>
                        <td><?php echo $scores['Discovery'] ?? '—'; ?></td>
                        <td><?php echo $scores['Objection'] ?? '—'; ?></td>
                        <td><?php echo $scores['Sales'] ?? '—'; ?></td>
                        <td>
                            <button class="button view-details" data-feedback="<?php echo esc_attr($feedback); ?>">Подробно</button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <script>
    document.querySelectorAll('.view-details').forEach(btn => {
        btn.addEventListener('click', () => {
            const feedback = btn.dataset.feedback;
            alert(feedback); // позже можно сделать красивый модальный
        });
    });
    </script>
    <?php
}

public static function render_chat_page() {
    ?>
    <div class="wrap">
        <h1>Presale Training — Chat</h1>
        
        <div id="presale-training-app" style="max-width: 1100px; margin: 20px 0; background: #fff; border: 1px solid #c3c4c7; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">

            <!-- Header -->
            <div style="padding: 16px 20px; background: #f6f7f7; border-bottom: 1px solid #c3c4c7; display: flex; justify-content: space-between; align-items: center;">
                <button class="button" id="new-scenario-btn">Новый случайный сценарий</button>
                <div id="scenario-box" style="flex: 1; margin: 0 20px; font-size: 14px; line-height: 1.5;"></div>
            </div>

            <!-- Chat Area -->
            <div style="display: flex; height: 620px;">
                
                <!-- Messages -->
                <div id="messages" style="
                    flex: 1; 
                    padding: 20px; 
                    overflow-y: auto; 
                    background: #f9f9f9; 
                    border-right: 1px solid #c3c4c7;
                    display: flex;
                    flex-direction: column;
                    gap: 16px;
                "></div>

                <!-- Sidebar (optional: scenario info) -->
                <div style="width: 280px; padding: 20px; background: #fff; border-left: 1px solid #c3c4c7; overflow-y: auto;">
                    <h3 style="margin-top:0;">Инструкция агенту</h3>
                    <small>Старайся:</small>
                    <ul style="font-size:13px; line-height:1.4;">
                        <li>Задавать уточняющие вопросы</li>
                        <li>Работать с возражениями</li>
                        <li>Связывать фичи с болью клиента</li>
                        <li>Закрывать на следующий шаг</li>
                    </ul>
                </div>
            </div>

            <!-- Input Area -->
            <div style="padding: 16px 20px; background: #f6f7f7; border-top: 1px solid #c3c4c7;">
                <textarea id="agent-input" style="width:100%; min-height: 110px; resize: vertical; padding: 12px; font-size: 15px;" 
                    placeholder="Напишите ответ как presale-агент..."></textarea>
                
                <div style="margin-top: 12px; display: flex; gap: 10px;">
                    <button class="button button-primary" id="send-btn" style="padding: 8px 20px;">Отправить</button>
                    <button class="button" id="evaluate-btn">Оценить разговор</button>
                    <button class="button" id="reset-btn">Сбросить чат</button>
                </div>
            </div>

            <!-- Evaluation -->
            <div id="evaluation" style="padding: 20px; background: #f0f0f1; border-top: 1px solid #c3c4c7; white-space: pre-wrap; display: none;"></div>
        </div>
    </div>

    <script>
    (function() {
    const inputEl = document.getElementById('agent-input');
    const evalEl = document.getElementById('evaluation');
    const scenarioBoxEl = document.getElementById('scenario-box');
    
    const state = {
        messages: [],
        scenario: null,           // теперь храним объект, а не текст
        isLoading: false
    };
    const messagesEl = document.getElementById('messages');
        
        function renderMessages() {
            messagesEl.innerHTML = state.messages.map(m => {
                const isCustomer = m.role === 'assistant';
                return `
                    <div style="max-width: 85%; align-self: ${isCustomer ? 'flex-start' : 'flex-end'};">
                        <div style="font-size: 13px; color: #666; margin-bottom: 4px;">
                            ${isCustomer ? '👤 Клиент' : '👨‍💼 Вы'}
                        </div>
                        <div style="background: ${isCustomer ? '#ffffff' : '#2271b1'}; 
                                    color: ${isCustomer ? '#000' : '#fff'}; 
                                    padding: 14px 18px; 
                                    border-radius: 12px; 
                                    font-size: 15.5px; 
                                    line-height: 1.45;">
                            ${escapeHtml(m.content)}
                        </div>
                    </div>
                `;
            }).join('');
            
            messagesEl.scrollTop = messagesEl.scrollHeight;
        }

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, t => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        }[t]));
    }

    function setLoading(isLoading) {
        state.isLoading = isLoading;
        const sendBtn = document.getElementById('send-btn');
        const newBtn = document.getElementById('new-scenario-btn');
        const evalBtn = document.getElementById('evaluate-btn');
        
        sendBtn.disabled = isLoading;
        newBtn.disabled = isLoading;
        evalBtn.disabled = isLoading;
        
        inputEl.disabled = isLoading;
    }

    async function api(path, payload = {}) {
        try {
            const res = await fetch('<?php echo esc_url_raw(rest_url('training/v1/')); ?>' + path, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'
                },
                body: JSON.stringify(payload)
            });

            if (!res.ok) {
                throw new Error(`HTTP error! status: ${res.status}`);
            }

            return await res.json();
        } catch (err) {
            console.error(err);
            return { error: err.message || 'Ошибка соединения с сервером' };
        }
    }

    async function loadScenario() {
        setLoading(true);
        scenarioBoxEl.innerHTML = '<em>Генерируем новый сценарий...</em>';
        
        try {
            const data = await api('start');

            if (data.error) {
                scenarioBoxEl.innerHTML = `<span style="color:red;">Ошибка: ${escapeHtml(data.error)}</span>`;
                return;
            }

            if (data.scenario) {
                state.scenario = data.scenario;
                
                // Красивое отображение сценария
                scenarioBoxEl.innerHTML = `
                    <strong>Сценарий:</strong><br>
                    <strong>Тип клиента:</strong> ${escapeHtml(data.scenario.customer_type)}<br>
                    <strong>Настроение:</strong> ${escapeHtml(data.scenario.mood)}<br>
                    <strong>Кейс:</strong> ${escapeHtml(data.scenario.use_case)}<br>
                    <strong>Главные опасения:</strong> ${escapeHtml(data.scenario.concerns)}
                `;

                // Начинаем диалог с первого сообщения клиента
                state.messages = [{
                    role: 'assistant',
                    content: data.scenario.first_message || "Здравствуйте, я хотел бы узнать про Crocoblock..."
                }];
                
                renderMessages();
            }
        } catch (e) {
            scenarioBoxEl.innerHTML = '<span style="color:red;">Не удалось загрузить сценарий</span>';
        } finally {
            setLoading(false);
        }
    }

    // === Обработчики ===
    document.getElementById('send-btn').addEventListener('click', sendMessage);
    
    // Отправка по Enter (Shift+Enter = новая строка)
    inputEl.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });

    async function sendMessage() {
        const text = inputEl.value.trim();
        if (!text || state.isLoading) return;

        // Добавляем сообщение агента
        state.messages.push({ role: 'user', content: text });
        inputEl.value = '';
        renderMessages();
        setLoading(true);

        try {
            const data = await api('chat', { 
                messages: state.messages, 
                scenario: state.scenario 
            });

            if (data.message) {
                state.messages.push({ role: 'assistant', content: data.message });
            } else if (data.error) {
                state.messages.push({ 
                    role: 'assistant', 
                    content: `[Ошибка] ${data.error}` 
                });
            }
        } catch (err) {
            state.messages.push({ 
                role: 'assistant', 
                content: '[Ошибка соединения]' 
            });
        } finally {
            renderMessages();
            setLoading(false);
        }
    }

    document.getElementById('evaluate-btn').addEventListener('click', async () => {
        if (state.messages.length < 3) {
            evalEl.textContent = 'Слишком короткий диалог для оценки.';
            return;
        }

        setLoading(true);
        evalEl.textContent = 'Анализирую разговор...';

        const data = await api('evaluate', { messages: state.messages });
        
        if (data.message) {
            evalEl.textContent = data.message;
        } else {
            evalEl.textContent = data.error ? `Ошибка: ${data.error}` : JSON.stringify(data, null, 2);
        }
        
        setLoading(false);
    });

    document.getElementById('reset-btn').addEventListener('click', () => {
        state.messages = [];
        evalEl.textContent = '';
        renderMessages();
    });

    document.getElementById('new-scenario-btn').addEventListener('click', () => {
        state.messages = [];
        evalEl.textContent = '';
        renderMessages();
        loadScenario();
    });

    // Запуск
    loadScenario();

})();
</script>
        <?php
    }
}

Presale_Training_MVP::init();

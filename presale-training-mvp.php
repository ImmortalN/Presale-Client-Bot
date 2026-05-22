<?php
/**
 * Plugin Name: Presale Training MVP
 * Description: WP admin chat trainer with OpenRouter roleplay and evaluation.
 * Version: 0.2.3
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

    private static function do_openrouter_request($payload, $api_key) {
        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => home_url(),
                'X-OpenRouter-Title' => 'Presale Training Plugin',
            ],
            'body' => wp_json_encode($payload),
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

        $content = $data['choices'][0]['message']['content'] ?? '';
        if ($content === '') {
            return ['error' => 'Empty response from model'];
        }

        return [
            'message' => $content,
            'model' => $data['model'] ?? $payload['model'],
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
            if (!isset($payload_copy['max_tokens'])) {
                $payload_copy['max_tokens'] = 1200;
            }

            $result = self::do_openrouter_request($payload_copy, $api_key);
            if (isset($result['message'])) {
                return $result;
            }
        }

        return ['error' => 'All models failed. Check error log.'];
    }

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
            'max_tokens' => 700,
        ], true);

        if (isset($response['error'])) {
            return new WP_REST_Response(['error' => $response['error']], 500);
        }

        $scenario = self::extract_scenario($response['message']);

        return rest_ensure_response([
            'scenario' => $scenario,
            'raw' => $response['message']
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
            . "Always reply in English only.\n\n"
            . "Always reply in English only.\n\n"
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
                'feedback' => $response['message'],
                'messages' => $messages,
            ], false);
        }

        return rest_ensure_response($response);
    }

    private static function extract_scenario($text) {
        $scenario = self::try_parse_json_object($text);
        if (is_array($scenario)) {
            return $scenario;
        }

        if (preg_match('/\{.*\}/s', (string) $text, $matches)) {
            $scenario = self::try_parse_json_object($matches[0]);
            if (is_array($scenario)) {
                return $scenario;
            }
        }

        return [
            'customer_type' => 'beginner WordPress user',
            'mood' => 'curious but cautious',
            'use_case' => 'wants to build a marketplace website',
            'concerns' => 'worried about complexity and budget',
            'first_message' => 'Hi! I want to build a marketplace site with Crocoblock, but I am not sure how hard it will be. Can you help?',
        ];
    }

    private static function try_parse_json_object($text) {
        $decoded = json_decode(trim((string) $text), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function get_roleplay_fallback_models() {
        $raw = (string) get_option(self::OPTION_ROLEPLAY_FALLBACK_MODELS, 'google/gemini-2.5-flash,openai/gpt-4o-mini,qwen/qwen2.5-32b-instruct:free');
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
        $fallback_models = esc_attr(get_option(self::OPTION_ROLEPLAY_FALLBACK_MODELS, 'google/gemini-2.5-flash,openai/gpt-4o-mini,qwen/qwen2.5-32b-instruct:free'));
        ?>
        <div class="wrap">
            <h1>Presale Training — Налаштування</h1>
            <form method="post" action="options.php" style="max-width: 900px; margin-top: 16px;">
                <?php settings_fields('presale_training_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_API_KEY); ?>">Ключ OpenRouter API</label></th>
                        <td><input type="password" id="<?php echo esc_attr(self::OPTION_API_KEY); ?>" name="<?php echo esc_attr(self::OPTION_API_KEY); ?>" value="<?php echo $api_key; ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_ROLEPLAY_MODEL); ?>">Модель roleplay</label></th>
                        <td><input type="text" id="<?php echo esc_attr(self::OPTION_ROLEPLAY_MODEL); ?>" name="<?php echo esc_attr(self::OPTION_ROLEPLAY_MODEL); ?>" value="<?php echo $roleplay_model; ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_ROLEPLAY_FALLBACK_MODELS); ?>">Резервні моделі roleplay</label></th>
                        <td>
                            <input type="text" id="<?php echo esc_attr(self::OPTION_ROLEPLAY_FALLBACK_MODELS); ?>" name="<?php echo esc_attr(self::OPTION_ROLEPLAY_FALLBACK_MODELS); ?>" value="<?php echo $fallback_models; ?>" class="regular-text" />
                            <p class="description">Список моделей через кому, якщо основна модель недоступна.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_EVAL_MODEL); ?>">Модель оцінювання</label></th>
                        <td><input type="text" id="<?php echo esc_attr(self::OPTION_EVAL_MODEL); ?>" name="<?php echo esc_attr(self::OPTION_EVAL_MODEL); ?>" value="<?php echo $eval_model; ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button('Зберегти налаштування'); ?>
            </form>
        </div>
        <?php
    }

    public static function render_results_page() {
        $last_result = get_option(self::OPTION_LAST_RESULT, []);
        ?>
        <div class="wrap">
            <h1>Presale Training — Результати</h1>
            <?php if (empty($last_result)) : ?>
                <p>Поки немає оцінених діалогів.</p>
            <?php else : ?>
                <p><strong>Дата:</strong> <?php echo esc_html($last_result['created_at'] ?? '—'); ?></p>
                <h2>Зворотний зв’язок</h2>
                <pre style="background:#fff; padding:12px; border:1px solid #dcdcde; white-space:pre-wrap;"><?php echo esc_html($last_result['feedback'] ?? ''); ?></pre>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_chat_page() {
        ?>
        <div class="wrap" style="height: calc(100vh - 70px);">
            <h1>Presale Training — Чат</h1>

            <div id="presale-training-app" style="display:flex; gap:16px; height: calc(100vh - 160px); min-height:650px;">
                <div style="flex: 0 0 36%; background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px; display:flex; flex-direction:column;">
                    <button class="button" id="new-scenario-btn" style="margin-bottom:12px;">Новий випадковий сценарій</button>
                    <h3 style="margin:0 0 8px 0;">Сценарій</h3>
                    <div id="scenario-box" style="flex:1; overflow:auto; background:#f6f7f7; border:1px solid #dcdcde; border-radius:6px; padding:12px; line-height:1.5;"></div>
                    <h3 style="margin:12px 0 8px 0;">Оцінювання</h3>
                    <pre id="evaluation" style="white-space:pre-wrap; max-height:34%; overflow:auto; background:#f0f0f1; border:1px solid #dcdcde; border-radius:6px; padding:12px;"></pre>
                </div>
                <div style="flex:1; background:#fff; border:1px solid #c3c4c7; border-radius:8px; display:flex; flex-direction:column; min-width:0;">
                    <div id="messages" style="flex:1; overflow:auto; padding:20px; background:#f9f9f9; border-bottom:1px solid #c3c4c7; display:flex; flex-direction:column; gap:16px;"></div>
                    <div style="padding:16px;">
                        <textarea id="agent-input" style="width:100%; min-height:110px; resize:vertical; padding:12px; font-size:15px;" placeholder="Введіть відповідь як presale-агент..."></textarea>
                        <div style="margin-top: 12px; display: flex; gap: 10px;">
                            <button class="button button-primary" id="send-btn">Відправити</button>
                            <button class="button" id="evaluate-btn">Оцінити розмову</button>
                            <button class="button" id="reset-btn">Скинути чат</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <script>
        (function() {
            const inputEl = document.getElementById('agent-input');
            const evalEl = document.getElementById('evaluation');
            const scenarioBoxEl = document.getElementById('scenario-box');
            const messagesEl = document.getElementById('messages');

            const state = { messages: [], scenario: null, isLoading: false };

            function renderMessages() {
                messagesEl.innerHTML = state.messages.map(m => {
                    const isCustomer = m.role === 'assistant';
                    return `
                        <div style="max-width: 86%; align-self: ${isCustomer ? 'flex-start' : 'flex-end'};">
                            <div style="font-size: 13px; color: #666; margin-bottom: 4px;">${isCustomer ? '👤 Клієнт' : '👨‍💼 Ви'}</div>
                            <div style="background: ${isCustomer ? '#ffffff' : '#2271b1'}; color: ${isCustomer ? '#000' : '#fff'}; padding: 14px 18px; border-radius: 12px; font-size: 15px; line-height: 1.45;">
                                ${escapeHtml(m.content)}
                            </div>
                        </div>`;
                }).join('');
                messagesEl.scrollTop = messagesEl.scrollHeight;
            }

            function escapeHtml(str) {
                return String(str || '').replace(/[&<>"']/g, t => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[t]));
            }

            function setLoading(isLoading) {
                state.isLoading = isLoading;
                document.getElementById('send-btn').disabled = isLoading;
                document.getElementById('new-scenario-btn').disabled = isLoading;
                document.getElementById('evaluate-btn').disabled = isLoading;
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
                    return await res.json();
                } catch (err) {
                    return { error: err.message || 'Помилка з’єднання із сервером' };
                }
            }

            async function loadScenario() {
                setLoading(true);
                scenarioBoxEl.innerHTML = '<em>Генеруємо новий сценарій...</em>';
                const data = await api('start');

                if (data.error) {
                    scenarioBoxEl.innerHTML = `<span style="color:#b32d2e;">Помилка: ${escapeHtml(data.error)}</span>`;
                    setLoading(false);
                    return;
                }

                if (data.scenario) {
                    state.scenario = data.scenario;
                    scenarioBoxEl.innerHTML = `
                        <strong>Тип клієнта:</strong> ${escapeHtml(data.scenario.customer_type)}<br>
                        <strong>Настрій:</strong> ${escapeHtml(data.scenario.mood)}<br>
                        <strong>Кейс:</strong> ${escapeHtml(data.scenario.use_case)}<br>
                        <strong>Ключові побоювання:</strong> ${escapeHtml(data.scenario.concerns)}
                    `;

                    state.messages = [{
                        role: 'assistant',
                        content: data.scenario.first_message || 'Hi! Can you help me choose the right Crocoblock setup?'
                    }];
                    renderMessages();
                }

                setLoading(false);
            }

            async function sendMessage() {
                const text = inputEl.value.trim();
                if (!text || state.isLoading) return;

                state.messages.push({ role: 'user', content: text });
                inputEl.value = '';
                renderMessages();
                setLoading(true);

                const data = await api('chat', { messages: state.messages, scenario: state.scenario });
                if (data.message) {
                    state.messages.push({ role: 'assistant', content: data.message });
                } else {
                    state.messages.push({ role: 'assistant', content: `[Error] ${data.error || 'Unknown error'}` });
                }

                renderMessages();
                setLoading(false);
            }

            document.getElementById('send-btn').addEventListener('click', sendMessage);
            inputEl.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });

            document.getElementById('evaluate-btn').addEventListener('click', async () => {
                if (state.messages.length < 3) {
                    evalEl.textContent = 'Діалог закороткий для оцінювання.';
                    return;
                }

                setLoading(true);
                evalEl.textContent = 'Аналізуємо розмову...';
                const data = await api('evaluate', { messages: state.messages });
                evalEl.textContent = data.message ? data.message : `Помилка: ${data.error || 'Unknown error'}`;
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

            loadScenario();
        })();
        </script>
        <?php
    }
}

Presale_Training_MVP::init();

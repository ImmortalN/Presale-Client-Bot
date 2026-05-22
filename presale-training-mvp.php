<?php
/**
 * Plugin Name: Presale Training MVP
 * Description: WP admin chat trainer with OpenRouter roleplay and evaluation.
 * Version: 0.2.0
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

    public static function handle_start_request() {
        $scenario_prompt = "Generate one realistic presale customer scenario for Crocoblock in JSON format with keys: customer_type, mood, use_case, concerns, first_message. "
            . "Keep it concise and practical for roleplay.";

        $response = self::openrouter_chat([
            'model' => self::get_roleplay_model(),
            'messages' => [
                ['role' => 'system', 'content' => $scenario_prompt],
            ],
            'temperature' => 0.9,
        ], true);

        if ($response instanceof WP_REST_Response) {
            return $response;
        }

        $scenario_text = $response['message'];
        $scenario = self::extract_scenario($scenario_text);

        return rest_ensure_response([
            'scenario_raw' => $scenario_text,
            'scenario' => $scenario,
        ]);
    }

    public static function handle_chat_request(WP_REST_Request $request) {
        $messages = $request->get_param('messages');
        $scenario = (string) $request->get_param('scenario');

        if (!is_array($messages)) {
            return new WP_REST_Response(['error' => 'messages must be an array'], 400);
        }

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
            . "You are NOT support.\n"
            . "Your goal is to realistically simulate a presale conversation.\n\n"
            . "Current scenario:\n" . sanitize_textarea_field($scenario);

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

        $eval_prompt = "You are evaluating a support presale agent.\n\n"
            . "Analyze the conversation and provide:\n"
            . "- clarity score (1-10)\n"
            . "- empathy score (1-10)\n"
            . "- discovery score (1-10)\n"
            . "- objection handling score (1-10)\n"
            . "- sales communication score (1-10)\n\n"
            . "Then provide:\n"
            . "- strengths\n"
            . "- weaknesses\n"
            . "- missed opportunities\n"
            . "- improvement suggestions\n\n"
            . "Be constructive and realistic.";

        $response = self::openrouter_chat([
            'model' => self::get_eval_model(),
            'messages' => [
                ['role' => 'system', 'content' => $eval_prompt],
                ['role' => 'user', 'content' => wp_json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
            ],
            'temperature' => 0.3,
        ], true);

        if (!($response instanceof WP_REST_Response) && !empty($response['message'])) {
            update_option(self::OPTION_LAST_RESULT, [
                'created_at' => current_time('mysql'),
                'feedback' => $response['message'],
                'messages' => $messages,
            ], false);
        }

        return rest_ensure_response($response);
    }

    // Замени свой метод openrouter_chat на этот
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
        $payload['model'] = $model;
        $result = self::do_openrouter_request($payload, $api_key);

        if (isset($result['message'])) {
            return $result; // Успех
        }
        // Если ошибка, пробуем следующий по списку (fallback)
    }

    return ['error' => 'All models failed or returned no data.'];
}

// В методе handle_start_request измени обработку ответа:
public static function handle_start_request() {
    $scenario_prompt = "Generate a JSON-only response for a presale scenario with these keys: customer_type, mood, use_case, concerns, first_message. Do not add any text before or after the JSON.";

    $response = self::openrouter_chat([
        'model' => self::get_roleplay_model(),
        'messages' => [['role' => 'system', 'content' => $scenario_prompt]],
        'temperature' => 0.9,
    ], true);

    if (isset($response['error'])) {
        return new WP_REST_Response($response, 500);
    }

    $scenario = self::extract_scenario($response['message']);
    return rest_ensure_response(['scenario' => $scenario]);
}

    private static function is_retryable_error($result) {
        if (!($result instanceof WP_REST_Response)) {
            return false;
        }

        $status = $result->get_status();
        $data = $result->get_data();
        $message = self::extract_error_message($data);

        if ($status === 429) {
            return true;
        }

        if (stripos($message, 'No endpoints found') !== false) {
            return true;
        }

        if (stripos($message, 'rate-limited') !== false || stripos($message, 'rate limited') !== false) {
            return true;
        }

        return false;
    }

    private static function extract_error_message($data) {
        if (isset($data['error']['error']['message'])) {
            return (string) $data['error']['error']['message'];
        }

        if (isset($data['error']['message'])) {
            return (string) $data['error']['message'];
        }

        if (isset($data['message'])) {
            return (string) $data['message'];
        }

        return '';
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
            'first_message' => 'Hi! I want to build a marketplace site, but I am not sure how hard it will be. Can you help?',
        ];
    }

    private static function try_parse_json_object($text) {
        $text = trim((string) $text);
        $decoded = json_decode($text, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return null;
    }

    private static function get_roleplay_fallback_models() {
        $raw = (string) get_option(self::OPTION_ROLEPLAY_FALLBACK_MODELS, 'mistralai/mistral-small-3.1-24b-instruct:free,google/gemini-2.5-flash,openai/gpt-4.1-mini');
        $models = array_filter(array_map('trim', explode(',', $raw)));
        return array_values(array_unique($models));
    }

    private static function get_roleplay_model() {
        return get_option(self::OPTION_ROLEPLAY_MODEL, 'meta-llama/llama-3.3-70b-instruct:free');
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
        $result = get_option(self::OPTION_LAST_RESULT, []);
        ?>
        <div class="wrap">
            <h1>Presale Training — Results</h1>
            <?php if (empty($result)) : ?>
                <p>No results yet. Finish a chat and click Evaluate.</p>
            <?php else : ?>
                <p><strong>Last evaluation:</strong> <?php echo esc_html($result['created_at']); ?></p>
                <h2>Feedback</h2>
                <pre style="white-space: pre-wrap; background: #fff; border: 1px solid #dcdcde; padding: 12px;"><?php echo esc_html($result['feedback']); ?></pre>
                <h2>Conversation</h2>
                <div style="background: #fff; border: 1px solid #dcdcde; padding: 12px;">
                    <?php foreach (($result['messages'] ?? []) as $message) : ?>
                        <p><strong><?php echo esc_html($message['role'] === 'assistant' ? 'Customer' : 'Agent'); ?>:</strong> <?php echo esc_html($message['content'] ?? ''); ?></p>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_chat_page() {
        ?>
        <div class="wrap">
            <h1>Presale Training — Chat</h1>
            <div id="presale-training-app" style="max-width: 900px; background: #fff; border: 1px solid #dcdcde; padding: 16px;">
                <p><button class="button" id="new-scenario-btn">New Random Scenario</button></p>
                <div id="scenario-box" style="background:#f6f7f7;padding:12px;border:1px solid #dcdcde;margin-bottom:12px;"></div>
                <div id="messages" style="border:1px solid #dcdcde;min-height:220px;padding:12px;margin:12px 0;background:#f9f9f9;"></div>
                <textarea id="agent-input" style="width:100%;min-height:80px;" placeholder="Type your answer as presale agent..."></textarea>
                <p>
                    <button class="button button-primary" id="send-btn">Send</button>
                    <button class="button" id="evaluate-btn">Evaluate Conversation</button>
                    <button class="button" id="reset-btn">Reset Chat</button>
                </p>
                <pre id="evaluation" style="white-space: pre-wrap; background:#f6f7f7; padding:12px;"></pre>
            </div>
        </div>
        <script>
        (function() {
            const messagesEl = document.getElementById('messages');
            const inputEl = document.getElementById('agent-input');
            const evalEl = document.getElementById('evaluation');
            const scenarioBoxEl = document.getElementById('scenario-box');
            const state = { messages: [], scenarioText: '' };

            function render() {
                messagesEl.innerHTML = state.messages.map(m => {
                    const label = m.role === 'assistant' ? 'Customer' : 'Agent';
                    return `<p><strong>${label}:</strong> ${escapeHtml(m.content)}</p>`;
                }).join('');
            }

            function escapeHtml(str) {
                return String(str || '').replace(/[&<>"']/g, t => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[t]));
            }

            async function api(path, payload) {
                const res = await fetch('<?php echo esc_url_raw(rest_url('training/v1/')); ?>' + path, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'
                    },
                    body: JSON.stringify(payload || {})
                });
                return await res.json();
            }

            async function loadScenario() {
                scenarioBoxEl.textContent = 'Loading random scenario...';
                const data = await api('start');
                if (data.scenario) {
                    const s = data.scenario;
                    state.scenarioText = `customer_type: ${s.customer_type || ''}\nmood: ${s.mood || ''}\nuse_case: ${s.use_case || ''}\nconcerns: ${(s.concerns || '')}`;
                    scenarioBoxEl.textContent = state.scenarioText;
                    if (s.first_message) {
                        state.messages = [{ role: 'assistant', content: s.first_message }];
                        render();
                    }
                } else {
                    state.scenarioText = data.scenario_raw || 'Failed to generate scenario';
                    scenarioBoxEl.textContent = state.scenarioText;
                }
            }

            document.getElementById('send-btn').addEventListener('click', async () => {
                const text = inputEl.value.trim();
                if (!text) return;
                state.messages.push({ role: 'user', content: text });
                inputEl.value = '';
                render();

                const data = await api('chat', { messages: state.messages, scenario: state.scenarioText });
                if (data.message) {
                    state.messages.push({ role: 'assistant', content: data.message });
                } else {
                    state.messages.push({ role: 'assistant', content: '[Error] ' + JSON.stringify(data.error || data) });
                }
                render();
            });

            document.getElementById('evaluate-btn').addEventListener('click', async () => {
                const data = await api('evaluate', { messages: state.messages });
                evalEl.textContent = data.message || JSON.stringify(data.error || data, null, 2);
            });

            document.getElementById('reset-btn').addEventListener('click', () => {
                state.messages = [];
                evalEl.textContent = '';
                render();
            });

            document.getElementById('new-scenario-btn').addEventListener('click', async () => {
                state.messages = [];
                evalEl.textContent = '';
                render();
                await loadScenario();
            });

            loadScenario();
        })();
        </script>
        <?php
    }
}

Presale_Training_MVP::init();

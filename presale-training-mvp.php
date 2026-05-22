<?php
/**
 * Plugin Name: Presale Training MVP
 * Description: Evening MVP: WP admin chat page + REST endpoint + OpenRouter roleplay/evaluation.
 * Version: 0.1.0
 * Author: Team
 */

if (!defined('ABSPATH')) {
    exit;
}

class Presale_Training_MVP {
    const OPTION_API_KEY = 'presale_training_openrouter_api_key';
    const OPTION_ROLEPLAY_MODEL = 'presale_training_roleplay_model';
    const OPTION_EVAL_MODEL = 'presale_training_eval_model';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_admin_page']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    public static function register_admin_page() {
        add_menu_page(
            'Presale Training',
            'Presale Training',
            'manage_options',
            'presale-training-mvp',
            [__CLASS__, 'render_admin_page'],
            'dashicons-format-chat',
            56
        );
    }

    public static function register_settings() {
        register_setting('presale_training_settings', self::OPTION_API_KEY);
        register_setting('presale_training_settings', self::OPTION_ROLEPLAY_MODEL);
        register_setting('presale_training_settings', self::OPTION_EVAL_MODEL);
    }

    public static function register_rest_routes() {
        register_rest_route('training/v1', '/chat', [
            'methods' => 'POST',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'callback' => [__CLASS__, 'handle_chat_request'],
        ]);

        register_rest_route('training/v1', '/evaluate', [
            'methods' => 'POST',
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
            'callback' => [__CLASS__, 'handle_evaluate_request'],
        ]);
    }

    public static function handle_chat_request(WP_REST_Request $request) {
        $messages = $request->get_param('messages');
        $scenario = $request->get_param('scenario');

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
            . "Your goal is to realistically simulate a presale conversation.";

        if (!empty($scenario)) {
            $system_prompt .= "\n\nCurrent scenario:\n" . sanitize_textarea_field($scenario);
        }

        $payload_messages = array_merge([
            [
                'role' => 'system',
                'content' => $system_prompt,
            ],
        ], $messages);

        $response = self::openrouter_chat([
            'model' => self::get_roleplay_model(),
            'messages' => $payload_messages,
            'temperature' => 0.8,
        ]);

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
        ]);

        return rest_ensure_response($response);
    }

    private static function openrouter_chat($payload) {
        $api_key = get_option(self::OPTION_API_KEY, '');

        if (empty($api_key)) {
            return new WP_REST_Response(['error' => 'OpenRouter API key is not configured'], 400);
        }

        $result = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'HTTP-Referer' => home_url(),
                'X-Title' => 'Presale Training MVP',
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 60,
        ]);

        if (is_wp_error($result)) {
            return new WP_REST_Response(['error' => $result->get_error_message()], 500);
        }

        $status = wp_remote_retrieve_response_code($result);
        $body = json_decode(wp_remote_retrieve_body($result), true);

        if ($status >= 400) {
            return new WP_REST_Response(['error' => $body], $status);
        }

        $content = $body['choices'][0]['message']['content'] ?? '';

        return [
            'message' => $content,
            'raw' => $body,
        ];
    }

    private static function get_roleplay_model() {
        return get_option(self::OPTION_ROLEPLAY_MODEL, 'deepseek/deepseek-chat-v3-0324:free');
    }

    private static function get_eval_model() {
        return get_option(self::OPTION_EVAL_MODEL, 'google/gemini-2.5-pro');
    }

    public static function render_admin_page() {
        $api_key = esc_attr(get_option(self::OPTION_API_KEY, ''));
        $roleplay_model = esc_attr(self::get_roleplay_model());
        $eval_model = esc_attr(self::get_eval_model());
        ?>
        <div class="wrap">
            <h1>Presale Training MVP</h1>

            <form method="post" action="options.php" style="max-width: 820px; margin-bottom: 24px;">
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
                        <th scope="row"><label for="<?php echo esc_attr(self::OPTION_EVAL_MODEL); ?>">Evaluation Model</label></th>
                        <td><input type="text" id="<?php echo esc_attr(self::OPTION_EVAL_MODEL); ?>" name="<?php echo esc_attr(self::OPTION_EVAL_MODEL); ?>" value="<?php echo $eval_model; ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>

            <div id="presale-training-app" style="max-width: 820px; background: #fff; border: 1px solid #dcdcde; padding: 16px;">
                <h2>Chat Simulator</h2>
                <p>Scenario</p>
                <textarea id="scenario" style="width:100%;min-height:90px;" placeholder="beginner WordPress user...">beginner WordPress user
wants marketplace website
worried about complexity
comparing with Toolset
budget-sensitive</textarea>
                <div id="messages" style="border:1px solid #dcdcde;min-height:220px;padding:12px;margin:12px 0;background:#f9f9f9;"></div>
                <textarea id="agent-input" style="width:100%;min-height:80px;" placeholder="Type your answer as presale agent..."></textarea>
                <p>
                    <button class="button button-primary" id="send-btn">Send</button>
                    <button class="button" id="evaluate-btn">Evaluate Conversation</button>
                    <button class="button" id="reset-btn">Reset</button>
                </p>
                <pre id="evaluation" style="white-space: pre-wrap; background:#f6f7f7; padding:12px;"></pre>
            </div>
        </div>
        <script>
        (function() {
            const messagesEl = document.getElementById('messages');
            const inputEl = document.getElementById('agent-input');
            const scenarioEl = document.getElementById('scenario');
            const evalEl = document.getElementById('evaluation');
            const state = { messages: [] };

            function render() {
                messagesEl.innerHTML = state.messages.map(m => {
                    const label = m.role === 'assistant' ? 'Customer' : 'Agent';
                    return `<p><strong>${label}:</strong> ${escapeHtml(m.content)}</p>`;
                }).join('');
            }

            function escapeHtml(str) {
                return str.replace(/[&<>"']/g, t => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[t]));
            }

            async function api(path, payload) {
                const res = await fetch('<?php echo esc_url_raw(rest_url('training/v1/')); ?>' + path, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-WP-Nonce': '<?php echo esc_js(wp_create_nonce('wp_rest')); ?>'
                    },
                    body: JSON.stringify(payload)
                });
                return await res.json();
            }

            document.getElementById('send-btn').addEventListener('click', async () => {
                const text = inputEl.value.trim();
                if (!text) return;
                state.messages.push({ role: 'user', content: text });
                inputEl.value = '';
                render();

                const data = await api('chat', { messages: state.messages, scenario: scenarioEl.value });
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
        })();
        </script>
        <?php
    }
}

Presale_Training_MVP::init();

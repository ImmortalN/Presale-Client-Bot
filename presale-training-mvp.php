<?php
/**
 * Plugin Name: Presale Training MVP
 * Description: WP admin chat trainer with OpenRouter roleplay and evaluation.
 * Version: 0.3.1
 * Author: Team
 */

if (!defined('ABSPATH')) {
    exit;
}

class Presale_Training_MVP {

    const OPTION_API_KEY                 = 'presale_training_openrouter_api_key';
    const OPTION_ROLEPLAY_MODEL          = 'presale_training_roleplay_model';
    const OPTION_EVAL_MODEL              = 'presale_training_eval_model';
    const OPTION_ROLEPLAY_FALLBACK_MODELS = 'presale_training_roleplay_fallback_models';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_admin_pages']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }

    public static function activate() {
        self::create_table();
    }

    private static function create_table() {
        global $wpdb;
        $table   = $wpdb->prefix . 'presale_training_results';
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            agent_name varchar(100) NOT NULL,
            scenario_summary text,
            overall_score tinyint unsigned DEFAULT NULL,
            discovery_score tinyint unsigned DEFAULT NULL,
            architecture_score tinyint unsigned DEFAULT NULL,
            commercial_score tinyint unsigned DEFAULT NULL,
            tone_score tinyint unsigned DEFAULT NULL,
            feedback longtext,
            messages longtext,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
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
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'handle_start_request'],
        ]);
        register_rest_route('training/v1', '/chat', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'handle_chat_request'],
        ]);
        register_rest_route('training/v1', '/evaluate', [
            'methods'             => 'POST',
            'permission_callback' => [__CLASS__, 'can_manage'],
            'callback'            => [__CLASS__, 'handle_evaluate_request'],
        ]);
    }

    public static function can_manage() {
        return current_user_can('manage_options');
    }

    private static function do_openrouter_request($payload, $api_key) {
        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'headers' => [
                'Authorization'     => 'Bearer ' . $api_key,
                'Content-Type'      => 'application/json',
                'HTTP-Referer'      => home_url(),
                'X-OpenRouter-Title'=> 'Presale Training Plugin',
            ],
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

        $content = $data['choices'][0]['message']['content'] ?? '';
        if ($content === '') {
            return ['error' => 'Empty response from model'];
        }

        return [
            'message' => $content,
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
            if (!isset($payload_copy['max_tokens'])) {
                $payload_copy['max_tokens'] = 400;
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
            'model'       => self::get_roleplay_model(),
            'messages'    => [['role' => 'user', 'content' => $scenario_prompt]],
            'temperature' => 0.85,
            'max_tokens'  => 700,
        ], true);

        if (isset($response['error'])) {
            return new WP_REST_Response(['error' => $response['error']], 500);
        }

        $scenario = self::extract_scenario($response['message']);

        return rest_ensure_response([
            'scenario' => $scenario,
            'raw'      => $response['message'],
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

        $system_prompt = "You are a realistic potential customer interested in Crocoblock products (JetEngine, JetFormBuilder, JetBooking etc.).\n\n"
            . "Rules of behavior:\n"
            . "- Keep every reply SHORT (1–3 sentences max, preferably under 40 words).\n"
            . "- Speak naturally, like a real person in a live chat. No corporate language.\n"
            . "- Sometimes be a bit vague, ask clarifying questions, express mild doubts.\n"
            . "- Occasionally mention competitors (Elementor Pro, ACF, custom code, other builders) but not aggressively.\n"
            . "- Do NOT instantly agree or buy. Make the agent work a little.\n"
            . "- After 5–7 exchanges, if the agent has given good value, start leaning toward “I need to think / can you send me a summary / follow-up?”.\n"
            . "- Never sound like an AI.\n"
            . "- Always reply in English only.\n\n"
            . "Current scenario:\n" . $scenario_text;

        $payload_messages = array_merge([
            ['role' => 'system', 'content' => $system_prompt],
        ], $messages);

        $response = self::openrouter_chat([
            'model'       => self::get_roleplay_model(),
            'messages'    => $payload_messages,
            'temperature' => 0.8,
        ], true);

        return rest_ensure_response($response);
    }

    public static function handle_evaluate_request(WP_REST_Request $request) {
        $messages   = $request->get_param('messages');
        $scenario   = $request->get_param('scenario');
        $agent_name = sanitize_text_field($request->get_param('agent_name') ?: '');

        if (!is_array($messages) || empty($messages)) {
            return new WP_REST_Response(['error' => 'messages must be a non-empty array'], 400);
        }

        if (empty($agent_name)) {
            return new WP_REST_Response(['error' => 'Будь ласка, вкажіть свій нік / ім’я агента'], 400);
        }

        $eval_prompt = "You are an expert AI Coach and Evaluator for Crocoblock Presale Agents. Your purpose is to analyze chat conversations or tickets between a support agent and a prospective client, evaluate the agent's performance based on Crocoblock's proprietary presale methodology, and provide highly structured, constructive feedback.

### CORE PRESALE PHILOSOPHY
The agent must act as a Solution Architect and Consultant, not a static information directory. The goal is to guide clients toward the right choice, demonstrate the business value of the ecosystem, and confidently lead the conversation toward a conversion using logic, empathy, and clear architectural solutions.

### RULES THE AGENT MUST FOLLOW:
1. **Prioritize Discovery First**: The agent must ask clear, open-ended clarifying questions to understand the client's business goals, target workflows, or reference designs before recommending specific products.
2. **Apply \"Outcome vs Feature\" (Handling Feature Gaps)**: If a specific feature is missing out-of-the-box (e.g., an auction system), the agent must never give a flat \"no\". Instead, focus on the client's final objective. Explain how the ecosystem can solve the problem by using JetEngine as the core database backbone (Custom Post Types, Meta Fields, Listings, and Relations) combined with other tools (like JetFormBuilder for input validation or third-party solutions).
3. **Recommend Power Pairs & Bundles**: Group plugins into logical business scenarios tailored to the client's industry niche (e.g., combining JetEngine + JetBooking + JetSmartFilters for a rental platform). Build clear functional value before displaying prices.
4. **Personalization & Social Proof**: Tailor every argument to the client's exact use case. Reference external validation when helpful, such as customer feedback trends on Trustpilot or Google reviews, to establish corporate trust.
5. **Implement the 7-Element Conversion Checklist**: In pitches, structural proposals, or follow-up interactions, the agent should actively integrate the following elements:
   - **Upsell / Cross-sell**: Compare separate plugin expenses with the All-Inclusive plan, demonstrating clear financial advantages (e.g., showing the mathematical breakdown of separate costs vs. the bundle).
   - **Clear Pricing & Totals**: Display explicit calculations and absolute numbers so the client clearly understands the savings.
   - **Subtle FOMO**: Gently remind the client about active seasonal promotional timelines or standard intervals between discounts, avoiding aggressive or high-pressure tactics.
   - **Direct Call to Action (CTA)**: Provide precise, direct links to pricing tables, checkouts, or specific cart layouts rather than general homepages.
   - **Risk Reversal**: Highlight the 30-day money-back guarantee to lower purchase friction and remove the fear of making a wrong choice.
   - **Support Team Value**: Emphasize access to 24/7 dedicated assistance, positioning it as an expert team backing their development journey.
   - **Defined Next Steps**: Maintain momentum by defining clear follow-up actions, such as offering a real-time technical consultation or booking a brief call.

### EVALUATION SCORING CRITERIA
Evaluate the interaction on a scale from 0% to 100% across these key dimensions:
- **Requirements Gathering & Discovery**: Did the agent investigate the project depth before selling?
- **Solution Architecture & Problem Solving**: Did the agent apply the \"Outcome vs Feature\" approach and connect plugins logically?
- **Value Building & Commercial Clarity**: Did the agent present clear math, compare separate purchases to the All-Inclusive option, and include risk reversal (refund policy, support)?
- **Conversion Checklist Implementation**: How many of the 7 required conversion elements were naturally incorporated?
- **Tone, Clarity & Formatting**: Was the tone warm, helpful, and natural? Were complex concepts simplified without overwhelming text dumps or heavy technical jargon?

### EVALUATION OUTPUT FORMAT
Analyze the provided text carefully and generate the review using this exact layout:

**1. General Overview**
[A brief, professional summary of the agent's interaction with the client]

**2. Key Strengths**
[Bullet points highlighting where the agent executed presale methodologies perfectly]

**3. Opportunities for Improvement**
[Constructive points identifying missed elements, missing checklist items, or technical jargon that could be simplified]

**4. Scoring Matrix**
- Discovery & Requirements Gathering: [X/100%]
- Solution Architecture: [X/100%]
- Commercial Pitch & Conversion Checklist: [X/100%]
- Tone, Simplicity & Clarity: [X/100%]
- **Overall Presale Score**: [Calculated Average %]

**5. Corrected Response Blueprint**
[Provide a rewritten version of the agent's final or primary message showing how it should look when incorporating all missing checklist elements and best practices seamlessly]";

        $response = self::openrouter_chat([
            'model'       => self::get_eval_model(),
            'messages'    => [
                ['role' => 'system', 'content' => $eval_prompt],
                ['role' => 'user', 'content' => wp_json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)],
            ],
            'temperature' => 0.3,
        ], false);

        if (isset($response['message'])) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'presale_training_results';

            $feedback_text     = $response['message'];
            $discovery_score   = self::extract_score($feedback_text, 'Discovery');
            $architecture_score= self::extract_score($feedback_text, 'Solution Architecture');
            $commercial_score  = self::extract_score($feedback_text, 'Commercial Pitch');
            $tone_score        = self::extract_score($feedback_text, 'Tone');
            $overall_score     = self::extract_score($feedback_text, 'Overall Presale Score');

            $scenario_summary = is_array($scenario)
                ? wp_json_encode($scenario, JSON_UNESCAPED_UNICODE)
                : (string) $scenario;

            $wpdb->insert($table_name, [
                'user_id'            => get_current_user_id(),
                'agent_name'         => $agent_name,
                'scenario_summary'   => $scenario_summary,
                'overall_score'      => $overall_score,
                'discovery_score'    => $discovery_score,
                'architecture_score' => $architecture_score,
                'commercial_score'   => $commercial_score,
                'tone_score'         => $tone_score,
                'feedback'           => $feedback_text,
                'messages'           => wp_json_encode($messages, JSON_UNESCAPED_UNICODE),
                'created_at'         => current_time('mysql'),
            ], ['%d', '%s', '%s', '%d', '%d', '%d', '%d', '%d', '%s', '%s', '%s']);

            // Повертаємо скори окремо, щоб фронтенд міг їх красиво показати
            $response['scores'] = [
                'overall'      => $overall_score,
                'discovery'    => $discovery_score,
                'architecture' => $architecture_score,
                'commercial'   => $commercial_score,
                'tone'         => $tone_score,
            ];
        }

        return rest_ensure_response($response);
    }

    private static function extract_score($text, $key) {
        $patterns = [
            '/' . preg_quote($key, '/') . '[^0-9]{0,40}?(\d{1,3})\s*%/ui',
            '/' . preg_quote($key, '/') . '[^0-9]{0,40}?(\d{1,3})\s*\/\s*100/ui',
            '/' . preg_quote($key, '/') . '.*?[\[\(]?\s*(\d{1,3})\s*[\]\)]?/ui',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches)) {
                return min(100, max(0, intval($matches[1])));
            }
        }
        return null;
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
            'mood'          => 'curious but cautious',
            'use_case'      => 'wants to build a marketplace website',
            'concerns'      => 'worried about complexity and budget',
            'first_message' => 'Hi! I want to build a marketplace site with Crocoblock, but I am not sure how hard it will be. Can you help?',
        ];
    }

    private static function try_parse_json_object($text) {
        $decoded = json_decode(trim((string) $text), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function get_roleplay_fallback_models() {
        $raw    = (string) get_option(self::OPTION_ROLEPLAY_FALLBACK_MODELS, 'google/gemini-2.5-flash,openai/gpt-4o-mini,qwen/qwen2.5-32b-instruct:free');
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
        $api_key         = esc_attr(get_option(self::OPTION_API_KEY, ''));
        $roleplay_model  = esc_attr(self::get_roleplay_model());
        $eval_model      = esc_attr(self::get_eval_model());
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
        global $wpdb;
        $table_name = $wpdb->prefix . 'presale_training_results';

        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") !== $table_name) {
            echo '<div class="wrap"><h1>Presale Training — Результати</h1>';
            echo '<div class="notice notice-warning"><p>Таблиця результатів ще не створена. Деактивуйте і знову активуйте плагін.</p></div></div>';
            return;
        }

        $results = $wpdb->get_results("SELECT * FROM $table_name ORDER BY id DESC LIMIT 100");
        ?>
        <div class="wrap">
            <h1>Presale Training — Результати</h1>
            <p class="description">Останні 100 оцінених діалогів. Натисніть «Деталі», щоб побачити повний фідбек і сценарій.</p>

            <?php if (empty($results)) : ?>
                <p>Поки немає збережених результатів оцінювання.</p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped" style="margin-top: 16px;">
                    <thead>
                        <tr>
                            <th style="width:50px;">ID</th>
                            <th style="width:140px;">Дата</th>
                            <th>Агент</th>
                            <th style="width:90px; text-align:center;">Overall</th>
                            <th style="width:90px; text-align:center;">Discovery</th>
                            <th style="width:90px; text-align:center;">Architecture</th>
                            <th style="width:90px; text-align:center;">Commercial</th>
                            <th style="width:80px; text-align:center;">Tone</th>
                            <th style="width:100px;">Дії</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $row) :
                            $overall = $row->overall_score !== null ? intval($row->overall_score) : null;
                            $score_class = '';
                            if ($overall !== null) {
                                if ($overall >= 80)      $score_class = 'color:#00a32a;font-weight:600;';
                                elseif ($overall >= 60) $score_class = 'color:#dba617;font-weight:600;';
                                else                   $score_class = 'color:#d63638;font-weight:600;';
                            }
                        ?>
                            <tr>
                                <td><?php echo esc_html($row->id); ?></td>
                                <td><?php echo esc_html($row->created_at); ?></td>
                                <td><?php echo esc_html($row->agent_name); ?></td>
                                <td style="text-align:center; <?php echo $score_class; ?>">
                                    <?php echo $overall !== null ? $overall . '%' : '—'; ?>
                                </td>
                                <td style="text-align:center;"><?php echo $row->discovery_score !== null ? esc_html($row->discovery_score) . '%' : '—'; ?></td>
                                <td style="text-align:center;"><?php echo $row->architecture_score !== null ? esc_html($row->architecture_score) . '%' : '—'; ?></td>
                                <td style="text-align:center;"><?php echo $row->commercial_score !== null ? esc_html($row->commercial_score) . '%' : '—'; ?></td>
                                <td style="text-align:center;"><?php echo $row->tone_score !== null ? esc_html($row->tone_score) . '%' : '—'; ?></td>
                                <td>
                                    <button type="button" class="button button-small toggle-feedback" data-target="feedback-<?php echo intval($row->id); ?>">
                                        Деталі
                                    </button>
                                </td>
                            </tr>
                            <tr id="feedback-<?php echo intval($row->id); ?>" class="feedback-row" style="display:none; background:#f9f9f9;">
                                <td colspan="9">
                                    <div style="padding:16px 12px;">
                                        <?php if (!empty($row->scenario_summary)) : ?>
                                            <h4 style="margin:0 0 8px;">Сценарій</h4>
                                            <pre style="background:#fff; padding:12px; border:1px solid #dcdcde; white-space:pre-wrap; margin-bottom:16px; font-size:13px;"><?php
                                                $scenario = json_decode($row->scenario_summary, true);
                                                if (is_array($scenario)) {
                                                    echo esc_html(
                                                        "Тип: " . ($scenario['customer_type'] ?? '—') . "\n" .
                                                        "Настрій: " . ($scenario['mood'] ?? '—') . "\n" .
                                                        "Кейс: " . ($scenario['use_case'] ?? '—') . "\n" .
                                                        "Побоювання: " . ($scenario['concerns'] ?? '—')
                                                    );
                                                } else {
                                                    echo esc_html($row->scenario_summary);
                                                }
                                            ?></pre>
                                        <?php endif; ?>

                                        <h4 style="margin:0 0 8px;">Повний зворотний зв’язок</h4>
                                        <pre style="background:#fff; padding:14px; border:1px solid #dcdcde; white-space:pre-wrap; max-height:420px; overflow:auto; font-size:13.5px; line-height:1.45;"><?php echo esc_html($row->feedback); ?></pre>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <script>
                    document.addEventListener('DOMContentLoaded', function() {
                        document.querySelectorAll('.toggle-feedback').forEach(function(btn) {
                            btn.addEventListener('click', function() {
                                const targetId = this.getAttribute('data-target');
                                const row = document.getElementById(targetId);
                                if (!row) return;

                                const isHidden = row.style.display === 'none' || row.style.display === '';
                                document.querySelectorAll('.feedback-row').forEach(r => r.style.display = 'none');
                                document.querySelectorAll('.toggle-feedback').forEach(b => b.textContent = 'Деталі');

                                if (isHidden) {
                                    row.style.display = 'table-row';
                                    this.textContent = 'Сховати';
                                }
                            });
                        });
                    });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function render_chat_page() {
        ?>
        <div class="wrap" style="height: calc(100vh - 70px);">
            <h1>Presale Training — Чат</h1>

            <!-- Поле з ніком агента -->
            <div style="margin-bottom: 16px; display:flex; align-items:center; gap:12px;">
                <label for="agent-name-input" style="font-weight:600; white-space:nowrap;">Ваш нік / Ім’я:</label>
                <input type="text" id="agent-name-input" class="regular-text" placeholder="Наприклад: Olena / Alex / Support_Nick" style="max-width:280px;" />
                <span id="agent-name-hint" style="color:#b32d2e; font-size:13px; display:none;">← обов’язково вкажіть нік перед оцінкою</span>
            </div>

            <div id="presale-training-app" style="display:flex; gap:16px; height: calc(100vh - 210px); min-height:600px;">
                <!-- Ліва колонка -->
                <div style="flex: 0 0 36%; background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px; display:flex; flex-direction:column;">
                    <button class="button" id="new-scenario-btn" style="margin-bottom:12px;">Новий випадковий сценарій</button>
                    <h3 style="margin:0 0 8px 0;">Сценарій</h3>
                    <div id="scenario-box" style="flex:1; overflow:auto; background:#f6f7f7; border:1px solid #dcdcde; border-radius:6px; padding:12px; line-height:1.5;"></div>
                    
                    <h3 style="margin:12px 0 8px 0;">Оцінювання</h3>
                    <div id="evaluation" style="max-height:38%; overflow:auto; background:#f0f0f1; border:1px solid #dcdcde; border-radius:6px; padding:12px; font-size:13.5px;"></div>
                </div>

                <!-- Права колонка — чат -->
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
            const inputEl        = document.getElementById('agent-input');
            const evalEl         = document.getElementById('evaluation');
            const scenarioBoxEl  = document.getElementById('scenario-box');
            const messagesEl     = document.getElementById('messages');
            const agentNameInput = document.getElementById('agent-name-input');
            const agentNameHint  = document.getElementById('agent-name-hint');

            // Відновлюємо нік з localStorage
            const savedName = localStorage.getItem('presale_agent_name') || '';
            if (savedName) {
                agentNameInput.value = savedName;
            }

            agentNameInput.addEventListener('input', function() {
                localStorage.setItem('presale_agent_name', this.value.trim());
                agentNameHint.style.display = 'none';
            });

            const state = { messages: [], scenario: null, isLoading: false };

            function renderMessages() {
                messagesEl.innerHTML = state.messages.map(m => {
                    const isCustomer = m.role === 'assistant';
                    return `
                        <div style="max-width: 86%; align-self: ${isCustomer ? 'flex-start' : 'flex-end'};">
                            <div style="font-size: 13px; color: #666; margin-bottom: 4px;">${isCustomer ? 'Клієнт' : 'Ви'}</div>
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

            function renderScores(scores, feedback) {
                if (!scores) {
                    evalEl.innerHTML = `<pre style="white-space:pre-wrap; margin:0;">${escapeHtml(feedback)}</pre>`;
                    return;
                }

                const overall = scores.overall;
                let overallColor = '#666';
                if (overall !== null) {
                    if (overall >= 80) overallColor = '#00a32a';
                    else if (overall >= 60) overallColor = '#dba617';
                    else overallColor = '#d63638';
                }

                const scoreRow = (label, value) => {
                    const val = value !== null && value !== undefined ? value + '%' : '—';
                    return `<div style="display:flex; justify-content:space-between; padding:4px 0; border-bottom:1px solid #e0e0e0;">
                        <span>${label}</span>
                        <strong>${val}</strong>
                    </div>`;
                };

                evalEl.innerHTML = `
                    <div style="background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:14px; margin-bottom:12px;">
                        <div style="text-align:center; margin-bottom:12px;">
                            <div style="font-size:13px; color:#666; margin-bottom:4px;">Загальний бал</div>
                            <div style="font-size:32px; font-weight:700; color:${overallColor};">
                                ${overall !== null ? overall + '%' : '—'}
                            </div>
                        </div>
                        ${scoreRow('Discovery', scores.discovery)}
                        ${scoreRow('Architecture', scores.architecture)}
                        ${scoreRow('Commercial', scores.commercial)}
                        ${scoreRow('Tone', scores.tone)}
                    </div>
                    <details open>
                        <summary style="cursor:pointer; font-weight:600; margin-bottom:8px;">Повний фідбек</summary>
                        <pre style="white-space:pre-wrap; margin:0; font-size:13px; line-height:1.45;">${escapeHtml(feedback)}</pre>
                    </details>
                `;
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
                evalEl.innerHTML = '';

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
                const agentName = agentNameInput.value.trim();
                if (!agentName) {
                    agentNameHint.style.display = 'inline';
                    agentNameInput.focus();
                    return;
                }

                if (state.messages.length < 3) {
                    evalEl.innerHTML = '<span style="color:#b32d2e;">Діалог закороткий для оцінювання.</span>';
                    return;
                }

                setLoading(true);
                evalEl.innerHTML = '<em>Аналізуємо розмову...</em>';

                const data = await api('evaluate', {
                    messages: state.messages,
                    scenario: state.scenario,
                    agent_name: agentName
                });

                if (data.message) {
                    renderScores(data.scores || null, data.message);
                } else {
                    evalEl.innerHTML = `<span style="color:#b32d2e;">Помилка: ${escapeHtml(data.error || 'Unknown error')}</span>`;
                }

                setLoading(false);
            });

            document.getElementById('reset-btn').addEventListener('click', () => {
                state.messages = [];
                evalEl.innerHTML = '';
                renderMessages();
            });

            document.getElementById('new-scenario-btn').addEventListener('click', () => {
                state.messages = [];
                evalEl.innerHTML = '';
                renderMessages();
                loadScenario();
            });

            loadScenario();
        })();
        </script>
        <?php
    }
}

register_activation_hook(__FILE__, ['Presale_Training_MVP', 'activate']);
Presale_Training_MVP::init();

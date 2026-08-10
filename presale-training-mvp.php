<?php
/**
 * Plugin Name: Presale Training MVP
 * Description: WP admin chat trainer with OpenRouter roleplay and evaluation.
 * Version: 0.4.0
 * Author: Immortal
 */

if (!defined('ABSPATH')) {
    exit;
}

class Presale_Training_MVP {

    const OPTION_API_KEY                  = 'presale_training_openrouter_api_key';
    const OPTION_ROLEPLAY_MODEL           = 'presale_training_roleplay_model';
    const OPTION_EVAL_MODEL               = 'presale_training_eval_model';
    const OPTION_ROLEPLAY_FALLBACK_MODELS = 'presale_training_roleplay_fallback_models';

    /** Max client (assistant) messages before client "disappears" */
    const MAX_CLIENT_MESSAGES = 5;

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'register_admin_pages']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
        add_shortcode('presale_training', [__CLASS__, 'render_shortcode']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_frontend_assets']);
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
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'handle_start_request'],
        ]);
        register_rest_route('training/v1', '/chat', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'handle_chat_request'],
        ]);
        register_rest_route('training/v1', '/evaluate', [
            'methods'             => 'POST',
            'permission_callback' => '__return_true',
            'callback'            => [__CLASS__, 'handle_evaluate_request'],
        ]);
    }

    public static function can_manage() {
        return current_user_can('manage_options');
    }

    private static function do_openrouter_request($payload, $api_key) {
        $response = wp_remote_post('https://openrouter.ai/api/v1/chat/completions', [
            'headers' => [
                'Authorization'      => 'Bearer ' . $api_key,
                'Content-Type'       => 'application/json',
                'HTTP-Referer'       => home_url(),
                'X-OpenRouter-Title' => 'Presale Training Plugin',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 90,
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

        $last_error = 'All models failed. Check error log.';
        foreach ($models as $model) {
            $payload_copy = $payload;
            $payload_copy['model'] = $model;
            if (!isset($payload_copy['max_tokens'])) {
                $payload_copy['max_tokens'] = 600;
            }

            $result = self::do_openrouter_request($payload_copy, $api_key);
            if (isset($result['message'])) {
                return $result;
            }
            if (isset($result['error'])) {
                $last_error = $result['error'];
            }
        }

        return ['error' => $last_error];
    }

    public static function handle_start_request() {
        $scenario_prompt = "Generate ONE realistic presale customer scenario for Crocoblock (JetEngine, JetFormBuilder, JetBooking, JetSmartFilters, All-Inclusive plan, etc.).\n\n"
            . "The customer should have a concrete project and natural reasons to discuss PRICING, plans (single plugins vs All-Inclusive), value, licensing, or comparisons — not only pure feature questions.\n\n"
            . "Return ONLY valid JSON with these exact keys:\n"
            . "- customer_type (string)\n"
            . "- mood (string)\n"
            . "- use_case (string, specific project)\n"
            . "- concerns (string or array: budget, complexity, licensing, competitors, timeline…)\n"
            . "- first_message (string: short, natural, 1–2 sentences, in English; should open room for discovery AND later commercial talk)\n\n"
            . "No explanations, no markdown, no code blocks.";

        $response = self::openrouter_chat([
            'model'       => self::get_roleplay_model(),
            'messages'    => [['role' => 'user', 'content' => $scenario_prompt]],
            'temperature' => 0.9,
            'max_tokens'  => 400,
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

        // Count existing client messages (role = assistant)
        $client_messages = 0;
        foreach ($messages as $m) {
            if (isset($m['role']) && $m['role'] === 'assistant') {
                $client_messages++;
            }
        }

        // After 5 client messages the client "disappears" — agent must write follow-up
        if ($client_messages >= self::MAX_CLIENT_MESSAGES) {
            return rest_ensure_response([
                'message'        => null,
                'system_notice'  => 'Клієнт перестав відповідати. Напишіть фолоуап: коротко нагадайте про себе, підсумуйте те, що обговорювали, і запропонуйте наступний крок (лінк на прайси / консультацію).',
                'force_followup' => true,
            ]);
        }

        $scenario_text = is_array($scenario)
            ? wp_json_encode($scenario, JSON_UNESCAPED_UNICODE)
            : (string) $scenario;

        $system_prompt = "You are a REAL potential customer considering Crocoblock products (JetEngine, JetFormBuilder, JetBooking, JetSmartFilters, All-Inclusive subscription, etc.).\n\n"
            . "CRITICAL ROLE RULES (never break them):\n"
            . "- You are ONLY the customer. NEVER reply as a support agent, Crocoblock employee, or salesperson.\n"
            . "- NEVER offer solutions, recommend plugins, give pricing advice, or sound helpful like support.\n"
            . "- NEVER start sentences with \"I can help\", \"Let me recommend\", \"Based on your needs\" — that is agent language.\n"
            . "- Speak as a real person who is buying / evaluating, not as an AI.\n\n"
            . "Reply style:\n"
            . "- Keep every reply SHORT: 1–3 sentences, preferably under 45 words.\n"
            . "- Always finish your sentence completely.\n"
            . "- Natural live-chat English only.\n"
            . "- Be mildly skeptical or cautious; do not buy instantly.\n"
            . "- Sometimes mention budget, price comparison, All-Inclusive vs single plugins, licensing, refund, competitors (Elementor Pro, ACF, custom code), or timeline.\n"
            . "- Ask concrete questions that let the agent talk about value, pricing math, bundles, FOMO, guarantee, or next steps.\n"
            . "- Vary your questions: features + commercial angles (cost, plan choice, worth it?, multi-site, promo).\n\n"
            . "Current scenario (stay consistent with it):\n" . $scenario_text;

        $payload_messages = array_merge([
            ['role' => 'system', 'content' => $system_prompt],
        ], $messages);

        $response = self::openrouter_chat([
            'model'       => self::get_roleplay_model(),
            'messages'    => $payload_messages,
            'temperature' => 0.75,
            'max_tokens'  => 220,
        ], true);

        // Light post-filter: if model slipped into agent role, reject and force retry-friendly error
        if (isset($response['message'])) {
            $msg_lower = strtolower($response['message']);
            $agent_phrases = [
                'i can help you',
                'let me recommend',
                'based on your needs',
                'i would suggest',
                'our all-inclusive',
                'you can purchase',
                'here is the link',
                'as a support',
                'from crocoblock',
            ];
            foreach ($agent_phrases as $phrase) {
                if (strpos($msg_lower, $phrase) !== false) {
                    return rest_ensure_response([
                        'error' => 'Role slip detected — please retry client reply.',
                    ]);
                }
            }
        }

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

        $eval_prompt = "You are an expert AI Coach and Evaluator for Crocoblock Presale Agents. Analyze the chat between a support agent and a prospective client. Evaluate only the AGENT messages (role \"user\" in the JSON) against Crocoblock presale methodology.

### CORE PRESALE PHILOSOPHY
The agent must act as a Solution Architect and Consultant, not a static information directory. Guide the client, show business value of the ecosystem, and lead toward conversion with logic, empathy, and clear architecture.

### RULES THE AGENT MUST FOLLOW
1. Discovery first: open-ended clarifying questions about goals, workflows, niche before heavy product pitch.
2. Outcome vs Feature: never flat \"no\"; map missing features to JetEngine + other tools solutions.
3. Power pairs & bundles: group plugins by business scenario; build value before price.
4. Personalization & social proof when natural.
5. 7-Element Conversion Checklist (use when commercial moment appears):
   - Upsell / Cross-sell (All-Inclusive vs separate)
   - Clear pricing & totals (math)
   - Subtle FOMO (promo timing)
   - Direct CTA / links
   - Risk reversal (30-day money-back)
   - Support team value
   - Defined next steps

### SCORING (0–100 integers only)
- Discovery & Requirements Gathering
- Solution Architecture
- Commercial Pitch & Conversion Checklist
- Tone, Simplicity & Clarity
- Overall Presale Score = average of the four above (round to nearest integer)

### OUTPUT FORMAT — STRICT
Use EXACTLY this layout (English). Scores must appear as \"NN%\" on the same line as the label so they can be parsed.

**1. General Overview**
[2–4 sentences]

**2. Key Strengths**
- ...

**3. Opportunities for Improvement**
- ...

**4. Scoring Matrix**
- Discovery & Requirements Gathering: XX%
- Solution Architecture: XX%
- Commercial Pitch & Conversion Checklist: XX%
- Tone, Simplicity & Clarity: XX%
- **Overall Presale Score**: XX%

**5. Corrected Response Blueprint**
[Rewritten example of a strong agent reply that includes missing checklist elements]";

        $user_content = "SCENARIO:\n" . (is_array($scenario) ? wp_json_encode($scenario, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : (string) $scenario)
            . "\n\nCHAT MESSAGES (assistant = client, user = agent):\n"
            . wp_json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $response = self::openrouter_chat([
            'model'       => self::get_eval_model(),
            'messages'    => [
                ['role' => 'system', 'content' => $eval_prompt],
                ['role' => 'user', 'content' => $user_content],
            ],
            'temperature' => 0.25,
            'max_tokens'  => 1800,
        ], false);

        if (isset($response['message'])) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'presale_training_results';

            $feedback_text      = $response['message'];
            $discovery_score    = self::extract_score($feedback_text, 'Discovery');
            $architecture_score = self::extract_score($feedback_text, 'Solution Architecture');
            $commercial_score   = self::extract_score($feedback_text, 'Commercial Pitch');
            $tone_score         = self::extract_score($feedback_text, 'Tone');
            $overall_score      = self::extract_score($feedback_text, 'Overall Presale Score');

            // Fallback: average of available scores if overall missing
            if ($overall_score === null) {
                $parts = array_filter([$discovery_score, $architecture_score, $commercial_score, $tone_score], function ($v) {
                    return $v !== null;
                });
                if (count($parts) > 0) {
                    $overall_score = (int) round(array_sum($parts) / count($parts));
                }
            }

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
            '/' . preg_quote($key, '/') . '[^0-9%]{0,50}?(\d{1,3})\s*%/ui',
            '/' . preg_quote($key, '/') . '[^0-9]{0,50}?(\d{1,3})\s*\/\s*100/ui',
            '/' . preg_quote($key, '/') . '.*?[\[\(]?\s*(\d{1,3})\s*%?\s*[\]\)]?/ui',
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
            'customer_type' => 'freelance web designer',
            'mood'          => 'interested but budget-conscious',
            'use_case'      => 'building a directory + booking site for local services',
            'concerns'      => 'price of All-Inclusive vs buying plugins separately, learning curve',
            'first_message' => 'Hi! I need a directory with booking for local services. Is the All-Inclusive plan worth it or should I just get JetEngine + JetBooking?',
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
            $concerns = $scenario['concerns'] ?? '—';
            if (is_array($concerns)) {
                $concerns = implode(', ', $concerns);
            }
            echo esc_html(
                "Тип: " . ($scenario['customer_type'] ?? '—') . "\n" .
                "Настрій: " . ($scenario['mood'] ?? '—') . "\n" .
                "Кейс: " . ($scenario['use_case'] ?? '—') . "\n" .
                "Побоювання: " . $concerns
            );
        } else {
            echo esc_html(is_string($row->scenario_summary) ? $row->scenario_summary : '—');
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

    /**
     * Shared JS logic used by both admin page and frontend shortcode.
     */
    private static function get_chat_js($rest_url, $nonce) {
        $max_client = (int) self::MAX_CLIENT_MESSAGES;
        // Build JS carefully without nested heredoc issues
        $js = <<<'JSBASE'
(function() {
    const inputEl        = document.getElementById('agent-input');
    const evalEl         = document.getElementById('evaluation');
    const scenarioBoxEl  = document.getElementById('scenario-box');
    const messagesEl     = document.getElementById('messages');
    const agentNameInput = document.getElementById('agent-name-input');
    const agentNameHint  = document.getElementById('agent-name-hint');
    const sendBtn        = document.getElementById('send-btn');
    const evaluateBtn    = document.getElementById('evaluate-btn');
    const retryBtn       = document.getElementById('retry-client-btn');
    const modalOverlay   = document.getElementById('pt-results-modal');
    const modalBody      = document.getElementById('pt-modal-body');
    const modalClose     = document.getElementById('pt-modal-close');

    const savedName = localStorage.getItem('presale_agent_name') || '';
    if (savedName && agentNameInput) agentNameInput.value = savedName;

    if (agentNameInput) {
        agentNameInput.addEventListener('input', function() {
            localStorage.setItem('presale_agent_name', this.value.trim());
            if (agentNameHint) agentNameHint.style.display = 'none';
        });
    }

    const state = {
        messages: [],
        scenario: null,
        isLoading: false,
        followupMode: false,
        chatEnded: false,
        evaluated: false
    };

    const MAX_CLIENT = __MAX_CLIENT__;
    const REST_URL = '__REST_URL__';
    const NONCE = '__NONCE__';

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, t => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[t]));
    }

    function countClientMessages() {
        return state.messages.filter(m => m.role === 'assistant').length;
    }

    function lastIsError() {
        if (!state.messages.length) return false;
        const last = state.messages[state.messages.length - 1];
        return last.role === 'assistant' && String(last.content || '').indexOf('[Error]') === 0;
    }

    function updateUI() {
        if (retryBtn) {
            retryBtn.style.display = lastIsError() && !state.chatEnded ? 'inline-block' : 'none';
        }
        if (sendBtn) {
            sendBtn.disabled = state.isLoading || state.chatEnded;
            if (state.followupMode && !state.chatEnded) {
                sendBtn.textContent = 'Відправити фолоуап';
            } else {
                sendBtn.textContent = 'Відправити';
            }
        }
        if (evaluateBtn) {
            evaluateBtn.disabled = state.isLoading;
        }
        if (inputEl) {
            inputEl.disabled = state.isLoading || state.chatEnded;
            if (state.followupMode && !state.chatEnded) {
                inputEl.placeholder = 'Напишіть фолоуап (підсумок + наступний крок)...';
            } else if (state.chatEnded) {
                inputEl.placeholder = 'Чат завершено. Можна оцінити ще раз або почати новий сценарій.';
            } else {
                inputEl.placeholder = 'Введіть відповідь як presale-агент...';
            }
        }
    }

    function renderMessages() {
        messagesEl.innerHTML = state.messages.map(m => {
            if (m.role === 'system') {
                return '<div class="pt-msg pt-msg-system"><div class="pt-msg-bubble"><strong>⚡ Система:</strong> ' + escapeHtml(m.content) + '</div></div>';
            }
            const isCustomer = m.role === 'assistant';
            const isError = isCustomer && String(m.content || '').indexOf('[Error]') === 0;
            const style = isError ? 'border:1px solid #d63638;background:#fcf0f1;' : '';
            return '<div class="pt-msg ' + (isCustomer ? 'pt-msg-customer' : 'pt-msg-agent') + '">' +
                '<div class="pt-label">' + (isCustomer ? 'Клієнт' : 'Ви') + '</div>' +
                '<div class="pt-msg-bubble" style="' + style + '">' + escapeHtml(m.content) + '</div></div>';
        }).join('');
        messagesEl.scrollTop = messagesEl.scrollHeight;
        updateUI();
    }

    function setLoading(isLoading) {
        state.isLoading = isLoading;
        ['send-btn', 'new-scenario-btn', 'evaluate-btn', 'retry-client-btn'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.disabled = isLoading;
        });
        if (inputEl) inputEl.disabled = isLoading || state.chatEnded;
        updateUI();
    }

    function buildScoresHtml(scores, feedback) {
        if (!scores) {
            return '<pre style="white-space:pre-wrap;margin:0;font-size:13px;">' + escapeHtml(feedback) + '</pre>';
        }
        const overall = scores.overall;
        let color = '#666';
        if (overall !== null && overall !== undefined) {
            if (overall >= 80) color = '#00a32a';
            else if (overall >= 60) color = '#dba617';
            else color = '#d63638';
        }
        const row = (label, val) =>
            '<div style="display:flex;justify-content:space-between;padding:5px 0;border-bottom:1px solid #e5e5e5;">' +
            '<span>' + label + '</span><strong>' + (val !== null && val !== undefined ? val + '%' : '—') + '</strong></div>';
        return '<div style="background:#fff;border:1px solid #ddd;border-radius:8px;padding:14px;margin-bottom:12px;">' +
            '<div style="text-align:center;margin-bottom:10px;">' +
            '<div style="font-size:12px;color:#666;">Загальний бал</div>' +
            '<div style="font-size:28px;font-weight:700;color:' + color + ';">' +
            (overall !== null && overall !== undefined ? overall + '%' : '—') + '</div></div>' +
            row('Discovery', scores.discovery) +
            row('Architecture', scores.architecture) +
            row('Commercial', scores.commercial) +
            row('Tone', scores.tone) +
            '</div><details open><summary style="cursor:pointer;font-weight:600;margin-bottom:6px;">Повний фідбек</summary>' +
            '<pre style="white-space:pre-wrap;margin:0;font-size:12.5px;line-height:1.45;">' + escapeHtml(feedback) + '</pre></details>';
    }

    function renderScores(scores, feedback) {
        const html = buildScoresHtml(scores, feedback);
        if (evalEl) evalEl.innerHTML = html;
        if (modalBody && modalOverlay) {
            modalBody.innerHTML = html;
            modalOverlay.style.display = 'flex';
        }
        state.evaluated = true;
    }

    function closeModal() {
        if (modalOverlay) modalOverlay.style.display = 'none';
    }

    if (modalClose) modalClose.addEventListener('click', closeModal);
    if (modalOverlay) {
        modalOverlay.addEventListener('click', function(e) {
            if (e.target === modalOverlay) closeModal();
        });
    }

    async function api(path, payload) {
        payload = payload || {};
        try {
            const res = await fetch(REST_URL + path, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': NONCE
                },
                body: JSON.stringify(payload)
            });
            return await res.json();
        } catch (err) {
            return { error: err.message || 'Помилка з’єднання' };
        }
    }

    async function loadScenario() {
        setLoading(true);
        state.messages = [];
        state.followupMode = false;
        state.chatEnded = false;
        state.evaluated = false;
        if (evalEl) evalEl.innerHTML = '';
        closeModal();
        scenarioBoxEl.innerHTML = '<em>Генеруємо новий сценарій...</em>';
        const data = await api('start');
        if (data.error) {
            scenarioBoxEl.innerHTML = '<span style="color:#b32d2e;">Помилка: ' + escapeHtml(data.error) + '</span>';
            setLoading(false);
            return;
        }
        if (data.scenario) {
            state.scenario = data.scenario;
            const concerns = Array.isArray(data.scenario.concerns)
                ? data.scenario.concerns.join(', ')
                : (data.scenario.concerns || '—');
            scenarioBoxEl.innerHTML =
                '<strong>Тип клієнта:</strong> ' + escapeHtml(data.scenario.customer_type) + '<br>' +
                '<strong>Настрій:</strong> ' + escapeHtml(data.scenario.mood) + '<br>' +
                '<strong>Кейс:</strong> ' + escapeHtml(data.scenario.use_case) + '<br>' +
                '<strong>Побоювання:</strong> ' + escapeHtml(concerns);
            state.messages = [{
                role: 'assistant',
                content: data.scenario.first_message || 'Hi! Can you help me choose the right Crocoblock setup?'
            }];
            renderMessages();
        }
        setLoading(false);
    }

    async function runEvaluate(showModal) {
        if (showModal === undefined) showModal = true;
        const agentName = agentNameInput ? agentNameInput.value.trim() : '';
        if (!agentName) {
            if (agentNameHint) agentNameHint.style.display = 'inline';
            if (agentNameInput) agentNameInput.focus();
            return false;
        }
        if (state.messages.length < 3) {
            if (evalEl) evalEl.innerHTML = '<span style="color:#b32d2e;">Діалог закороткий для оцінювання.</span>';
            return false;
        }
        setLoading(true);
        if (evalEl) evalEl.innerHTML = '<em>Аналізуємо розмову...</em>';
        const data = await api('evaluate', {
            messages: state.messages,
            scenario: state.scenario,
            agent_name: agentName
        });
        if (data.message) {
            if (showModal) {
                renderScores(data.scores || null, data.message);
            } else {
                if (evalEl) evalEl.innerHTML = buildScoresHtml(data.scores || null, data.message);
                state.evaluated = true;
            }
            setLoading(false);
            return true;
        } else {
            if (evalEl) evalEl.innerHTML = '<span style="color:#b32d2e;">Помилка аналізу: ' + escapeHtml(data.error || 'Unknown error') + '. Спробуйте кнопку «Оцінити розмову» ще раз.</span>';
            setLoading(false);
            return false;
        }
    }

    async function sendMessage() {
        const text = inputEl.value.trim();
        if (!text || state.isLoading || state.chatEnded) return;

        state.messages.push({ role: 'user', content: text });
        inputEl.value = '';
        renderMessages();
        setLoading(true);

        // Follow-up was just sent → end chat & auto-evaluate
        if (state.followupMode) {
            state.chatEnded = true;
            state.followupMode = false;
            renderMessages();
            setLoading(false);
            await runEvaluate(true);
            return;
        }

        const data = await api('chat', { messages: state.messages, scenario: state.scenario });

        if (data.force_followup && data.system_notice) {
            state.messages.push({ role: 'system', content: data.system_notice });
            state.followupMode = true;
        } else if (data.message) {
            state.messages.push({ role: 'assistant', content: data.message });
            if (countClientMessages() >= MAX_CLIENT) {
                state.messages.push({
                    role: 'system',
                    content: 'Клієнт перестав відповідати. Напишіть фолоуап: коротко нагадайте про себе, підсумуйте те, що обговорювали, і запропонуйте наступний крок.'
                });
                state.followupMode = true;
            }
        } else {
            state.messages.push({
                role: 'assistant',
                content: '[Error] ' + (data.error || 'Unknown error')
            });
        }
        renderMessages();
        setLoading(false);
    }

    async function retryClientReply() {
        if (!lastIsError() || state.isLoading || state.chatEnded) return;
        state.messages.pop();
        renderMessages();
        setLoading(true);
        const data = await api('chat', { messages: state.messages, scenario: state.scenario });
        if (data.force_followup && data.system_notice) {
            state.messages.push({ role: 'system', content: data.system_notice });
            state.followupMode = true;
        } else if (data.message) {
            state.messages.push({ role: 'assistant', content: data.message });
        } else {
            state.messages.push({
                role: 'assistant',
                content: '[Error] ' + (data.error || 'Unknown error')
            });
        }
        renderMessages();
        setLoading(false);
    }

    if (sendBtn) sendBtn.addEventListener('click', sendMessage);
    if (inputEl) {
        inputEl.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                sendMessage();
            }
        });
    }
    if (retryBtn) retryBtn.addEventListener('click', retryClientReply);

    if (evaluateBtn) {
        evaluateBtn.addEventListener('click', async () => {
            await runEvaluate(true);
        });
    }

    const resetBtn = document.getElementById('reset-btn');
    if (resetBtn) {
        resetBtn.addEventListener('click', () => {
            state.messages = [];
            state.followupMode = false;
            state.chatEnded = false;
            state.evaluated = false;
            if (evalEl) evalEl.innerHTML = '';
            closeModal();
            renderMessages();
        });
    }

    const newScenarioBtn = document.getElementById('new-scenario-btn');
    if (newScenarioBtn) {
        newScenarioBtn.addEventListener('click', () => {
            loadScenario();
        });
    }

    loadScenario();
})();
JSBASE;
        $js = str_replace('__MAX_CLIENT__', (string) $max_client, $js);
        $js = str_replace('__REST_URL__', $rest_url, $js);
        $js = str_replace('__NONCE__', $nonce, $js);
        return $js;
    }

    public static function render_chat_page() {
        $rest_url = esc_url_raw(rest_url('training/v1/'));
        $nonce    = wp_create_nonce('wp_rest');
        ?>
        <div class="wrap" style="height: calc(100vh - 70px);">
            <h1>Presale Training — Чат</h1>

            <div style="margin-bottom: 16px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <label for="agent-name-input" style="font-weight:600; white-space:nowrap;">Ваш нік / Ім’я:</label>
                <input type="text" id="agent-name-input" class="regular-text" placeholder="Наприклад: Olena / Alex / Support_Nick" style="max-width:280px;" />
                <span id="agent-name-hint" style="color:#b32d2e; font-size:13px; display:none;">← обов’язково вкажіть нік перед оцінкою</span>
            </div>

            <div id="presale-training-app" style="display:flex; gap:16px; height: calc(100vh - 210px); min-height:600px;">
                <div style="flex: 0 0 36%; background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px; display:flex; flex-direction:column;">
                    <button class="button" id="new-scenario-btn" style="margin-bottom:12px;">Новий випадковий сценарій</button>
                    <h3 style="margin:0 0 8px 0;">Сценарій</h3>
                    <div id="scenario-box" style="flex:1; overflow:auto; background:#f6f7f7; border:1px solid #dcdcde; border-radius:6px; padding:12px; line-height:1.5;"></div>
                    <h3 style="margin:12px 0 8px 0;">Оцінювання</h3>
                    <div id="evaluation" style="max-height:38%; overflow:auto; background:#f0f0f1; border:1px solid #dcdcde; border-radius:6px; padding:12px; font-size:13.5px;"></div>
                </div>

                <div style="flex:1; background:#fff; border:1px solid #c3c4c7; border-radius:8px; display:flex; flex-direction:column; min-width:0;">
                    <div id="messages" style="flex:1; overflow:auto; padding:20px; background:#f9f9f9; border-bottom:1px solid #c3c4c7; display:flex; flex-direction:column; gap:16px;"></div>
                    <div style="padding:16px;">
                        <textarea id="agent-input" style="width:100%; min-height:110px; resize:vertical; padding:12px; font-size:15px;" placeholder="Введіть відповідь як presale-агент..."></textarea>
                        <div style="margin-top: 12px; display: flex; gap: 10px; flex-wrap:wrap;">
                            <button class="button button-primary" id="send-btn">Відправити</button>
                            <button class="button" id="retry-client-btn" style="display:none; border-color:#d63638; color:#d63638;">↻ Повторити відповідь клієнта</button>
                            <button class="button" id="evaluate-btn">Оцінити розмову</button>
                            <button class="button" id="reset-btn">Скинути чат</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="pt-results-modal" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:100000; align-items:center; justify-content:center; padding:20px;">
            <div style="background:#fff; border-radius:12px; max-width:640px; width:100%; max-height:85vh; overflow:auto; box-shadow:0 12px 40px rgba(0,0,0,0.2);">
                <div style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #e0e0e0;">
                    <strong style="font-size:16px;">Результати оцінювання</strong>
                    <button type="button" id="pt-modal-close" class="button">Закрити</button>
                </div>
                <div id="pt-modal-body" style="padding:20px;"></div>
            </div>
        </div>

        <style>
        .pt-msg { max-width: 86%; }
        .pt-msg-customer { align-self: flex-start; }
        .pt-msg-agent { align-self: flex-end; }
        .pt-msg-system { align-self: center; max-width: 92%; }
        .pt-msg-bubble { padding: 14px 18px; border-radius: 12px; font-size: 15px; line-height: 1.45; }
        .pt-msg-customer .pt-msg-bubble { background: #fff; border: 1px solid #e2e2e2; color: #000; }
        .pt-msg-agent .pt-msg-bubble { background: #2271b1; color: #fff; }
        .pt-msg-system .pt-msg-bubble { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; text-align: center; }
        .pt-label { font-size: 13px; color: #666; margin-bottom: 4px; }
        </style>

        <script>
        <?php echo self::get_chat_js($rest_url, $nonce); ?>
        </script>
        <?php
    }

    public static function enqueue_frontend_assets() {
        wp_register_style('presale-training-front', false);
        wp_enqueue_style('presale-training-front');
        wp_add_inline_style('presale-training-front', self::get_frontend_css());
    }

    private static function get_frontend_css() {
        return '
        .presale-training-wrap { max-width: 1100px; margin: 30px auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .presale-training-wrap * { box-sizing: border-box; }
        .pt-password-box { max-width: 420px; margin: 80px auto; padding: 36px 32px; background: #fff; border: 1px solid #e0e0e0; border-radius: 14px; text-align: center; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
        .pt-password-box h3 { margin: 0 0 8px; font-size: 22px; }
        .pt-password-box p { color: #666; margin-bottom: 20px; }
        .pt-password-box input { width: 100%; padding: 13px 14px; margin-bottom: 14px; font-size: 16px; border: 1px solid #ccc; border-radius: 8px; }
        .pt-password-box button { width: 100%; background: #2271b1; color: #fff; border: none; padding: 13px; border-radius: 8px; cursor: pointer; font-size: 15px; font-weight: 600; }
        .pt-password-box button:hover { background: #135e96; }
        .pt-error { color: #d63638; margin-bottom: 12px; font-size: 14px; }

        .pt-agent-name { margin-bottom: 18px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
        .pt-agent-name label { font-weight: 600; white-space: nowrap; }
        .pt-agent-name input { padding: 9px 12px; border: 1px solid #ccc; border-radius: 6px; width: 260px; font-size: 14px; }
        .pt-agent-name-hint { color: #b32d2e; font-size: 13px; display: none; }

        .pt-layout { display: flex; gap: 20px; min-height: 620px; }
        .pt-sidebar { flex: 0 0 340px; background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; padding: 18px; display: flex; flex-direction: column; }
        .pt-chat { flex: 1; background: #fff; border: 1px solid #e0e0e0; border-radius: 12px; display: flex; flex-direction: column; min-width: 0; }
        .pt-messages { flex: 1; overflow: auto; padding: 20px; background: #f7f8f9; display: flex; flex-direction: column; gap: 14px; border-radius: 12px 12px 0 0; }
        .pt-input-area { padding: 16px; border-top: 1px solid #eee; }
        .pt-input-area textarea { width: 100%; min-height: 90px; padding: 12px; font-size: 15px; border: 1px solid #ccc; border-radius: 8px; resize: vertical; font-family: inherit; }
        .pt-buttons { margin-top: 12px; display: flex; gap: 10px; flex-wrap: wrap; }
        .pt-btn { padding: 10px 18px; border-radius: 6px; border: 1px solid #ccc; background: #f0f0f1; cursor: pointer; font-size: 14px; }
        .pt-btn-primary { background: #2271b1; color: #fff; border-color: #2271b1; }
        .pt-btn-primary:hover { background: #135e96; }
        .pt-btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .pt-btn-retry { border-color: #d63638; color: #d63638; }

        .pt-msg { max-width: 85%; }
        .pt-msg-customer { align-self: flex-start; }
        .pt-msg-agent { align-self: flex-end; }
        .pt-msg-bubble { padding: 12px 16px; border-radius: 12px; font-size: 15px; line-height: 1.45; }
        .pt-msg-customer .pt-msg-bubble { background: #fff; border: 1px solid #e2e2e2; }
        .pt-msg-agent .pt-msg-bubble { background: #2271b1; color: #fff; }
        .pt-msg-system { align-self: center; max-width: 92%; }
        .pt-msg-system .pt-msg-bubble { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; text-align: center; }
        .pt-label { font-size: 12px; color: #888; margin-bottom: 4px; }

        .pt-scenario-box { flex: 1; overflow: auto; background: #f6f7f7; border: 1px solid #e5e5e5; border-radius: 8px; padding: 12px; font-size: 14px; line-height: 1.5; margin-bottom: 14px; }
        .pt-eval-box { max-height: 280px; overflow: auto; background: #f0f0f1; border: 1px solid #e5e5e5; border-radius: 8px; padding: 12px; font-size: 13px; }

        .pt-sidebar h3 { margin: 0 0 8px; font-size: 15px; }
        .pt-new-scenario { margin-bottom: 14px; width: 100%; }

        #pt-results-modal { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.45); z-index: 99999; align-items: center; justify-content: center; padding: 20px; }
        #pt-results-modal .pt-modal-inner { background: #fff; border-radius: 12px; max-width: 640px; width: 100%; max-height: 85vh; overflow: auto; box-shadow: 0 12px 40px rgba(0,0,0,0.2); }
        #pt-results-modal .pt-modal-header { display: flex; justify-content: space-between; align-items: center; padding: 16px 20px; border-bottom: 1px solid #e0e0e0; }
        #pt-results-modal .pt-modal-body { padding: 20px; }

        @media (max-width: 860px) {
            .pt-layout { flex-direction: column; }
            .pt-sidebar { flex: none; }
        }
        ';
    }

    public static function render_shortcode($atts) {
        $atts = shortcode_atts([
            'password' => 'croc2026',
        ], $atts, 'presale_training');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $passed = !empty($_SESSION['presale_training_auth']);
        $error  = '';

        if (!$passed && isset($_POST['presale_password'])) {
            if (hash_equals($atts['password'], (string) $_POST['presale_password'])) {
                $_SESSION['presale_training_auth'] = true;
                $passed = true;
            } else {
                $error = 'Невірний пароль';
            }
        }

        if (!$passed) {
            ob_start();
            ?>
            <div class="presale-training-wrap">
                <div class="pt-password-box">
                    <h3>Presale Training</h3>
                    <p>Введіть пароль для доступу</p>
                    <?php if ($error) : ?>
                        <div class="pt-error"><?php echo esc_html($error); ?></div>
                    <?php endif; ?>
                    <form method="post">
                        <input type="password" name="presale_password" placeholder="Пароль" required autocomplete="current-password">
                        <button type="submit">Увійти</button>
                    </form>
                </div>
            </div>
            <?php
            return ob_get_clean();
        }

        ob_start();
        self::render_frontend_chat();
        return ob_get_clean();
    }

    public static function render_frontend_chat() {
        $rest_url = esc_url_raw(rest_url('training/v1/'));
        $nonce    = wp_create_nonce('wp_rest');
        ?>
        <div class="presale-training-wrap">
            <div class="pt-agent-name">
                <label for="agent-name-input">Ваш нік / Ім’я:</label>
                <input type="text" id="agent-name-input" placeholder="Наприклад: Olena / Alex" />
                <span id="agent-name-hint" class="pt-agent-name-hint">← обов’язково вкажіть нік перед оцінкою</span>
            </div>

            <div class="pt-layout">
                <div class="pt-sidebar">
                    <button class="pt-btn pt-new-scenario" id="new-scenario-btn">Новий випадковий сценарій</button>
                    <h3>Сценарій</h3>
                    <div id="scenario-box" class="pt-scenario-box"></div>
                    <h3>Оцінювання</h3>
                    <div id="evaluation" class="pt-eval-box"></div>
                </div>

                <div class="pt-chat">
                    <div id="messages" class="pt-messages"></div>
                    <div class="pt-input-area">
                        <textarea id="agent-input" placeholder="Введіть відповідь як presale-агент..."></textarea>
                        <div class="pt-buttons">
                            <button class="pt-btn pt-btn-primary" id="send-btn">Відправити</button>
                            <button class="pt-btn pt-btn-retry" id="retry-client-btn" style="display:none;">↻ Повторити відповідь клієнта</button>
                            <button class="pt-btn" id="evaluate-btn">Оцінити розмову</button>
                            <button class="pt-btn" id="reset-btn">Скинути чат</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="pt-results-modal">
            <div class="pt-modal-inner">
                <div class="pt-modal-header">
                    <strong>Результати оцінювання</strong>
                    <button type="button" class="pt-btn" id="pt-modal-close">Закрити</button>
                </div>
                <div class="pt-modal-body" id="pt-modal-body"></div>
            </div>
        </div>

        <script>
        <?php echo self::get_chat_js($rest_url, $nonce); ?>
        </script>
        <?php
    }
}

register_activation_hook(__FILE__, ['Presale_Training_MVP', 'activate']);
Presale_Training_MVP::init();

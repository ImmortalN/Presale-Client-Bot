<?php
/**
 * Plugin Name: Presale Training MVP
 * Description: WP admin chat trainer with OpenRouter roleplay and evaluation.
 * Version: 0.4.7
 * Author: Team
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

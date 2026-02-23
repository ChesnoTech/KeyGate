<?php
/**
 * Internationalization (i18n) Helper Functions
 * OEM Activation System v2.0
 *
 * Provides translation support for English and Russian languages.
 * Language files are stored in /lang/ directory as PHP arrays.
 */

// Global language array
$LANG = [];
$CURRENT_LANG = 'en';

/**
 * Translate a string key to the current language.
 * Supports sprintf-style parameter substitution.
 *
 * @param string $key The translation key (e.g., 'nav.dashboard')
 * @param mixed ...$args Optional sprintf arguments
 * @return string Translated string, or the key itself if not found
 */
function __($key, ...$args) {
    global $LANG;
    $text = $LANG[$key] ?? $key;
    if (!empty($args)) {
        return vsprintf($text, $args);
    }
    return $text;
}

/**
 * Echo a translated string (shorthand for echo __()).
 *
 * @param string $key The translation key
 * @param mixed ...$args Optional sprintf arguments
 */
function _e($key, ...$args) {
    echo __($key, ...$args);
}

/**
 * Load a language file by language code.
 * Falls back to English if the requested language file doesn't exist.
 *
 * @param string $langCode Language code ('en' or 'ru')
 */
function loadLanguage($langCode) {
    global $LANG, $CURRENT_LANG;

    // Sanitize language code
    $langCode = preg_replace('/[^a-z]/', '', strtolower($langCode));
    if (empty($langCode)) {
        $langCode = 'en';
    }

    $langDir = __DIR__ . '/../lang/';
    $file = $langDir . $langCode . '.php';

    if (file_exists($file)) {
        $LANG = require $file;
        $CURRENT_LANG = $langCode;
    } else {
        // Fallback to English
        $fallback = $langDir . 'en.php';
        if (file_exists($fallback)) {
            $LANG = require $fallback;
        }
        $CURRENT_LANG = 'en';
    }
}

/**
 * Get the current language code.
 *
 * @return string Current language code
 */
function getCurrentLanguage() {
    global $CURRENT_LANG;
    return $CURRENT_LANG;
}

/**
 * Get available languages with their display names.
 *
 * @return array Associative array of language code => display name
 */
function getAvailableLanguages() {
    return [
        'en' => 'English',
        'ru' => 'Русский'
    ];
}

/**
 * Get all current language strings as JSON for JavaScript use.
 *
 * @return string JSON-encoded language strings
 */
function getLanguageJSON() {
    global $LANG;
    return json_encode($LANG, JSON_UNESCAPED_UNICODE);
}

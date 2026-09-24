<?php
// config/gemini_config.example.php
// TEMPLATE ONLY — copy this file to config/gemini_config.php and put your
// real key in the copy. config/gemini_config.php is listed in .gitignore
// so it never gets committed; this .example file (with placeholders only)
// is the one that's safe to keep in version control.
//
// Where to get a key: https://aistudio.google.com/apikey (Google AI Studio
// -> "Get API key"). It's free to create; Google's own usage/quota limits
// apply to how much you can call it.

if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', 'YOUR_KEY_HERE');
}

// Centralized model name — change this ONE line to switch models later
// instead of hunting through every file that calls the Gemini API.
if (!defined('GEMINI_MODEL')) {
    define('GEMINI_MODEL', 'gemini-3.6-flash');
}

// Centralized endpoint — the Interactions API. As of this implementation
// it is still officially in beta, so the real path is v1beta, not v1 (Google
// documents this explicitly — there is no stable v1/interactions yet). If
// Google promotes it to v1 later, this is the one place to update.
if (!defined('GEMINI_API_URL')) {
    define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/interactions');
}

<?php
// includes/google_forms_service.php
// Central, only place in the app that talks to the Google Forms API.
// Mirrors the design of includes/gemini_service.php: raw cURL (no
// composer google/apiclient dependency), a single shared-account
// credential loaded from config/google_config.php (gitignored, never sent
// to the browser), and every caller re-validating teacher/student
// ownership itself before calling in here — this file has no concept of
// "who is allowed to do this", only "how to talk to Google".
//
// ONE shared Google account owns every Form this creates (per the setup
// the user chose): a single long-lived refresh token
// (GOOGLE_REFRESH_TOKEN) is exchanged for short-lived access tokens on
// demand. There is no per-teacher Google login anywhere in this app.
//
// Score sync is POLLING, not a push webhook — this app is reached at
// http://localhost/... in the environment this was built for, which a
// Google server cannot call into, so instant Apps Script triggers are not
// usable here. googleFormsSyncResponses() is instead called lazily
// wherever the teacher might want to see fresh scores (same
// "safe to call repeatedly on page view" pattern already used by
// syncTutorialPlanItems() in includes/tutorial_helpers.php).

require_once __DIR__ . '/../config/database.php';

if (file_exists(__DIR__ . '/../config/google_config.php')) {
    require_once __DIR__ . '/../config/google_config.php';
}

if (!defined('GOOGLE_TOKEN_URL')) {
    define('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token');
}
if (!defined('GOOGLE_FORMS_API_BASE')) {
    define('GOOGLE_FORMS_API_BASE', 'https://forms.googleapis.com/v1/forms');
}
if (!defined('GOOGLE_HTTP_TIMEOUT_SECONDS')) {
    define('GOOGLE_HTTP_TIMEOUT_SECONDS', 20);
}

/**
 * Whether the shared Google account has actually been set up yet (the
 * setup checklist completed AND modules/ai/google/oauth_setup.php run
 * successfully at least once). Every public function below checks this
 * first so a not-yet-configured system fails with one clear message
 * instead of a confusing cURL/HTTP error deep inside a request.
 */
function googleFormsIsConfigured()
{
    return defined('GOOGLE_OAUTH_CLIENT_ID') && GOOGLE_OAUTH_CLIENT_ID !== '' && GOOGLE_OAUTH_CLIENT_ID !== 'YOUR_CLIENT_ID_HERE.apps.googleusercontent.com'
        && defined('GOOGLE_OAUTH_CLIENT_SECRET') && GOOGLE_OAUTH_CLIENT_SECRET !== '' && GOOGLE_OAUTH_CLIENT_SECRET !== 'YOUR_CLIENT_SECRET_HERE'
        && defined('GOOGLE_REFRESH_TOKEN') && GOOGLE_REFRESH_TOKEN !== '';
}

/**
 * Exchange the shared account's long-lived refresh token for a short-lived
 * access token. Cached per-request only (a static var) — this app's call
 * volume never justifies a persistent token cache, and a fresh exchange is
 * cheap and always correctly reflects the current token's validity.
 *
 * @return array{success:bool,access_token?:string,error?:string}
 */
function googleGetAccessToken()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!googleFormsIsConfigured()) {
        $cached = ['success' => false, 'error' => 'Google Forms is not set up yet. An Administrator needs to complete the Google Cloud setup and run the one-time authorization step first.'];
        return $cached;
    }

    $result = googleHttpRequest('POST', GOOGLE_TOKEN_URL, [], [
        'client_id' => GOOGLE_OAUTH_CLIENT_ID,
        'client_secret' => GOOGLE_OAUTH_CLIENT_SECRET,
        'refresh_token' => GOOGLE_REFRESH_TOKEN,
        'grant_type' => 'refresh_token',
    ], true);

    if (!$result['success']) {
        error_log('Google OAuth token refresh failed: ' . ($result['error'] ?? 'unknown'));
        $cached = ['success' => false, 'error' => 'Could not connect to Google right now. Please try again in a moment.'];
        return $cached;
    }

    $token = $result['body']['access_token'] ?? null;
    if (!$token) {
        error_log('Google OAuth token refresh: no access_token in response — ' . substr(json_encode($result['body']), 0, 500));
        $cached = ['success' => false, 'error' => 'Google did not return a usable access token. The shared account may need to be re-authorized (see modules/ai/google/oauth_setup.php).'];
        return $cached;
    }

    $cached = ['success' => true, 'access_token' => $token];
    return $cached;
}

/**
 * Create a fully-configured, auto-graded Google Form quiz from a
 * structured question set (the same shape aiSanitizeQuizQuestions() in
 * includes/gemini_service.php produces/re-validates: an array of
 * {question, choices[], correct_index, points}). The CALLER is
 * responsible for re-sanitizing $questions immediately before calling
 * this (never trust a client-resubmitted draft directly) — this function
 * assumes it has already received clean data and focuses purely on the
 * Google API calls.
 *
 * @param string $title
 * @param string $description Shown at the top of the Form, above the questions.
 * @param array  $questions   [{question, choices[], correct_index, points}, ...]
 * @return array{success:bool,form_id?:string,edit_url?:string,view_url?:string,max_score?:int,error?:string}
 */
function googleFormsCreateQuizForm($title, $description, array $questions)
{
    if (empty($questions)) {
        return ['success' => false, 'error' => 'There are no gradable questions to build a Google Form from.'];
    }

    $token = googleGetAccessToken();
    if (!$token['success']) {
        return ['success' => false, 'error' => $token['error']];
    }
    // No Content-Type here — googleHttpRequest() already adds
    // 'Content-Type: application/json' itself whenever $asJsonRequest is
    // true (both POST calls below pass true), so including it here too
    // would send the header twice.
    $headers = ['Authorization: Bearer ' . $token['access_token']];

    // Step 1 — create the Form. The API only accepts `info.title` at
    // creation time; everything else (description, quiz mode, questions)
    // is added via a follow-up batchUpdate, per Google's documented
    // two-step create flow for the Forms API.
    $title = mb_substr(trim($title) !== '' ? trim($title) : 'Quiz', 0, 300);
    $create = googleHttpRequest('POST', GOOGLE_FORMS_API_BASE, $headers, [
        'info' => ['title' => $title],
    ], true, true);

    if (!$create['success']) {
        error_log('Google Forms create failed: ' . ($create['error'] ?? 'unknown') . ' body=' . substr(json_encode($create['body'] ?? null), 0, 800));
        return ['success' => false, 'error' => 'Google Forms could not create the quiz form. Please try again.'];
    }
    $form_id = $create['body']['formId'] ?? null;
    if (!$form_id) {
        return ['success' => false, 'error' => 'Google Forms did not return a form id.'];
    }

    // Step 2 — turn on quiz grading and add every question in one
    // batchUpdate (atomic: either all requests apply or none do).
    $requests = [
        [
            'updateSettings' => [
                'settings' => ['quizSettings' => ['isQuiz' => true]],
                'updateMask' => 'quizSettings.isQuiz',
            ],
        ],
    ];

    if (trim((string) $description) !== '') {
        $requests[] = [
            'updateFormInfo' => [
                'info' => ['description' => mb_substr(trim($description), 0, 4000)],
                'updateMask' => 'description',
            ],
        ];
    }

    $max_score = 0;
    $index = 0;
    foreach ($questions as $q) {
        $points = max(1, (int) ($q['points'] ?? 1));
        $max_score += $points;
        $correct_text = $q['choices'][$q['correct_index']];

        $options = [];
        foreach ($q['choices'] as $choice) {
            $options[] = ['value' => mb_substr($choice, 0, 300)];
        }

        $requests[] = [
            'createItem' => [
                'item' => [
                    'title' => mb_substr($q['question'], 0, 1000),
                    'questionItem' => [
                        'question' => [
                            'required' => true,
                            'grading' => [
                                'pointValue' => $points,
                                'correctAnswers' => ['answers' => [['value' => mb_substr($correct_text, 0, 300)]]],
                            ],
                            'choiceQuestion' => [
                                'type' => 'RADIO',
                                'options' => $options,
                                'shuffle' => false,
                            ],
                        ],
                    ],
                ],
                'location' => ['index' => $index],
            ],
        ];
        $index++;
    }

    $batch = googleHttpRequest('POST', GOOGLE_FORMS_API_BASE . '/' . rawurlencode($form_id) . ':batchUpdate', $headers, [
        'requests' => $requests,
    ], true, true);

    if (!$batch['success']) {
        error_log('Google Forms batchUpdate failed: ' . ($batch['error'] ?? 'unknown') . ' body=' . substr(json_encode($batch['body'] ?? null), 0, 800));
        // The Form container was created but couldn't be populated — surface
        // this clearly rather than handing back a blank, useless Form link.
        return ['success' => false, 'error' => 'The quiz form was started but the questions could not be added. Please try again.', 'form_id' => $form_id];
    }

    // Step 3 — read back the responder (student-facing) link.
    $get = googleHttpRequest('GET', GOOGLE_FORMS_API_BASE . '/' . rawurlencode($form_id), $headers, null, false, true);
    $view_url = $get['success'] ? ($get['body']['responderUri'] ?? null) : null;
    if (!$view_url) {
        // Fall back to the documented predictable viewform URL shape if the
        // read-back for some reason didn't include it.
        $view_url = 'https://docs.google.com/forms/d/' . $form_id . '/viewform';
    }
    $edit_url = 'https://docs.google.com/forms/d/' . $form_id . '/edit';

    return [
        'success' => true,
        'form_id' => $form_id,
        'edit_url' => $edit_url,
        'view_url' => $view_url,
        'max_score' => $max_score,
    ];
}

/**
 * Open or close a Form to new responses, via Google's own
 * forms:setPublishSettings — this is how "one attempt only" is actually
 * enforced: once closed, Google itself refuses any further submission
 * (the student sees Google's own "this form is no longer accepting
 * responses" page), not just something this app's UI hides. Uses the same
 * shared-account access token / scopes already in use (forms.body /
 * drive.file already cover setPublishSettings — no extra OAuth consent
 * needed).
 *
 * @param string $formId
 * @param bool   $accepting
 * @return array{success:bool,error?:string}
 */
function googleFormsSetAcceptingResponses($formId, $accepting)
{
    $token = googleGetAccessToken();
    if (!$token['success']) {
        return ['success' => false, 'error' => $token['error']];
    }
    $headers = ['Authorization: Bearer ' . $token['access_token']];

    $result = googleHttpRequest(
        'POST',
        GOOGLE_FORMS_API_BASE . '/' . rawurlencode($formId) . ':setPublishSettings',
        $headers,
        [
            'publishSettings' => [
                'publishState' => [
                    'isPublished' => true,
                    'isAcceptingResponses' => (bool) $accepting,
                ],
            ],
        ],
        true,
        true
    );

    if (!$result['success']) {
        error_log('googleFormsSetAcceptingResponses(' . $formId . ', ' . ($accepting ? '1' : '0') . ') failed: ' . ($result['error'] ?? 'unknown'));
        return ['success' => false, 'error' => 'Could not update the Google Form\'s response setting right now.'];
    }

    return ['success' => true];
}

/**
 * Poll a Form for responses and return only the ones NOT already in
 * $knownResponseIds — the caller (modules/ai/gform_sync.php) is
 * responsible for persisting what's "known" (ai_quiz_gform_responses,
 * keyed by google_response_id) and re-calling this on every check, so a
 * response already recorded is never returned twice. Grade info
 * (per-question score) is included automatically by the Forms API for a
 * form with quiz mode on — this never re-derives a score by comparing
 * answers itself, it only reads the score Google's own grading already
 * computed.
 *
 * @param string $formId
 * @param array  $knownResponseIds google_response_id values already recorded for this form
 * @return array{success:bool,responses?:array,error?:string}
 */
function googleFormsListNewResponses($formId, array $knownResponseIds = [])
{
    $token = googleGetAccessToken();
    if (!$token['success']) {
        return ['success' => false, 'error' => $token['error']];
    }
    $headers = ['Authorization: Bearer ' . $token['access_token']];

    $result = googleHttpRequest('GET', GOOGLE_FORMS_API_BASE . '/' . rawurlencode($formId) . '/responses', $headers, null, false, true);
    if (!$result['success']) {
        error_log('Google Forms list responses failed: ' . ($result['error'] ?? 'unknown'));
        return ['success' => false, 'error' => 'Could not check Google Forms for new submissions right now.'];
    }

    $known = array_flip($knownResponseIds);
    $out = [];
    foreach (($result['body']['responses'] ?? []) as $resp) {
        $response_id = $resp['responseId'] ?? null;
        if (!$response_id || isset($known[$response_id])) {
            continue; // already recorded, or malformed — skip
        }

        $score = 0.0;
        $answers_summary = [];
        foreach (($resp['answers'] ?? []) as $question_id => $answer) {
            $grade = $answer['grade'] ?? null;
            if ($grade !== null && isset($grade['score'])) {
                $score += (float) $grade['score'];
            }
            $text_answers = $answer['textAnswers']['answers'] ?? [];
            $values = array_map(function ($a) { return (string) ($a['value'] ?? ''); }, $text_answers);
            $answers_summary[] = implode(', ', $values);
        }

        $out[] = [
            'google_response_id' => $response_id,
            'score' => $score,
            'submitted_at' => isset($resp['lastSubmittedTime']) ? gmdate('Y-m-d H:i:s', strtotime($resp['lastSubmittedTime'])) : null,
            'answers_json' => json_encode($answers_summary),
        ];
    }

    return ['success' => true, 'responses' => $out];
}

/**
 * Minimal shared cURL helper for this file — same defensive shape as
 * geminiHttpAttempt() in includes/gemini_service.php (never throws, always
 * returns a success/error array; a non-2xx HTTP status is treated as
 * failure even if the body parses as JSON).
 *
 * @param string      $method
 * @param string      $url
 * @param array       $headers
 * @param array|null  $body           Sent as JSON, or as application/x-www-form-urlencoded when $asForm is true.
 * @param bool        $hasBody
 * @param bool        $asJsonRequest  If true, JSON-encode $body as the request payload; if false and $hasBody, send as form-encoded (used only for the OAuth token exchange).
 * @return array{success:bool,body?:array,error?:string,http_code?:int}
 */
function googleHttpRequest($method, $url, array $headers, $body = null, $hasBody = false, $asJsonRequest = false)
{
    if (!function_exists('curl_init')) {
        return ['success' => false, 'error' => 'cURL extension is not available on this server.'];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    curl_setopt($ch, CURLOPT_TIMEOUT, GOOGLE_HTTP_TIMEOUT_SECONDS);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

    if ($hasBody && $body !== null) {
        if ($asJsonRequest) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        }
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http_code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['success' => false, 'error' => 'cURL error: ' . $err, 'http_code' => 0];
    }

    $decoded = json_decode((string) $raw, true);
    if ($http_code < 200 || $http_code >= 300) {
        $msg = is_array($decoded) ? ($decoded['error']['message'] ?? $decoded['error_description'] ?? $raw) : $raw;
        return ['success' => false, 'error' => 'HTTP ' . $http_code . ': ' . substr((string) $msg, 0, 500), 'http_code' => $http_code, 'body' => is_array($decoded) ? $decoded : null];
    }

    return ['success' => true, 'body' => is_array($decoded) ? $decoded : [], 'http_code' => $http_code];
}

/**
 * Upload a Word file to the shared account's Drive as a Google Doc (Drive
 * converts it) and let anyone with the link view it -- the student gets the
 * link as a classwork attachment. Uses the drive.file scope already granted.
 *
 * @return array{success:bool,file_id?:string,view_url?:string,error?:string}
 */
function googleDocsCreateFromDocx($docxPath, $title)
{
    if (!is_file($docxPath)) {
        return ['success' => false, 'error' => 'The Word file to convert was not found.'];
    }
    $token = googleGetAccessToken();
    if (!$token['success']) {
        return ['success' => false, 'error' => $token['error']];
    }
    $auth = 'Authorization: Bearer ' . $token['access_token'];

    $boundary = 'egp' . bin2hex(random_bytes(8));
    $meta = json_encode(['name' => mb_substr(trim($title) !== '' ? trim($title) : 'Activity', 0, 200), 'mimeType' => 'application/vnd.google-apps.document']);
    $body = "--$boundary\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n$meta\r\n"
        . "--$boundary\r\nContent-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document\r\n\r\n"
        . file_get_contents($docxPath) . "\r\n--$boundary--";

    $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,webViewLink');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [$auth, 'Content-Type: multipart/related; boundary=' . $boundary],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $up = json_decode((string) $raw, true);
    if ($code < 200 || $code >= 300 || empty($up['id'])) {
        error_log('Google Docs upload failed: HTTP ' . $code . ' ' . $err . ' ' . substr((string) $raw, 0, 500));
        return ['success' => false, 'error' => 'Google Docs could not create the document right now.'];
    }
    $fileId = $up['id'];

    $perm = googleHttpRequest('POST', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($fileId) . '/permissions',
        [$auth], ['role' => 'reader', 'type' => 'anyone'], true, true);
    if (!$perm['success']) {
        error_log('Google Docs share-by-link failed for ' . $fileId . ': ' . ($perm['error'] ?? 'unknown'));
        return ['success' => false, 'error' => 'The Google Doc was created but could not be shared with the students.'];
    }
    return [
        'success' => true,
        'file_id' => $fileId,
        'view_url' => $up['webViewLink'] ?? ('https://docs.google.com/document/d/' . $fileId . '/edit?usp=sharing'),
    ];
}

/** Replace the description shown at the top of an existing Form. */
function googleFormsUpdateDescription($formId, $description)
{
    $token = googleGetAccessToken();
    if (!$token['success']) {
        return ['success' => false, 'error' => $token['error']];
    }
    $r = googleHttpRequest('POST', GOOGLE_FORMS_API_BASE . '/' . rawurlencode($formId) . ':batchUpdate',
        ['Authorization: Bearer ' . $token['access_token']],
        ['requests' => [['updateFormInfo' => ['info' => ['description' => mb_substr(trim((string) $description), 0, 4000)], 'updateMask' => 'description']]]],
        true, true);
    return $r['success'] ? ['success' => true] : ['success' => false, 'error' => $r['error'] ?? 'unknown'];
}

<?php
// includes/email_verify_helpers.php
//
// "Is this a real, working email account?" for Online Enrollment -- the same
// kind of check as Google's "Couldn't find this account", without sending
// anything to the applicant:
//   1. format
//   2. common domain typos (gmial.com -> gmail.com) are pointed out
//   3. the domain must really receive email (MX / A record)
//   4. the domain's own mail server is asked whether the mailbox exists
//      (SMTP RCPT TO -- no message is sent). Gmail, for example, answers
//      "550 5.1.1 The email account that you tried to reach does not exist".
// If the mail server cannot be reached or does not give a clear answer, the
// address is NOT rejected (status 'unknown') -- a network problem must never
// lock out a real applicant. Results are cached in the session for 30 min.

const EV_TIMEOUT_SECONDS = 5;
const EV_CONNECT_SECONDS = 1.5;
const EV_TOTAL_SECONDS = 4;

/** Well-known domains and their common misspellings. */
function evTypoSuggestion($domain)
{
    $fixes = [
        'gmail.com' => ['gmial.com', 'gmai.com', 'gmail.co', 'gmail.con', 'gmal.com', 'gmaill.com', 'gnail.com', 'gamil.com', 'gmail.cm', 'gmail.om', 'gmsil.com', 'gmail.ph'],
        'yahoo.com' => ['yaho.com', 'yahooo.com', 'yahoo.co', 'yahoo.con', 'yhoo.com', 'yahho.com'],
        'hotmail.com' => ['hotmial.com', 'hotmai.com', 'hotmail.co', 'hotmail.con', 'hotmil.com'],
        'outlook.com' => ['outlok.com', 'outloo.com', 'outlook.co', 'outlook.con'],
        'icloud.com' => ['iclod.com', 'icloud.co', 'icoud.com'],
    ];
    foreach ($fixes as $right => $wrongs) {
        if (in_array($domain, $wrongs, true)) {
            return $right;
        }
    }
    return null;
}

/** File where the last mail host that answered quickly is remembered per domain. */
function evHostCacheFile()
{
    return rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'egradepro_mx_hosts.json';
}

/** MX hosts for a domain, the one that last answered put first (skips a dead primary). */
function evOrderedHosts($domain, array $mxHosts)
{
    $cache = @json_decode((string) @file_get_contents(evHostCacheFile()), true) ?: [];
    $good = $cache[$domain] ?? null;
    if ($good && ($good['time'] ?? 0) > time() - 86400 && in_array($good['host'], $mxHosts, true)) {
        $mxHosts = array_values(array_unique(array_merge([$good['host']], $mxHosts)));
    }
    return $mxHosts;
}

function evRememberHost($domain, $host)
{
    $file = evHostCacheFile();
    $cache = @json_decode((string) @file_get_contents($file), true) ?: [];
    if (($cache[$domain]['host'] ?? '') === $host && ($cache[$domain]['time'] ?? 0) > time() - 3600) {
        return;
    }
    $cache[$domain] = ['host' => $host, 'time' => time()];
    @file_put_contents($file, json_encode($cache), LOCK_EX);
}

/** Ask the domain's mail server whether it accepts this mailbox. */
function evSmtpMailboxCheck($email, array $mxHosts)
{
    $domain = substr(strrchr($email, '@'), 1);
    $deadline = microtime(true) + EV_TOTAL_SECONDS; // never make the applicant wait longer than this
    foreach (array_slice(evOrderedHosts($domain, $mxHosts), 0, 3) as $host) {
        if (microtime(true) > $deadline - 0.5) {
            break;
        }
        // IPv4 on purpose: a mail host's IPv6 address often just times out here.
        $ip = gethostbyname($host);
        $fp = @fsockopen($ip !== $host ? $ip : $host, 25, $errno, $errstr, EV_CONNECT_SECONDS);
        if (!$fp) {
            continue;
        }
        stream_set_timeout($fp, max(1, (int) ceil($deadline - microtime(true))));
        $read = function () use ($fp) {
            $out = '';
            while (($line = fgets($fp, 515)) !== false) {
                $out .= $line;
                if (!isset($line[3]) || $line[3] === ' ') break;
            }
            return $out;
        };
        $code = function ($reply) { return (int) substr(trim($reply), 0, 3); };
        if ($code($read()) !== 220) { fclose($fp); continue; }
        fwrite($fp, "EHLO " . (gethostname() ?: 'localhost') . "\r\n");
        $ehlo = $read();
        if (stripos($ehlo, 'PIPELINING') !== false) {
            // One round trip instead of two (the server said it supports this).
            fwrite($fp, "MAIL FROM:<>\r\nRCPT TO:<" . $email . ">\r\n");
            $from = $read();
            $reply = $read();
        } else {
            fwrite($fp, "MAIL FROM:<>\r\n");
            $from = $read();
            if ($code($from) === 250) {
                fwrite($fp, "RCPT TO:<" . $email . ">\r\n");
                $reply = $read();
            } else {
                $reply = '';
            }
        }
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
        if ($code($from) !== 250) {
            continue;
        }
        evRememberHost($domain, $host);
        $c = $code($reply);
        if ($c === 250 || $c === 251) {
            return 'exists';
        }
        // 550 / 551 / 553 with "does not exist / no such user / unknown" = no account.
        if (in_array($c, [550, 551, 553], true) && preg_match('/5\.1\.1|does not exist|no such user|user unknown|unknown user|not found|invalid recipient|mailbox unavailable|recipient rejected/i', $reply)) {
            return 'missing';
        }
        return 'unknown'; // greylisting, policy blocks, rate limits ...
    }
    return 'unknown';
}

/**
 * @return array{status:string, message:string, suggestion?:string}
 *   status: ok | not_found | bad_domain | invalid | typo | unknown
 */
function evCheckEmail($email)
{
    $email = strtolower(trim((string) $email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['status' => 'invalid', 'message' => 'Enter a valid email address.'];
    }
    if (isset($_SESSION['ev_cache'][$email]) && $_SESSION['ev_cache'][$email]['time'] > time() - 1800) {
        return $_SESSION['ev_cache'][$email]['result'];
    }
    list($local, $domain) = explode('@', $email, 2);

    if ($fix = evTypoSuggestion($domain)) {
        $result = ['status' => 'typo', 'message' => "Couldn't find this email account. Did you mean " . $local . '@' . $fix . '?', 'suggestion' => $local . '@' . $fix];
    } else {
        $mx = [];
        $weights = [];
        $hasMx = @getmxrr($domain, $mx, $weights);
        if ($hasMx && $mx) {
            array_multisort($weights, $mx);
        } elseif (@checkdnsrr($domain, 'A')) {
            $mx = [$domain];
        }
        if (!$mx) {
            $result = ['status' => 'bad_domain', 'message' => "Couldn't find this email account. \"" . $domain . '" does not receive email — check the spelling.'];
        } else {
            // Don't hold the session lock while waiting on a mail server (other
            // requests of this page would queue behind it); reopen to cache.
            $hadSession = session_status() === PHP_SESSION_ACTIVE;
            if ($hadSession) session_write_close();
            $probe = evSmtpMailboxCheck($email, $mx);
            if ($hadSession) @session_start();
            if ($probe === 'missing') {
                $result = ['status' => 'not_found', 'message' => "Couldn't find this email account. Please enter an email address that really exists (for example your working Gmail account)."];
            } elseif ($probe === 'exists') {
                $result = ['status' => 'ok', 'message' => 'Email account found.'];
            } else {
                $result = ['status' => 'unknown', 'message' => ''];
            }
        }
    }
    if (session_status() === PHP_SESSION_ACTIVE && $result['status'] !== 'unknown') { // 'unknown' is retried next time
        $_SESSION['ev_cache'][$email] = ['time' => time(), 'result' => $result];
    }
    return $result;
}

/** True when the address must be refused (clearly not a real account). */
function evIsRejected(array $result)
{
    return in_array($result['status'], ['invalid', 'typo', 'bad_domain', 'not_found'], true);
}

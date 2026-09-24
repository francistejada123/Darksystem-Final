<?php
// modules/settings/backup_restore.php
// Restores the database from an uploaded .sql dump (produced by
// backup_download.php or a standard mysqldump). Admin only, requires an
// explicit confirmation checkbox since this overwrites existing data.

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

/**
 * Splits a raw SQL dump into individual statements, respecting semicolons
 * that appear inside quoted strings (so a semicolon in someone's remarks
 * text doesn't get treated as a statement boundary).
 */
function splitSqlStatements($sql) {
    $statements = [];
    $current = '';
    $len = strlen($sql);
    $in_string = false;
    $string_char = '';
    $i = 0;

    while ($i < $len) {
        $char = $sql[$i];

        if ($in_string) {
            $current .= $char;
            if ($char === '\\' && $i + 1 < $len) {
                // Escaped character inside a string — copy it verbatim and skip past it.
                $current .= $sql[$i + 1];
                $i += 2;
                continue;
            }
            if ($char === $string_char) {
                $in_string = false;
            }
            $i++;
            continue;
        }

        if ($char === "'" || $char === '"') {
            $in_string = true;
            $string_char = $char;
            $current .= $char;
            $i++;
            continue;
        }

        if ($char === ';') {
            $trimmed = trim($current);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $current = '';
            $i++;
            continue;
        }

        $current .= $char;
        $i++;
    }

    $trimmed = trim($current);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

/**
 * Strips SQL comment lines (-- ...) so they don't get treated as content.
 * Kept outside the quote-aware splitter since comments are always
 * whole-line here (matches what backup_download.php and mysqldump emit).
 */
function stripSqlComments($sql) {
    $lines = explode("\n", $sql);
    $kept = array_filter($lines, function ($line) {
        $t = ltrim($line);
        return $t === '' || substr($t, 0, 2) !== '--';
    });
    return implode("\n", $kept);
}

$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        die('Invalid security token. Please refresh the page and try again.');
    }

    if (empty($_POST['confirm_restore'])) {
        $result = ['success' => false, 'message' => 'You must check the confirmation box to proceed.'];
    } elseif (!isset($_FILES['sql_file']) || $_FILES['sql_file']['error'] !== UPLOAD_ERR_OK) {
        $result = ['success' => false, 'message' => 'File upload failed. Please choose a .sql file and try again.'];
    } else {
        $sql_content = file_get_contents($_FILES['sql_file']['tmp_name']);
        if ($sql_content === false || trim($sql_content) === '') {
            $result = ['success' => false, 'message' => 'The uploaded file is empty or unreadable.'];
        } else {
            $sql_content = stripSqlComments($sql_content);
            $statements = splitSqlStatements($sql_content);

            if (empty($statements)) {
                $result = ['success' => false, 'message' => 'No executable SQL statements found in the file.'];
            } else {
                global $db;
                set_time_limit(180);

                $executed = 0;
                $failed = 0;
                $failed_details = [];

                foreach ($statements as $stmt) {
                    try {
                        $db->exec($stmt);
                        $executed++;
                    } catch (PDOException $e) {
                        $failed++;
                        if (count($failed_details) < 10) {
                            $failed_details[] = substr($stmt, 0, 80) . '... — ' . $e->getMessage();
                        }
                    }
                }

                logActivity($_SESSION['user_id'], 'Database Restore', 'settings',
                    "Restored from upload: $executed statement(s) executed, $failed failed");

                $result = [
                    'success' => $failed === 0,
                    'message' => "$executed statement(s) executed successfully" . ($failed > 0 ? ", $failed failed" : '.'),
                    'failed_details' => $failed_details,
                ];
            }
        }
    }
}

$page_title = 'Backup & Restore';
$current_page = 'settings';
$base_url = BASE_URL;
include '../../includes/header.php';
?>

<div class="container-fluid py-4">
    <h4 class="fw-bold mb-4"><i class="fas fa-database me-2" style="color:var(--ink);"></i>Restore Result</h4>

    <?php if ($result): ?>
        <div class="alert <?php echo $result['success'] ? 'alert-success' : 'alert-danger'; ?>">
            <i class="fas fa-<?php echo $result['success'] ? 'check-circle' : 'exclamation-circle'; ?> me-2"></i>
            <?php echo htmlspecialchars($result['message']); ?>
        </div>
        <?php if (!empty($result['failed_details'])): ?>
            <div class="alert alert-warning">
                <strong>First few failed statements:</strong>
                <ul class="small mb-0">
                    <?php foreach ($result['failed_details'] as $d): ?>
                        <li><?php echo htmlspecialchars($d); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <a href="<?php echo BASE_URL; ?>modules/settings/backup.php" class="btn btn-dark" style="background:var(--ink); border-color:var(--ink);">
        <i class="fas fa-arrow-left me-2"></i>Back to Backup &amp; Restore
    </a>
</div>

<?php include '../../includes/footer.php'; ?>
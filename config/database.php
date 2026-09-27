<?php
// config/database.php
// Database configuration and connection

// All PHP date()/time() calls across the system (dashboard "Updated"
// timestamps, generated_at values, etc.) should reflect Philippine Time.
date_default_timezone_set('Asia/Manila');

// Never print PHP errors/warnings on the page (they expose server paths and
// code). They still go to the PHP error log (php.ini error_log -- on XAMPP
// C:\xampp\php\logs\php_error_log).
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

// Admin / Registrar / Teacher portals: desktop or laptop only. Student Portal and
// Online Enrollment also work on phones (includes/device_policy.php).
require_once __DIR__ . '/../includes/device_policy.php';

$host = 'localhost';
$dbname = 'egradepro';
$username = 'root';
$password = '';

try {
    $db = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
} catch(PDOException $e) {
    error_log("Database connection failed: " . $e->getMessage());
    die("A database error occurred. Please try again later.");
}

// ============================================================
// CORE DATABASE FUNCTIONS
// ============================================================

/**
 * Execute a query with prepared statements
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters for prepared statement
 * @return PDOStatement|false Returns statement object or false on error
 */
function executeQuery($sql, $params = []) {
    global $db;
    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch(PDOException $e) {
        error_log("Database Error (executeQuery): " . $e->getMessage() . " - SQL: $sql");
        return false;
    }
}

/**
 * Get single record from database
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters for prepared statement
 * @return array|null Returns associative array or null if no record found
 */
function getRecord($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    if ($stmt) {
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return null;
}

/**
 * Get multiple records from database
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters for prepared statement
 * @return array Returns array of records (empty if none)
 */
function getRecords($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    if ($stmt) {
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return [];
}

/**
 * Insert record and return last insert ID
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters for prepared statement
 * @return int|false Returns last insert ID or false on error
 */
function insertRecord($sql, $params = []) {
    global $db;
    $stmt = executeQuery($sql, $params);
    if ($stmt) {
        return $db->lastInsertId();
    }
    return false;
}

/**
 * Update record and return number of affected rows
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters for prepared statement
 * @return int|false Returns number of affected rows or false on error
 */
function updateRecord($sql, $params = []) {
    $stmt = executeQuery($sql, $params);
    if ($stmt) {
        return $stmt->rowCount();
    }
    return false;
}

/**
 * Delete record and return number of affected rows
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters for prepared statement
 * @return int|false Returns number of affected rows or false on error
 */
function deleteRecord($sql, $params = []) {
    return updateRecord($sql, $params);
}

/**
 * Get count of records in a table
 * @param string $table Table name
 * @param string $where WHERE clause (without the word "WHERE")
 * @param array $params Parameters for prepared statement
 * @return int Returns count
 */
function getCount($table, $where = '', $params = []) {
    $sql = "SELECT COUNT(*) as total FROM $table";
    if ($where) {
        $sql .= " WHERE $where";
    }
    $result = getRecord($sql, $params);
    return $result ? (int)$result['total'] : 0;
}

// ============================================================
// SECURITY & HELPER FUNCTIONS
// ============================================================

/**
 * Sanitize input to prevent XSS
 * @param string|array $input Input to sanitize
 * @return string|array Sanitized input
 */
function sanitize($input) {
    if (is_array($input)) {
        return array_map('sanitize', $input);
    }
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email address
 * @param string $email Email to validate
 * @return bool True if valid, false otherwise
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Hash password using bcrypt
 * @param string $password Plain text password
 * @return string Hashed password
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password against hash
 * @param string $password Plain text password
 * @param string $hash Hashed password
 * @return bool True if match, false otherwise
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// ============================================================
// CSRF TOKEN FUNCTIONS
// ============================================================

/**
 * Verify CSRF token
 * @param string $token Token to verify
 * @return bool True if valid, false otherwise
 */
function verifyCSRFToken($token) {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Generate CSRF token
 * @return string CSRF token
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// ============================================================
// ACTIVITY LOGGING
// ============================================================

/**
 * Log user activity
 * @param int $userId User ID
 * @param string $module Module name
 * @param string $action Action performed
 * @param string|array $details Additional details
 * @param string|null $ipAddress IP address (optional)
 * @return bool True on success, false on failure
 */
/**
 * Make sections.current_enrollment equal the students ACTUALLY enrolled in
 * each section this school year (Approved enrollment, active student) --
 * the same rule as the "Students in <section>" list. The stored counter used
 * to be +1/-1 by hand on every approve / reject / move / delete and drifted.
 * One UPDATE for all sections; runs at most once per request.
 */
/**
 * Self-migrating schema checks (the *EnsureSchema / *ensureTable helpers) run
 * their CREATE/ALTER/information_schema checks only until they succeed once;
 * after that a "schema_ok_<key>" row in system_settings lets every later page
 * skip them. All flags are read with ONE query per request.
 * When a helper gains a new column/table, bump its key (e.g. _v1 -> _v2) so it runs again.
 */
function schemaIsReady($key) {
    static $flags = null;
    if ($flags === null) {
        $flags = [];
        foreach (getRecords("SELECT setting_key FROM system_settings WHERE setting_key LIKE 'schema\\_ok\\_%'") as $r) {
            $flags[$r['setting_key']] = true;
        }
        $GLOBALS['__schema_flags'] = &$flags;
    }
    return isset($flags['schema_ok_' . $key]);
}
function schemaMarkReady($key) {
    executeQuery(
        "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, '1', 'system')
         ON DUPLICATE KEY UPDATE setting_value = '1'",
        ['schema_ok_' . $key]
    );
    if (isset($GLOBALS['__schema_flags'])) {
        $GLOBALS['__schema_flags']['schema_ok_' . $key] = true;
    }
}

function syncSectionEnrollmentCounts($schoolYearId = null) {
    static $done = [];
    if ($schoolYearId === null) {
        $sy = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
        $schoolYearId = $sy ? (int) $sy['sy_id'] : 0;
    }
    $schoolYearId = (int) $schoolYearId;
    if (!$schoolYearId || isset($done[$schoolYearId])) {
        return;
    }
    $done[$schoolYearId] = true;
    executeQuery(
        "UPDATE sections sec
         LEFT JOIN (
             SELECT e.section_id, COUNT(DISTINCT e.student_id) AS cnt
             FROM enrollments e
             INNER JOIN students s ON s.student_id = e.student_id AND s.is_active = 1
             WHERE e.school_year_id = ? AND e.enrollment_status = 'Approved' AND e.section_id IS NOT NULL
             GROUP BY e.section_id
         ) live ON live.section_id = sec.section_id
         SET sec.current_enrollment = COALESCE(live.cnt, 0)
         WHERE sec.current_enrollment <> COALESCE(live.cnt, 0)",
        [$schoolYearId]
    );

    // One source of truth for a student's section: the approved enrollment of
    // the active school year. Keep students.section_id (read by some pages)
    // in step with it, e.g. after a transfer to another section.
    $active = getRecord("SELECT sy_id FROM school_years WHERE is_active = 1");
    if ($active && (int) $active['sy_id'] === $schoolYearId) {
        executeQuery(
            "UPDATE students s
             JOIN (
                 SELECT student_id, MAX(section_id) AS section_id
                 FROM enrollments
                 WHERE school_year_id = ? AND enrollment_status = 'Approved' AND section_id IS NOT NULL
                 GROUP BY student_id
                 HAVING COUNT(DISTINCT section_id) = 1
             ) e ON e.student_id = s.student_id
             SET s.section_id = e.section_id
             WHERE NOT (s.section_id <=> e.section_id)",
            [$schoolYearId]
        );
    }
}

function logActivity($userId, $module, $action, $details = null, $ipAddress = null) {
    global $db;
    
    try {
        // Get IP address if not provided
        if ($ipAddress === null) {
            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        }
        
        // Get user agent
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        
        // Some callers pass (action, module) instead of (module, action). A module
        // is a short lowercase key (e.g. "grading"); an action is words
        // (e.g. "Added assessment item"). Put each in its own column.
        if (is_string($action) && is_string($module)
            && preg_match('/^[a-z][a-z_]*$/', $action) && !preg_match('/^[a-z][a-z_]*$/', $module)) {
            [$module, $action] = [$action, $module];
        }

        // Convert details to JSON if array
        if (is_array($details)) {
            $details = json_encode($details);
        }
        
        // Use existing column names: log_id, description, created_at
        $query = "INSERT INTO activity_logs (user_id, module, action, description, ip_address, user_agent, created_at) 
                  VALUES (?, ?, ?, ?, ?, ?, NOW())";
        $stmt = $db->prepare($query);
        $stmt->execute([$userId, $module, $action, $details, $ipAddress, $userAgent]);
        
        return true;
    } catch (PDOException $e) {
        // Fallback to error log if DB insert fails
        error_log("Activity Log Error: " . $e->getMessage());
        error_log("User: $userId, Module: $module, Action: $action");
        return false;
    }
}

// ============================================================
// ADDITIONAL HELPER FUNCTIONS
// ============================================================

/**
 * Check if a record exists
 * @param string $table Table name
 * @param string $where WHERE clause (without the word "WHERE")
 * @param array $params Parameters for prepared statement
 * @return bool True if exists, false otherwise
 */
function recordExists($table, $where, $params = []) {
    $count = getCount($table, $where, $params);
    return $count > 0;
}

/**
 * Get single value from database
 * @param string $sql SQL query with placeholders
 * @param array $params Parameters for prepared statement
 * @return mixed|null Returns value or null
 */
function getValue($sql, $params = []) {
    $result = getRecord($sql, $params);
    if ($result) {
        return reset($result);
    }
    return null;
}

/**
 * Begin transaction
 */
function beginTransaction() {
    global $db;
    return $db->beginTransaction();
}

/**
 * Commit transaction
 */
function commitTransaction() {
    global $db;
    return $db->commit();
}

/**
 * Rollback transaction
 */
function rollbackTransaction() {
    global $db;
    return $db->rollBack();
}

/**
 * Escape string for LIKE queries
 * @param string $string String to escape
 * @return string Escaped string
 */
function escapeLike($string) {
    return str_replace(['%', '_'], ['\%', '\_'], $string);
}

/**
 * Get current timestamp
 * @return string Current timestamp in Y-m-d H:i:s format
 */
function getCurrentTimestamp() {
    return date('Y-m-d H:i:s');
}

/**
 * Format date for display
 * @param string $date Date string
 * @param string $format Format (default: 'M d, Y')
 * @return string Formatted date
 */
function formatDate($date, $format = 'M d, Y') {
    if (empty($date)) {
        return '';
    }
    $timestamp = strtotime($date);
    return date($format, $timestamp);
}

/**
 * Get school name from settings
 * @return string School name
 */
function getSchoolName() {
    $setting = getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'school_name'");
    return $setting ? $setting['setting_value'] : 'Imelda Integrated Secondary School';
}

// ============================================================
// INITIALIZATION: CREATE DEFAULT TABLES IF NOT EXISTS
// ============================================================

// Create subjects table if not exists
$create_subjects = "
CREATE TABLE IF NOT EXISTS subjects (
    subject_id INT AUTO_INCREMENT PRIMARY KEY,
    subject_code VARCHAR(20) NOT NULL UNIQUE,
    subject_name VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
executeQuery($create_subjects);

// Create teacher_subjects table if not exists
$create_teacher_subjects = "
CREATE TABLE IF NOT EXISTS teacher_subjects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    subject_id INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_teacher_subject (teacher_id, subject_id)
)";

executeQuery($create_teacher_subjects);

// Master class schedule used by Subject Management, Teacher Attendance,
// Teacher Portal and Student Portal. Safe/additive: no existing assignment
// or grade data is changed. One row = one class meeting on one weekday.
$create_teacher_class_schedule = "
CREATE TABLE IF NOT EXISTS teacher_class_schedule (
    schedule_id INT AUTO_INCREMENT PRIMARY KEY,
    teacher_id INT NOT NULL,
    subject_code VARCHAR(20) NOT NULL,
    section_id INT NOT NULL,
    school_year_id INT NOT NULL,
    day_of_week TINYINT NOT NULL,
    start_time TIME NOT NULL,
    end_time TIME NOT NULL,
    shift_type VARCHAR(30) NOT NULL DEFAULT 'Custom',
    shift VARCHAR(10) NULL DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tcs_teacher_day (teacher_id, school_year_id, day_of_week, start_time, end_time),
    INDEX idx_tcs_section_day (section_id, school_year_id, day_of_week, start_time, end_time),
    INDEX idx_tcs_assignment (teacher_id, subject_code, section_id, school_year_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
";
executeQuery($create_teacher_class_schedule);
// Existing installations may already have teacher_class_schedule from the
// Attendance module. Add the simple AM/PM master-schedule value if needed.
executeQuery("ALTER TABLE teacher_class_schedule ADD COLUMN IF NOT EXISTS shift VARCHAR(10) NULL DEFAULT NULL AFTER shift_type");

// Create activity_logs table if not exists (optional)
$create_activity_logs = "
CREATE TABLE IF NOT EXISTS activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    module VARCHAR(50),
    action VARCHAR(100),
    description TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_id (user_id),
    INDEX idx_created_at (created_at)
)";
executeQuery($create_activity_logs);

// Insert default subjects if table is empty
$subject_count = getCount('subjects');
if ($subject_count == 0) {
    $default_subjects = [
        ['MATH101', 'General Mathematics'],
        ['MATH102', 'Statistics and Probability'],
        ['ENG101', 'English for Academic Purposes'],
        ['ENG102', 'Creative Writing'],
        ['SCI101', 'General Biology'],
        ['SCI102', 'General Chemistry'],
        ['FIL101', 'Komunikasyon at Pananaliksik'],
        ['FIL102', 'Pagbasa at Pagsusuri'],
        ['AP101', 'Philippine Politics and Governance'],
        ['AP102', 'Understanding Culture, Society and Politics'],
        ['TLE101', 'Computer Systems Servicing'],
        ['MAPEH101', 'Music and Arts'],
        ['PE101', 'Physical Education and Health']
    ];
    
    foreach ($default_subjects as $subject) {
        executeQuery("INSERT IGNORE INTO subjects (subject_code, subject_name) VALUES (?, ?)", $subject);
    }
}

// Create roles table if not exists (for auth)
$create_roles = "
CREATE TABLE IF NOT EXISTS roles (
    role_id INT AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL UNIQUE,
    role_description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
)";
executeQuery($create_roles);

// Insert default roles
$role_count = getCount('roles');
if ($role_count == 0) {
    executeQuery("INSERT IGNORE INTO roles (role_name) VALUES ('Administrator')");
    executeQuery("INSERT IGNORE INTO roles (role_name) VALUES ('Teacher')");
    executeQuery("INSERT IGNORE INTO roles (role_name) VALUES ('Student')");
}

// Create dashboard_preferences table if not exists — backs the
// Customizable Admin Dashboard (per-user widget visibility/order; see
// includes/dashboard_helpers.php and database/migrations/2026_dashboard_preferences.sql
// for the fuller, foreign-keyed version of this same table). No FK here,
// same as the other tables in this initialization block, so this
// auto-create can never fail because of engine/charset timing — it only
// ever stores presentation preferences, never any dashboard statistic.
$create_dashboard_preferences = "
CREATE TABLE IF NOT EXISTS dashboard_preferences (
    preference_id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    widget_key VARCHAR(50) NOT NULL,
    is_visible TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_widget (user_id, widget_key),
    INDEX idx_dashboard_preferences_user (user_id)
)";
executeQuery($create_dashboard_preferences);

// Seed the "Enrollment Officer" role if it isn't there yet — same safe,
// additive, re-run-safe pattern as database/migrations/2026_enrollment_officer_role.sql
// (which stays as the canonical migration for a manual import). Does NOT
// touch, renumber, or remove any existing role — Administrator, Registrar,
// Teacher, Student and Parent are all untouched. Enrollment Officer reviews
// and processes online enrollment applications and walk-in enrollment
// through the existing modules/enrollment/* pages; it does not get full
// Administrator or Registrar access (see includes/header.php's sidebar and
// index.php's dashboard for what it can actually see).
if (!getRecord("SELECT role_id FROM roles WHERE role_name = ?", ['Enrollment Officer'])) {
    executeQuery("INSERT INTO roles (role_name, role_description) VALUES (?, ?)", [
        'Enrollment Officer',
        'Reviews and processes online enrollment applications and the enrollment records they create. Does not have full Administrator or Registrar access.'
    ]);
}

// One-time data correction: "Activity" items belong under Written Works,
// together with Quizzes -- NOT under Performance Tasks. Some existing
// assessment items (e.g. "Activity 1", "Act 2") were filed under the
// Performance Tasks category; per the school's grading policy these are
// in-class formative Written Work, so their category is corrected here.
// Only genuine Performance Tasks (projects, practicals, recitations, etc.)
// stay under Performance Tasks.
//
// Additive and re-run-safe, same pattern as the rest of this file:
//   - Guarded by a system_settings flag so the (indexed) UPDATE only ever
//     runs once, not on every request.
//   - Touches ONLY assessment_items.category. It never changes a raw
//     assessment_scores value and never rewrites an already-finalized
//     `grades`/`grade_components` row -- every existing student score and
//     historical grading record stays exactly as it was recorded. The
//     corrected category only affects a *future* Save/Finalize computation
//     (Written Works now correctly pools Quizzes + Activities via Total
//     Raw Score / Total HPS x 100, and Performance Tasks no longer double
//     counts them).
//   - The name match is intentionally conservative (whole item name only,
//     e.g. "Activity 1", "Act. 2", "ACT3") so it can never catch an
//     unrelated Performance Task whose name merely contains "act" as a
//     substring (e.g. "Practical Exam", "Interactive Demo").
if (!getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'migration_activity_items_to_ww'")) {
    executeQuery("
        UPDATE assessment_items
        SET category = 'WW'
        WHERE category = 'PT'
          AND item_name REGEXP '^[[:space:]]*act(ivity)?\\.?[[:space:]]*[0-9]*[[:space:]]*$'
    ");
    executeQuery(
        "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)",
        ['migration_activity_items_to_ww', '1', 'system']
    );
}

// One-time data correction: SY 2026-2027 Senior High subject master list.
// The Grade 11/12 subjects that were in the table (both the 'GAS'-strand
// rows and the 'HUMSS'-strand rows) do not match the school's official
// curriculum for SY 2026-2027, and the ASSH Cluster (which every strand
// dropdown in this system -- Subject Management, Online Enrollment,
// Enrollment, Student bulk-import, Settings -- already expects instead of
// HUMSS) had no subjects of its own yet.
//
//   - Guarded by a system_settings flag so this only ever runs once.
//   - Deactivates (is_active = 0) every currently-active Grade 11/12
//     subject row. NEVER deletes them -- they stay in the table for
//     historical reference, just hidden from active dropdowns/lists
//     (every subject query in this codebase filters WHERE is_active = 1).
//     Verified before writing this: none of these rows are referenced by
//     any teacher_subjects, teacher_subject_assignments, grades,
//     grade_components, assessment_items or assessment_plans row, so no
//     existing assignment or encoded grade is affected.
//   - Adds the correct Grade 11 GAS, Grade 11 ASSH, Grade 12 GAS and
//     Grade 12 ASSH subjects (66 rows) as new, uniquely-coded, active
//     rows, matching the school's official Term 1-3 subject list.
//   - Touches ONLY the `subjects` table. It does not touch students,
//     enrollments, sections, teacher_subjects, grades, grade_components,
//     or any historical record. Existing HUMSS-labeled sections and
//     enrollments (including the school's prior-year HUMSS sections, and
//     the current-year HUMSS enrollment records left for the registrar to
//     reassign by hand) are completely unaffected -- this migration never
//     writes to `sections` or `enrollments`.
//   - MAPEH (Grades 7-10) is intentionally NOT touched: its two-component
//     structure (Music and Arts / PE and Health) is the official DO 015,
//     s. 2026 ECR structure already implemented in
//     includes/grading_helpers.php (gh_fixedComponentSets()), and already
//     has real teacher-entered assessment data keyed to it.
// MAPEH is now graded as four components (Music / Arts / Physical Education /
// Health) instead of two. Only rows still on the old two-component value are
// changed; saved items keep their old tag and still count
// (gh_effectiveComponents() in includes/grading_helpers.php).
if (!getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'migration_2026_mapeh_four_components'")) {
    executeQuery("UPDATE subjects SET components = 'Music, Arts, Physical Education, Health'
                  WHERE subject_category = 'ks_mapeh' AND REPLACE(components, ' ', '') = 'MusicandArts,PEandHealth'");
    executeQuery("INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES ('migration_2026_mapeh_four_components', '1', 'system')
                  ON DUPLICATE KEY UPDATE setting_value = '1'");
}

if (!getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'migration_2026_shs_subjects_v1'")) {
    executeQuery("UPDATE subjects SET is_active = 0 WHERE grade_level IN ('Grade 11', 'Grade 12') AND is_active = 1");

    // [subject_code, subject_name, description, grade_level, strand, subject_type, units, terms_required]
    $shs_subjects_2026 = [
        ['G11GAS-PERSDEV', 'Personal Development', 'Grade 11 GAS - Term 1', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-LIT21', '21st Century Literature', 'Grade 11 GAS - Term 1', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-EMPTECH', 'Empowerment Technologies', 'Grade 11 GAS - Term 1', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-UCSP', 'Understanding Culture, Society and Politics', 'Grade 11 GAS - Term 1', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-ELS', 'Earth and Life Science', 'Grade 11 GAS - Term 1', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-ORALCOM', 'Oral Communication', 'Grade 11 GAS - Term 1', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-GENMATH', 'General Mathematics', 'Grade 11 GAS - Term 1', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-HOPE1', 'Health-Optimizing Physical Education 1 (HOPE 1)', 'Grade 11 GAS - Term 1', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-PHILO', 'Introduction to the Philosophy of the Human Person', 'Grade 11 GAS - Term 2', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-STATPROB', 'Statistics and Probability', 'Grade 11 GAS - Term 2', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-DISS', 'Disciplines and Ideas in the Social Sciences (DISS)', 'Grade 11 GAS - Term 2', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-READWRITE', 'Reading and Writing', 'Grade 11 GAS - Term 2', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-PR1', 'Practical Research 1 (PR1)', 'Grade 11 GAS - Term 3', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-WORLDREL', 'Introduction to World Religions and Belief Systems', 'Grade 11 GAS - Term 3', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-PAGBASA', 'Pagbasa at Pagsusuri ng Iba\'t Ibang Teksto Tungo sa Pananaliksik', 'Grade 11 GAS - Term 3', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11GAS-HOPE2', 'Health-Optimizing Physical Education 2 (HOPE 2)', 'Grade 11 GAS - Term 3', 'Grade 11', 'GAS', 'Academic Track', 1, 3],
        ['G11ASSH-ORALCOM', 'Oral Communication', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-KOMUNIK', 'Komunikasyon at Pananaliksik sa Wika at Kulturang Pilipino', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-GENMATH', 'General Mathematics', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-EARTHSCI', 'Earth Science', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-PERSDEV', 'Personal Development / Pansariling Kaunlaran', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-UCSP', 'Understanding Culture, Society and Politics', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-HOPE1', 'Health-Optimizing Physical Education 1', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-EMPTECH', 'Empowerment Technologies', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-LIT21', '21st Century Literature', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-READWRITE', 'Reading and Writing', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-PAGBASA', 'Pagbasa at Pagsusuri ng Iba\'t Ibang Teksto Tungo sa Pananaliksik', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-PHYSCI', 'Physical Science', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-STATPROB', 'Statistics and Probability', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-PHILO', 'Introduction to the Philosophy of the Human Person', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-HOPE2', 'Health-Optimizing Physical Education 2', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-DISS', 'Disciplines and Ideas in the Social Sciences', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-WORLDREL', 'Introduction to World Religions and Belief Systems', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G11ASSH-PR1', 'Practical Research 1', 'Grade 11 ASSH Cluster', 'Grade 11', 'ASSH', 'Academic Track', 1, 3],
        ['G12GAS-CPAR', 'Contemporary Philippine Arts from the Regions (CPAR)', 'Grade 12 GAS - Term 1', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-CREATWRITE', 'Creative Writing', 'Grade 12 GAS - Term 1', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-PR2', 'Practical Research 2 (PR2)', 'Grade 12 GAS - Term 1', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-PPG', 'Philippine Politics and Governance (PPG)', 'Grade 12 GAS - Term 1', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-DIASS', 'Disciplines and Ideas in Applied Social Sciences (DIASS)', 'Grade 12 GAS - Term 1', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-HOPE3', 'Health-Optimizing Physical Education 3 (HOPE 3)', 'Grade 12 GAS - Term 1', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-PAGSULAT', 'Pagsulat sa Filipino sa Piling Larang', 'Grade 12 GAS - Term 2', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-CESC', 'Community Engagement, Solidarity and Citizenship (CESC)', 'Grade 12 GAS - Term 2', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-MIL', 'Media and Information Literacy (MIL)', 'Grade 12 GAS - Term 2', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-EAPP', 'English for Academic and Professional Purposes (EAPP)', 'Grade 12 GAS - Term 2', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-ENTREP', 'Entrepreneurship', 'Grade 12 GAS - Term 2', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-III', 'Inquiries, Investigations and Immersion (III)', 'Grade 12 GAS - Term 3', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-WORKIMM', 'Work Immersion', 'Grade 12 GAS - Term 3', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-DRRR', 'Disaster Readiness and Risk Reduction (DRRR)', 'Grade 12 GAS - Term 3', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-TRENDS', 'Trends, Networks and Critical Thinking in the 21st Century', 'Grade 12 GAS - Term 3', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12GAS-HOPE4', 'Health-Optimizing Physical Education 4 (HOPE 4)', 'Grade 12 GAS - Term 3', 'Grade 12', 'GAS', 'Academic Track', 1, 3],
        ['G12ASSH-CPAR', 'Contemporary Philippine Arts from the Regions (CPAR)', 'Grade 12 ASSH - Term 1', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-CREATWRITE', 'Creative Writing', 'Grade 12 ASSH - Term 1', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-PR2', 'Practical Research 2 (PR2)', 'Grade 12 ASSH - Term 1', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-DIASS', 'Disciplines and Ideas in Applied Social Sciences (DIASS)', 'Grade 12 ASSH - Term 1', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-EAPP', 'English for Academic and Professional Purposes (EAPP)', 'Grade 12 ASSH - Term 1', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-HOPE3', 'Health-Optimizing Physical Education 3 (HOPE 3)', 'Grade 12 ASSH - Term 1', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-PPG', 'Philippine Politics and Governance (PPG)', 'Grade 12 ASSH - Term 2', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-PAGSULAT', 'Pagsulat sa Filipino sa Piling Larang', 'Grade 12 ASSH - Term 2', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-CESC', 'Community Engagement, Solidarity and Citizenship (CESC)', 'Grade 12 ASSH - Term 2', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-MIL', 'Media and Information Literacy (MIL)', 'Grade 12 ASSH - Term 2', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-ENTREP', 'Entrepreneurship', 'Grade 12 ASSH - Term 2', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-CREATNF', 'Creative Nonfiction', 'Grade 12 ASSH - Term 3', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-TRENDS', 'Trends, Networks and Critical Thinking in the 21st Century', 'Grade 12 ASSH - Term 3', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-III', 'Inquiries, Investigations and Immersion (III)', 'Grade 12 ASSH - Term 3', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-WORKIMM', 'Work Immersion / ASSH Culminating Activity', 'Grade 12 ASSH - Term 3', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
        ['G12ASSH-HOPE4', 'Health-Optimizing Physical Education 4 (HOPE 4)', 'Grade 12 ASSH - Term 3', 'Grade 12', 'ASSH', 'Academic Track', 1, 3],
    ];

    foreach ($shs_subjects_2026 as $s) {
        executeQuery(
            "INSERT IGNORE INTO subjects
                (subject_code, subject_name, description, grade_level, strand, subject_type, units, terms_required, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)",
            $s
        );
    }

    executeQuery(
        "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)",
        ['migration_2026_shs_subjects_v1', '1', 'system']
    );
}

// One-time data correction: give every Senior High subject a specific term
// (Term 1 / Term 2 / Term 3), so Teacher Management -> Assign Subject to
// Teacher can filter by Grade Level + Strand/Cluster + Term instead of
// showing every term's subjects mixed together.
//   - Adds `subjects.term` as a new NULLABLE column (IF NOT EXISTS, so this
//     is safe to run more than once even without the settings-flag guard
//     below). NULL for every existing Grades 7-10 subject and any other
//     subject outside this list -- those subjects run all three terms
//     already (subjects.terms_required = 3) and are completely unaffected;
//     no query anywhere else in the codebase reads this new column.
//   - Backfills `term` on exactly the 66 Grade 11/12 subjects added by the
//     migration above, matching the school's official Term 1-3 schedule
//     (Grade 11 ASSH Cluster's placement confirmed with the administrator,
//     mirroring Grade 11 GAS's term structure).
//   - Guarded by a system_settings flag so the backfill UPDATEs only ever
//     run once. Touches ONLY the `subjects` table -- no student, teacher,
//     section, enrollment or grade record is affected.
if (!getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'migration_2026_shs_subject_terms_v1'")) {
    executeQuery("ALTER TABLE subjects ADD COLUMN IF NOT EXISTS term VARCHAR(20) NULL DEFAULT NULL AFTER strand");

    executeQuery("UPDATE subjects SET term = 'Term 1' WHERE subject_code IN ('G11ASSH-EARTHSCI', 'G11ASSH-EMPTECH', 'G11ASSH-GENMATH', 'G11ASSH-HOPE1', 'G11ASSH-KOMUNIK', 'G11ASSH-LIT21', 'G11ASSH-ORALCOM', 'G11ASSH-PERSDEV', 'G11ASSH-PHYSCI', 'G11ASSH-UCSP', 'G11GAS-ELS', 'G11GAS-EMPTECH', 'G11GAS-GENMATH', 'G11GAS-HOPE1', 'G11GAS-LIT21', 'G11GAS-ORALCOM', 'G11GAS-PERSDEV', 'G11GAS-UCSP', 'G12ASSH-CPAR', 'G12ASSH-CREATWRITE', 'G12ASSH-DIASS', 'G12ASSH-EAPP', 'G12ASSH-HOPE3', 'G12ASSH-PR2', 'G12GAS-CPAR', 'G12GAS-CREATWRITE', 'G12GAS-DIASS', 'G12GAS-HOPE3', 'G12GAS-PPG', 'G12GAS-PR2')");
    executeQuery("UPDATE subjects SET term = 'Term 2' WHERE subject_code IN ('G11ASSH-DISS', 'G11ASSH-PHILO', 'G11ASSH-READWRITE', 'G11ASSH-STATPROB', 'G11GAS-DISS', 'G11GAS-PHILO', 'G11GAS-READWRITE', 'G11GAS-STATPROB', 'G12ASSH-CESC', 'G12ASSH-ENTREP', 'G12ASSH-MIL', 'G12ASSH-PAGSULAT', 'G12ASSH-PPG', 'G12GAS-CESC', 'G12GAS-EAPP', 'G12GAS-ENTREP', 'G12GAS-MIL', 'G12GAS-PAGSULAT')");
    executeQuery("UPDATE subjects SET term = 'Term 3' WHERE subject_code IN ('G11ASSH-HOPE2', 'G11ASSH-PAGBASA', 'G11ASSH-PR1', 'G11ASSH-WORLDREL', 'G11GAS-HOPE2', 'G11GAS-PAGBASA', 'G11GAS-PR1', 'G11GAS-WORLDREL', 'G12ASSH-CREATNF', 'G12ASSH-HOPE4', 'G12ASSH-III', 'G12ASSH-TRENDS', 'G12ASSH-WORKIMM', 'G12GAS-DRRR', 'G12GAS-HOPE4', 'G12GAS-III', 'G12GAS-TRENDS', 'G12GAS-WORKIMM')");

    executeQuery(
        "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)",
        ['migration_2026_shs_subject_terms_v1', '1', 'system']
    );
}

// NOTE: an earlier version of this migration created a new `term_schedules`
// table for automatic Term 1/2/3 switching in Teacher Management. That table
// turned out to duplicate a table that already existed and was already in
// production use: `term_windows` (read/written by includes/grading_helpers.php
// via gh_getTermWindow()/gh_setTermWindow(), and edited from "Manage
// Assessment Items" in modules/grading/items.php). Teacher Management's
// "Term Dates" button was corrected to read/write `term_windows` instead, so
// nothing in the app uses `term_schedules` any more. This migration now
// drops it -- it only ever held one admin's own test row, no real data.
if (!getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'migration_2026_drop_term_schedules_v1'")) {
    executeQuery("DROP TABLE IF EXISTS term_schedules");

    executeQuery(
        "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)",
        ['migration_2026_drop_term_schedules_v1', '1', 'system']
    );
}

// Google-Classroom-style Teacher Portal redesign: brand new, additive tables
// only. Nothing existing (teacher_subjects, subjects, ai_generated_resources,
// ai_resource_submissions, assessment_items, grades, etc.) is touched, dropped,
// or altered by this block -- the new "classroom" pages are a thin layer on
// top of the same teacher_subjects-based ownership already used everywhere
// else (see modules/grading/index.php, modules/teachers/my_sections.php).
//
//   - class_stream_posts / class_stream_attachments: the new "Stream" tab.
//     Nothing resembling a class-wide announcement/post feed existed before
//     this (confirmed against the live schema) -- student_support_messages is
//     strictly one-teacher-to-one-student and was not reused here on purpose.
//     Each post is scoped to one teacher+subject+section+school_year(+term),
//     the same 4-5 column key as teacher_subjects, so "who can see/post here"
//     is answered by the exact same ownership check already used elsewhere.
//   - classwork_assignments / classwork_assignment_attachments /
//     classwork_assignment_students: the new "Classwork" tab. This is a
//     class-scoped *header* around the existing, already-mature per-student
//     AI resource pipeline (ai_generated_resources / ai_resource_submissions
//     / ai_quiz_grade_sync.php) -- it does NOT replace or duplicate that
//     pipeline. Posting an AI-generated assignment to a whole class fans out
//     one ai_generated_resources row per enrolled student exactly as today,
//     and classwork_assignment_students.resource_id just links each of those
//     already-created rows back to the one class-wide assignment so the
//     Classwork tab can show them (and their submission progress) as a
//     single card instead of one row per student.
//   - No FKs, same as every other table in this initialization block, so
//     this auto-create can never fail because of engine/charset ordering.
if (!getRecord("SELECT setting_value FROM system_settings WHERE setting_key = 'migration_2026_classroom_ui_v1'")) {
    executeQuery("
        CREATE TABLE IF NOT EXISTS class_stream_posts (
            post_id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            subject_code VARCHAR(20) NOT NULL,
            section_id INT NOT NULL,
            school_year_id INT NOT NULL,
            term VARCHAR(20) NULL DEFAULT NULL,
            body TEXT NOT NULL,
            link_url VARCHAR(500) NULL DEFAULT NULL,
            is_deleted TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_class_stream_class (teacher_id, section_id, subject_code, school_year_id, term),
            INDEX idx_class_stream_created (created_at)
        )
    ");

    executeQuery("
        CREATE TABLE IF NOT EXISTS class_stream_attachments (
            attachment_id INT AUTO_INCREMENT PRIMARY KEY,
            post_id INT NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NULL DEFAULT NULL,
            mime_type VARCHAR(100) NULL DEFAULT NULL,
            file_size INT NULL DEFAULT NULL,
            attachment_type ENUM('file','link') NOT NULL DEFAULT 'file',
            external_url VARCHAR(500) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_class_stream_attach_post (post_id)
        )
    ");

    executeQuery("
        CREATE TABLE IF NOT EXISTS classwork_assignments (
            assignment_id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            subject_code VARCHAR(20) NOT NULL,
            section_id INT NOT NULL,
            school_year_id INT NOT NULL,
            term VARCHAR(20) NULL DEFAULT NULL,
            title VARCHAR(255) NOT NULL,
            category VARCHAR(30) NOT NULL DEFAULT 'Activity',
            instructions TEXT NULL,
            ai_generated TINYINT(1) NOT NULL DEFAULT 0,
            output_format VARCHAR(20) NULL DEFAULT NULL,
            due_date DATETIME NULL DEFAULT NULL,
            status ENUM('Draft','Published') NOT NULL DEFAULT 'Published',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_classwork_class (teacher_id, section_id, subject_code, school_year_id, term),
            INDEX idx_classwork_created (created_at)
        )
    ");

    executeQuery("
        CREATE TABLE IF NOT EXISTS classwork_assignment_attachments (
            attachment_id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            original_filename VARCHAR(255) NOT NULL,
            stored_filename VARCHAR(255) NULL DEFAULT NULL,
            mime_type VARCHAR(100) NULL DEFAULT NULL,
            file_size INT NULL DEFAULT NULL,
            attachment_type ENUM('file','link') NOT NULL DEFAULT 'file',
            external_url VARCHAR(500) NULL DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_classwork_attach_assignment (assignment_id)
        )
    ");

    executeQuery("
        CREATE TABLE IF NOT EXISTS classwork_assignment_students (
            link_id INT AUTO_INCREMENT PRIMARY KEY,
            assignment_id INT NOT NULL,
            student_id INT NOT NULL,
            resource_id INT NULL DEFAULT NULL,
            status ENUM('Assigned','Submitted','Reviewed') NOT NULL DEFAULT 'Assigned',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_classwork_student (assignment_id, student_id),
            INDEX idx_classwork_students_resource (resource_id)
        )
    ");

    executeQuery(
        "INSERT INTO system_settings (setting_key, setting_value, setting_group) VALUES (?, ?, ?)",
        ['migration_2026_classroom_ui_v1', '1', 'system']
    );
}
// Database rules (foreign keys / unique rules) added once -- see includes/data_integrity.php
require_once __DIR__ . '/../includes/data_integrity.php';
if (!empty($db)) {
    diEnsureIntegrity();
}

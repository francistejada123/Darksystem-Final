<?php
// includes/auth.php
// Authentication and session management

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ============================================================
// SESSION & AUTHENTICATION FUNCTIONS
// ============================================================

/**
 * Check if user is logged in
 * @return bool True if logged in, false otherwise
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

/**
 * Check if user has specific role
 * @param string $role Role name to check
 * @return bool True if has role, false otherwise
 */
function hasRole($role) {
    if (!isLoggedIn()) {
        return false;
    }
    
    // Check from session first (faster)
    if (isset($_SESSION['role']) && $_SESSION['role'] === $role) {
        return true;
    }
    
    // Verify from database
    global $db;
    $user_id = $_SESSION['user_id'];
    
    try {
        $sql = "SELECT r.role_name FROM users u 
                JOIN roles r ON u.role_id = r.role_id 
                WHERE u.user_id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute([$user_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['role_name'] === $role) {
            // Update session role
            $_SESSION['role'] = $result['role_name'];
            return true;
        }
    } catch (PDOException $e) {
        error_log("hasRole Error: " . $e->getMessage());
    }
    
    return false;
}

/**
 * Check if user has any of the given roles
 * @param array $roles Array of role names
 * @return bool True if has any role, false otherwise
 */
function hasAnyRole($roles) {
    if (!isLoggedIn()) {
        return false;
    }
    
    foreach ($roles as $role) {
        if (hasRole($role)) {
            return true;
        }
    }
    return false;
}

/**
 * Require user to be logged in
 * Redirects to login page if not logged in
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * Require specific role
 * Redirects to dashboard if user doesn't have the role
 * @param string $role Role name required
 */
function requireRole($role) {
    requireLogin();
    if (!hasRole($role)) {
        header('Location: index.php');
        exit;
    }
}

/**
 * Require any of the given roles
 * Redirects to dashboard if user doesn't have any of the roles
 * @param array $roles Array of role names
 */
function requireAnyRole($roles) {
    requireLogin();
    if (!hasAnyRole($roles)) {
        header('Location: index.php');
        exit;
    }
}

// ============================================================
// USER DATA FUNCTIONS
// ============================================================

/**
 * Get current user data
 * @return array|null User data or null if not logged in
 */
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    global $db;
    $user_id = $_SESSION['user_id'];
    
    try {
        $sql = "SELECT u.*, r.role_name 
                FROM users u 
                JOIN roles r ON u.role_id = r.role_id 
                WHERE u.user_id = ?";
        $result = getRecord($sql, [$user_id]);
        
        if ($result) {
            // Get additional data based on role
            $result['permissions'] = getRolePermissions($result['role_id']);
        }
        
        return $result;
    } catch (PDOException $e) {
        error_log("getCurrentUser Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Get role permissions
 * @param int $role_id Role ID
 * @return array Array of permissions
 */
function getRolePermissions($role_id) {
    $permissions = [
        1 => ['all', 'manage_users', 'manage_teachers', 'manage_students', 'manage_subjects', 'manage_enrollment', 'manage_settings'], // Administrator
        2 => ['manage_enrollment', 'manage_records', 'manage_reports'], // Registrar
        3 => ['manage_classes', 'manage_grading', 'manage_reports'], // Teacher
        4 => ['view_grades', 'view_enrollment'], // Student
        5 => ['view_grades', 'view_enrollment'] // Parent
    ];
    return isset($permissions[$role_id]) ? $permissions[$role_id] : [];
}

/**
 * Get user by username or email
 * @param string $username Username or email
 * @return array|null User data or null if not found
 */
function getUserByUsername($username) {
    try {
        $sql = "SELECT u.*, r.role_name 
                FROM users u 
                JOIN roles r ON u.role_id = r.role_id 
                WHERE u.username = ? OR u.email = ?";
        return getRecord($sql, [$username, $username]);
    } catch (PDOException $e) {
        error_log("getUserByUsername Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Update last login timestamp
 * @param int $user_id User ID
 * @return bool True on success, false on failure
 */
function updateLastLogin($user_id) {
    try {
        $sql = "UPDATE users SET last_login = NOW() WHERE user_id = ?";
        return executeQuery($sql, [$user_id]) !== false;
    } catch (PDOException $e) {
        error_log("updateLastLogin Error: " . $e->getMessage());
        return false;
    }
}

// ============================================================
// ACCOUNT LOCKOUT FUNCTIONS (brute-force protection)
// ============================================================

const LOCKOUT_MAX_ATTEMPTS = 5;
const LOCKOUT_MINUTES = 15;

/**
 * Check if a users-table account (by user_id) is currently locked.
 * @param array $user Row from users table (must include locked_until)
 * @return bool True if still locked
 */
function isAccountLocked($user) {
    if (empty($user['locked_until'])) {
        return false;
    }
    return strtotime($user['locked_until']) > time();
}

/**
 * Record a failed login for a users-table account and lock it if the
 * attempt threshold is reached.
 * @param int $user_id
 */
function registerFailedLogin($user_id) {
    try {
        $sql = "UPDATE users SET failed_attempts = failed_attempts + 1,
                locked_until = CASE
                    WHEN failed_attempts + 1 >= ? THEN DATE_ADD(NOW(), INTERVAL ? MINUTE)
                    ELSE locked_until
                END
                WHERE user_id = ?";
        executeQuery($sql, [LOCKOUT_MAX_ATTEMPTS, LOCKOUT_MINUTES, $user_id]);
    } catch (PDOException $e) {
        error_log("registerFailedLogin Error: " . $e->getMessage());
    }
}

/**
 * Reset failed-attempt counter after a successful login.
 * @param int $user_id
 */
function resetFailedLogin($user_id) {
    try {
        executeQuery("UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE user_id = ?", [$user_id]);
    } catch (PDOException $e) {
        error_log("resetFailedLogin Error: " . $e->getMessage());
    }
}

/**
 * IP-based rate limiting for the LRN-only student portal (no password there,
 * so per-account lockout doesn't apply — this blocks LRN-guessing by IP
 * instead, using the existing activity_logs table).
 */
const STUDENT_RATE_MAX_ATTEMPTS = 10;
const STUDENT_RATE_WINDOW_MINUTES = 15;

/**
 * Check if the current IP has too many recent failed student-portal logins.
 * @return bool True if blocked
 */
function isStudentIpRateLimited() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    try {
        $sql = "SELECT COUNT(*) as cnt FROM activity_logs
                WHERE module = 'student' AND action = 'Failed login' -- what student_login.php logs via logActivity(null, 'student', 'Failed login', ...)
                AND ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)";
        $result = getRecord($sql, [$ip, STUDENT_RATE_WINDOW_MINUTES]);
        return $result && (int)$result['cnt'] >= STUDENT_RATE_MAX_ATTEMPTS;
    } catch (PDOException $e) {
        error_log("isStudentIpRateLimited Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Minutes remaining until an account unlocks. Returns 0 if not locked.
 */
function lockoutMinutesRemaining($locked_until) {
    if (empty($locked_until)) {
        return 0;
    }
    $diff = strtotime($locked_until) - time();
    return $diff > 0 ? (int)ceil($diff / 60) : 0;
}

// ============================================================
// AUTHENTICATION HELPER FUNCTIONS
// ============================================================

/**
 * Login user
 * @param int $user_id User ID
 * @param string $role User role
 * @param bool $remember Remember me (set longer session)
 * @return bool True on success
 */
function loginUser($user_id, $role, $remember = false) {
    $_SESSION['user_id'] = $user_id;
    $_SESSION['role'] = $role;
    $_SESSION['logged_in'] = true;
    
    // Remember me - set session lifetime to 30 days
    if ($remember) {
        ini_set('session.cookie_lifetime', 60 * 60 * 24 * 30);
    }
    
    // Update last login
    updateLastLogin($user_id);
    
    // Log the login activity (gumagamit ng logActivity mula sa database.php)
    if (function_exists('logActivity')) {
        logActivity($user_id, 'auth', 'Login', 'User logged in');
    }
    
    // Regenerate session ID for security
    session_regenerate_id(true);
    
    return true;
}

/**
 * Logout user
 * @return bool True on success
 */
function logoutUser() {
    // Log the logout activity (gumagamit ng logActivity mula sa database.php)
    if (isLoggedIn() && function_exists('logActivity')) {
        logActivity($_SESSION['user_id'], 'auth', 'Logout', 'User logged out');
    }
    
    // Clear all session variables
    $_SESSION = [];
    
    // Delete session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    
    // Destroy session
    session_destroy();
    
    return true;
}

/**
 * Check if user has permission
 * @param string $permission Permission to check
 * @return bool True if has permission, false otherwise
 */
function hasPermission($permission) {
    if (!isLoggedIn()) {
        return false;
    }
    
    $user = getCurrentUser();
    if (!$user) {
        return false;
    }
    
    $permissions = getRolePermissions($user['role_id']);
    return in_array('all', $permissions) || in_array($permission, $permissions);
}

// ============================================================
// NOTIFICATION FUNCTIONS (nasa auth.php lang ito)
// ============================================================

/**
 * Create notification for user
 * @param int $user_id User ID
 * @param string $title Notification title
 * @param string $message Notification message
 * @param string $type Notification type (info, success, warning, error)
 * @param string $link Optional link
 * @return bool True on success, false on failure
 */
function createNotification($user_id, $title, $message, $type = 'info', $link = '') {
    try {
        $sql = "INSERT INTO notifications (user_id, title, message, type, link, created_at) 
                VALUES (?, ?, ?, ?, ?, NOW())";
        return executeQuery($sql, [$user_id, $title, $message, $type, $link]) !== false;
    } catch (PDOException $e) {
        error_log("createNotification Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get unread notifications count for user
 * @param int $user_id User ID
 * @return int Number of unread notifications
 */
function getUnreadNotifications($user_id) {
    try {
        $sql = "SELECT COUNT(*) as count FROM notifications 
                WHERE user_id = ? AND is_read = 0";
        $result = getRecord($sql, [$user_id]);
        return $result ? (int)$result['count'] : 0;
    } catch (PDOException $e) {
        error_log("getUnreadNotifications Error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get all notifications for user
 * @param int $user_id User ID
 * @param int $limit Limit number of notifications
 * @return array Array of notifications
 */
function getNotifications($user_id, $limit = 20) {
    try {
        $sql = "SELECT * FROM notifications 
                WHERE user_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?";
        return getRecords($sql, [$user_id, $limit]);
    } catch (PDOException $e) {
        error_log("getNotifications Error: " . $e->getMessage());
        return [];
    }
}

/**
 * Mark notification as read
 * @param int $notification_id Notification ID
 * @return bool True on success, false on failure
 */
function markNotificationRead($notification_id) {
    try {
        $sql = "UPDATE notifications SET is_read = 1 WHERE notification_id = ?";
        return executeQuery($sql, [$notification_id]) !== false;
    } catch (PDOException $e) {
        error_log("markNotificationRead Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Mark all notifications as read for user
 * @param int $user_id User ID
 * @return bool True on success, false on failure
 */
function markAllNotificationsRead($user_id) {
    try {
        $sql = "UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0";
        return executeQuery($sql, [$user_id]) !== false;
    } catch (PDOException $e) {
        error_log("markAllNotificationsRead Error: " . $e->getMessage());
        return false;
    }
}
?>
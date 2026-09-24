<?php
// modules/settings/school_years.php
// School Year management actions

require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/grading_helpers.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', '/watdahel/');
}

if (!hasRole('Administrator')) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$action = isset($_GET['action']) ? sanitize($_GET['action']) : (isset($_POST['action']) ? sanitize($_POST['action']) : '');

// Handle Add School Year
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'add_sy') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $_SESSION['error_message'] = 'Invalid security token.';
        header('Location: index.php?tab=school_years');
        exit;
    }
    
    $sy_name = sanitize($_POST['sy_name'] ?? '');
    $start_date = sanitize($_POST['start_date'] ?? '');
    $end_date = sanitize($_POST['end_date'] ?? '');
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $errors = [];
    
    if (empty($sy_name)) $errors[] = 'School year name is required.';
    if (empty($start_date)) $errors[] = 'Start date is required.';
    if (empty($end_date)) $errors[] = 'End date is required.';
    
    // Check duplicate
    if (empty($errors)) {
        $check = getRecord("SELECT sy_id FROM school_years WHERE sy_name = ?", [$sy_name]);
        if ($check) {
            $errors[] = 'School year already exists.';
        }
    }
    
    if (empty($errors)) {
        // If setting as active, deactivate all others
        if ($is_active) {
            executeQuery("UPDATE school_years SET is_active = 0 WHERE is_active = 1");
        }
        
        // Every new school year is created with the DepEd Three-Term
        // structure. There is no 'quarter' option to choose from, and the
        // column is enum('term') in the database, so this is written
        // explicitly rather than left to a default that could drift.
        $sql = "INSERT INTO school_years (sy_name, start_date, end_date, is_active, grading_structure) 
                VALUES (?, ?, ?, ?, 'term')";
        $result = executeQuery($sql, [$sy_name, $start_date, $end_date, $is_active]);
        
        if ($result) {
            logActivity($_SESSION['user_id'], 'Added school year', 'settings', "Added school year: $sy_name");
            $_SESSION['success_message'] = 'School year added successfully!';
        } else {
            $_SESSION['error_message'] = 'Failed to add school year.';
        }
    } else {
        $_SESSION['error_message'] = implode('<br>', $errors);
    }
    
    header('Location: index.php?tab=school_years');
    exit;
}

// Handle Activate School Year
if (isset($_GET['action']) && $_GET['action'] === 'activate' && isset($_GET['id'])) {
    $sy_id = intval($_GET['id']);
    
    // Deactivate all
    executeQuery("UPDATE school_years SET is_active = 0 WHERE is_active = 1");
    
    // Activate selected
    $result = executeQuery("UPDATE school_years SET is_active = 1 WHERE sy_id = ?", [$sy_id]);
    
    if ($result) {
        $sy = getRecord("SELECT sy_name FROM school_years WHERE sy_id = ?", [$sy_id]);
        logActivity($_SESSION['user_id'], 'Activated school year', 'settings', "Activated school year: " . ($sy['sy_name'] ?? ''));
        $_SESSION['success_message'] = 'School year activated successfully!';
    } else {
        $_SESSION['error_message'] = 'Failed to activate school year.';
    }
    
    header('Location: index.php?tab=school_years');
    exit;
}

// Handle Delete School Year
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $sy_id = intval($_GET['id']);
    
    // Check if it's active
    $sy = getRecord("SELECT is_active, sy_name FROM school_years WHERE sy_id = ?", [$sy_id]);
    if ($sy && $sy['is_active'] == 1) {
        $_SESSION['error_message'] = 'Cannot delete active school year. Deactivate it first.';
        header('Location: index.php?tab=school_years');
        exit;
    }
    
    $result = executeQuery("DELETE FROM school_years WHERE sy_id = ?", [$sy_id]);
    
    if ($result) {
        logActivity($_SESSION['user_id'], 'Deleted school year', 'settings', "Deleted school year ID: $sy_id");
        $_SESSION['success_message'] = 'School year deleted successfully!';
    } else {
        $_SESSION['error_message'] = 'Failed to delete school year.';
    }
    
    header('Location: index.php?tab=school_years');
    exit;
}

// If no action, redirect
header('Location: index.php?tab=school_years');
exit;
?>
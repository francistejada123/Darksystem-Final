<?php
// includes/dashboard_helpers.php
// Customizable Admin/Registrar Dashboard — per-user widget visibility and
// order, layered on TOP of the existing index.php dashboard. This file
// does not compute, fetch, or alter any statistic; it only decides which
// of the already-existing widgets render and in what order, backed by the
// additive `dashboard_preferences` table
// (see database/migrations/2026_dashboard_preferences.sql).

/**
 * Canonical registry of the Administrator/Registrar dashboard widgets.
 * 'order' here is the ORIGINAL default order — also what a user who has
 * never customized their dashboard sees, and what "Reset to Default"
 * restores. 'span' is a CSS class (inline <style> block in index.php)
 * controlling how wide the widget renders inside the dashboard grid.
 *
 * IMPORTANT: keys must stay stable — they are stored in the database.
 * Renaming a key here would silently orphan any preferences already
 * saved for it (the widget would just fall back to being visible at its
 * default position, never lost data, but also never respecting the old
 * saved value). Add new widgets by appending a new key; don't reuse or
 * rename existing ones.
 */
function getDashboardWidgetRegistry() {
    return [
        'stat_total_students'     => ['label' => 'Total Students',     'order' => 1,  'span' => 'dw-span-stat'],
        'stat_total_teachers'     => ['label' => 'Total Teachers',     'order' => 2,  'span' => 'dw-span-stat'],
        'stat_active_enrollments' => ['label' => 'Active Enrollments', 'order' => 3,  'span' => 'dw-span-stat'],
        'stat_passing_rate'       => ['label' => 'Passing Rate',       'order' => 4,  'span' => 'dw-span-stat'],
        'card_enrollment_status'  => ['label' => 'Enrollment Status',  'order' => 5,  'span' => 'dw-span-3'],
        'card_enrollment_trend'   => ['label' => 'Enrollment Trend',   'order' => 6,  'span' => 'dw-span-5'],
        'card_system_overview'    => ['label' => 'System Overview',    'order' => 7,  'span' => 'dw-span-4'],
        'card_recent_enrollments' => ['label' => 'Recent Enrollments', 'order' => 8,  'span' => 'dw-span-8'],
        'card_at_risk_students'   => ['label' => 'At-Risk Students',   'order' => 9,  'span' => 'dw-span-4'],
        'card_recent_activity'    => ['label' => 'Recent Activity',    'order' => 10, 'span' => 'dw-span-12'],
    ];
}

/**
 * Merge a user's saved dashboard_preferences rows with the registry
 * defaults, so a widget nobody has ever customized still renders visible
 * in its original position. Every registry key is guaranteed to be
 * present in the returned array, so callers never need an isset() check.
 *
 * @param int $user_id
 * @return array widget_key => ['label'=>string,'span'=>string,'visible'=>bool,'order'=>int]
 *               sorted ascending by 'order'.
 */
function getDashboardPreferences($user_id) {
    $registry = getDashboardWidgetRegistry();

    $rows = getRecords(
        "SELECT widget_key, is_visible, sort_order FROM dashboard_preferences WHERE user_id = ?",
        [$user_id]
    );
    $saved = [];
    foreach ($rows as $row) {
        $saved[$row['widget_key']] = $row;
    }

    $result = [];
    foreach ($registry as $key => $def) {
        if (isset($saved[$key])) {
            $result[$key] = [
                'label'   => $def['label'],
                'span'    => $def['span'],
                'visible' => (bool)$saved[$key]['is_visible'],
                'order'   => (int)$saved[$key]['sort_order'],
            ];
        } else {
            $result[$key] = [
                'label'   => $def['label'],
                'span'    => $def['span'],
                'visible' => true,
                'order'   => $def['order'],
            ];
        }
    }

    uasort($result, function ($a, $b) {
        return $a['order'] <=> $b['order'];
    });

    return $result;
}

/**
 * Save a user's widget visibility/order choices.
 * @param int $user_id
 * @param array $widgets [ ['key' => 'stat_total_students', 'visible' => true, 'order' => 1], ... ]
 *                        Any key not in the registry is silently ignored.
 * @return bool True on success.
 */
function saveDashboardPreferences($user_id, $widgets) {
    $registry = getDashboardWidgetRegistry();
    global $db;

    try {
        $db->beginTransaction();

        $sql = "INSERT INTO dashboard_preferences (user_id, widget_key, is_visible, sort_order)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE is_visible = VALUES(is_visible), sort_order = VALUES(sort_order)";
        $stmt = $db->prepare($sql);

        foreach ($widgets as $w) {
            $key = $w['key'] ?? '';
            if (!isset($registry[$key])) {
                continue; // defensive: ignore anything that isn't a real widget
            }
            $visible = !empty($w['visible']) ? 1 : 0;
            $order = (int)($w['order'] ?? 0);
            $stmt->execute([$user_id, $key, $visible, $order]);
        }

        $db->commit();
        return true;
    } catch (PDOException $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log("saveDashboardPreferences Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Reset a user's dashboard back to the default layout by deleting all
 * their saved preferences. getDashboardPreferences() then falls back to
 * the registry defaults for every widget.
 * @param int $user_id
 * @return bool True on success.
 */
function resetDashboardPreferences($user_id) {
    return deleteRecord("DELETE FROM dashboard_preferences WHERE user_id = ?", [$user_id]) !== false;
}

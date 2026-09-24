<?php
// includes/student_portal_ui.php
// Small, shared presentation helpers for the Student Portal's Google
// Classroom-style "Classes" grid (modules/students/portal/dashboard.php)
// and each subject's own detail page (modules/students/portal/subject.php).
// Split out into one file so both pages always agree on which color a
// given subject gets, instead of keeping two copies of the same
// palette/hash logic in sync by hand -- a class card on the dashboard and
// that same subject's banner on its detail page should always match.

if (!function_exists('spSubjectColorPalette')) {
    /**
     * A fixed, Google-Classroom-like flat color palette. Deliberately NOT
     * randomized per page load -- spSubjectColor() always maps the same
     * subject_code to the same color, so a student's class cards never
     * shuffle color on a refresh.
     */
    function spSubjectColorPalette()
    {
        return [
            '#123B6D', // school navy
            '#9A7300', // readable gold
            '#24527F', // heritage blue
            '#7E6100', // deep gold
            '#163F70', // academy navy
            '#A27A00', // warm gold
            '#1B466F', // muted blue
            '#6F5600', // bronze gold
            '#2D5B83', // slate blue
            '#8A6800', // banner gold
        ];
    }
}

if (!function_exists('spSubjectColor')) {
    /**
     * Deterministic color for one subject_code, from spSubjectColorPalette().
     * @param string $subject_code
     * @return string A hex color, e.g. '#1A73E8'.
     */
    function spSubjectColor($subject_code)
    {
        $palette = spSubjectColorPalette();
        $index = crc32((string) $subject_code) % count($palette);
        if ($index < 0) {
            $index += count($palette);
        }
        return $palette[$index];
    }
}

if (!function_exists('spInitial')) {
    /** First letter of a name, uppercased -- for a small avatar circle. Falls back to '?' for an empty name. */
    function spInitial($name)
    {
        $name = trim((string) $name);
        return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
    }
}

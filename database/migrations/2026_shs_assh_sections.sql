-- 2026_shs_assh_sections.sql
-- OPTIONAL and idempotent (safe to run more than once). Run it only if the live
-- database is missing any of the Grade 11/12 ASSH sections for the active school
-- year. It never deletes or updates anything: it only INSERTs the ASSH A/B/C
-- sections that do not exist yet. HUMSS rows stay on file (historical records);
-- the application simply no longer lists or assigns them.

INSERT INTO sections (section_name, strand, grade_level, is_senior_high, academic_year, capacity, current_enrollment, is_active)
SELECT t.section_name, 'ASSH', t.grade_level, 1, sy.sy_name, 40, 0, 1
FROM (
    SELECT '11-ASSH A' AS section_name, 'Grade 11' AS grade_level UNION ALL
    SELECT '11-ASSH B', 'Grade 11' UNION ALL
    SELECT '11-ASSH C', 'Grade 11' UNION ALL
    SELECT '12-ASSH A', 'Grade 12' UNION ALL
    SELECT '12-ASSH B', 'Grade 12' UNION ALL
    SELECT '12-ASSH C', 'Grade 12'
) t
JOIN school_years sy ON sy.is_active = 1
WHERE NOT EXISTS (
    SELECT 1 FROM sections x
    WHERE x.section_name = t.section_name AND x.academic_year = sy.sy_name
);

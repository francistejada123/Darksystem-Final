-- 2026_retire_humss_sections.sql
-- Hides the old Grade 11/12 HUMSS sections (11-HUMSS A/B/C, 12-HUMSS A/B/C)
-- everywhere in EGradePro (Grade Management / Class Record, Subject Management,
-- teachers, enrollment...): every page lists only sections with is_active = 1.
-- Only the ASSH Cluster and GAS sections stay for Grade 11 and 12.
--
-- Safe: nothing is deleted. A HUMSS section that still has an enrolled
-- student this school year is NOT touched (move the student first).
-- To undo: set is_active = 1 again for those sections.

UPDATE sections s
SET s.is_active = 0
WHERE s.grade_level IN ('Grade 11', 'Grade 12')
  AND (UPPER(TRIM(s.strand)) = 'HUMSS' OR s.section_name LIKE '%HUMSS%')
  AND NOT EXISTS (
      SELECT 1 FROM enrollments e
      JOIN school_years sy ON sy.sy_id = e.school_year_id AND sy.is_active = 1
      WHERE e.section_id = s.section_id
  );

-- Check: should list only ASSH and GAS sections for Grade 11/12.
SELECT section_id, section_name, strand, grade_level, is_active
FROM sections
WHERE grade_level IN ('Grade 11', 'Grade 12')
ORDER BY grade_level, is_active DESC, section_name;

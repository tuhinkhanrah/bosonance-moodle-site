-- Read-only Moodle Question Bank Integrity Audit (MariaDB)
-- Safe: executes SELECT queries only; makes no data changes.

-- 1. Question categories with missing context
SELECT
    'cat_missing_context' AS issue_type,
    qc.id AS record_id,
    qc.parent AS parent_or_ref_1,
    qc.contextid AS context_or_ref_2,
    qc.name AS detail
FROM mdl_question_categories qc
LEFT JOIN mdl_context ctx ON ctx.id = qc.contextid
WHERE ctx.id IS NULL

UNION ALL

-- 2. Question categories with missing parent category (when parent > 0)
SELECT
    'cat_missing_parent' AS issue_type,
    qc.id AS record_id,
    qc.parent AS parent_or_ref_1,
    qc.contextid AS context_or_ref_2,
    qc.name AS detail
FROM mdl_question_categories qc
LEFT JOIN mdl_question_categories p ON p.id = qc.parent
WHERE qc.parent > 0
  AND p.id IS NULL

UNION ALL

-- 3. Question categories with contextid mismatch against parent category
SELECT
    'cat_context_mismatch' AS issue_type,
    qc.id AS record_id,
    qc.parent AS parent_or_ref_1,
    qc.contextid AS context_or_ref_2,
    CONCAT(qc.name, ' (parent ctx=', p.contextid, ')') AS detail
FROM mdl_question_categories qc
JOIN mdl_question_categories p ON p.id = qc.parent
WHERE qc.contextid <> p.contextid

UNION ALL

-- 4. Orphaned question_bank_entries (missing question category)
SELECT
    'qbe_missing_category' AS issue_type,
    qbe.id AS record_id,
    qbe.questioncategoryid AS parent_or_ref_1,
    0 AS context_or_ref_2,
    COALESCE(qbe.idnumber, '') AS detail
FROM mdl_question_bank_entries qbe
LEFT JOIN mdl_question_categories qc ON qc.id = qbe.questioncategoryid
WHERE qc.id IS NULL

UNION ALL

-- 5. Orphaned question_versions (missing question_bank_entries row)
SELECT
    'qv_missing_entry' AS issue_type,
    qv.id AS record_id,
    qv.questionbankentryid AS parent_or_ref_1,
    qv.questionid AS context_or_ref_2,
    CONCAT('version=', qv.version) AS detail
FROM mdl_question_versions qv
LEFT JOIN mdl_question_bank_entries qbe ON qbe.id = qv.questionbankentryid
WHERE qbe.id IS NULL

UNION ALL

-- 6. Orphaned question_versions (missing question row)
SELECT
    'qv_missing_question' AS issue_type,
    qv.id AS record_id,
    qv.questionbankentryid AS parent_or_ref_1,
    qv.questionid AS context_or_ref_2,
    CONCAT('version=', qv.version) AS detail
FROM mdl_question_versions qv
LEFT JOIN mdl_question q ON q.id = qv.questionid
WHERE q.id IS NULL

ORDER BY issue_type, record_id;
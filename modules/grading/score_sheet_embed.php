<?php
// modules/grading/score_sheet_embed.php
// Class Score Sheet -- the main score-entry view of Encode Grades (step 5).
// Included by index.php INSIDE #gradeForm, so every cell posts as
// items[item_id][student_id] with the same Save / Finalize submit the page
// already had (validateRawScore() against each item's HPS, stored in
// assessment_scores). The result columns keep the ids the page's live
// preview uses (ww-pct-*, pt-pct-*, qa-pct-*, initial-*, final-*, remarks-*),
// and "View" opens the existing computation modal. Nothing is computed here.
if (!defined('BASE_URL')) exit;

$__sheet_owns_scores = true; // the category modals no longer render score boxes
$ss_fmt = function ($n) { return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); };

// Columns: every existing item, grouped WW / PT / Examinations; exams in the
// order they are combined (ST1, ST2, Term Exam).
$ss_exam_order = ['ST1' => 1, 'ST2' => 2, 'TE' => 3];
$ss_qa = $assessment_items['QA'];
usort($ss_qa, function ($a, $b) use ($ss_exam_order) {
    $x = $ss_exam_order[$a['assessment_subtype'] ?? ''] ?? 9;
    $y = $ss_exam_order[$b['assessment_subtype'] ?? ''] ?? 9;
    return $x === $y ? ((int)$a['item_order'] <=> (int)$b['item_order']) : ($x <=> $y);
});
$ss_groups = [
    'WW' => ['items' => $assessment_items['WW']],
    'PT' => ['items' => $assessment_items['PT']],
    'QA' => ['items' => $weights['exam_mode'] === 'none' ? [] : $ss_qa],
];
$ss_subtype_labels = ['ST1' => 'ST1', 'ST2' => 'ST2', 'TE' => 'Term Exam'];
$ss_has_exam = $weights['exam_mode'] !== 'none';
?>
<style>
    .grading-page .ss-cats { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 12px; }
    .grading-page .ss-cat { display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--border); border-left-width: 4px; border-radius: 8px; background: var(--surface); padding: 7px 12px; font-size: 13.5px; color: var(--ink); cursor: pointer; }
    .grading-page .ss-cat:hover { background: var(--surface-hover); }
    .grading-page .ss-cat b { font-weight: 700; }
    .grading-page .ss-cat span { color: var(--text-muted); }
    .grading-page .ss-cat em { font-style: normal; font-weight: 700; color: #1d6fd6; }
    .grading-page .ss-cat-ww { border-left-color: #1F9D6E; }
    .grading-page .ss-cat-pt { border-left-color: #1F6FEB; }
    .grading-page .ss-cat-qa { border-left-color: #C77D00; }
    .grading-page .ss-titlebar { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin: 4px 0 12px; }
    .grading-page .ss-titlebar h3 { font-size: 22px; font-weight: 800; color: var(--ink); margin: 0; }
    .grading-page .ss-sub { font-size: 14.5px; color: var(--text-muted); }
    .grading-page .ss-tips-btn { margin-left: auto; border: 0; background: none; color: #1d6fd6; font-size: 14px; display: inline-flex; align-items: center; gap: 8px; }
    .grading-page .ss-tips-btn span { text-decoration: underline; }
    .grading-page .ss-tips-btn[aria-expanded="true"] .fa-chevron-right { transform: rotate(90deg); }
    .grading-page .ss-table thead th.ss-grp-ww, .grading-page .ss-table thead th.ss-tint-ww { background: #EEF8F2; }
    .grading-page .ss-table thead th.ss-grp-pt, .grading-page .ss-table thead th.ss-tint-pt { background: #EEF4FD; }
    .grading-page .ss-table thead th.ss-grp-qa, .grading-page .ss-table thead th.ss-tint-qa { background: #FEF6E7; }
    .grading-page .ss-table .btn-computation { width: 36px; height: 36px; padding: 0; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; }
    html[data-theme="dark"] .grading-page .ss-table thead th.ss-grp-ww, html[data-theme="dark"] .grading-page .ss-table thead th.ss-tint-ww { background: rgba(31,157,110,.14); }
    html[data-theme="dark"] .grading-page .ss-table thead th.ss-grp-pt, html[data-theme="dark"] .grading-page .ss-table thead th.ss-tint-pt { background: rgba(31,111,235,.14); }
    html[data-theme="dark"] .grading-page .ss-table thead th.ss-grp-qa, html[data-theme="dark"] .grading-page .ss-table thead th.ss-tint-qa { background: rgba(199,125,0,.14); }
    html[data-theme="dark"] .grading-page .ss-tips-btn { color: #8ab4f8; }
    .grading-page .ss-tips { display: flex; gap: 10px; align-items: flex-start; background: #EDF4FB; border: 1px solid #d7e6f8; border-radius: 10px; padding: 9px 14px; font-size: 13px; color: #475569; margin-bottom: 12px; }
    .grading-page .ss-tips i { color: #2f7fe0; margin-top: 3px; }
    .grading-page .ss-wrap { overflow: auto; max-height: 70vh; border: 1px solid var(--border); border-radius: 12px; }
    .grading-page .ss-table { border-collapse: separate; border-spacing: 0; width: max-content; min-width: 100%; font-size: 13.5px; margin: 0; }
    .grading-page .ss-table th, .grading-page .ss-table td { border-bottom: 1px solid var(--border); border-right: 1px solid var(--border); padding: 6px 8px; background: var(--surface); text-align: center; white-space: nowrap; vertical-align: middle; }
    .grading-page .ss-table thead th { position: sticky; z-index: 3; background: var(--surface-hover, #f6f8fb); color: var(--ink); font-weight: 700; font-size: 12.5px; }
    .grading-page .ss-table thead tr:first-child th { top: 0; height: 36px; }
    .grading-page .ss-table thead tr:nth-child(2) th { top: 36px; }
    .grading-page .ss-table .ss-num { width: 38px; color: var(--text-muted); }
    .grading-page .ss-table .ss-name { position: sticky; left: 0; z-index: 2; text-align: left; min-width: 210px; font-weight: 600; color: var(--ink); white-space: normal; }
    .grading-page .ss-table thead .ss-name { z-index: 4; }
    .grading-page .ss-table .ss-lrn { font-family: ui-monospace, Consolas, monospace; font-size: 12.5px; color: var(--text-muted); }
    .grading-page .ss-grp-ww { box-shadow: inset 0 3px 0 #1F9D6E; }
    .grading-page .ss-grp-pt { box-shadow: inset 0 3px 0 #1F6FEB; }
    .grading-page .ss-grp-qa { box-shadow: inset 0 3px 0 #C77D00; }
    .grading-page .ss-grp-res { box-shadow: inset 0 3px 0 #14213D; }
    .grading-page .ss-item { min-width: 92px; max-width: 150px; white-space: normal !important; line-height: 1.25; }
    .grading-page .ss-item small { display: block; font-weight: 500; color: var(--text-muted); font-size: 11.5px; margin-top: 2px; }
    .grading-page .ss-item .ss-hps { display: inline-block; margin-top: 3px; font-size: 11.5px; font-weight: 700; color: var(--ink); background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 1px 6px; }
    .grading-page .ss-cell { width: 76px; height: 34px; border: 1px solid transparent; border-radius: 6px; text-align: center; font-size: 14px; background: transparent; color: var(--ink); }
    .grading-page .ss-cell::placeholder { color: #b4bfcc; }
    .grading-page .ss-cell:focus { outline: none; border-color: #2f7fe0; background: var(--surface); box-shadow: 0 0 0 3px rgba(47,127,224,.15); }
    .grading-page .ss-cell.dirty { background: #FFF8E6; }
    .grading-page .ss-cell.bad { background: #fdecec; border-color: #d92d20; color: #b42318; }
    .grading-page .ss-cell:disabled { color: var(--ink); }
    .grading-page .ss-res { min-width: 86px; }
    .grading-page .ss-table td.ss-res-first, .grading-page .ss-table th.ss-res-first { border-left: 2px solid var(--border); }
    .grading-page .ss-table .remarks, .grading-page .ss-table .initial-grade, .grading-page .ss-table .final-grade { white-space: nowrap; }
    .grading-page .ss-status { font-size: 13.5px; color: var(--text-muted); }
    .grading-page .ss-status b.bad { color: #b42318; }
    .grading-page .ss-save { background: #0f7b5f !important; color: #fff !important; border: 0 !important; }
    .grading-page .ss-save:hover { background: #0c6a52 !important; }
    html[data-theme="dark"] .grading-page .ss-tips { background: rgba(47,127,224,.10); border-color: rgba(47,127,224,.25); color: #c7d2e6; }
    html[data-theme="dark"] .grading-page .ss-cell.dirty { background: rgba(224,138,0,.16); }
    html[data-theme="dark"] .grading-page .ss-cell.bad { background: rgba(217,45,32,.18); }
</style>

<?php $ss_item_total = count($assessment_items['WW']) + count($assessment_items['PT']) + ($ss_has_exam ? count($ss_qa) : 0); ?>
<div class="ss-titlebar">
    <h3>Class Score Sheet</h3>
    <span class="ss-sub"><?php echo count($section_students); ?> student<?php echo count($section_students) === 1 ? '' : 's'; ?> &middot; <?php echo $ss_item_total; ?> item<?php echo $ss_item_total === 1 ? '' : 's'; ?></span>
    <?php if (!$term_readonly): ?>
        <button type="button" class="ss-tips-btn" aria-controls="ssTips" aria-expanded="false"
                onclick="var t=document.getElementById('ssTips');t.hidden=!t.hidden;this.setAttribute('aria-expanded',t.hidden?'false':'true');">
            <i class="far fa-keyboard"></i><span>Keyboard &amp; paste tips</span><i class="fas fa-chevron-right"></i>
        </button>
    <?php endif; ?>
</div>
<?php if (!$term_readonly): ?>
    <div class="ss-tips" id="ssTips" hidden>
        <i class="fas fa-circle-info"></i>
        <div>Type scores right in the sheet. Use the <strong>arrow keys</strong>, <strong>Enter</strong> (down) or <strong>Tab</strong> to move, and <strong>paste</strong> a block copied from Excel starting at any cell.
            Each score must be from 0 up to that item's HPS. A <strong>blank</strong> cell means <em>No Score</em>; <strong>0</strong> is a recorded score.
            Scores are saved when you click <strong>Finalize</strong>, which also computes the grades.</div>
    </div>
<?php endif; ?>

<div class="ss-wrap">
    <table class="ss-table" id="gradeEncodeTable">
        <thead>
            <tr>
                <th class="ss-num" rowspan="2">#</th>
                <th class="ss-name" rowspan="2">Student Name</th>
                <th rowspan="2">LRN</th>
                <?php foreach ($ss_groups as $gk => $g): if (empty($g['items'])) continue; $meta = $__cat_meta[$gk]; ?>
                    <th colspan="<?php echo count($g['items']); ?>" class="ss-grp-<?php echo $meta['short']; ?>"><?php echo htmlspecialchars($meta['label']); ?> <?php echo (int) ${$meta['short'] . '_pct'}; ?>%</th>
                <?php endforeach; ?>
                <th colspan="<?php echo $ss_has_exam ? 7 : 6; ?>" class="ss-grp-res ss-res-first">Results</th>
            </tr>
            <tr>
                <?php foreach ($ss_groups as $gk => $g): foreach ($g['items'] as $item): ?>
                    <th class="ss-item ss-tint-<?php echo $__cat_meta[$gk]['short']; ?>">
                        <?php echo htmlspecialchars($item['item_name']); ?>
                        <?php
                            $ss_sub = [];
                            if ($gk === 'QA' && !empty($item['assessment_subtype'])) $ss_sub[] = $ss_subtype_labels[$item['assessment_subtype']] ?? $item['assessment_subtype'];
                            if (!empty($item['component'])) $ss_sub[] = $item['component'];
                        ?>
                        <?php if ($ss_sub): ?><small><?php echo htmlspecialchars(implode(' · ', $ss_sub)); ?></small><?php endif; ?>
                        <span class="ss-hps" title="Highest Possible Score (set in Manage Assessment Items)">/ <?php echo $ss_fmt($item['highest_score']); ?></span>
                    </th>
                <?php endforeach; endforeach; ?>
                <th class="ss-res ss-res-first">WW %</th>
                <th class="ss-res">PT %</th>
                <?php if ($ss_has_exam): ?><th class="ss-res"><?php echo htmlspecialchars($exam_label); ?> %</th><?php endif; ?>
                <th class="ss-res">Initial Grade</th>
                <th class="ss-res"><?php echo htmlspecialchars($period_label); ?></th>
                <th class="ss-res">Remarks</th>
                <th class="ss-res">View</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($section_students as $r => $student):
                $sid = (int)$student['student_id'];
                $s_name = $student['last_name'] . ', ' . $student['first_name'];
                // Same result values the Encode Grades table showed: live
                // category % from the saved scores; Initial / Term Grade /
                // Remarks only once the term has been finalized.
                $grade = $grade_data[$sid] ?? null;
                $remarks = ($is_finalized && $grade) ? $grade['remarks'] : '';
                $row_incomplete = ($remarks === 'Incomplete');
                $initial = ($is_finalized && $grade && !$row_incomplete) ? $grade['initial_grade'] : '';
                $final = ($is_finalized && $grade && !$row_incomplete) ? $grade['final_grade'] : '';
                $ww_live = !empty($assessment_items['WW']) ? computeCategoryPercent($assessment_items['WW'], $assessment_scores_map, $sid) : null;
                $pt_live = !empty($assessment_items['PT']) ? computeCategoryPercent($assessment_items['PT'], $assessment_scores_map, $sid) : null;
                $qa_exam_live = computeExamPercent($assessment_items['QA'], $assessment_scores_map, $sid, $weights['exam_mode']);
                $qa_live = $qa_exam_live['percent'];
            ?>
                <tr>
                    <td class="ss-num"><?php echo $r + 1; ?></td>
                    <td class="ss-name">
                        <?php echo htmlspecialchars($s_name); ?>
                        <input type="hidden" name="grades[<?php echo $sid; ?>][student_id]" value="<?php echo $sid; ?>">
                    </td>
                    <td class="ss-lrn"><?php echo htmlspecialchars($student['lrn']); ?></td>
                    <?php $c = 0; foreach ($ss_groups as $g): foreach ($g['items'] as $item):
                        $iid = (int)$item['item_id'];
                        $raw = $assessment_scores_map[$iid][$sid] ?? null;
                        $val = ($raw === null || $raw === '') ? '' : $ss_fmt($raw);
                    ?>
                        <td><input type="text" inputmode="decimal" class="ss-cell"
                                   name="items[<?php echo $iid; ?>][<?php echo $sid; ?>]"
                                   data-r="<?php echo $r; ?>" data-c="<?php echo $c++; ?>" data-student="<?php echo $sid; ?>"
                                   data-hps="<?php echo (float)$item['highest_score']; ?>" max="<?php echo (float)$item['highest_score']; ?>"
                                   data-orig="<?php echo htmlspecialchars($val); ?>"
                                   value="<?php echo htmlspecialchars($val); ?>" placeholder="—"
                                   aria-label="<?php echo htmlspecialchars($s_name . ' - ' . $item['item_name']); ?>"></td>
                    <?php endforeach; endforeach; ?>
                    <td class="ss-res-first"><span class="fw-bold" id="ww-pct-<?php echo $sid; ?>"><?php echo $ww_live !== null ? number_format($ww_live, 2) . '%' : '—'; ?></span></td>
                    <td><span class="fw-bold" id="pt-pct-<?php echo $sid; ?>"><?php echo $pt_live !== null ? number_format($pt_live, 2) . '%' : '—'; ?></span></td>
                    <?php if ($ss_has_exam): ?>
                        <td><span class="fw-bold" id="qa-pct-<?php echo $sid; ?>"><?php
                            echo (empty($assessment_items['QA']) || $qa_live === null) ? '—' : number_format($qa_live, 2) . '%' . (!$qa_exam_live['complete'] ? ' *' : '');
                        ?></span></td>
                    <?php endif; ?>
                    <td class="initial-grade" id="initial-<?php echo $sid; ?>">
                        <span class="fw-bold <?php echo ($initial !== '' && $initial < 75) ? 'text-danger' : 'text-success'; ?>"><?php echo $initial !== '' ? number_format($initial, 2) : '—'; ?></span>
                    </td>
                    <td class="final-grade" id="final-<?php echo $sid; ?>">
                        <span class="fw-bold <?php echo ($final !== '' && $final < 75) ? 'text-danger' : 'text-success'; ?>"><?php echo $final !== '' ? number_format($final, 2) : '—'; ?></span>
                    </td>
                    <td class="remarks" id="remarks-<?php echo $sid; ?>">
                        <?php if ($remarks): ?>
                            <span class="badge-status <?php echo $remarks == 'Passed' ? 'passed' : 'failed'; ?>"><?php echo $remarks; ?></span>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="gc-cell">
                        <button type="button" class="btn-computation js-view-computation"
                                data-student-id="<?php echo $sid; ?>"
                                data-name="<?php echo htmlspecialchars($s_name, ENT_QUOTES); ?>"
                                data-lrn="<?php echo htmlspecialchars($student['lrn'], ENT_QUOTES); ?>"
                                title="View Computation" aria-label="View grade computation for <?php echo htmlspecialchars($s_name, ENT_QUOTES); ?>">
                            <i class="far fa-eye"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if (!$term_readonly): ?>
<script>
(function () {
    var table = document.getElementById('gradeEncodeTable'), form = document.getElementById('gradeForm');
    if (!table || !form) return;
    var cells = Array.prototype.slice.call(table.querySelectorAll('.ss-cell'));
    if (!cells.length) return;
    var grid = {}, submitting = false;
    cells.forEach(function (el) { grid[el.dataset.r + ':' + el.dataset.c] = el; });

    // '' = No Score; otherwise a number from 0 to the item's HPS.
    function check(el) {
        var v = el.value.trim(), msg = '';
        if (v !== '') {
            if (!/^\d+(\.\d{1,2})?$|^\.\d{1,2}$/.test(v)) msg = 'Enter a number (up to 2 decimals) or leave it blank for No Score.';
            else if (parseFloat(v) > parseFloat(el.dataset.hps) + 0.00001) msg = 'Max ' + (+parseFloat(el.dataset.hps).toFixed(2)) + ' (the HPS).';
        }
        el.setCustomValidity(msg);
        el.classList.toggle('bad', !!msg);
        el.title = msg;
        var o = el.dataset.orig;
        el.classList.toggle('dirty', v !== o && !(v !== '' && o !== '' && parseFloat(v) === parseFloat(o)));
        return !msg;
    }
    function refresh() {
        var bad = 0, dirty = 0;
        cells.forEach(function (el) { if (el.classList.contains('bad')) bad++; if (el.classList.contains('dirty')) dirty++; });
        var st = document.getElementById('ssStatus');
        if (st) st.innerHTML = bad ? '<b class="bad">' + bad + ' invalid score' + (bad === 1 ? '' : 's') + '</b> — fix the red cells before saving.'
            : (dirty ? dirty + ' changed cell' + (dirty === 1 ? '' : 's') + ' not saved yet.' : '');
    }
    function changed(el) {
        check(el);
        if (typeof calculateGrade === 'function') calculateGrade(el.dataset.student);
    }
    function move(el, dr, dc) {
        var n = grid[(+el.dataset.r + dr) + ':' + (+el.dataset.c + dc)];
        if (n) { n.focus(); n.select(); }
    }

    table.addEventListener('input', function (e) { if (e.target.classList.contains('ss-cell')) { changed(e.target); refresh(); } });
    table.addEventListener('focusin', function (e) { if (e.target.classList.contains('ss-cell')) e.target.select(); });
    table.addEventListener('keydown', function (e) {
        var el = e.target;
        if (!el.classList.contains('ss-cell')) return;
        var allSel = el.selectionStart === 0 && el.selectionEnd === el.value.length;
        if (e.key === 'ArrowDown' || (e.key === 'Enter' && !e.shiftKey)) { e.preventDefault(); move(el, 1, 0); }
        else if (e.key === 'ArrowUp' || (e.key === 'Enter' && e.shiftKey)) { e.preventDefault(); move(el, -1, 0); }
        else if (e.key === 'ArrowRight' && (allSel || el.selectionStart === el.value.length)) { e.preventDefault(); move(el, 0, 1); }
        else if (e.key === 'ArrowLeft' && (allSel || (el.selectionStart === 0 && el.selectionEnd === 0))) { e.preventDefault(); move(el, 0, -1); }
        else if (e.key === 'Escape') { el.value = el.dataset.orig; changed(el); refresh(); }
    });
    // Paste a block copied from Excel / Sheets (tab-separated columns, one
    // row per line) starting at the focused cell; cells past the edge are ignored.
    table.addEventListener('paste', function (e) {
        var el = e.target;
        if (!el.classList.contains('ss-cell')) return;
        var text = (e.clipboardData || window.clipboardData).getData('text');
        if (!text || (text.indexOf('\t') === -1 && text.indexOf('\n') === -1)) return; // a single value pastes normally
        e.preventDefault();
        var lines = text.replace(/\r/g, '').replace(/\n+$/, '').split('\n');
        var r0 = +el.dataset.r, c0 = +el.dataset.c;
        lines.forEach(function (line, i) {
            line.split('\t').forEach(function (v, j) {
                var t = grid[(r0 + i) + ':' + (c0 + j)];
                if (t) { t.value = v.trim(); changed(t); }
            });
        });
        refresh();
    });

    // Finalize: a bad cell stops the click before the button's own
    // handler (Finalize's confirm submits the form without a submit event).
    form.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('button[type=submit]') : null;
        if (!btn || !form.contains(btn)) return;
        var bad = null;
        cells.forEach(function (el) { if (!check(el) && !bad) bad = el; });
        refresh();
        if (bad) { e.preventDefault(); e.stopImmediatePropagation(); bad.focus(); bad.reportValidity(); return; }
        submitting = true;
    }, true);
    form.addEventListener('submit', function (e) {
        var bad = null;
        cells.forEach(function (el) { if (!check(el) && !bad) bad = el; });
        refresh();
        if (bad) { e.preventDefault(); e.stopImmediatePropagation(); bad.focus(); bad.reportValidity(); return; }
        submitting = true;
    }, true);
    window.addEventListener('beforeunload', function (e) {
        if (submitting || !table.querySelector('.ss-cell.dirty')) return;
        e.preventDefault(); e.returnValue = '';
    });
    cells.forEach(check);
    refresh();
})();
</script>
<?php endif; ?>

<?php
// includes/fixed_timetable.php
// Fixed JHS (Grade 7-10) class timetable -- pure computation, no database
// writes, no new tables. The weekly Days + Start/End of every Subject +
// Section is derived from data the system already owns:
//
//   - Shift by grade (existing rule): Grade 7-8 = AM, Grade 9-10 = PM.
//   - Shift hours from Settings -> Attendance (JHS Morning/Afternoon):
//     AM 06:00-12:30 (break 09:00-09:30), PM 12:30-19:00 (break 15:30-16:00).
//   - Eight 45-minute periods per shift from the shift start, skipping the
//     configured 30-minute break, Monday to Friday for every subject.
//     Subjects take sequential periods in list order, so two subjects of one
//     section never overlap each other.
//
// Both the read-only display and the assign-time auto-attach use ONLY these
// functions, so what Admin sees before assigning is exactly what gets saved.

if (!function_exists('ftShiftOf')) {
    function ftShiftOf($grade) {
        if (in_array($grade, ['Grade 7', 'Grade 8'], true)) return 'AM';
        if (in_array($grade, ['Grade 9', 'Grade 10'], true)) return 'PM';
        return '';
    }
}

if (!function_exists('ftShiftConfig')) {
    function ftShiftConfig() {
        $cfg = [
            'jhs_morning_start' => '06:00', 'jhs_morning_break_start' => '09:00',
            'jhs_morning_break_end' => '09:30', 'jhs_morning_end' => '12:30',
            'jhs_afternoon_start' => '12:30', 'jhs_afternoon_break_start' => '15:30',
            'jhs_afternoon_break_end' => '16:00', 'jhs_afternoon_end' => '19:00',
        ];
        if (function_exists('getRecords')) {
            foreach (getRecords("SELECT setting_key, setting_value FROM system_settings WHERE setting_group = 'attendance'") as $r) {
                if (array_key_exists($r['setting_key'], $cfg) && $r['setting_value'] !== null && $r['setting_value'] !== '') {
                    $cfg[$r['setting_key']] = substr($r['setting_value'], 0, 5);
                }
            }
        }
        return $cfg;
    }
}

if (!function_exists('ftPeriods')) {
    // Eight 45-minute class periods of a shift, break skipped. Returns list
    // of [start, end] in 'HH:MM'.
    function ftPeriods($shift) {
        $cfg = ftShiftConfig();
        if ($shift === 'AM') {
            $s = $cfg['jhs_morning_start']; $e = $cfg['jhs_morning_end'];
            $bs = $cfg['jhs_morning_break_start']; $be = $cfg['jhs_morning_break_end'];
        } elseif ($shift === 'PM') {
            $s = $cfg['jhs_afternoon_start']; $e = $cfg['jhs_afternoon_end'];
            $bs = $cfg['jhs_afternoon_break_start']; $be = $cfg['jhs_afternoon_break_end'];
        } else {
            return [];
        }
        $toMin = function ($hm) { $p = explode(':', substr((string)$hm, 0, 5)); return ((int)$p[0]) * 60 + (int)($p[1] ?? 0); };
        $toHm = function ($m) { return sprintf('%02d:%02d', intdiv($m, 60), $m % 60); };
        $start = $toMin($s); $end = $toMin($e); $bs = $toMin($bs); $be = $toMin($be);
        $out = [];
        $t = $start;
        while ($t + 45 <= $end && count($out) < 8) {
            if ($t < $be && $t + 45 > $bs) { $t = $be; continue; } // break window: jump over it
            $out[] = [$toHm($t), $toHm($t + 45)];
            $t += 45;
        }
        return $out;
    }
}

if (!function_exists('ftSlotFor')) {
    // Fixed slot of one subject given the section's subject codes IN DISPLAY
    // ORDER (same order every caller already lists): every subject meets
    // Monday to Friday at its own 45-minute period. Returns
    // ['days'=>[1,2,3,4,5], 'start'=>'HH:MM', 'end'=>'HH:MM',
    //  'shift'=>'AM'|'PM', 'shift_label'=>'AM Shift'|...,
    //  'summary'=>'Mon-Tue-Wed-Thu-Fri · 6:00 AM – 6:45 AM']
    // or null when the grade is not JHS, the subject is not in the list, or
    // there are more subjects than periods.
    function ftSlotFor(array $ordered_codes, $subject_code, $grade) {
        $shift = ftShiftOf($grade);
        if ($shift === '') return null;
        $idx = array_search($subject_code, $ordered_codes, true);
        if ($idx === false) return null;
        $periods = ftPeriods($shift);
        if (!$periods || ((int)$idx) >= count($periods)) return null;
        [$start, $end] = $periods[(int)$idx];
        $labels = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
        return [
            'days' => [1, 2, 3, 4, 5],
            'start' => $start,
            'end' => $end,
            'shift' => $shift,
            'shift_label' => $shift === 'AM' ? 'AM Shift' : 'PM Shift',
            'shift_type' => $shift === 'AM' ? 'JHS Morning' : 'JHS Afternoon',
            'summary' => implode('-', $labels) . ' · ' . date('g:i A', strtotime($start)) . ' – ' . date('g:i A', strtotime($end)),
        ];
    }
}

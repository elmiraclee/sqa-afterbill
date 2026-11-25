<?php
/**
 * AfterBill - Repeat schedules for recurring bills
 *
 * A recurring bill repeats weekly, monthly, every 3 months or yearly (bills.repeat_interval) and
 * may stop at an end date (bills.repeat_until; NULL = never). "Ends after N bills" in the form is
 * stored as the end date of the N-th bill. Generation itself: includes/recurring_bills.php.
 * Columns: database/migrations/005_more_features.sql
 */

const REPEAT_INTERVALS = [
    'weekly'    => 'Weekly',
    'monthly'   => 'Monthly',
    'quarterly' => 'Every 3 months',
    'yearly'    => 'Yearly',
];
const REPEAT_MONTH_STEPS = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12]; // weekly = 7 days
const REPEAT_MAX_COUNT = 520; // "ends after N bills": 10 years of weekly bills

/**
 * Date of the $n-th bill of a series whose first bill is due $firstDue (n = 1 is $firstDue).
 * Month-based intervals keep the first bill's day, clamped to short months (Jan 31 → Feb 28 →
 * Mar 31), never drifting.
 */
function repeatNthDate(string $firstDue, string $interval, int $n): string
{
    $first = new DateTime($firstDue);
    if ($interval === 'weekly') {
        return $first->modify('+' . (7 * ($n - 1)) . ' days')->format('Y-m-d');
    }
    $months = (REPEAT_MONTH_STEPS[$interval] ?? 1) * ($n - 1);
    $target = (new DateTime($first->format('Y-m-01')))->modify("+$months months");
    $day = min((int)$first->format('j'), (int)$target->format('t'));
    return $target->format('Y-m-') . str_pad((string)$day, 2, '0', STR_PAD_LEFT);
}

/** "Monthly", "Weekly · until Mar 5, 2027". */
function repeatLabel(?string $interval, ?string $until): string
{
    $label = REPEAT_INTERVALS[$interval ?? 'monthly'] ?? 'Monthly';
    if ($until !== null) {
        $label .= ' · until ' . (new DateTime($until))->format('M j, Y');
    }
    return $label;
}

/**
 * Reads the repeat fields of the add/edit bill form. Returns
 *   ['interval' => string, 'until' => ?string, 'end' => 'never'|'on'|'after', 'until_input' => string,
 *    'count_input' => string, 'errors' => string[]]
 * The *_input values are what the user typed, to show the form again. $dueDate is the (already
 * validated, or '' if invalid) first due date. Only validated when $isRecurring.
 */
function readRepeatFields(array $post, bool $isRecurring, string $dueDate): array
{
    $out = [
        'interval'    => is_string($post['repeat_interval'] ?? null) ? $post['repeat_interval'] : 'monthly',
        'until'       => null,
        'end'         => is_string($post['repeat_end'] ?? null) ? $post['repeat_end'] : 'never',
        'until_input' => trim((string)(is_string($post['repeat_until'] ?? null) ? $post['repeat_until'] : '')),
        'count_input' => trim((string)(is_string($post['repeat_count'] ?? null) ? $post['repeat_count'] : '')),
        'errors'      => [],
    ];
    if (!$isRecurring) {
        $out['interval'] = isset(REPEAT_INTERVALS[$out['interval']]) ? $out['interval'] : 'monthly';
        $out['end'] = in_array($out['end'], ['never', 'on', 'after'], true) ? $out['end'] : 'never';
        return $out;
    }
    if (!isset(REPEAT_INTERVALS[$out['interval']])) {
        $out['errors'][] = 'Please choose how often the bill repeats.';
        $out['interval'] = 'monthly';
    }
    if ($out['end'] === 'on') {
        $d = DateTime::createFromFormat('Y-m-d', $out['until_input']);
        if (!$d || $d->format('Y-m-d') !== $out['until_input'] || $out['until_input'] > '2100-12-31') {
            $out['errors'][] = 'Please enter a valid end date for the repeating bill.';
        } elseif ($dueDate !== '' && $out['until_input'] < $dueDate) {
            $out['errors'][] = 'The end date can\'t be before the due date.';
        } else {
            $out['until'] = $out['until_input'];
        }
    } elseif ($out['end'] === 'after') {
        if (!ctype_digit($out['count_input']) || (int)$out['count_input'] < 2 || (int)$out['count_input'] > REPEAT_MAX_COUNT) {
            $out['errors'][] = 'Number of bills must be a whole number from 2 to ' . REPEAT_MAX_COUNT . '.';
        } elseif ($dueDate !== '' && empty($out['errors'])) {
            $out['until'] = repeatNthDate($dueDate, $out['interval'], (int)$out['count_input']);
        }
    } else {
        $out['end'] = 'never';
    }
    return $out;
}

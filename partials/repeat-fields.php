<?php
/**
 * AfterBill - "Repeats" fields of the Add / Edit Bill forms
 *
 * Expects $old['is_recurring'] (bool) and $repeat (from readRepeatFields(), or defaults).
 * The options only show while "This bill repeats" is ticked (CSS :has, no script needed);
 * without :has support they simply stay visible.
 */
?>
<style>
    .repeat-options {
        margin: -10px 0 22px 26px; padding: 14px 16px; border: 1px solid var(--border); border-radius: 10px;
        background: var(--light-gray);
    }
    .form-check:has(#is_recurring:not(:checked)) + .repeat-options { display: none; }
    .repeat-options .form-group { margin-bottom: 12px; }
    .repeat-end { display: flex; flex-direction: column; gap: 8px; font-size: 0.84rem; }
    .repeat-end label { display: flex; align-items: center; gap: 8px; font-weight: 500; margin: 0; }
    .repeat-end input[type="date"], .repeat-end input[type="number"] {
        width: auto; padding: 6px 10px; border: 1.5px solid var(--border); border-radius: 7px;
        font-family: inherit; font-size: 0.84rem;
    }
    .repeat-end input[type="number"] { width: 90px; }
</style>

<div class="form-check">
    <input type="checkbox" id="is_recurring" name="is_recurring" value="1"
        <?= $old['is_recurring'] ? 'checked' : '' ?>>
    <label for="is_recurring">This bill repeats</label>
</div>

<div class="repeat-options">
    <div class="form-group">
        <label for="repeat_interval">Repeats</label>
        <select id="repeat_interval" name="repeat_interval">
            <?php foreach (REPEAT_INTERVALS as $value => $label): ?>
                <option value="<?= $value ?>" <?= $repeat['interval'] === $value ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="repeat-end" role="radiogroup" aria-label="When the bill stops repeating">
        <label><input type="radio" name="repeat_end" value="never" <?= $repeat['end'] === 'never' ? 'checked' : '' ?>> Never ends</label>
        <label>
            <input type="radio" name="repeat_end" value="on" <?= $repeat['end'] === 'on' ? 'checked' : '' ?>> Ends on
            <input type="date" name="repeat_until" aria-label="Last due date" value="<?= htmlspecialchars($repeat['until_input']) ?>">
        </label>
        <label>
            <input type="radio" name="repeat_end" value="after" <?= $repeat['end'] === 'after' ? 'checked' : '' ?>> Ends after
            <input type="number" name="repeat_count" min="2" max="<?= REPEAT_MAX_COUNT ?>" aria-label="Number of bills"
                   value="<?= htmlspecialchars($repeat['count_input']) ?>"> bills
        </label>
        <span style="font-size:0.75rem;color:var(--text-muted);">Counted from this bill's due date. Changes apply to the bills created from now on.</span>
    </div>
</div>

<?php
/**
 * AfterBill - Add/Edit Bill modal, shared by bills.php and calendar.php.
 * Expects $currency, $redirectTo (page to return to after save), $redirectQs.
 */
$redirectTo = $redirectTo ?? 'bills.php';
$redirectQs = $redirectQs ?? '';
?>
<div class="modal-overlay" id="billModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3 id="modalTitle">Add Bill</h3>
            <button type="button" class="modal-close" data-close-modal>
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>
        <form method="POST" action="bills.php" id="billForm">
            <input type="hidden" name="action" value="create" id="billFormAction">
            <input type="hidden" name="bill_id" value="" id="billIdField">
            <input type="hidden" name="redirect_to" value="<?= e($redirectTo) ?>">
            <input type="hidden" name="redirect_qs" value="<?= e($redirectQs) ?>" id="billRedirectQs">
            <div class="modal-body">
                <div class="form-row">
                    <label for="name">Bill Name</label>
                    <input type="text" id="name" name="name" class="input" placeholder="e.g. Meralco Electricity" required maxlength="150">
                </div>

                <div class="form-grid-2">
                    <div class="form-row">
                        <label for="category">Category</label>
                        <select id="category" name="category" class="input" required>
                            <?php foreach (BILL_CATEGORIES as $cat): ?>
                                <option value="<?= e($cat) ?>"><?= e($cat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-row">
                        <label for="amount">Amount (<?= e(currency_symbol($currency)) ?>)</label>
                        <input type="number" id="amount" name="amount" class="input" placeholder="0.00" step="0.01" min="0.01" required>
                    </div>
                </div>

                <div class="form-grid-2">
                    <div class="form-row">
                        <label for="due_date">Due Date</label>
                        <input type="date" id="due_date" name="due_date" class="input" required>
                    </div>
                    <div class="form-row">
                        <label for="frequency">Billing Frequency</label>
                        <select id="frequency" name="frequency" class="input" required>
                            <?php foreach (BILL_FREQUENCIES as $freq): ?>
                                <option value="<?= e($freq) ?>"><?= e($freq) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <label for="reminder_at">Reminder Date/Time (optional)</label>
                    <input type="datetime-local" id="reminder_at" name="reminder_at" class="input">
                </div>

                <div class="form-row">
                    <label for="notes">Notes (optional)</label>
                    <textarea id="notes" name="notes" class="input" rows="3" maxlength="500" placeholder="Account number, payment method, etc."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline" data-close-modal>Cancel</button>
                <button type="submit" class="btn btn-primary">Save Bill</button>
            </div>
        </form>
    </div>
</div>

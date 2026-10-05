<?php
$supervisors = [
    "RECKLYN D. RUAZA, RN",
    "BELL MANDREW MANLIGUEZ, RN - HEPO II"
];
?>

<div class="col-12">
    <label class="form-label">Approved By</label>

    <select
        class="form-select"
        name="approved_by"
        <?= $isCompleted ? "disabled" : "" ?>
        required
    >
        <option value="">Select Supervisor</option>

        <?php foreach ($supervisors as $supervisor): ?>
            <option
                value="<?= e($supervisor) ?>"
                <?= ($request["approved_by"] ?? "") === $supervisor
                    ? "selected"
                    : "" ?>
            >
                <?= e($supervisor) ?>
            </option>
        <?php endforeach; ?>
    </select>
</div>

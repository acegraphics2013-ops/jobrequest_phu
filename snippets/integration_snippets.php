<?php
/*
|--------------------------------------------------------------------------
| 1. FILTER ACTIVE REQUESTS
|--------------------------------------------------------------------------
| Add this to active request queries:
|
| WHERE COALESCE(archived, 0) = 0
*/

/*
|--------------------------------------------------------------------------
| 2. ARCHIVE BUTTON FOR CLOSED REQUESTS
|--------------------------------------------------------------------------
*/

if (
    in_array(
        $_SESSION["role"] ?? "",
        ["supervisor", "admin"],
        true
    )
    &&
    $row["approval_status"] === "Closed"
) {
    ?>
    <form
        method="POST"
        action="<?= e(BASE_URL) ?>/staff/archive_request.php"
        onsubmit="return confirm('Archive this closed request?');"
    >
        <input
            type="hidden"
            name="csrf_token"
            value="<?= e($_SESSION["csrf_token"]) ?>"
        >

        <input
            type="hidden"
            name="request_id"
            value="<?= (int) $row["id"] ?>"
        >

        <button
            class="btn btn-sm btn-outline-secondary"
            type="submit"
        >
            Archive
        </button>
    </form>
    <?php
}

/*
|--------------------------------------------------------------------------
| 3. KANBAN SIDEBAR LINK
|--------------------------------------------------------------------------
*/

?>
<a
    class="nav-link <?= navActive([
        '/staff/kanban.php'
    ]) ?>"
    href="<?= e(BASE_URL) ?>/staff/kanban.php"
>
    <i class="bi bi-kanban"></i>
    <span>Kanban Board</span>
</a>

<?php
/*
|--------------------------------------------------------------------------
| 4. ARCHIVE SIDEBAR LINK
|--------------------------------------------------------------------------
*/
?>

<?php if (
    in_array(
        $role,
        ["supervisor", "admin"],
        true
    )
): ?>
    <a
        class="nav-link <?= navActive([
            '/staff/archived_requests.php'
        ]) ?>"
        href="<?= e(BASE_URL) ?>/staff/archived_requests.php"
    >
        <i class="bi bi-archive"></i>
        <span>Archived Requests</span>
    </a>
<?php endif; ?>

<?php
/*
|--------------------------------------------------------------------------
| 5. EVALUATION ANALYTICS SIDEBAR LINK
|--------------------------------------------------------------------------
*/
?>

<?php if (
    in_array(
        $role,
        ["supervisor", "admin"],
        true
    )
): ?>
    <a
        class="nav-link <?= navActive([
            '/reports/evaluation_analytics.php'
        ]) ?>"
        href="<?= e(BASE_URL) ?>/reports/evaluation_analytics.php"
    >
        <i class="bi bi-bar-chart"></i>
        <span>Evaluation Analytics</span>
    </a>
<?php endif; ?>

<?php
/*
|--------------------------------------------------------------------------
| 6. QR BUTTON
|--------------------------------------------------------------------------
*/
?>

<a
    class="btn btn-sm btn-outline-dark"
    href="<?= e(BASE_URL) ?>/user/qr.php?id=<?= (int) $row["id"] ?>"
>
    <i class="bi bi-qr-code"></i>
    QR
</a>

<?php
include "includes/config.php";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* Redirect users who are already signed in. */
if (!empty($_SESSION["user_id"]) && !empty($_SESSION["role"])) {
    switch ($_SESSION["role"]) {
        case "admin":
        case "staff":
        case "supervisor":
            header("Location: dashboard.php");
            exit;

        case "user":
            header("Location: user/my_requests.php");
            exit;
    }
}

$login_error = $_SESSION["login_error"] ?? "";
unset($_SESSION["login_error"]);

$login_email = $_SESSION["login_email"] ?? "";
unset($_SESSION["login_email"]);

/* Account-request flashes (public Request Account form). */
$account_success = $_SESSION["account_success"] ?? "";
unset($_SESSION["account_success"]);

$account_error = $_SESSION["account_error"] ?? "";
unset($_SESSION["account_error"]);

$account_old = $_SESSION["account_old"] ?? [];
unset($_SESSION["account_old"]);

$account_open = !empty($_SESSION["account_open"]);
unset($_SESSION["account_open"]);

if (!is_array($account_old)) {
    $account_old = [];
}

if (empty($_SESSION["account_csrf"])) {
    $_SESSION["account_csrf"] = bin2hex(random_bytes(32));
}

$account_csrf = (string) $_SESSION["account_csrf"];

/* Departments for the Request Account select (fallback: free text). */
$account_departments = [];

try {
    $deptResult = $conn->query(
        "SELECT department_name
          FROM departments
          ORDER BY department_name ASC"
    );

    if ($deptResult) {
        $account_departments =
            $deptResult->fetch_all(MYSQLI_ASSOC);
    }
} catch (Throwable $deptError) {
    $account_departments = [];
}

$account_value = static function (string $key) use ($account_old): string {
    return htmlspecialchars(
        (string) ($account_old[$key] ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
};

$account_role_old = (string) ($account_old["requested_role"] ?? "user");

if (!in_array($account_role_old, ["user", "staff", "supervisor"], true)) {
    $account_role_old = "user";
}

/* Account-help flashes (public Need Account Help form). */
$help_success = $_SESSION["help_success"] ?? "";
unset($_SESSION["help_success"]);

$help_error = $_SESSION["help_error"] ?? "";
unset($_SESSION["help_error"]);

$help_old = $_SESSION["help_old"] ?? [];
unset($_SESSION["help_old"]);

$help_open = !empty($_SESSION["help_open"]);
unset($_SESSION["help_open"]);

if (!is_array($help_old)) {
    $help_old = [];
}

$help_value = static function (string $key) use ($help_old): string {
    return htmlspecialchars(
        (string) ($help_old[$key] ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
};

$help_type_old = (string) ($help_old["issue_type"] ?? "forgot_password");

if (!in_array($help_type_old, ["forgot_password", "inactive", "correction", "other"], true)) {
    $help_type_old = "forgot_password";
}

$base = rtrim(BASE_URL, "/");
$logoUrl = $base . "/assets/images/astmmc-logo.png";
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#1d4ed8">
    <meta name="description"
        content="HPU Workbench — ASTMMC Health Promotion Unit workspace for Job Requests, Publication Submissions, Office Activity / Event coordination, Calendar, IEC Materials, Client Experience, notifications and workflow monitoring.">
    <title>HPU Workbench | ASTMMC Health Promotion Unit</title>
    <link rel="icon" type="image/x-icon" href="<?= $base ?>/favicon.ico">
    <link rel="icon" type="image/png" href="<?= htmlspecialchars($logoUrl, ENT_QUOTES, "UTF-8") ?>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= $base ?>/assets/css/page_headers.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Sora:wght@700;800&display=swap"
        rel="stylesheet">

    <style>
        :root {
            --hpu-green: #0d7a2e;
            --hpu-green-dark: #0a5e22;
            --hpu-green-deep: #031f10;
            --hpu-lime: #16a34a;
            --hpu-mint: #dcfce7;
            --wb-navy: #0c1b3a;
            --wb-blue: #1d4ed8;
            --wb-blue-dark: #163d8a;
            --wb-blue-bright: #2563eb;
            --wb-blue-mist: #dbeafe;
            --ink: #0a1628;
            --muted: #4a5b6e;
            --faint: #7a8ba5;
            --border: #c3d3eb;
            --border-soft: #dce5f4;
            --surface: #ffffff;
            --page: #e6eef8;
            --danger: #b42318;
            --danger-soft: #fef3f2;
            --danger-border: #f2c2bd;
            --ring: 0 0 0 4px rgba(29, 78, 216, .16);
            --ring-green: 0 0 0 4px rgba(13, 94, 46, .14);
            --card-shadow: 0 18px 50px rgba(12, 30, 58, .16), 0 2px 8px rgba(10, 78, 34, .10);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            font-family: "Inter", "Segoe UI", Arial, sans-serif;
            color: var(--ink);
            background: var(--page);
            -webkit-font-smoothing: antialiased;
        }

        button,
        input {
            font: inherit;
        }

        a {
            color: var(--wb-blue-dark);
        }

        :focus-visible {
            outline: 3px solid rgba(37, 99, 235, .5);
            outline-offset: 2px;
            border-radius: 6px;
        }

        .page-shell {
            min-height: 100vh;
            display: grid;
            grid-template-columns: minmax(0, 1.15fr) minmax(460px, .85fr);
        }

        /* ================= BRAND PANEL ================= */
        @keyframes floatOrb {

            0%,
            100% {
                transform: translateY(0) scale(1);
            }

            50% {
                transform: translateY(-18px) scale(1.05);
            }
        }

        @keyframes floatOrb2 {

            0%,
            100% {
                transform: translateY(0) rotate(0);
            }

            50% {
                transform: translateY(14px) rotate(6deg);
            }
        }

        @keyframes fadeUp {
            from {
                opacity: 0;
                transform: translateY(18px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulseDot {

            0%,
            100% {
                box-shadow: 0 0 0 4px rgba(34, 197, 94, .18);
            }

            50% {
                box-shadow: 0 0 0 8px rgba(34, 197, 94, .08);
            }
        }

        .brand-panel {
            position: relative;
            overflow: hidden;
            isolation: isolate;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 28px;
            padding: 34px 44px 28px;
            color: #fff;
            background:
                radial-gradient(900px 480px at 85% -10%, rgba(10, 74, 40, .5), transparent 60%),
                radial-gradient(700px 500px at -10% 100%, rgba(15, 30, 70, .6), transparent 60%),
                radial-gradient(600px 420px at 50% 55%, rgba(5, 30, 20, .4), transparent 65%),
                linear-gradient(150deg, #081428 0%, #0D2745 30%, #0B5328 60%, #063D15 100%);
        }

        .brand-panel::before {
            content: "";
            position: absolute;
            inset: 0;
            z-index: 0;
            background-image:
                linear-gradient(rgba(255, 255, 255, .055) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, .055) 1px, transparent 1px);
            background-size: 34px 34px;
            mask-image: radial-gradient(ellipse at 30% 20%, black 30%, transparent 75%);
            pointer-events: none;
        }

        .brand-orb {
            position: absolute;
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
        }

        .brand-orb.o1 {
            width: 520px;
            height: 520px;
            right: -200px;
            top: -190px;
            background: radial-gradient(circle, rgba(14, 78, 120, .4), transparent 70%);
            border: 1px solid rgba(20, 60, 100, .4);
            animation: floatOrb 9s ease-in-out infinite;
        }

        .brand-orb.o2 {
            width: 340px;
            height: 340px;
            left: -130px;
            bottom: -120px;
            background: radial-gradient(circle, rgba(6, 50, 20, .45), transparent 70%);
            animation: floatOrb2 11s ease-in-out infinite;
        }

        .brand-orb.o3 {
            width: 190px;
            height: 190px;
            top: 44%;
            right: 16%;
            background: radial-gradient(circle, rgba(20, 60, 40, .4), transparent 70%);
            animation: floatOrb2 7s ease-in-out infinite reverse;
        }

        .brand-top,
        .hero,
        .brand-footer {
            position: relative;
            z-index: 1;
        }

        .brand-top {
            display: flex;
            align-items: center;
            gap: 14px;
            animation: fadeUp .5s ease both;
        }

        .brand-logo {
            width: 60px;
            height: 60px;
            flex: 0 0 60px;
            border-radius: 50%;
            background: #fff;
            border: 3px solid rgba(255, 255, 255, .75);
            box-shadow: 0 8px 24px rgba(0, 0, 0, .25);
            overflow: hidden;
            display: grid;
            place-items: center;
        }

        .brand-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 5px;
        }

        .brand-org {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .13em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .72);
            line-height: 1.4;
        }

        .brand-unit {
            font-family: "Sora", "Inter", sans-serif;
            font-size: 17px;
            font-weight: 800;
            letter-spacing: -.01em;
            margin-top: 2px;
        }

        .brand-badge {
            margin-left: auto;
            flex: 0 0 auto;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 7px 12px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .22);
            background: rgba(255, 255, 255, .10);
            -webkit-backdrop-filter: blur(8px);
            backdrop-filter: blur(8px);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .brand-badge .dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--hpu-lime);
            animation: pulseDot 2.2s infinite;
        }

        .hero {
            max-width: 680px;
            padding: 8px 0 0;
            animation: fadeUp .55s .06s ease both;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid rgba(255, 255, 255, .20);
            background: rgba(255, 255, 255, .09);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .09em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .92);
        }

        .hero h1 {
            font-family: "Sora", "Inter", sans-serif;
            margin: 18px 0 12px;
            font-size: clamp(34px, 4.2vw, 56px);
            line-height: .98;
            letter-spacing: -.045em;
            font-weight: 800;
        }

        .hero h1 .grad {
            display: block;
            background: linear-gradient(100deg, #16a34a 0%, #3b82f6 55%, #2563eb 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero>p {
            margin: 0;
            max-width: 600px;
            color: rgba(255, 255, 255, .78);
            font-size: 15.5px;
            line-height: 1.7;
        }

        .hero>p strong {
            color: #fff;
        }

        .module-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 10px;
            margin-top: 22px;
        }

        .module {
            padding: 13px 13px 12px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, .15);
            background: rgba(255, 255, 255, .08);
            backdrop-filter: blur(10px);
            transition: transform .18s ease, background .2s ease, border-color .2s ease;
            min-width: 0;
        }

        .module:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, .13);
            border-color: rgba(255, 255, 255, .28);
        }

        .module .m-icon {
            width: 32px;
            height: 32px;
            border-radius: 9px;
            display: grid;
            place-items: center;
            margin-bottom: 10px;
            background: rgba(29, 78, 216, .35);
            color: #dbeafe;
            font-size: 16px;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .15), 0 0 12px rgba(29, 78, 216, .35);
        }

        .module:nth-child(even) .m-icon {
            background: rgba(13, 94, 46, .35);
            color: #dcfce7;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .15), 0 0 12px rgba(13, 94, 46, .35);
        }

        .module strong {
            display: block;
            font-size: 12.5px;
            line-height: 1.25;
            margin-bottom: 3px;
        }

        .module span {
            display: block;
            font-size: 11px;
            line-height: 1.45;
            color: rgba(255, 255, 255, .64);
        }

        .module.more {
            background: rgba(29, 78, 216, .25);
            border-color: rgba(59, 130, 246, .5);
        }

        .module.more .m-icon {
            background: rgba(29, 78, 216, .45);
            color: #dbeafe;
        }

        .workflow-strip {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 14px;
            border: 1px solid rgba(255, 255, 255, .14);
            background: rgba(0, 0, 0, .22);
            backdrop-filter: blur(8px);
        }

        .workflow-strip .w-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .72);
            margin-bottom: 12px;
        }

        .workflow-strip .w-label a {
            color: #bbf7d0;
            font-size: 11px;
            text-decoration: none;
        }

        .w-steps {
            display: flex;
            align-items: flex-start;
            gap: 0;
        }

        .w-step {
            flex: 1;
            min-width: 0;
            text-align: center;
            position: relative;
        }

        .w-step::before {
            content: "";
            position: absolute;
            top: 9px;
            left: -50%;
            width: 100%;
            height: 2px;
            background: rgba(255, 255, 255, .18);
        }

        .w-step:first-child::before {
            display: none;
        }

        .w-dot {
            position: relative;
            z-index: 1;
            width: 20px;
            height: 20px;
            margin: 0 auto 6px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: #1e3a8a;
            border: 2px solid rgba(147, 197, 253, .60);
            color: #bfdbfe;
            font-size: 10px;
        }

        .w-step.done .w-dot {
            background: var(--hpu-lime);
            border-color: #bbf7d0;
            color: #052e16;
            box-shadow: 0 0 10px rgba(34, 197, 94, .55);
        }

        .w-step.now .w-dot {
            background: #fff;
            border-color: #fff;
            color: var(--wb-blue-dark);
            box-shadow: 0 0 0 5px rgba(147, 197, 253, .35);
        }

        .w-step span {
            display: block;
            font-size: 10px;
            font-weight: 700;
            line-height: 1.3;
            color: rgba(255, 255, 255, .72);
            padding: 0 2px;
        }

        .w-step.now span {
            color: #fff;
        }

        .w-note {
            margin: 10px 0 0;
            font-size: 11px;
            color: rgba(255, 255, 255, .58);
            line-height: 1.5;
        }

        .w-note b {
            color: rgba(255, 255, 255, .85);
        }

        .brand-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
            padding-top: 16px;
            border-top: 1px solid rgba(255, 255, 255, .14);
            font-size: 11px;
            color: rgba(255, 255, 255, .62);
            line-height: 1.55;
        }

        .role-chips {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
        }

        .role-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            border: 1px solid rgba(255, 255, 255, .16);
            background: rgba(255, 255, 255, .08);
            color: rgba(255, 255, 255, .88);
        }

        .role-chip i {
            font-size: 12px;
            color: #93c5fd;
        }

        .role-chip:nth-child(even) i {
            color: #4ade80;
        }

        .brand-contact {
            text-align: right;
        }

        .brand-contact a {
            color: #fff;
            font-weight: 700;
            text-decoration: none;
            border-bottom: 1px dotted rgba(255, 255, 255, .5);
        }

        .brand-contact a:hover {
            border-bottom-style: solid;
        }

        .login-panel {
            position: relative;
            display: grid;
            place-items: center;
            padding: 32px 30px;
            background:
                radial-gradient(ellipse at 12% 6%, rgba(13, 83, 40, .35), transparent 42%),
                radial-gradient(ellipse at 92% 96%, rgba(15, 30, 70, .45), transparent 42%),
                #d1daea;
        }

        .login-wrap {
            width: min(100%, 580px);
            animation: fadeUp .5s .08s ease backwards;
        }

        .mobile-brand {
            display: none;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
        }

        .mobile-logo {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: #fff;
            border: 2px solid var(--wb-blue-dark);
            box-shadow: 0 0 0 3px rgba(13, 94, 46, .3);
            overflow: hidden;
            display: grid;
            place-items: center;
            flex: 0 0 48px;
        }

        .mobile-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 4px;
        }

        .mobile-brand strong {
            display: block;
            font-size: 15px;
            font-family: "Sora", sans-serif;
        }

        .mobile-brand span {
            display: block;
            font-size: 11px;
            color: var(--muted);
            margin-top: 1px;
        }

        .login-card {
            background: #ffffff;
            border: 1px solid rgba(37, 99, 235, 0.25);
            border-radius: 24px;
            box-shadow: 0 24px 60px -10px rgba(15, 23, 42, 0.15), 0 8px 25px -5px rgba(37, 99, 235, 0.1);
            padding: 36px 36px 30px;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
        }

        .login-card::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: var(--wb-blue-dark);
        }

        .login-card .sh {
            margin-bottom: 18px;
        }

        .sh-eyebrow {
            color: var(--wb-blue-dark) !important;
        }

        .sh-icon {
            background: var(--wb-blue-dark) !important;
            border-color: var(--wb-blue-dark) !important;
            color: #fff !important;
        }

        .landing-hint {
            display: flex;
            gap: 8px;
            align-items: flex-start;
            margin: 0 0 18px;
            padding: 11px 13px;
            border-radius: 12px;
            background: var(--wb-blue-dark);
            border: 1px solid var(--hpu-green);
            font-size: 12px;
            line-height: 1.55;
            color: #fff;
        }

        .landing-hint i {
            font-size: 16px;
            margin-top: 1px;
            color: var(--hpu-lime);
            flex: 0 0 auto;
        }

        @keyframes shake {

            10%,
            90% {
                transform: translateX(-1px);
            }

            20%,
            80% {
                transform: translateX(2px);
            }

            30%,
            50%,
            70% {
                transform: translateX(-3px);
            }

            40%,
            60% {
                transform: translateX(3px);
            }
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-8px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .alert-danger {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border: 1px solid var(--danger-border);
            border-radius: 12px;
            background: var(--danger-soft);
            color: var(--danger);
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 18px;
            animation: shake .45s ease both, slideIn .3s ease;
        }

        .alert-danger i {
            font-size: 17px;
            margin-top: 1px;
            flex: 0 0 auto;
        }

        .alert-success {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 18px;
            border: 1px solid var(--hpu-green);
            background: var(--hpu-green);
            color: #fff;
            animation: slideIn .3s ease;
        }

        .alert-success i {
            font-size: 17px;
            margin-top: 1px;
            flex: 0 0 auto;
            color: var(--hpu-lime);
        }

        .field {
            margin-bottom: 20px;
        }

        .field-label {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            margin-bottom: 8px;
        }

        .field-label label {
            margin: 0;
            font-size: 13px;
            font-weight: 700;
            color: #2d3843;
        }

        .field-note {
            font-size: 11px;
            color: var(--faint);
        }

        .input-shell {
            position: relative;
        }

        .input-shell .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 17px;
            pointer-events: none;
            width: 20px;
            text-align: center;
            transition: color .2s ease;
        }

        .input-shell input {
            width: 100%;
            height: 54px;
            padding: 0 52px 0 48px;
            border: 1.5px solid #a8c5e8;
            border-radius: 14px;
            outline: none;
            background: #eef2ff;
            color: var(--ink);
            font-size: 15px;
            font-weight: 500;
            transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
        }

        .input-shell input::placeholder {
            color: #9ca3b6;
            font-weight: 400;
        }

        .input-shell input:hover {
            border-color: rgba(29, 78, 216, .6);
            background: #e0efff;
        }

        .input-shell input:focus {
            border-color: var(--wb-blue);
            background: #fff;
            box-shadow: var(--ring), var(--ring-green);
        }

        .input-shell input.is-invalid {
            border-color: #dc2626;
            background: #fef2f2;
        }

        .input-shell input.is-valid {
            border-color: var(--hpu-green);
            background: #f0fdf4;
        }

        .input-shell:focus-within .input-icon {
            color: var(--wb-blue);
        }

        .password-toggle {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            width: 40px;
            height: 40px;
            border: 0;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: transparent;
            color: #788791;
            cursor: pointer;
            transition: color .15s ease, background .15s ease;
        }

        .password-toggle:hover {
            color: var(--wb-blue-dark);
            background: #dbeafe;
        }

        .password-toggle:focus-visible {
            outline: 2px solid var(--wb-blue);
            outline-offset: 2px;
        }

        .password-toggle i {
            font-size: 19px;
        }

        .caps-warn {
            display: none;
            align-items: center;
            gap: 6px;
            margin-top: 7px;
            font-size: 11.5px;
            font-weight: 600;
            color: #92400e;
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 8px;
            padding: 6px 9px;
        }

        .caps-warn.show {
            display: inline-flex;
        }

        .form-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 8px 0 20px;
            font-size: 13px;
            color: var(--muted);
        }

        .remember {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            cursor: pointer;
            user-select: none;
            font-weight: 600;
        }

        .remember input {
            width: 18px;
            height: 18px;
            accent-color: var(--hpu-green);
            cursor: pointer;
        }

        .help-link {
            font-size: 13px;
            font-weight: 700;
            color: var(--wb-blue-dark);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color .15s ease;
        }

        .help-link:hover {
            color: var(--hpu-green);
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .signin-btn {
            width: 100%;
            min-height: 54px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            border: 0;
            border-radius: 14px;
            cursor: pointer;
            background: var(--wb-blue-dark);
            color: #fff;
            font-size: 15px;
            font-weight: 800;
            letter-spacing: -.01em;
            box-shadow: 0 4px 14px rgba(13, 83, 40, .35);
            transition: transform .12s ease, box-shadow .15s ease, background .15s ease;
            position: relative;
            overflow: hidden;
        }

        .signin-btn::before {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, rgba(255, 255, 255, .15), rgba(255, 255, 255, 0));
            opacity: 0;
            transition: opacity .2s ease;
        }

        .signin-btn:hover:not(:disabled) {
            transform: translateY(-1px);
            box-shadow: 0 10px 24px rgba(13, 83, 40, .45);
            background: #123a8c;
        }

        .signin-btn:hover:not(:disabled)::before {
            opacity: 1;
        }

        .signin-btn:active:not(:disabled) {
            transform: translateY(1px);
            box-shadow: 0 2px 6px rgba(13, 83, 40, .3);
        }

        .signin-btn:disabled {
            opacity: .7;
            cursor: wait;
            transform: none;
        }

        .signin-btn .spinner {
            display: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, .4);
            border-top-color: #fff;
            animation: spin .8s linear infinite;
        }

        .signin-btn.loading .spinner {
            display: inline-block;
        }

        .signin-btn.loading .btn-arrow {
            display: none;
        }

        .signin-btn.loading {
            pointer-events: none;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }

        .divider {
            display: flex;
            align-items: center;
            gap: 14px;
            margin: 24px 0 20px;
            color: var(--faint);
            font-size: 11px;
            font-weight: 800;
            letter-spacing: .08em;
            text-transform: uppercase;
        }

        .divider::before,
        .divider::after {
            content: "";
            flex: 1;
            height: 1px;
            background: var(--wb-blue-dark);
        }

        .track-box {
            border: 1px solid var(--wb-blue-dark);
            border-radius: 16px;
            background: var(--wb-blue-dark);
            padding: 16px;
        }

        .track-box .t-head {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 8px;
        }

        .track-box .t-icon {
            width: 34px;
            height: 34px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            background: var(--hpu-green);
            color: #fff;
            font-size: 16px;
            flex: 0 0 34px;
        }

        .track-box strong {
            font-size: 14px;
            display: block;
            color: #fff;
        }

        .track-box .t-sub {
            font-size: 12px;
            color: #d1fae5;
            margin: 0;
            line-height: 1.5;
        }

        .track-row {
            display: flex;
            gap: 10px;
            margin-top: 12px;
        }

        .track-row input {
            flex: 1;
            min-width: 0;
            height: 46px;
            padding: 0 14px;
            border: 1.5px solid var(--wb-blue);
            border-radius: 12px;
            background: #fff;
            font-size: 14px;
            outline: none;
        }

        .track-row input:focus {
            border-color: var(--hpu-green);
            box-shadow: var(--ring-green);
        }

        .track-row button {
            flex: 0 0 auto;
            height: 46px;
            padding: 0 20px;
            border-radius: 12px;
            border: 1px solid var(--hpu-green);
            background: var(--hpu-green);
            color: #fff;
            font-weight: 800;
            font-size: 14px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            transition: background .15s ease, border-color .15s ease;
        }

        .track-row button:hover {
            background: var(--hpu-green-dark);
            border-color: var(--hpu-green-dark);
            color: #fff;
        }

        .track-err {
            display: none;
            margin: 10px 0 0;
            font-size: 12px;
            font-weight: 600;
            color: var(--danger);
        }

        .track-err.show {
            display: block;
            animation: fadeIn .2s ease;
        }

        .alert-success {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            border-radius: 12px;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 18px;
            border: 1px solid var(--hpu-green);
            background: var(--hpu-green);
            color: #fff;
        }

        .alert-success i {
            font-size: 17px;
            margin-top: 1px;
            flex: 0 0 auto;
            color: var(--hpu-lime);
        }

        .signup-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 16px;
            font-size: 13px;
            color: var(--wb-blue-dark);
            text-align: center;
            flex-wrap: wrap;
        }

        .signup-row button {
            border: 0;
            background: none;
            padding: 0;
            cursor: pointer;
            color: var(--wb-blue-dark);
            font-weight: 800;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            text-decoration: underline;
            text-underline-offset: 3px;
        }

        .signup-row button:hover {
            color: var(--hpu-green);
        }

        .acct-modal .modal-content {
            border-radius: 18px;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .acct-modal .modal-header {
            background: linear-gradient(135deg, var(--hpu-green-dark), var(--hpu-green));
            color: #fff;
            border-bottom: 0;
            padding: 18px 22px;
        }

        .acct-modal .modal-title {
            font-family: "Sora", "Inter", sans-serif;
            font-size: 1.05rem;
            font-weight: 800;
        }

        .acct-modal .modal-header .btn-close {
            filter: invert(1);
        }

        .acct-modal .modal-body {
            padding: 22px;
            max-height: 70vh;
            overflow-y: auto;
        }

        .acct-modal .modal-footer {
            border-top: 1px solid var(--border-soft);
            padding: 14px 22px;
        }

        .acct-sub {
            margin: 0 0 16px;
            font-size: 12.5px;
            color: var(--muted);
            line-height: 1.6;
        }

        .acct-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
        }

        .acct-grid .full {
            grid-column: 1 / -1;
        }

        .acct-field label {
            display: block;
            margin-bottom: 6px;
            font-size: 12.5px;
            font-weight: 700;
            color: #2d3843;
        }

        .acct-field label .req {
            color: #dc2626;
        }

        .acct-field input,
        .acct-field select,
        .acct-field textarea {
            width: 100%;
            border: 1.5px solid #dfe7e3;
            border-radius: 10px;
            outline: none;
            background: #f8fafc;
            color: var(--ink);
            font-size: 13.5px;
            padding: 10px 12px;
            transition: border-color .18s ease, box-shadow .2s ease, background .18s ease;
        }

        .acct-field textarea {
            resize: vertical;
            min-height: 74px;
        }

        .acct-field input:focus,
        .acct-field select:focus,
        .acct-field textarea:focus {
            border-color: var(--hpu-green);
            background: #fff;
            box-shadow: var(--ring);
        }

        .acct-hint {
            margin: 6px 0 0;
            font-size: 11px;
            color: var(--faint);
            line-height: 1.5;
        }

        .acct-hint.strong {
            color: #92400e;
        }

        .hp-field {
            position: absolute;
            left: -9999px;
            top: auto;
            width: 1px;
            height: 1px;
            overflow: hidden;
        }

        .role-locked {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 11px 13px;
            border: 1.5px solid #dfe7e3;
            border-radius: 10px;
            background: #f0fdf4;
            color: var(--ink);
        }

        .role-locked>i:first-child {
            display: grid;
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            place-items: center;
            border-radius: 9px;
            background: var(--hpu-green);
            color: #fff;
            font-size: 15px;
        }

        .role-locked strong {
            display: block;
            font-size: 13.5px;
        }

        .role-locked span {
            display: block;
            margin-top: 2px;
            font-size: 11.5px;
            line-height: 1.5;
            color: var(--muted);
        }

        .role-lock-icon {
            margin-left: auto;
            flex: 0 0 auto;
            color: var(--hpu-green);
            font-size: 14px;
            margin-top: 2px;
        }

        .acct-submit {
            width: 100%;
            min-height: 50px;
            border: 0;
            border-radius: 12px;
            cursor: pointer;
            background: linear-gradient(180deg, var(--hpu-green-dark), var(--hpu-green) 55%, var(--hpu-green));
            color: #fff;
            font-size: 14px;
            font-weight: 800;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 10px 24px rgba(10, 62, 34, .3);
        }

        .acct-submit:hover:not(:disabled) {
            filter: brightness(1.05);
        }

        .acct-submit:disabled {
            opacity: .75;
            cursor: wait;
        }

        @media (max-width: 560px) {
            .acct-grid {
                grid-template-columns: 1fr;
            }
        }

        .after-login {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            margin-top: 14px;
        }

        .after-login div {
            border: 1px solid var(--wb-blue-dark);
            border-radius: 11px;
            background: var(--wb-blue-dark);
            padding: 10px 11px;
            font-size: 11px;
            line-height: 1.5;
            color: #fff;
        }

        .after-login b {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11.5px;
            color: #fff;
            margin-bottom: 2px;
        }

        .after-login b i {
            color: var(--hpu-lime);
        }

        .security-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            margin-top: 18px;
            color: var(--hpu-green);
            font-size: 11px;
            text-align: center;
        }

        .security-note i {
            color: var(--hpu-green);
            font-size: 14px;
        }

        .system-meta {
            margin-top: 12px;
            color: var(--hpu-green-dark);
            font-size: 10.5px;
            text-align: center;
            line-height: 1.6;
        }

        .system-meta a {
            color: var(--wb-blue-dark);
        }

        @media (max-height: 820px) and (min-width: 1081px) {
            .brand-panel {
                justify-content: flex-start;
                overflow-y: auto;
            }

            .brand-footer {
                margin-top: auto;
            }

            .hero h1 {
                margin-top: 12px;
                font-size: clamp(30px, 3.5vw, 46px);
            }

            .module-grid {
                margin-top: 16px;
            }

            .workflow-strip {
                margin-top: 12px;
            }

            .login-panel {
                align-items: start;
                overflow-y: auto;
            }

            .login-wrap {
                margin: auto;
            }
        }

        @media (max-width: 1440px) {
            .page-shell {
                grid-template-columns: minmax(0, 1.1fr) minmax(440px, .9fr);
            }

            .brand-panel {
                padding: 30px 36px 24px;
            }

            .hero h1 {
                font-size: clamp(32px, 3.8vw, 52px);
            }

            .hero>p {
                font-size: 15px;
            }

            .module-grid {
                gap: 9px;
            }

            .module {
                padding: 12px 12px 11px;
            }
        }

        @media (max-width: 1200px) {
            .page-shell {
                grid-template-columns: minmax(0, 1.05fr) minmax(420px, .95fr);
            }

            .brand-panel {
                padding: 28px 32px 22px;
            }

            .login-wrap {
                width: min(100%, 440px);
            }

            .login-card {
                padding: 26px 26px 22px;
            }
        }

        @media (max-width: 1080px) {
            .page-shell {
                grid-template-columns: 1fr;
            }

            .brand-panel {
                display: none;
            }

            .login-panel {
                min-height: 100vh;
                padding: 26px 20px;
            }

            .mobile-brand {
                display: flex;
            }
        }

        @media (max-width: 768px) {
            .login-panel {
                padding: 24px 16px;
            }

            .login-card {
                padding: 24px 20px 20px;
                border-radius: 18px;
            }

            .module-grid {
                grid-template-columns: 1fr 1fr;
                gap: 9px;
            }

            .module {
                padding: 12px 12px 10px;
            }

            .hero h1 {
                font-size: clamp(28px, 6vw, 44px);
            }

            .hero>p {
                font-size: 14.5px;
            }

            .after-login {
                grid-template-columns: 1fr;
                gap: 6px;
            }
        }

        @media (max-width: 560px) {
            .login-panel {
                padding: 20px 12px;
                align-items: start;
            }

            .login-card {
                padding: 22px 18px 20px;
                border-radius: 16px;
            }

            .module-grid {
                grid-template-columns: 1fr 1fr;
            }

            .after-login {
                grid-template-columns: 1fr;
            }

            .track-row {
                flex-direction: column;
            }

            .track-row button {
                width: 100%;
                justify-content: center;
            }

            .form-row {
                flex-wrap: wrap;
            }

            .input-shell input {
                height: 50px;
                font-size: 16px;
            }

            .signin-btn {
                min-height: 50px;
                font-size: 15px;
            }

            .track-row input,
            .track-row button {
                height: 44px;
            }

            .mobile-brand {
                margin-bottom: 14px;
            }
        }

        @media (max-width: 420px) {
            .login-panel {
                padding: 16px 10px;
            }

            .login-card {
                padding: 20px 16px 18px;
                border-radius: 14px;
            }

            .module-grid {
                grid-template-columns: 1fr;
            }

            .hero h1 {
                font-size: clamp(24px, 7vw, 36px);
            }

            .hero>p {
                font-size: 13.5px;
            }

            .signin-btn {
                font-size: 14.5px;
            }
        }

        @media (min-width: 1920px) {
            .page-shell {
                grid-template-columns: minmax(0, 1.2fr) minmax(500px, .8fr);
                max-width: 100%;
            }

            .brand-panel {
                padding: 44px 56px 36px;
            }

            .brand-top {
                gap: 18px;
            }

            .brand-logo {
                width: 72px;
                height: 72px;
            }

            .brand-org {
                font-size: 12px;
            }

            .brand-unit {
                font-size: 20px;
            }

            .brand-badge {
                padding: 9px 16px;
                font-size: 12px;
            }

            .hero h1 {
                font-size: clamp(48px, 3.5vw, 72px);
            }

            .hero>p {
                font-size: 18px;
                line-height: 1.8;
            }

            .module-grid {
                gap: 14px;
            }

            .module {
                padding: 16px 16px 14px;
            }

            .module .m-icon {
                width: 38px;
                height: 38px;
                font-size: 19px;
            }

            .workflow-strip {
                padding: 18px 20px;
            }

            .w-step span {
                font-size: 11px;
            }

            .login-wrap {
                width: min(100%, 520px);
            }

            .login-card {
                padding: 36px 36px 30px;
            }

            .input-shell input {
                height: 58px;
                font-size: 16px;
            }

            .signin-btn {
                min-height: 58px;
                font-size: 16px;
            }

            .track-box {
                padding: 18px;
            }

            .track-row input,
            .track-row button {
                height: 50px;
            }
        }

        @media (min-width: 2560px) {
            .page-shell {
                grid-template-columns: minmax(0, 1.25fr) minmax(560px, .75fr);
            }

            .brand-panel {
                padding: 52px 64px 42px;
            }

            .brand-logo {
                width: 84px;
                height: 84px;
            }

            .brand-unit {
                font-size: 22px;
            }

            .hero h1 {
                font-size: clamp(56px, 3vw, 84px);
            }

            .hero>p {
                font-size: 20px;
            }

            .module-grid {
                gap: 16px;
            }

            .module {
                padding: 18px 18px 16px;
            }

            .login-wrap {
                width: min(100%, 580px);
            }

            .login-card {
                padding: 40px 40px 34px;
            }

            .input-shell input {
                height: 62px;
                font-size: 17px;
            }

            .signin-btn {
                min-height: 62px;
                font-size: 17px;
            }
        }

        @media (prefers-reduced-motion: reduce) {

            *,
            *::before,
            *::after {
                animation-duration: .001s !important;
                animation-iteration-count: 1 !important;
                transition-duration: .001s !important;
                scroll-behavior: auto !important;
            }
        }

        /* ============================================================
           2026 UI REFRESH
           Keeps native PHP/MySQLi + Bootstrap structure unchanged.
        ============================================================ */

        :root {
            --ui-navy: #0b1830;
            --ui-navy-2: #10254a;
            --ui-blue: #1d4ed8;
            --ui-blue-2: #2563eb;
            --ui-green: #0b6b2b;
            --ui-green-2: #15803d;
            --ui-bg: #eef3f9;
            --ui-surface: #ffffff;
            --ui-border: #dce5f0;
            --ui-text: #142033;
            --ui-muted: #66758a;
            --ui-soft-blue: #eff6ff;
            --ui-soft-green: #ecfdf3;
            --ui-shadow: 0 24px 70px rgba(15, 23, 42, .14);
        }

        body {
            min-height: 100vh;
            background:
                radial-gradient(circle at 88% 6%, rgba(37, 99, 235, .10), transparent 26rem),
                radial-gradient(circle at 8% 94%, rgba(21, 128, 61, .08), transparent 25rem),
                var(--ui-bg);
        }

        .page-shell {
            min-height: 100vh;
            grid-template-columns: minmax(0, 1.12fr) minmax(430px, .88fr);
            background: var(--ui-bg);
        }

        /* ---------- LEFT / BRAND SIDE ---------- */

        .brand-panel {
            padding: 32px 40px 26px;
            background:
                radial-gradient(600px 420px at 90% 8%, rgba(37, 99, 235, .27), transparent 65%),
                radial-gradient(520px 420px at 10% 95%, rgba(21, 128, 61, .22), transparent 65%),
                linear-gradient(145deg, #081629 0%, #0d2445 46%, #0b4c2b 100%);
        }

        .brand-panel::before {
            opacity: .56;
            background-size: 40px 40px;
        }

        .brand-orb {
            opacity: .55;
            filter: blur(.2px);
        }

        .brand-top {
            padding-bottom: 16px;
            border-bottom: 1px solid rgba(255, 255, 255, .10);
        }

        .brand-logo {
            width: 56px;
            height: 56px;
            flex-basis: 56px;
            border: 0;
            box-shadow:
                0 10px 30px rgba(0, 0, 0, .24),
                0 0 0 5px rgba(255, 255, 255, .08);
        }

        .brand-org {
            max-width: 430px;
            color: rgba(255, 255, 255, .66);
            font-size: 10.5px;
            letter-spacing: .12em;
        }

        .brand-unit {
            font-size: 18px;
            letter-spacing: -.025em;
        }

        .brand-badge {
            padding: 6px 10px;
            background: rgba(255, 255, 255, .08);
            border-color: rgba(255, 255, 255, .13);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .08);
            backdrop-filter: blur(12px);
        }

        .hero {
            max-width: 720px;
            padding-top: 4px;
        }

        .eyebrow {
            padding: 6px 10px;
            background: rgba(255, 255, 255, .065);
            border-color: rgba(255, 255, 255, .13);
            color: rgba(255, 255, 255, .82);
            font-size: 10px;
        }

        .hero h1 {
            max-width: 720px;
            margin: 16px 0 13px;
            font-size: clamp(36px, 4.1vw, 58px);
            line-height: 1.01;
        }

        .hero h1 .grad {
            background: linear-gradient(90deg, #86efac 0%, #93c5fd 58%, #60a5fa 100%);
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .hero>p {
            max-width: 650px;
            font-size: 14.5px;
            line-height: 1.72;
            color: rgba(255, 255, 255, .70);
        }

        .module-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 9px;
            margin-top: 20px;
        }

        .module {
            min-height: 104px;
            padding: 12px;
            border-radius: 13px;
            border-color: rgba(255, 255, 255, .11);
            background: rgba(255, 255, 255, .055);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .06);
        }

        .module:hover {
            transform: translateY(-2px);
            background: rgba(255, 255, 255, .09);
            border-color: rgba(147, 197, 253, .34);
        }

        .module .m-icon {
            width: 30px;
            height: 30px;
            margin-bottom: 8px;
            border-radius: 8px;
            font-size: 14px;
            background: rgba(37, 99, 235, .23);
            box-shadow: none;
        }

        .module:nth-child(even) .m-icon {
            background: rgba(21, 128, 61, .24);
            box-shadow: none;
        }

        .module strong {
            font-size: 12px;
        }

        .module span {
            font-size: 10.5px;
            color: rgba(255, 255, 255, .56);
        }

        .module.more {
            background: rgba(255, 255, 255, .065);
            border-color: rgba(134, 239, 172, .20);
        }

        .module.more .m-icon {
            background: rgba(21, 128, 61, .28);
            color: #dcfce7;
        }

        .workflow-strip {
            margin-top: 13px;
            padding: 12px 14px 11px;
            border-radius: 13px;
            background: rgba(3, 15, 30, .32);
            border-color: rgba(255, 255, 255, .09);
        }

        .workflow-strip .w-label {
            margin-bottom: 10px;
            font-size: 9.5px;
        }

        .workflow-strip .w-label a {
            color: #86efac;
        }

        .w-dot {
            width: 19px;
            height: 19px;
            font-size: 9px;
            background: rgba(37, 99, 235, .42);
        }

        .w-step span {
            font-size: 9px;
            color: rgba(255, 255, 255, .63);
        }

        .w-note {
            margin-top: 8px;
            font-size: 9.8px;
            color: rgba(255, 255, 255, .50);
        }

        .brand-footer {
            padding-top: 14px;
            color: rgba(255, 255, 255, .52);
        }

        .role-chip {
            padding: 4px 8px;
            font-size: 10px;
            background: rgba(255, 255, 255, .055);
            border-color: rgba(255, 255, 255, .11);
        }

        /* ---------- LOGIN SIDE ---------- */

        .login-panel {
            position: relative;
            min-width: 0;
            padding: 28px;
            background:
                radial-gradient(circle at 80% 10%, rgba(37, 99, 235, .10), transparent 22rem),
                radial-gradient(circle at 18% 90%, rgba(21, 128, 61, .075), transparent 22rem),
                #eef3f9;
        }

        .login-panel::before {
            content: "";
            position: absolute;
            inset: 0;
            pointer-events: none;
            background-image:
                linear-gradient(rgba(37, 99, 235, .022) 1px, transparent 1px),
                linear-gradient(90deg, rgba(37, 99, 235, .022) 1px, transparent 1px);
            background-size: 32px 32px;
            mask-image: radial-gradient(circle at center, black 20%, transparent 78%);
        }

        .login-wrap {
            position: relative;
            z-index: 1;
            width: min(100%, 580px);
        }

        .login-card {
            padding: 34px 34px 28px;
            border: 1px solid rgba(37, 99, 235, 0.22);
            border-radius: 24px;
            background: rgba(255, 255, 255, .98);
            box-shadow: 0 24px 60px -10px rgba(15, 23, 42, 0.15);
        }

        .login-card::before {
            height: 4px;
            background: linear-gradient(90deg, var(--ui-blue), var(--ui-green));
        }

        .login-card .sh {
            margin-bottom: 14px;
        }

        .login-card .sh-icon {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            background: var(--ui-navy-2) !important;
            border-color: transparent !important;
            box-shadow: 0 7px 16px rgba(15, 35, 70, .16);
        }

        .sh-eyebrow {
            color: var(--ui-blue) !important;
            font-size: 10px !important;
            letter-spacing: .10em !important;
        }

        .sh-title {
            color: var(--ui-text);
            letter-spacing: -.025em;
        }

        .sh-subtitle {
            color: var(--ui-muted) !important;
        }

        .landing-hint {
            margin-bottom: 16px;
            padding: 10px 12px;
            border: 1px solid #dbe7f7;
            border-radius: 11px;
            background: #f7faff;
            color: #42526a;
            font-size: 11px;
            line-height: 1.5;
        }

        .landing-hint i {
            color: var(--ui-blue);
        }

        .landing-hint strong {
            color: var(--ui-text);
        }

        .field {
            margin-bottom: 16px;
        }

        .field-label {
            margin-bottom: 6px;
        }

        .field-label label {
            color: #25344b;
            font-size: 12px;
        }

        .field-note {
            font-size: 10px;
        }

        .input-shell input {
            height: 50px;
            padding-left: 45px;
            border: 1px solid #cfdbea;
            border-radius: 12px;
            background: #f8fafc;
            font-size: 14px;
        }

        .input-shell input:hover {
            background: #fff;
            border-color: #a8bfdc;
        }

        .input-shell input:focus {
            border-color: var(--ui-blue);
            background: #fff;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, .10);
        }

        .input-shell .input-icon {
            left: 14px;
            color: #8492a6;
            font-size: 15px;
        }

        .password-toggle {
            right: 7px;
            width: 37px;
            height: 37px;
            border-radius: 9px;
        }

        .form-row {
            margin: 4px 0 16px;
            font-size: 12px;
        }

        .help-link {
            color: var(--ui-blue);
            font-size: 12px;
        }

        .remember input {
            width: 16px;
            height: 16px;
            accent-color: var(--ui-blue);
        }

        .signin-btn {
            min-height: 50px;
            border-radius: 12px;
            background: linear-gradient(135deg, #163d8a 0%, #1d4ed8 55%, #0b6b2b 120%);
            font-size: 14px;
            box-shadow: 0 10px 24px rgba(29, 78, 216, .20);
        }

        .signin-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #123472 0%, #1d4ed8 65%, #0b6b2b 125%);
            box-shadow: 0 14px 30px rgba(29, 78, 216, .26);
        }

        .signup-row {
            margin-top: 14px;
            font-size: 12px;
            color: var(--ui-muted);
        }

        .signup-row button {
            color: var(--ui-blue);
            font-size: 12px;
            text-decoration: none;
        }

        .signup-row button:hover {
            color: var(--ui-green);
            text-decoration: underline;
        }

        .divider {
            margin: 20px 0 16px;
            color: #8a99ac;
            font-size: 9px;
        }

        .divider::before,
        .divider::after {
            background: #dce5ef;
        }

        .track-box {
            padding: 13px;
            border: 1px solid #d8e3f0;
            border-radius: 13px;
            background: #f8fafc;
        }

        .track-box .t-head {
            margin-bottom: 6px;
        }

        .track-box .t-icon {
            width: 32px;
            height: 32px;
            flex-basis: 32px;
            border-radius: 9px;
            background: var(--ui-soft-blue);
            color: var(--ui-blue);
        }

        .track-box strong {
            color: var(--ui-text);
            font-size: 12.5px;
        }

        .track-box .t-sub {
            color: var(--ui-muted);
            font-size: 10.5px;
            line-height: 1.45;
        }

        .track-row {
            gap: 8px;
            margin-top: 10px;
        }

        .track-row input {
            height: 42px;
            border-color: #cfdbea;
            border-radius: 10px;
            background: #fff;
            font-size: 12px;
        }

        .track-row button {
            height: 42px;
            padding: 0 15px;
            border: 0;
            border-radius: 10px;
            background: var(--ui-navy-2);
            font-size: 12px;
            box-shadow: 0 6px 14px rgba(15, 35, 70, .13);
        }

        .track-row button:hover {
            background: var(--ui-blue);
        }

        .after-login {
            gap: 7px;
            margin-top: 12px;
        }

        .after-login div {
            padding: 9px 10px;
            border: 1px solid #dce5ef;
            border-radius: 10px;
            background: #f8fafc;
            color: var(--ui-muted);
            font-size: 10px;
        }

        .after-login b {
            color: var(--ui-text);
            font-size: 10.5px;
        }

        .after-login b i {
            color: var(--ui-blue);
        }

        .security-note {
            margin-top: 14px;
            color: #66758a;
            font-size: 10px;
        }

        .security-note i {
            color: var(--ui-green);
        }

        .system-meta {
            margin-top: 10px;
            color: #718096;
            font-size: 9.5px;
        }

        .system-meta a {
            color: var(--ui-blue);
        }

        /* ---------- ALERTS ---------- */

        .alert-danger,
        .alert-success {
            padding: 10px 12px;
            border-radius: 11px;
            font-size: 11.5px;
            line-height: 1.45;
        }

        .alert-success {
            border-color: #bbf7d0;
            background: #ecfdf3;
            color: #166534;
        }

        .alert-success i {
            color: #16a34a;
        }

        /* ---------- MODALS ---------- */

        .acct-modal .modal-content {
            border: 1px solid #dce5ef;
            border-radius: 18px;
            box-shadow: 0 28px 80px rgba(15, 23, 42, .18);
        }

        .acct-modal .modal-header {
            padding: 16px 20px;
            background: linear-gradient(135deg, #10254a, #1d4ed8);
        }

        .acct-modal .modal-title {
            font-size: 1rem;
        }

        .acct-modal .modal-body {
            padding: 20px;
        }

        .acct-modal .modal-footer {
            padding: 13px 20px;
        }

        .acct-field input,
        .acct-field select,
        .acct-field textarea {
            border-color: #d4deea;
            background: #f8fafc;
        }

        .acct-field input:focus,
        .acct-field select:focus,
        .acct-field textarea:focus {
            border-color: var(--ui-blue);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, .10);
        }

        .acct-submit {
            background: linear-gradient(135deg, #163d8a, #1d4ed8);
            box-shadow: 0 10px 24px rgba(29, 78, 216, .20);
        }

        /* ---------- RESPONSIVE ---------- */

        @media (max-width: 1360px) {
            .brand-panel {
                padding: 28px 30px 22px;
            }

            .hero h1 {
                font-size: clamp(32px, 3.65vw, 50px);
            }

            .module {
                min-height: 98px;
            }
        }

        @media (max-width: 1080px) {
            .page-shell {
                grid-template-columns: 1fr;
            }

            .brand-panel {
                display: none;
            }

            .login-panel {
                min-height: 100vh;
                padding: 24px 18px;
            }

            .login-wrap {
                width: min(100%, 500px);
            }

            .mobile-brand {
                display: flex;
                padding: 0 2px;
            }

            .mobile-logo {
                border: 0;
                box-shadow:
                    0 8px 20px rgba(15, 23, 42, .12),
                    0 0 0 3px rgba(37, 99, 235, .08);
            }
        }

        @media (max-width: 560px) {
            .login-panel {
                padding: 14px 10px 20px;
            }

            .login-card {
                padding: 22px 17px 18px;
                border-radius: 17px;
            }

            .landing-hint {
                font-size: 10.5px;
            }

            .form-row {
                align-items: flex-start;
            }

            .track-row {
                flex-direction: column;
            }

            .track-row button {
                width: 100%;
                justify-content: center;
            }

            .after-login {
                grid-template-columns: 1fr;
            }

            .system-meta {
                padding-inline: 8px;
            }
        }

        @media (max-height: 760px) and (min-width: 1081px) {
            .brand-panel {
                overflow-y: auto;
                padding-top: 22px;
                padding-bottom: 18px;
            }

            .hero h1 {
                font-size: clamp(28px, 3.25vw, 43px);
                margin-top: 12px;
            }

            .hero>p {
                font-size: 13px;
                line-height: 1.55;
            }

            .module-grid {
                margin-top: 13px;
            }

            .module {
                min-height: 88px;
                padding: 10px;
            }

            .workflow-strip {
                margin-top: 10px;
            }

            .login-panel {
                align-items: start;
                overflow-y: auto;
                padding-top: 18px;
                padding-bottom: 18px;
            }

            .login-card {
                padding-top: 23px;
                padding-bottom: 20px;
            }
        }

        /* Current Workbench module additions */
        .capability-row {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 10px;
        }

        .capability-chip {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 7px;
            border: 1px solid rgba(255, 255, 255, .10);
            border-radius: 999px;
            color: rgba(255, 255, 255, .65);
            background: rgba(3, 15, 30, .20);
            font-size: 8.5px;
            font-weight: 750;
            white-space: nowrap;
        }

        .capability-chip i {
            color: #93c5fd;
        }

        .mobile-modules {
            display: none;
            gap: 5px;
            flex-wrap: wrap;
            margin: -3px 2px 12px;
        }

        .mobile-modules span {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 5px 8px;
            border: 1px solid #dbe5f1;
            border-radius: 999px;
            color: #475569;
            background: rgba(255, 255, 255, .78);
            font-size: 8.5px;
            font-weight: 750;
        }

        .mobile-modules i {
            color: var(--ui-blue, #1d4ed8);
        }

        @media (max-width: 1080px) {
            .mobile-modules {
                display: flex;
            }
        }

        @media (min-width: 1081px) and (max-height: 800px) {
            .capability-row {
                gap: 4px;
                margin-top: 6px;
            }

            .capability-chip {
                padding: 3px 6px;
                font-size: 7px;
            }
        }

        @media (max-width: 420px) {
            .mobile-modules span {
                padding: 4px 6px;
                font-size: 7.5px;
            }
        }


        /* ============================================================
           LANDING FEATURE PHOTO SLIDER
           Add matching photos in: assets/images/workspace-slider/
           The built-in icon/gradient fallback keeps the slider usable
           even when a photo has not been added yet.
        ============================================================ */
        .brand-main {
            position: relative;
            z-index: 1;
            display: grid;
            grid-template-columns: minmax(0, 1.62fr) minmax(270px, .78fr);
            gap: 18px;
            align-items: stretch;
            flex: 1 1 auto;
            min-height: 0;
        }

        .brand-main>.hero {
            max-width: none;
            min-width: 0;
        }

        .feature-showcase {
            min-width: 0;
            display: flex;
            align-items: stretch;
            animation: fadeUp .58s .12s ease both;
        }

        .feature-slider {
            position: relative;
            width: 100%;
            min-height: 560px;
            overflow: hidden;
            border: 1px solid rgba(255, 255, 255, .15);
            border-radius: 22px;
            background: rgba(5, 18, 39, .50);
            box-shadow:
                inset 0 1px 0 rgba(255, 255, 255, .08),
                0 22px 48px rgba(0, 0, 0, .20);
            backdrop-filter: blur(10px);
        }

        .feature-slide {
            --slide-a: #1d4ed8;
            --slide-b: #0b6b2b;
            position: absolute;
            inset: 0;
            opacity: 0;
            visibility: hidden;
            transform: translateX(18px) scale(.985);
            transition: opacity .55s ease, transform .55s ease, visibility .55s;
            isolation: isolate;
        }

        .feature-slide.is-active {
            opacity: 1;
            visibility: visible;
            transform: translateX(0) scale(1);
            z-index: 2;
        }

        .feature-slide::after {
            content: "";
            position: absolute;
            inset: 0;
            z-index: -1;
            background:
                linear-gradient(180deg, rgba(4, 12, 28, .05) 0%, rgba(4, 12, 28, .18) 35%, rgba(4, 12, 28, .92) 100%),
                linear-gradient(135deg, color-mix(in srgb, var(--slide-a) 42%, transparent), color-mix(in srgb, var(--slide-b) 32%, transparent));
        }

        .feature-slide img {
            position: absolute;
            inset: 0;
            z-index: -2;
            width: 100%;
            height: 100%;
            object-fit: cover;
            object-position: center;
            filter: saturate(.92) contrast(1.03);
        }

        .feature-slide img.is-missing {
            display: none;
        }

        .feature-fallback {
            position: absolute;
            inset: 0;
            z-index: -3;
            display: grid;
            place-items: center;
            overflow: hidden;
            background:
                radial-gradient(circle at 70% 20%, color-mix(in srgb, var(--slide-a) 52%, transparent), transparent 40%),
                radial-gradient(circle at 20% 82%, color-mix(in srgb, var(--slide-b) 50%, transparent), transparent 42%),
                linear-gradient(145deg, #0b1830, #10254a 52%, #07351e);
        }

        .feature-fallback::before,
        .feature-fallback::after {
            content: "";
            position: absolute;
            border: 1px solid rgba(255, 255, 255, .09);
            border-radius: 50%;
        }

        .feature-fallback::before {
            width: 330px;
            height: 330px;
            top: -120px;
            right: -120px;
        }

        .feature-fallback::after {
            width: 240px;
            height: 240px;
            bottom: -100px;
            left: -90px;
        }

        .feature-fallback i {
            font-size: clamp(70px, 7vw, 116px);
            color: rgba(255, 255, 255, .18);
            filter: drop-shadow(0 18px 30px rgba(0, 0, 0, .22));
        }

        .feature-slide-content {
            position: absolute;
            left: 0;
            right: 0;
            bottom: 0;
            padding: 28px 24px 62px;
            color: #fff;
        }

        .feature-kicker {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 10px;
            padding: 6px 9px;
            border: 1px solid rgba(255, 255, 255, .18);
            border-radius: 999px;
            background: rgba(255, 255, 255, .10);
            backdrop-filter: blur(8px);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .10em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, .86);
        }

        .feature-slide h2 {
            margin: 0;
            font-family: "Sora", "Inter", sans-serif;
            font-size: clamp(25px, 2.1vw, 36px);
            line-height: 1.02;
            letter-spacing: -.035em;
            font-weight: 800;
            text-shadow: 0 4px 18px rgba(0, 0, 0, .30);
        }

        .feature-slide p {
            margin: 9px 0 0;
            max-width: 32ch;
            font-size: 11.5px;
            line-height: 1.55;
            color: rgba(255, 255, 255, .74);
        }

        .feature-index {
            position: absolute;
            top: 18px;
            left: 18px;
            z-index: 5;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 9px;
            border-radius: 999px;
            background: rgba(4, 12, 28, .42);
            border: 1px solid rgba(255, 255, 255, .13);
            color: rgba(255, 255, 255, .86);
            font-size: 9px;
            font-weight: 800;
            letter-spacing: .08em;
            backdrop-filter: blur(8px);
        }

        .feature-controls {
            position: absolute;
            z-index: 10;
            left: 18px;
            right: 18px;
            bottom: 18px;
            display: flex;
            align-items: center;
            gap: 9px;
        }

        .feature-dots {
            display: flex;
            align-items: center;
            gap: 5px;
            flex: 1;
            min-width: 0;
        }

        .feature-dot {
            width: 6px;
            height: 6px;
            flex: 0 0 6px;
            padding: 0;
            border: 0;
            border-radius: 999px;
            background: rgba(255, 255, 255, .38);
            cursor: pointer;
            transition: width .2s ease, background .2s ease, transform .2s ease;
        }

        .feature-dot:hover {
            transform: scale(1.18);
            background: rgba(255, 255, 255, .72);
        }

        .feature-dot.is-active {
            width: 18px;
            background: #86efac;
        }

        .feature-nav {
            width: 32px;
            height: 32px;
            flex: 0 0 32px;
            display: grid;
            place-items: center;
            padding: 0;
            border: 1px solid rgba(255, 255, 255, .16);
            border-radius: 10px;
            background: rgba(4, 12, 28, .40);
            color: #fff;
            cursor: pointer;
            backdrop-filter: blur(8px);
            transition: background .18s ease, transform .18s ease;
        }

        .feature-nav:hover {
            background: rgba(255, 255, 255, .16);
            transform: translateY(-1px);
        }

        .feature-progress {
            position: absolute;
            z-index: 11;
            top: 0;
            left: 0;
            height: 3px;
            width: 0;
            background: linear-gradient(90deg, #86efac, #60a5fa);
        }

        .feature-slider.is-running .feature-progress {
            animation: featureProgress 3.8s linear forwards;
        }

        @keyframes featureProgress {
            from {
                width: 0;
            }

            to {
                width: 100%;
            }
        }

        @media (min-width: 1081px) and (max-width: 1440px) {
            .brand-main {
                grid-template-columns: minmax(0, 1.68fr) minmax(245px, .72fr);
                gap: 14px;
            }

            .feature-slider {
                min-height: 520px;
            }

            .feature-slide-content {
                padding: 22px 19px 58px;
            }

            .feature-slide h2 {
                font-size: clamp(22px, 2vw, 30px);
            }
        }

        @media (min-width: 1081px) and (max-height: 820px) {
            .brand-main {
                align-items: start;
            }

            .feature-showcase {
                position: sticky;
                top: 0;
                min-height: 0;
            }

            .feature-slider {
                min-height: 470px;
                height: min(64vh, 560px);
            }
        }

        @media (max-width: 1080px) {
            .brand-main {
                display: block;
            }

            .feature-showcase {
                display: none;
            }
        }

        /* ============================================================
           FIT-ALL-SCREENS FIX — keep landing usable on any monitor
           Desktop: lock shell to viewport height, scroll panels inside.
           Mobile/tablet: natural page scroll, single column.
           ============================================================ */
        html,
        body {
            height: 100%;
        }

        body {
            overflow-x: hidden;
        }

        img {
            max-width: 100%;
        }

        .page-shell {
            width: 100%;
            margin-inline: auto;
        }

        /* ---- Desktop / laptop monitors: fit viewport, no page scroll ---- */
        @media (min-width: 1081px) {

            html,
            body {
                height: 100%;
                overflow: hidden;
            }

            .page-shell {
                height: 100vh;
                height: 100dvh;
                min-height: 100vh;
                min-height: 100dvh;
                max-height: 100vh;
                max-height: 100dvh;
                overflow: hidden;
                grid-template-columns: minmax(0, 1fr) minmax(400px, 480px);
            }

            .brand-panel,
            .login-panel {
                height: 100vh;
                height: 100dvh;
                max-height: 100vh;
                max-height: 100dvh;
                min-height: 0;
                overflow-y: auto;
                overflow-x: hidden;
                overscroll-behavior: contain;
                scrollbar-width: thin;
                scrollbar-color: rgba(148, 163, 184, .7) transparent;
            }

            .brand-panel {
                display: flex;
                flex-direction: column;
                justify-content: flex-start;
                gap: clamp(14px, 2vh, 26px);
                padding: clamp(20px, 3vh, 34px) clamp(22px, 2.6vw, 42px);
            }

            .brand-main {
                flex: 1 1 auto;
                min-height: 0;
                align-items: start;
            }

            .feature-showcase {
                min-height: 0;
                align-items: start;
            }

            .feature-slider {
                min-height: clamp(340px, 52vh, 560px);
                height: clamp(340px, 55vh, 600px);
                max-height: 62vh;
            }

            .login-panel {
                display: flex;
                justify-content: center;
                align-items: flex-start;
                padding: clamp(16px, 2.6vh, 30px) clamp(16px, 2vw, 30px);
            }

            .login-wrap {
                margin: auto;
                width: min(100%, 468px);
                max-width: 100%;
            }
        }

        /* ---- Common small laptop: 1366x768, 1280x720, 1440x900 ---- */
        @media (min-width: 1081px) and (max-width: 1440px) {
            .page-shell {
                grid-template-columns: minmax(0, 1fr) minmax(380px, 440px);
            }

            .brand-main {
                grid-template-columns: minmax(0, 1.55fr) minmax(230px, .75fr);
                gap: 14px;
            }

            .hero h1 {
                font-size: clamp(28px, 2.9vw, 42px);
                margin: 12px 0 10px;
            }

            .hero>p {
                font-size: clamp(12.5px, 1vw, 14px);
                line-height: 1.6;
            }

            .module-grid {
                margin-top: 14px;
            }

            .module {
                min-height: 0;
            }
        }

        /* ---- Short screens: fit height by compacting ---- */
        @media (min-width: 1081px) and (max-height: 900px) {
            .brand-panel {
                gap: 14px;
                padding-top: 18px;
                padding-bottom: 16px;
            }

            .brand-logo {
                width: 46px;
                height: 46px;
                flex-basis: 46px;
            }

            .hero h1 {
                font-size: clamp(26px, 3.2vh, 38px);
                margin: 10px 0 8px;
            }

            .hero>p {
                font-size: 12.8px;
                line-height: 1.55;
            }

            .module-grid {
                margin-top: 12px;
                gap: 8px;
            }

            .module {
                min-height: 0;
                padding: 10px;
            }

            .module .m-icon {
                width: 26px;
                height: 26px;
                font-size: 13px;
                margin-bottom: 6px;
            }

            .capability-row {
                margin-top: 8px;
            }

            .workflow-strip {
                margin-top: 10px;
                padding: 10px 12px;
            }

            .feature-slider {
                min-height: 360px;
                height: 56vh;
            }

            .feature-slide-content {
                padding: 18px 16px 56px;
            }

            .login-card {
                padding: 22px 22px 18px;
            }

            .field {
                margin-bottom: 13px;
            }

            .input-shell input {
                height: 48px;
            }

            .signin-btn {
                min-height: 48px;
            }

            .divider {
                margin: 16px 0 12px;
            }

            .track-box {
                padding: 12px;
            }
        }

        @media (min-width: 1081px) and (max-height: 760px) {

            .capability-row,
            .w-note,
            .brand-contact div:last-child {
                display: none;
            }

            .hero h1 {
                font-size: clamp(24px, 4vh, 32px);
            }

            .module-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .module span {
                display: none;
            }

            .module:nth-child(n+4) {
                display: none;
            }

            .workflow-strip .w-label span:last-child {
                display: none;
            }

            .feature-slider {
                min-height: 320px;
                height: 52vh;
            }

            .after-login {
                display: none;
            }

            .security-note,
            .system-meta {
                margin-top: 8px;
            }
        }

        /* ---- Large / wide monitors: keep proportions, center ---- */
        @media (min-width: 1600px) {
            .page-shell {
                grid-template-columns: minmax(0, 1fr) minmax(460px, 540px);
            }

            .login-wrap {
                width: min(100%, 500px);
            }

            .feature-slider {
                max-height: 640px;
            }
        }

        @media (min-width: 2100px) {
            .page-shell {
                max-width: 2300px;
                margin-inline: auto;
                border-inline: 1px solid rgba(148, 163, 184, .25);
            }
        }

        /* ---- Tablet / mobile: natural scroll, full-width form ---- */
        @media (max-width: 1080px) {

            html,
            body {
                height: auto;
                overflow: auto;
            }

            .page-shell {
                display: block;
                min-height: 100vh;
                min-height: 100dvh;
                height: auto;
                max-height: none;
                overflow: visible;
            }

            .login-panel {
                display: block;
                min-height: 100vh;
                min-height: 100dvh;
                height: auto;
                max-height: none;
                overflow: visible;
                padding: 22px 16px 28px;
            }

            .login-wrap {
                margin: 0 auto;
                width: min(100%, 520px);
            }

            .login-card {
                width: 100%;
                max-width: 100%;
            }
        }

        @media (max-width: 560px) {
            .login-panel {
                padding: 14px 10px 22px;
            }

            .login-card {
                border-radius: 16px;
            }
        }

        /* ============================================================
           STATIC + NO-SCROLL (slider excluded: faded + functional)
           ============================================================ */
        .brand-orb {
            display: none !important;
        }

        .brand-top,
        .hero,
        .login-wrap {
            animation: none !important;
        }

        .brand-badge .dot {
            animation: none !important;
            box-shadow: none !important;
        }

        .module:hover,
        .signin-btn:hover:not(:disabled) {
            transform: none !important;
        }

        /* ---- Fully functional FADED slider ---- */
        .feature-showcase {
            animation: none !important;
        }

        .feature-slider {
            overflow: hidden;
        }

        .feature-slider .feature-slide {
            transform: none !important;
            opacity: 0;
            visibility: hidden;
            transition: opacity .8s ease, visibility 0s linear .8s !important;
            z-index: 1;
        }

        .feature-slider .feature-slide.is-active {
            opacity: 1 !important;
            visibility: visible !important;
            transition: opacity .8s ease, visibility 0s !important;
            z-index: 2;
        }

        .feature-slider .feature-slide-content {
            padding-bottom: 62px;
        }

        .feature-controls,
        .feature-dots,
        .feature-index {
            display: flex !important;
        }

        .feature-dots {
            display: flex !important;
        }

        .feature-index {
            display: inline-flex !important;
        }

        .feature-slider .feature-dot {
            transition: width .3s ease, background .3s ease, transform .3s ease !important;
        }

        .feature-slider .feature-nav {
            transition: background .18s ease !important;
        }

        .feature-slider .feature-progress {
            display: block !important;
        }

        .feature-slider.is-running .feature-progress {
            animation: featureProgress 5s linear forwards !important;
        }

        @media (min-width: 1081px) {

            html,
            body {
                overflow: hidden !important;
            }

            .page-shell,
            .brand-panel {
                overflow: hidden !important;
            }

            /* Login keeps page-fit but allows inner scroll so ALL text is reachable */
            .login-panel {
                overflow-y: auto !important;
                overflow-x: hidden !important;
                scrollbar-width: thin !important;
                scrollbar-color: rgba(148, 163, 184, .7) transparent !important;
            }

            .brand-panel::-webkit-scrollbar {
                display: none !important;
            }

            .login-panel::-webkit-scrollbar {
                display: block !important;
                width: 8px;
            }

            .login-panel::-webkit-scrollbar-thumb {
                background: rgba(148, 163, 184, .6);
                border-radius: 999px;
            }

            .login-panel::-webkit-scrollbar-track {
                background: transparent;
            }

            /* Tight static fit */
            .brand-panel {
                gap: 12px;
            }

            .feature-slider {
                min-height: 400px;
                height: 46vh;
                max-height: 480px;
            }

            .login-panel {
                display: flex !important;
                justify-content: center !important;
                align-items: flex-start !important;
            }

            .login-wrap {
                margin: auto !important;
                width: min(100%, 468px) !important;
                max-width: 100%;
                min-height: 0;
            }

            .login-card,
            .system-meta {
                flex-shrink: 0;
            }
        }

        @media (min-width: 1081px) and (max-height: 900px) {

            .capability-row,
            .w-note {
                display: none !important;
            }

            /* Keep login texts visible — compact instead of hiding */
            .after-login {
                display: grid !important;
                grid-template-columns: 1fr 1fr;
                gap: 6px;
                margin-top: 10px;
            }

            .after-login div {
                font-size: 10px;
                line-height: 1.45;
                padding: 8px 9px;
            }

            .hero h1 {
                font-size: clamp(24px, 3vh, 34px);
            }

            .module-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .module:nth-child(n+4) {
                display: none;
            }

            .workflow-strip {
                padding: 10px 12px;
            }

            .feature-slider {
                min-height: 320px;
                height: 44vh;
                max-height: 420px;
            }

            .login-card {
                padding: 20px 20px 16px;
            }

            .field {
                margin-bottom: 12px;
            }

            .input-shell input {
                height: 46px;
            }

            .signin-btn {
                min-height: 48px;
            }

            .security-note,
            .system-meta {
                margin-top: 8px;
                font-size: 9px;
            }
        }

        /* Keep ALL login texts reachable on very short monitors */
        @media (min-width: 1081px) and (max-height: 760px) {

            .after-login,
            .security-note,
            .system-meta,
            .track-box {
                display: grid !important;
                visibility: visible !important;
            }

            .after-login {
                grid-template-columns: 1fr 1fr;
            }

            .security-note,
            .system-meta {
                display: flex !important;
                justify-content: center;
            }

            .system-meta {
                display: block !important;
                text-align: center;
            }
        }

        /* ============================================================
           SLIDER EXACT STRETCH: eyebrow top -> workflow bottom
           Hero drives height, slider stretches to match, shrinks on short.
           ============================================================ */
        @media (min-width: 1081px) {
            .brand-main {
                align-items: stretch !important;
            }

            .brand-main>.hero {
                min-height: 0;
            }

            .feature-showcase {
                align-self: stretch !important;
                align-items: stretch !important;
                height: auto !important;
                min-height: 0 !important;
                max-height: none !important;
                display: flex !important;
            }

            .feature-slider {
                height: 100% !important;
                min-height: 0 !important;
                max-height: none !important;
                align-self: stretch !important;
                flex: 1 1 auto;
            }

            .feature-slide img {
                height: 100% !important;
                object-fit: cover;
            }
        }

        /* Login header: icon beside text, all text visible */
        .login-card .sh {
            display: flex !important;
            flex-direction: row !important;
            align-items: flex-start !important;
            flex-wrap: nowrap !important;
            gap: 12px !important;
        }

        .login-card .sh-icon {
            flex: 0 0 42px !important;
            margin-top: 2px;
        }

        .login-card .sh .min-w-0 {
            flex: 1 1 auto !important;
            min-width: 0 !important;
        }

        /* ============================================================
           MINIMAL HPU AUTH LAYOUT
           Keeps the existing PHP, Bootstrap modals and form behaviour.
           ============================================================ */
        body.minimal-auth {
            --minimal-navy: #071a38;
            --minimal-navy-2: #0c2855;
            --minimal-blue: #2157d5;
            --minimal-teal: #087b73;
            --minimal-mint: #73e6b0;
            --minimal-panel: #f2f6fb;
        }

        .minimal-auth .page-shell {
            grid-template-columns: minmax(0, 1fr) minmax(430px, 520px) !important;
        }

        .minimal-auth .brand-panel {
            padding: clamp(28px, 3.2vw, 54px) clamp(34px, 4vw, 70px) !important;
            background:
                radial-gradient(720px 540px at 94% 8%, rgba(37, 99, 235, .28), transparent 64%),
                radial-gradient(680px 520px at 88% 100%, rgba(5, 150, 105, .24), transparent 62%),
                linear-gradient(145deg, #07172f 0%, #0b2b58 58%, #07543f 100%) !important;
        }

        .minimal-auth .brand-top {
            padding-bottom: 0 !important;
            border-bottom: 0 !important;
        }

        .minimal-auth .brand-logo {
            width: 62px !important;
            height: 62px !important;
            flex-basis: 62px !important;
        }

        .minimal-auth .brand-org {
            color: rgba(255, 255, 255, .74) !important;
            font-size: 11px !important;
            letter-spacing: .15em !important;
        }

        .minimal-auth .brand-unit {
            margin-top: 3px;
            font-size: 22px !important;
            font-weight: 800 !important;
        }

        .minimal-auth .brand-badge,
        .minimal-auth .capability-row,
        .minimal-auth .workflow-strip,
        .minimal-auth .feature-showcase,
        .minimal-auth .brand-footer,
        .minimal-auth .landing-hint,
        .minimal-auth .after-login,
        .minimal-auth .system-meta,
        .minimal-auth .field-note,
        .minimal-auth .mobile-modules {
            display: none !important;
        }

        .minimal-auth .brand-main {
            display: grid !important;
            grid-template-columns: minmax(0, 1.28fr) minmax(250px, .72fr) !important;
            align-items: center !important;
            gap: clamp(28px, 4vw, 72px) !important;
            flex: 1 1 auto;
            min-height: 0;
        }

        .minimal-auth .hero {
            max-width: 760px !important;
            padding-top: 0 !important;
        }

        .minimal-auth .eyebrow {
            margin-bottom: 24px;
            padding: 7px 11px !important;
            font-size: 10px !important;
        }

        .minimal-auth .hero h1 {
            max-width: 760px !important;
            margin: 0 0 22px !important;
            font-size: clamp(42px, 4.25vw, 72px) !important;
            line-height: 1.02 !important;
            letter-spacing: -.055em !important;
        }

        .minimal-auth .hero>p {
            max-width: 670px !important;
            margin: 0 !important;
            color: rgba(255, 255, 255, .73) !important;
            font-size: clamp(14px, 1vw, 17px) !important;
            line-height: 1.72 !important;
        }

        .minimal-auth .module-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr)) !important;
            gap: 14px !important;
            margin-top: 32px !important;
        }

        .minimal-auth .module:nth-child(n+4) {
            display: none !important;
        }

        .minimal-auth .module {
            min-height: 132px !important;
            padding: 16px !important;
            border-radius: 16px !important;
            background: rgba(255, 255, 255, .065) !important;
            border-color: rgba(255, 255, 255, .13) !important;
        }

        .minimal-auth .module .m-icon {
            width: 38px !important;
            height: 38px !important;
            margin-bottom: 12px !important;
            border-radius: 10px !important;
            font-size: 17px !important;
        }

        .minimal-auth .module strong {
            font-size: 14px !important;
        }

        .minimal-auth .module span {
            margin-top: 5px;
            font-size: 11.5px !important;
            line-height: 1.45 !important;
        }

        .minimal-visual {
            position: relative;
            min-height: 490px;
            isolation: isolate;
        }

        .minimal-visual::before {
            content: "";
            position: absolute;
            inset: 6% -12% 2% 2%;
            z-index: -1;
            border-radius: 46% 54% 56% 44%;
            background: radial-gradient(circle at 38% 34%, rgba(96, 165, 250, .18), transparent 58%);
        }

        .minimal-doc {
            position: absolute;
            border: 1px solid rgba(147, 197, 253, .38);
            background: linear-gradient(155deg, rgba(37, 99, 235, .35), rgba(5, 150, 105, .16));
            box-shadow: 0 28px 70px rgba(0, 0, 0, .18), inset 0 1px 0 rgba(255, 255, 255, .14);
            backdrop-filter: blur(9px);
        }

        .minimal-doc.window {
            top: 10%;
            right: 8%;
            width: 78%;
            height: 42%;
            border-radius: 18px;
        }

        .minimal-doc.paper {
            right: 0;
            bottom: 16%;
            width: 72%;
            height: 54%;
            border-radius: 18px;
        }

        .minimal-doc .doc-dots {
            display: flex;
            gap: 7px;
            padding: 16px 17px 12px;
        }

        .minimal-doc .doc-dots i {
            width: 9px;
            height: 9px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .35);
        }

        .minimal-doc .doc-dots i:first-child {
            background: var(--minimal-mint);
        }

        .minimal-doc .doc-lines {
            display: grid;
            gap: 13px;
            padding: 16px 22px;
        }

        .minimal-doc .doc-lines i {
            width: 72%;
            height: 11px;
            border-radius: 999px;
            background: rgba(191, 219, 254, .25);
        }

        .minimal-doc .doc-lines i:nth-child(2) {
            width: 48%;
        }

        .minimal-doc .doc-lines i:nth-child(3) {
            width: 62%;
        }

        .minimal-doc.paper::after {
            content: "";
            position: absolute;
            top: -1px;
            right: -1px;
            width: 72px;
            height: 72px;
            border-left: 1px solid rgba(191, 219, 254, .32);
            border-bottom: 1px solid rgba(191, 219, 254, .32);
            border-radius: 0 17px 0 20px;
            background: linear-gradient(225deg, rgba(224, 242, 254, .42), rgba(96, 165, 250, .14));
        }

        .minimal-sync {
            position: absolute;
            left: 0;
            bottom: 11%;
            display: grid;
            place-items: center;
            width: 116px;
            height: 116px;
            border: 1px solid rgba(125, 211, 252, .55);
            border-radius: 50%;
            color: #93c5fd;
            background: linear-gradient(145deg, rgba(37, 99, 235, .36), rgba(8, 123, 115, .28));
            box-shadow: 0 22px 50px rgba(0, 0, 0, .24), inset 0 1px 0 rgba(255, 255, 255, .15);
            font-size: 50px;
            backdrop-filter: blur(8px);
        }

        .minimal-auth .login-panel {
            padding: 34px 30px !important;
            background:
                radial-gradient(circle at 78% 8%, rgba(37, 99, 235, .10), transparent 22rem),
                radial-gradient(circle at 20% 92%, rgba(8, 123, 115, .08), transparent 23rem),
                var(--minimal-panel) !important;
        }

        .minimal-auth .login-wrap {
            width: min(100%, 440px) !important;
        }

        .minimal-auth .login-card {
            width: 100%;
            padding: 38px 34px 28px !important;
            border: 1px solid rgba(37, 99, 235, .18) !important;
            border-radius: 24px !important;
            background: #fff !important;
            box-shadow: 0 24px 70px rgba(15, 35, 70, .14) !important;
        }

        .minimal-auth .login-card .sh {
            gap: 14px !important;
            margin-bottom: 30px !important;
        }

        .minimal-auth .login-card .sh-icon {
            flex-basis: 50px !important;
            width: 50px !important;
            height: 50px !important;
            border-radius: 14px !important;
            font-size: 20px !important;
        }

        .minimal-auth .sh-eyebrow {
            margin-bottom: 4px !important;
            font-size: 10px !important;
            letter-spacing: .12em !important;
        }

        .minimal-auth .sh-title {
            font-size: 1.55rem !important;
            line-height: 1.15 !important;
        }

        .minimal-auth .sh-subtitle {
            margin-top: 5px !important;
            font-size: 12.5px !important;
            line-height: 1.45 !important;
        }

        .minimal-auth .field {
            margin-bottom: 18px !important;
        }

        .minimal-auth .field-label {
            margin-bottom: 7px !important;
        }

        .minimal-auth .field-label label {
            font-size: 12px !important;
            font-weight: 750 !important;
        }

        .minimal-auth .input-shell input {
            height: 54px !important;
            border-radius: 12px !important;
            background: #fbfdff !important;
        }

        .minimal-auth .form-row {
            margin: 2px 0 18px !important;
        }

        .minimal-auth .signin-btn {
            min-height: 54px !important;
            border-radius: 12px !important;
            background: linear-gradient(100deg, var(--minimal-blue), var(--minimal-teal)) !important;
            box-shadow: 0 12px 28px rgba(33, 87, 213, .22) !important;
        }

        .minimal-auth .signup-row {
            margin-top: 18px !important;
        }

        .minimal-auth .divider {
            margin: 22px 0 16px !important;
            font-size: 10px !important;
        }

        .minimal-track-toggle {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 9px;
            width: 100%;
            min-height: 52px;
            border: 1.5px solid var(--minimal-blue);
            border-radius: 12px;
            color: var(--minimal-blue);
            background: #fff;
            font-size: 13px;
            font-weight: 800;
            transition: border-color .18s ease, color .18s ease, background .18s ease;
        }

        .minimal-track-toggle:hover,
        .minimal-track-toggle[aria-expanded="true"] {
            border-color: var(--minimal-teal);
            color: var(--minimal-teal);
            background: #f6fffc;
        }

        .minimal-auth #trackingPanel .track-box {
            margin-top: 12px;
        }

        .minimal-auth .security-note {
            margin-top: 22px !important;
        }

        @media (max-width: 1440px) and (min-width: 1081px) {
            .minimal-auth .brand-panel {
                padding: 28px 38px !important;
            }

            .minimal-auth .brand-main {
                grid-template-columns: minmax(0, 1.38fr) minmax(220px, .62fr) !important;
                gap: 26px !important;
            }

            .minimal-auth .hero h1 {
                font-size: clamp(38px, 4vw, 56px) !important;
            }

            .minimal-auth .minimal-visual {
                min-height: 390px;
            }

            .minimal-auth .module {
                min-height: 116px !important;
                padding: 13px !important;
            }
        }

        @media (min-width: 1081px) and (max-height: 760px) {
            .minimal-auth .brand-panel {
                overflow-y: auto !important;
            }

            .minimal-auth .eyebrow {
                margin-bottom: 14px;
            }

            .minimal-auth .hero h1 {
                font-size: clamp(34px, 5vh, 46px) !important;
                margin-bottom: 14px !important;
            }

            .minimal-auth .module-grid {
                margin-top: 20px !important;
            }

            .minimal-auth .module {
                min-height: 90px !important;
            }

            .minimal-auth .module span {
                display: none;
            }

            .minimal-auth .minimal-visual {
                min-height: 340px;
            }

            .minimal-auth .login-wrap {
                margin-top: 0 !important;
                margin-bottom: 0 !important;
            }

            .minimal-auth .login-card {
                padding: 25px 25px 20px !important;
            }

            .minimal-auth .login-card .sh {
                margin-bottom: 20px !important;
            }

            .minimal-auth .security-note {
                display: flex !important;
                margin-top: 16px !important;
            }
        }

        @media (max-width: 1080px) {
            .minimal-auth .page-shell {
                display: block !important;
            }

            .minimal-auth .login-panel {
                display: grid !important;
                place-items: center !important;
                padding: 24px 16px !important;
            }

            .minimal-auth .login-wrap {
                width: min(100%, 440px) !important;
                margin: 0 auto !important;
            }

            .minimal-auth .mobile-brand {
                margin-bottom: 16px;
            }
        }

        @media (max-width: 560px) {
            .minimal-auth .login-panel {
                padding: 14px 10px 22px !important;
            }

            .minimal-auth .login-card {
                padding: 26px 18px 22px !important;
                border-radius: 18px !important;
            }

            .minimal-auth .login-card .sh {
                margin-bottom: 24px !important;
            }

            .minimal-auth .sh-title {
                font-size: 1.35rem !important;
            }
        }
    </style>
</head>

<body class="minimal-auth">

    <div class="page-shell">

        <!-- ============ BRAND / MODULE PANEL ============ -->
        <aside class="brand-panel" aria-label="HPU Workbench introduction">
            <div class="brand-orb o1" aria-hidden="true"></div>
            <div class="brand-orb o2" aria-hidden="true"></div>
            <div class="brand-orb o3" aria-hidden="true"></div>

            <div class="brand-top">
                <div class="brand-logo" aria-hidden="true">
                    <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, "UTF-8") ?>" alt=""
                        onerror="this.style.display='none';this.parentElement.innerHTML='<i class=&quot;bi bi-hospital&quot; style=&quot;font-size:26px;color:#00662d&quot;></i>';">
                </div>
                <div>
                    <div class="brand-org">Adela Serra Ty Memorial Medical Center</div>
                    <div class="brand-unit">HPU Workbench</div>
                </div>
                <div class="brand-badge"><span class="dot"></span> Internal Workspace</div>
            </div>

            <div class="brand-main">
                <div class="hero">
                    <div class="eyebrow">
                        <i class="bi bi-heart-pulse-fill"></i>
                        PHU - Health Promotion Unit · Digital Operations Workspace
                    </div>

                    <h1>
                        One HPU workspace.
                        <span class="grad">Requests, activities and publications in sync.</span>
                    </h1>

                    <p>
                        A secure ASTMMC workspace to <strong>submit, review, assign, coordinate and monitor</strong>
                        Job Requests, Publication Submissions, Office Activity / Event requests,
                        IEC resources and Client Experience feedback.
                    </p>

                    <div class="module-grid" aria-label="HPU Workbench core modules">

                        <div class="module">
                            <div class="m-icon" aria-hidden="true">
                                <i class="bi bi-briefcase"></i>
                            </div>
                            <strong>Job Requests</strong>
                            <span>Graphic Design, Video, Coverage &amp; IEC service requests.</span>
                        </div>

                        <div class="module">
                            <div class="m-icon" aria-hidden="true">
                                <i class="bi bi-newspaper"></i>
                            </div>
                            <strong>Publication</strong>
                            <span>Event / Activity and Recognition / Milestone submissions.</span>
                        </div>

                        <div class="module">
                            <div class="m-icon" aria-hidden="true">
                                <i class="bi bi-calendar3-week"></i>
                            </div>
                            <strong>Activities Calendar</strong>
                            <span>Office Activity / Event schedules and HPU support requests.</span>
                        </div>

                        <div class="module">
                            <div class="m-icon" aria-hidden="true">
                                <i class="bi bi-kanban"></i>
                            </div>
                            <strong>Workflow &amp; Assignment</strong>
                            <span>Review, approval, Kanban, assignment and deadline monitoring.</span>
                        </div>

                        <div class="module">
                            <div class="m-icon" aria-hidden="true">
                                <i class="bi bi-folder2-open"></i>
                            </div>
                            <strong>IEC Materials</strong>
                            <span>Shared HPU library with review, approval and resource governance.</span>
                        </div>

                        <div class="module more">
                            <div class="m-icon" aria-hidden="true">
                                <i class="bi bi-chat-square-heart"></i>
                            </div>
                            <strong>Client Experience</strong>
                            <span>Post-service surveys, feedback monitoring and analytics.</span>
                        </div>

                    </div>

                    <div class="capability-row" aria-label="Additional Workbench capabilities">
                        <span class="capability-chip">
                            <i class="bi bi-bell"></i> Notifications
                        </span>

                        <span class="capability-chip">
                            <i class="bi bi-chat-dots"></i> Request Conversation
                        </span>

                        <span class="capability-chip">
                            <i class="bi bi-calendar-x"></i> Blocked Dates
                        </span>

                        <span class="capability-chip">
                            <i class="bi bi-person-plus"></i> Account Requests
                        </span>

                        <span class="capability-chip">
                            <i class="bi bi-bar-chart"></i> Analytics
                        </span>

                        <span class="capability-chip">
                            <i class="bi bi-archive"></i> Archive
                        </span>
                    </div>

                    <div class="workflow-strip" aria-label="Job request workflow preview">
                        <div class="w-label">
                            <span>
                                <i class="bi bi-diagram-3 me-1"></i>
                                Job Request Workflow
                            </span>
                            <span>Revision &amp; evaluation aware</span>
                        </div>

                        <div class="w-steps">

                            <div class="w-step done">
                                <div class="w-dot">
                                    <i class="bi bi-check"></i>
                                </div>
                                <span>Pending</span>
                            </div>

                            <div class="w-step done">
                                <div class="w-dot">
                                    <i class="bi bi-check"></i>
                                </div>
                                <span>Review</span>
                            </div>

                            <div class="w-step now">
                                <div class="w-dot">
                                    <i class="bi bi-record-fill"></i>
                                </div>
                                <span>Approved</span>
                            </div>

                            <div class="w-step">
                                <div class="w-dot">4</div>
                                <span>Development</span>
                            </div>

                            <div class="w-step">
                                <div class="w-dot">5</div>
                                <span>Completed</span>
                            </div>

                            <div class="w-step">
                                <div class="w-dot">6</div>
                                <span>Evaluation</span>
                            </div>

                            <div class="w-step">
                                <div class="w-dot">7</div>
                                <span>Closed</span>
                            </div>

                        </div>

                        <p class="w-note">
                            <b>For Revision</b> returns the request for correction ·
                            <b>Disapproved</b> ends processing ·
                            <b>For Evaluation</b> opens the Client Experience Survey.
                        </p>
                    </div>
                </div>

                <div class="minimal-visual" aria-hidden="true">
                    <div class="minimal-doc window">
                        <div class="doc-dots"><i></i><i></i><i></i></div>
                        <div class="doc-lines"><i></i><i></i><i></i></div>
                    </div>
                    <div class="minimal-doc paper">
                        <div class="doc-lines"><i></i><i></i><i></i></div>
                    </div>
                    <div class="minimal-sync"><i class="bi bi-arrow-repeat"></i></div>
                </div>

                <section class="feature-showcase" aria-label="HPU Workbench feature highlights">
                    <div class="feature-slider" id="featureSlider" aria-roledescription="carousel"
                        aria-label="HPU Workbench modules">
                        <div class="feature-progress" aria-hidden="true"></div>
                        <article class="feature-slide is-active" style="--slide-a:#2563eb;--slide-b:#0b6b2b;"
                            aria-hidden="false">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-palette2"></i></div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/graphic-design.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="Graphic Design" loading="eager" onerror="this.classList.add('is-missing')">
                            <span class="feature-index">01 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-palette2"></i>Creative Services</span>
                                <h2>Graphic Design</h2>
                                <p>Design requests, visual materials and branded communication outputs.</p>
                            </div>
                        </article>
                        <article class="feature-slide" style="--slide-a:#1d4ed8;--slide-b:#15803d;" aria-hidden="true">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-newspaper"></i></div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/publication.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="Publication" loading="lazy" onerror="this.classList.add('is-missing')">
                            <span class="feature-index">02 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-newspaper"></i>Content & Publishing</span>
                                <h2>Publication</h2>
                                <p>Submit, review and coordinate publication and milestone content.</p>
                            </div>
                        </article>
                        <article class="feature-slide" style="--slide-a:#0ea5e9;--slide-b:#0b6b2b;" aria-hidden="true">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-calendar3-week"></i></div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/activities-calendar.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="Activities Calendar" loading="lazy" onerror="this.classList.add('is-missing')">
                            <span class="feature-index">03 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-calendar3-week"></i>Planning &
                                    Coordination</span>
                                <h2>Activities Calendar</h2>
                                <p>See activity schedules, event requests and HPU support timelines.</p>
                            </div>
                        </article>
                        <article class="feature-slide" style="--slide-a:#2563eb;--slide-b:#0f766e;" aria-hidden="true">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-kanban"></i></div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/workflow-assignment.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="Workflow & Assignment" loading="lazy" onerror="this.classList.add('is-missing')">
                            <span class="feature-index">04 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-kanban"></i>Operations</span>
                                <h2>Workflow & Assignment</h2>
                                <p>Review, approve, assign and monitor requests through the workbench.</p>
                            </div>
                        </article>
                        <article class="feature-slide" style="--slide-a:#16a34a;--slide-b:#1d4ed8;" aria-hidden="true">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-folder2-open"></i></div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/iec-materials.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="IEC Materials" loading="lazy" onerror="this.classList.add('is-missing')">
                            <span class="feature-index">05 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-folder2-open"></i>Resource Library</span>
                                <h2>IEC Materials</h2>
                                <p>Access governed IEC resources, reviews, approvals and shared materials.</p>
                            </div>
                        </article>
                        <article class="feature-slide" style="--slide-a:#15803d;--slide-b:#2563eb;" aria-hidden="true">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-chat-square-heart"></i>
                            </div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/client-experience.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="Client Experience Survey" loading="lazy"
                                onerror="this.classList.add('is-missing')">
                            <span class="feature-index">06 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-chat-square-heart"></i>Feedback &
                                    Evaluation</span>
                                <h2>Client Experience Survey</h2>
                                <p>Collect post-service feedback and turn responses into service insights.</p>
                            </div>
                        </article>
                        <article class="feature-slide" style="--slide-a:#2563eb;--slide-b:#0891b2;" aria-hidden="true">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-bell"></i></div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/notifications.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="Notifications" loading="lazy" onerror="this.classList.add('is-missing')">
                            <span class="feature-index">07 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-bell"></i>Stay Updated</span>
                                <h2>Notifications</h2>
                                <p>Keep users informed about actions, assignments and request changes.</p>
                            </div>
                        </article>
                        <article class="feature-slide" style="--slide-a:#1d4ed8;--slide-b:#0b6b2b;" aria-hidden="true">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-chat-dots"></i></div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/request-conversation.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="Request Conversation" loading="lazy" onerror="this.classList.add('is-missing')">
                            <span class="feature-index">08 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-chat-dots"></i>Collaboration</span>
                                <h2>Request Conversation</h2>
                                <p>Keep request discussions connected to the work instead of scattered messages.</p>
                            </div>
                        </article>
                        <article class="feature-slide" style="--slide-a:#0b6b2b;--slide-b:#1d4ed8;" aria-hidden="true">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-person-plus"></i></div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/account-requests.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="Account Requests" loading="lazy" onerror="this.classList.add('is-missing')">
                            <span class="feature-index">09 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-person-plus"></i>Access Management</span>
                                <h2>Account Requests</h2>
                                <p>Route new account requests for authorized review and activation.</p>
                            </div>
                        </article>
                        <article class="feature-slide" style="--slide-a:#2563eb;--slide-b:#16a34a;" aria-hidden="true">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-bar-chart-line"></i></div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/analytics.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="Analytics" loading="lazy" onerror="this.classList.add('is-missing')">
                            <span class="feature-index">10 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-bar-chart-line"></i>Performance
                                    Insights</span>
                                <h2>Analytics</h2>
                                <p>See workload, turnaround and operational trends in one workspace.</p>
                            </div>
                        </article>
                        <article class="feature-slide" style="--slide-a:#334155;--slide-b:#0b6b2b;" aria-hidden="true">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-archive"></i></div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/archive.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="Archive" loading="lazy" onerror="this.classList.add('is-missing')">
                            <span class="feature-index">11 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-archive"></i>Records</span>
                                <h2>Archive</h2>
                                <p>Keep completed work organized, searchable and available for reference.</p>
                            </div>
                        </article>
                        <article class="feature-slide" style="--slide-a:#1d4ed8;--slide-b:#15803d;" aria-hidden="true">
                            <div class="feature-fallback" aria-hidden="true"><i class="bi bi-diagram-3"></i></div>
                            <img src="<?= htmlspecialchars($base . '/assets/images/workspace-slider/job-request-workflow.jpg', ENT_QUOTES, 'UTF-8') ?>"
                                alt="Job Request Workflow" loading="lazy" onerror="this.classList.add('is-missing')">
                            <span class="feature-index">12 / 12</span>
                            <div class="feature-slide-content">
                                <span class="feature-kicker"><i class="bi bi-diagram-3"></i>End-to-End Process</span>
                                <h2>Job Request Workflow</h2>
                                <p>Track requests from pending review through approval, completion and evaluation.</p>
                            </div>
                        </article>
                        <div class="feature-controls" aria-label="Feature slider controls">
                            <div class="feature-dots" id="featureDots" aria-label="Choose slide"></div>
                            <button class="feature-nav" type="button" id="featurePrev" aria-label="Previous feature"><i
                                    class="bi bi-chevron-left"></i></button>
                            <button class="feature-nav" type="button" id="featureNext" aria-label="Next feature"><i
                                    class="bi bi-chevron-right"></i></button>
                        </div>
                    </div>
                </section>
            </div>

            <div class="brand-footer">
                <div>
                    <div class="role-chips" aria-label="Roles supported">
                        <span class="role-chip"><i class="bi bi-person"></i>Requestor</span>
                        <span class="role-chip"><i class="bi bi-person-workspace"></i>Staff</span>
                        <span class="role-chip"><i class="bi bi-patch-check"></i>Supervisor</span>
                        <span class="role-chip"><i class="bi bi-shield-lock"></i>Admin</span>
                    </div>
                    <div style="margin-top:8px;">Authorized access only · ASTMMC Health Promotion Unit - Version 1.1
                    </div>
                </div>
                <div class="brand-contact">
                    <div><i class="bi bi-envelope-fill me-1"></i><a
                            href="mailto:astmmchealthpromounit@gmail.com">astmmchealthpromounit@gmail.com</a></div>
                    <div><i class="bi bi-telephone-fill me-1"></i>Fanvil 1051</div>
                </div>
            </div>
        </aside>

        <!-- ============ LOGIN PANEL ============ -->
        <main class="login-panel">
            <div class="login-wrap">

                <div class="mobile-brand">
                    <div class="mobile-logo" aria-hidden="true">
                        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, "UTF-8") ?>" alt=""
                            onerror="this.style.display='none';this.parentElement.innerHTML='<i class=&quot;bi bi-hospital&quot; style=&quot;font-size:24px;color:#00662d&quot;></i>';">
                    </div>
                    <div>
                        <strong>HPU Workbench</strong>
                        <span>ASTMMC · Health Promotion Unit</span>
                    </div>
                </div>
                <div class="mobile-modules" aria-label="HPU Workbench modules">
                    <span><i class="bi bi-briefcase"></i> Job Requests</span>
                    <span><i class="bi bi-newspaper"></i> Publication</span>
                    <span><i class="bi bi-calendar3-week"></i> Activities</span>
                    <span><i class="bi bi-folder2-open"></i> IEC</span>
                    <span><i class="bi bi-chat-square-heart"></i> Evaluation</span>
                </div>


                <section class="login-card" aria-labelledby="loginTitle">
                    <div class="sh">
                        <div class="sh-icon" aria-hidden="true"><i class="bi bi-shield-lock"></i></div>
                        <div class="min-w-0">
                            <p class="sh-eyebrow">Secure Sign In</p>
                            <h1 class="sh-title" id="loginTitle" style="font-size:1.35rem;">Welcome back</h1>
                            <p class="sh-subtitle">Sign in to access HPU Workbench.</p>
                        </div>
                    </div>

                    <div class="landing-hint" role="note">
                        <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
                        <span><strong>Where will I land?</strong> Requestors go to <strong>My Requests</strong> · HPU
                            Staff, Supervisors &amp; Admins go to the <strong>Workbench Dashboard</strong> (Kanban,
                            assignments, calendar, analytics).</span>
                    </div>

                    <?php if ($login_error !== ""): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($login_error, ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($account_success !== ""): ?>
                        <div class="alert alert-success" role="alert">
                            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($account_success, ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($account_error !== ""): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($account_error, ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($help_success !== ""): ?>
                        <div class="alert alert-success" role="alert">
                            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($help_success, ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($help_error !== ""): ?>
                        <div class="alert alert-danger" role="alert">
                            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($help_error, ENT_QUOTES, "UTF-8") ?></span>
                        </div>
                    <?php endif; ?>

                    <form id="loginForm" action="<?= htmlspecialchars($base . "/login.php", ENT_QUOTES, "UTF-8") ?>"
                        method="POST" autocomplete="on" novalidate>

                        <div class="field">
                            <div class="field-label">
                                <label for="email">Email address</label>
                                <span class="field-note">Registered HPU account</span>
                            </div>
                            <div class="input-shell">
                                <i class="bi bi-envelope input-icon" aria-hidden="true"></i>
                                <input type="email" id="email" name="email" placeholder="Enter your email address"
                                    autocomplete="username" inputmode="email" spellcheck="false" required autofocus
                                    value="<?= htmlspecialchars($login_email, ENT_QUOTES, "UTF-8") ?>">
                            </div>
                        </div>

                        <div class="field">
                            <div class="field-label">
                                <label for="password">Password</label>
                                <span class="field-note">Case-sensitive</span>
                            </div>
                            <div class="input-shell">
                                <i class="bi bi-lock input-icon" aria-hidden="true"></i>
                                <input type="password" id="password" name="password" placeholder="Enter your password"
                                    autocomplete="current-password" required minlength="1">
                                <button class="password-toggle" type="button" id="passwordToggle"
                                    aria-label="Show password" title="Show password" aria-pressed="false">
                                    <i class="bi bi-eye" id="eyeIcon" aria-hidden="true"></i>
                                </button>
                            </div>
                            <span class="caps-warn" id="capsWarn" role="status"><i class="bi bi-capslock-fill"
                                    aria-hidden="true"></i> Caps Lock is on</span>
                        </div>

                        <div class="form-row">
                            <label class="remember"><input type="checkbox" id="rememberEmail"> Remember email</label>
                            <button type="button" class="help-link" data-bs-toggle="modal" data-bs-target="#helpModal"
                                style="border:0;background:none;padding:0;cursor:pointer;">Forgot password?</button>
                        </div>

                        <button class="btn signin-btn" type="submit" id="signinBtn">
                            <span class="spinner" aria-hidden="true"></span>
                            <span id="signinLabel">Sign In</span>
                            <i class="bi bi-arrow-right btn-arrow" aria-hidden="true"></i>
                        </button>
                    </form>

                    <div class="signup-row">
                        <span>No account?</span>
                        <button type="button" data-bs-toggle="modal" data-bs-target="#accountModal">
                            <i class="bi bi-person-plus" aria-hidden="true"></i>Request Access
                        </button>
                    </div>

                    <div class="divider">or</div>

                    <button type="button" class="minimal-track-toggle" data-bs-toggle="collapse"
                        data-bs-target="#trackingPanel" aria-expanded="false" aria-controls="trackingPanel">
                        <i class="bi bi-file-earmark-search" aria-hidden="true"></i>
                        Track a Request
                    </button>

                    <div class="collapse" id="trackingPanel">
                        <div class="track-box" aria-label="Track a request without signing in">
                            <div class="t-head">
                                <div class="t-icon" aria-hidden="true"><i class="bi bi-upc-scan"></i></div>
                                <div>
                                    <strong>Track a Request or Publication</strong>
                                    <p class="t-sub">Paste your tracking link or 64-character token to view its
                                        current status.</p>
                                </div>
                            </div>
                            <div class="track-row">
                                <input id="trackInput" type="text" placeholder="Paste tracking link or token…"
                                    autocomplete="off" spellcheck="false" aria-label="Tracking link or token">
                                <button type="button" id="trackBtn"><i class="bi bi-search"
                                        aria-hidden="true"></i>Track</button>
                            </div>
                            <p class="track-err" id="trackErr" role="alert"><i
                                    class="bi bi-exclamation-circle me-1"></i>That doesn't look like a valid tracking
                                link or token.</p>
                        </div>
                    </div>

                    <div class="after-login" aria-label="What you can do after signing in">
                        <div><b><i class="bi bi-file-earmark-plus"></i>Requestors</b>Job Requests · Publications ·
                            Office Activities · My Requests · Surveys.</div>
                        <div><b><i class="bi bi-kanban"></i>HPU team</b>Review · Kanban · Assignments · Publication
                            Management · Calendar · IEC.</div>
                    </div>

                    <div class="security-note">
                        <i class="bi bi-shield-check" aria-hidden="true"></i>
                        Protected workspace for authorized personnel only.
                    </div>
                </section>

                <div class="system-meta">
                    Adela Serra Ty Memorial Medical Center · Health Promotion Unit<br>
                    <span>Job Requests · Publication · Activities Calendar · Kanban · IEC · Client Experience</span>
                </div>
            </div>
        </main>
    </div>

    <!-- ============ REQUEST ACCOUNT MODAL (top-level: keeps Bootstrap fixed positioning interactive) ============ -->
    <div class="modal fade acct-modal" id="accountModal" tabindex="-1" aria-labelledby="accountModalTitle"
        aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <form class="modal-content" id="accountForm"
                action="<?= htmlspecialchars($base . "/request_account.php", ENT_QUOTES, "UTF-8") ?>" method="POST"
                autocomplete="on" novalidate>
                <div class="modal-header">
                    <h2 class="modal-title" id="accountModalTitle"><i class="bi bi-person-plus me-2"
                            aria-hidden="true"></i>Request Workbench Account</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token"
                        value="<?= htmlspecialchars($account_csrf, ENT_QUOTES, "UTF-8") ?>">
                    <p class="hp-field" aria-hidden="true">
                        <label>Leave empty<input type="text" name="website" value="" tabindex="-1"
                                autocomplete="off"></label>
                    </p>
                    <p class="acct-sub">
                        Fill this in and the HPU team (<strong>admin, supervisor or HPU staff</strong>) will review it.
                        Once approved you can sign in immediately with the email and password you choose below.
                    </p>
                    <div class="acct-grid">
                        <div class="acct-field full">
                            <label for="accName">Complete name <span class="req">*</span></label>
                            <input type="text" id="accName" name="name" placeholder="e.g. Juan D. Cruz" required
                                maxlength="150" autocomplete="name" value="<?= $account_value("name") ?>">
                        </div>
                        <div class="acct-field">
                            <label for="accEmail">Email address <span class="req">*</span></label>
                            <input type="email" id="accEmail" name="email" placeholder="name@hospital.gov.ph" required
                                maxlength="150" inputmode="email" autocomplete="email"
                                value="<?= $account_value("email") ?>">
                        </div>
                        <div class="acct-field">
                            <label for="accContact">Contact number</label>
                            <input type="text" id="accContact" name="contact" placeholder="09xx xxx xxxx" maxlength="50"
                                autocomplete="tel" value="<?= $account_value("contact") ?>">
                        </div>
                        <div class="acct-field">
                            <label for="accDept">Department / Office <span class="req">*</span></label>
                            <?php if ($account_departments): ?>
                                <select id="accDept" name="department" required>
                                    <option value="">— Select office —</option>
                                    <?php foreach ($account_departments as $dept): ?>
                                        <?php $deptName = (string) ($dept["department_name"] ?? ""); ?>
                                        <option value="<?= htmlspecialchars($deptName, ENT_QUOTES, "UTF-8") ?>" <?= ((string) ($account_old["department"] ?? "") === $deptName) ? "selected" : "" ?>>
                                            <?= htmlspecialchars($deptName, ENT_QUOTES, "UTF-8") ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <input type="text" id="accDept" name="department" placeholder="Your department / office"
                                    required maxlength="150" value="<?= $account_value("department") ?>">
                            <?php endif; ?>
                        </div>
                        <div class="acct-field">
                            <label for="accPosition">Position / Designation</label>
                            <input type="text" id="accPosition" name="position" placeholder="e.g. Nurse II, Admin Aide"
                                maxlength="150" value="<?= $account_value("position") ?>">
                        </div>
                        <div class="acct-field full">
                            <label>Request access as <span class="req">*</span></label>
                            <input type="hidden" name="requested_role" value="user">
                            <div class="role-locked" aria-label="Access type: Requestor">
                                <i class="bi bi-person-check-fill" aria-hidden="true"></i>
                                <div>
                                    <strong>Requestor — file &amp; track job requests</strong>
                                    <span>All public accounts are created as Requestor. HPU Staff /
                                        Supervisor access is granted internally by an administrator.</span>
                                </div>
                                <i class="bi bi-lock-fill role-lock-icon" aria-hidden="true"></i>
                            </div>
                            <p class="acct-hint">Requestor accounts are approved fastest. Need HPU Staff or
                                Supervisor access? An administrator can upgrade your account after approval.</p>
                        </div>
                        <div class="acct-field full" id="reasonWrap">
                            <label for="accReason">Justification</label>
                            <textarea id="accReason" name="reason" maxlength="2000"
                                placeholder="Anything HPU should know about your request? (optional)"><?= $account_value("reason") ?></textarea>
                        </div>
                        <div class="acct-field">
                            <label for="accPass">Choose password <span class="req">*</span></label>
                            <input type="password" id="accPass" name="password" placeholder="Min. 8 characters" required
                                minlength="8" autocomplete="new-password">
                        </div>
                        <div class="acct-field">
                            <label for="accPass2">Confirm password <span class="req">*</span></label>
                            <input type="password" id="accPass2" name="confirm_password" placeholder="Repeat password"
                                required minlength="8" autocomplete="new-password">
                            <p class="acct-hint" id="passHint"></p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success" id="accountSubmit">
                        <i class="bi bi-send me-1" aria-hidden="true"></i>Submit Request
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ============ ACCOUNT HELP MODAL (top-level: keeps Bootstrap fixed positioning interactive) ============ -->
    <div class="modal fade acct-modal" id="helpModal" tabindex="-1" aria-labelledby="helpModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" id="helpForm"
                action="<?= htmlspecialchars($base . "/request_help.php", ENT_QUOTES, "UTF-8") ?>" method="POST"
                autocomplete="on" novalidate>
                <div class="modal-header">
                    <h2 class="modal-title" id="helpModalTitle"><i class="bi bi-question-circle me-2"
                            aria-hidden="true"></i>Need Account Help?</h2>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token"
                        value="<?= htmlspecialchars($account_csrf, ENT_QUOTES, "UTF-8") ?>">
                    <p class="hp-field" aria-hidden="true">
                        <label>Leave empty<input type="text" name="website" value="" tabindex="-1"
                                autocomplete="off"></label>
                    </p>
                    <p class="acct-sub">
                        Tell us what's wrong and HPU personnel will assist you.
                        This creates a tracked ticket — no more lost emails.
                        No account yet? Use <strong>Request Account</strong> instead.
                    </p>
                    <div class="acct-grid">
                        <div class="acct-field full">
                            <label for="helpType">What's the problem? <span class="req">*</span></label>
                            <select id="helpType" name="issue_type" required>
                                <option value="forgot_password" <?= $help_type_old === "forgot_password" ? "selected" : "" ?>>I forgot my password — reset it</option>
                                <option value="inactive" <?= $help_type_old === "inactive" ? "selected" : "" ?>>My account
                                    is inactive — reactivate it</option>
                                <option value="correction" <?= $help_type_old === "correction" ? "selected" : "" ?>>My name
                                    / department / role is wrong</option>
                                <option value="other" <?= $help_type_old === "other" ? "selected" : "" ?>>Something else
                                </option>
                            </select>
                        </div>
                        <div class="acct-field">
                            <label for="helpEmail">Account email <span class="req">*</span></label>
                            <input type="email" id="helpEmail" name="email" placeholder="Your registered email" required
                                maxlength="150" inputmode="email" autocomplete="email"
                                value="<?= $help_value("email") ?>">
                        </div>
                        <div class="acct-field">
                            <label for="helpContact">Contact number</label>
                            <input type="text" id="helpContact" name="contact" placeholder="Where HPU can reach you"
                                maxlength="50" autocomplete="tel" value="<?= $help_value("contact") ?>">
                        </div>
                        <div class="acct-field full">
                            <label for="helpDetails">Describe the problem <span class="req">*</span></label>
                            <textarea id="helpDetails" name="details" maxlength="2000" required
                                placeholder="e.g. I can't sign in since last week; my department changed from X to Y…"><?= $help_value("details") ?></textarea>
                            <p class="acct-hint">At least 10 characters. Never share your current password here.</p>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="flex-direction:column;align-items:stretch;gap:10px;">
                    <button type="submit" class="acct-submit" id="helpSubmit">
                        <i class="bi bi-send me-1" aria-hidden="true"></i>Send Help Request
                    </button>
                    <div style="text-align:center;font-size:11.5px;color:var(--faint);">
                        Prefer email? <a
                            href="mailto:astmmchealthpromounit@gmail.com?subject=HPU%20Workbench%20-%20Account%20help">astmmchealthpromounit@gmail.com</a>
                        · Fanvil 1051
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        window.__HPU_BASE_URL__ = <?= json_encode($base, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        window.__ACCOUNT_OPEN__ = <?= $account_open ? "true" : "false" ?>;
        window.__HELP_OPEN__ = <?= $help_open ? "true" : "false" ?>;
        (function () {
            var form = document.getElementById("loginForm");
            var email = document.getElementById("email");
            var password = document.getElementById("password");
            var toggle = document.getElementById("passwordToggle");
            var icon = document.getElementById("eyeIcon");
            var capsWarn = document.getElementById("capsWarn");
            var remember = document.getElementById("rememberEmail");
            var btn = document.getElementById("signinBtn");
            var label = document.getElementById("signinLabel");

            /* Prefill remembered email (only when no server-side value). */
            try {
                var saved = localStorage.getItem("hpu_login_email") || "";
                if (email && !email.value && saved) {
                    email.value = saved;
                    if (remember) remember.checked = true;
                } else if (email && email.value && remember && saved === email.value) {
                    remember.checked = true;
                }
            } catch (e) { }

            /* Show / hide password. */
            if (password && toggle && icon) {
                toggle.addEventListener("click", function () {
                    var show = password.type === "password";
                    password.type = show ? "text" : "password";
                    icon.className = show ? "bi bi-eye-slash" : "bi bi-eye";
                    toggle.setAttribute("aria-label", show ? "Hide password" : "Show password");
                    toggle.setAttribute("title", show ? "Hide password" : "Show password");
                    toggle.setAttribute("aria-pressed", show ? "true" : "false");
                    password.focus();
                });
            }

            /* Caps-lock warning. */
            function checkCaps(e) {
                if (!capsWarn || !e.getModifierState) return;
                try {
                    capsWarn.classList.toggle("show", !!e.getModifierState("CapsLock"));
                } catch (err) { }
            }
            if (password) {
                password.addEventListener("keyup", checkCaps);
                password.addEventListener("keydown", checkCaps);
                password.addEventListener("blur", function () { if (capsWarn) capsWarn.classList.remove("show"); });
            }

            /* Client-side validation + loading state (server remains authoritative). */
            if (form) {
                form.addEventListener("submit", function (ev) {
                    var ok = true;
                    [email, password].forEach(function (el) {
                        if (!el) return;
                        el.classList.remove("is-invalid");
                    });
                    if (email && !email.value.trim()) { email.classList.add("is-invalid"); ok = false; }
                    else if (email && email.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) { email.classList.add("is-invalid"); ok = false; }
                    if (password && !password.value) { password.classList.add("is-invalid"); ok = false; }
                    if (!ok) {
                        ev.preventDefault();
                        (document.querySelector(".is-invalid") || {}).focus?.();
                        return;
                    }
                    try {
                        if (remember && remember.checked && email) localStorage.setItem("hpu_login_email", email.value.trim());
                        else if (remember && !remember.checked) localStorage.removeItem("hpu_login_email");
                    } catch (e) { }
                    if (btn) {
                        btn.classList.add("loading");
                        btn.disabled = true;
                        if (label) label.textContent = "Signing in…";
                    }
                });
                ["email", "password"].forEach(function (id) {
                    var el = document.getElementById(id);
                    if (el) el.addEventListener("input", function () { el.classList.remove("is-invalid"); });
                });
            }

            /* Password toggle + caps for track? No. Track redirect: accept full URL or raw token. */
            var trackInput = document.getElementById("trackInput");
            var trackBtn = document.getElementById("trackBtn");
            var trackErr = document.getElementById("trackErr");
            function goTrack() {
                if (!trackInput) return;
                var raw = (trackInput.value || "").trim();
                var token = "";
                var m = raw.match(/token=([0-9a-fA-F]{64})/);
                if (m) token = m[1].toLowerCase();
                else if (/^[0-9a-fA-F]{64}$/.test(raw)) token = raw.toLowerCase();
                else {
                    var hex = (raw.match(/[0-9a-fA-F]{64}/) || [])[0];
                    if (hex) token = hex.toLowerCase();
                }
                if (!token) {
                    if (trackErr) trackErr.classList.add("show");
                    trackInput.focus();
                    return;
                }
                if (trackErr) trackErr.classList.remove("show");
                window.location.href = window.__HPU_BASE_URL__ + "/track.php?token=" + encodeURIComponent(token);
            }
            if (trackBtn) trackBtn.addEventListener("click", goTrack);
            if (trackInput) trackInput.addEventListener("keydown", function (e) {
                if (e.key === "Enter") { e.preventDefault(); goTrack(); }
                if (trackErr) trackErr.classList.remove("show");
            });

            /* ---- Request Account modal: auto-open, role hint, validation ---- */
            try {
                if (window.__ACCOUNT_OPEN__) {
                    var modalEl = document.getElementById("accountModal");
                    if (modalEl && window.bootstrap && window.bootstrap.Modal) {
                        window.bootstrap.Modal.getOrCreateInstance(modalEl).show();
                    }
                }
                if (window.__HELP_OPEN__) {
                    var helpEl = document.getElementById("helpModal");
                    if (helpEl && window.bootstrap && window.bootstrap.Modal) {
                        window.bootstrap.Modal.getOrCreateInstance(helpEl).show();
                    }
                }
            } catch (e) { }

            var accForm = document.getElementById("accountForm");
            var accRole = document.getElementById("accRole");
            var reasonStar = document.getElementById("reasonStar");
            var accReason = document.getElementById("accReason");
            var accPass = document.getElementById("accPass");
            var accPass2 = document.getElementById("accPass2");
            var passHint = document.getElementById("passHint");
            var accSubmit = document.getElementById("accountSubmit");

            function syncRoleHint() {
                if (!accRole) return;
                var elevated = accRole.value !== "user";
                if (reasonStar) reasonStar.style.display = elevated ? "inline" : "none";
                if (accReason) accReason.required = elevated;
            }
            if (accRole) {
                accRole.addEventListener("change", syncRoleHint);
                syncRoleHint();
            }

            function syncPassHint() {
                if (!passHint || !accPass || !accPass2) return;
                if (accPass2.value && accPass.value !== accPass2.value) {
                    passHint.textContent = "Passwords do not match yet.";
                    passHint.classList.add("strong");
                } else {
                    passHint.textContent = "";
                    passHint.classList.remove("strong");
                }
            }
            if (accPass) accPass.addEventListener("input", syncPassHint);
            if (accPass2) accPass2.addEventListener("input", syncPassHint);

            if (accForm) {
                accForm.addEventListener("submit", function (ev) {
                    var email = document.getElementById("accEmail");
                    var ok = true;
                    if (email && email.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
                        email.focus();
                        ok = false;
                    }
                    if (accPass && accPass.value.length < 8) { accPass.focus(); ok = false; }
                    else if (accPass && accPass2 && accPass.value !== accPass2.value) { accPass2.focus(); ok = false; }
                    if (!ok) { ev.preventDefault(); return; }
                    if (accSubmit) {
                        accSubmit.disabled = true;
                        accSubmit.innerHTML = "Submitting…";
                    }
                });
            }

            /* ---- Account Help form: light validation + loading state ---- */
            var helpForm = document.getElementById("helpForm");
            var helpSubmit = document.getElementById("helpSubmit");
            if (helpForm) {
                helpForm.addEventListener("submit", function (ev) {
                    var email = document.getElementById("helpEmail");
                    var details = document.getElementById("helpDetails");
                    var ok = true;
                    if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test((email.value || "").trim())) { email.focus(); ok = false; }
                    else if (details && (details.value || "").trim().length < 10) { details.focus(); ok = false; }
                    if (!ok) { ev.preventDefault(); return; }
                    if (helpSubmit) {
                        helpSubmit.disabled = true;
                        helpSubmit.innerHTML = "Sending…";
                    }
                });
            }


            /* ---- Landing feature slider: FADED auto-play ---- */
            (function initFeatureSlider() {
                var slider = document.getElementById("featureSlider");
                if (!slider) return;

                var slides = Array.prototype.slice.call(slider.querySelectorAll(".feature-slide"));
                var dotsWrap = document.getElementById("featureDots");
                var prev = document.getElementById("featurePrev");
                var next = document.getElementById("featureNext");
                var current = 0;
                var timer = null;
                var interval = 5000;

                if (!slides.length) return;
                if (dotsWrap) dotsWrap.innerHTML = "";

                function buildDots() {
                    if (!dotsWrap) return;
                    slides.forEach(function (_, index) {
                        var dot = document.createElement("button");
                        dot.type = "button";
                        dot.className = "feature-dot" + (index === current ? " is-active" : "");
                        dot.setAttribute("aria-label", "Show feature " + (index + 1));
                        dot.setAttribute("aria-current", index === current ? "true" : "false");
                        dot.addEventListener("click", function () {
                            show(index, true);
                        });
                        dotsWrap.appendChild(dot);
                    });
                }

                function restartProgress() {
                    slider.classList.remove("is-running");
                    void slider.offsetWidth;
                    if (timer) slider.classList.add("is-running");
                }

                function show(index, userInitiated) {
                    current = (index + slides.length) % slides.length;
                    slides.forEach(function (slide, i) {
                        var active = i === current;
                        slide.classList.toggle("is-active", active);
                        slide.setAttribute("aria-hidden", active ? "false" : "true");
                    });

                    if (dotsWrap) {
                        Array.prototype.forEach.call(dotsWrap.children, function (dot, i) {
                            var active = i === current;
                            dot.classList.toggle("is-active", active);
                            dot.setAttribute("aria-current", active ? "true" : "false");
                        });
                    }

                    if (userInitiated) start();
                    else restartProgress();
                }

                function stop() {
                    if (timer) window.clearInterval(timer);
                    timer = null;
                    slider.classList.remove("is-running");
                }

                function start() {
                    stop();
                    if (slides.length < 2) return;
                    timer = window.setInterval(function () { show(current + 1, false); }, interval);
                    restartProgress();
                }

                buildDots();
                if (prev) prev.addEventListener("click", function () { show(current - 1, true); });
                if (next) next.addEventListener("click", function () { show(current + 1, true); });

                slider.addEventListener("mouseenter", stop);
                slider.addEventListener("mouseleave", start);
                slider.addEventListener("focusin", stop);
                slider.addEventListener("focusout", function (e) {
                    if (!slider.contains(e.relatedTarget)) start();
                });
                slider.addEventListener("keydown", function (e) {
                    if (e.key === "ArrowLeft") { e.preventDefault(); show(current - 1, true); }
                    if (e.key === "ArrowRight") { e.preventDefault(); show(current + 1, true); }
                });

                document.addEventListener("visibilitychange", function () {
                    if (document.hidden) stop();
                    else start();
                });

                start();
            })();

        })();
    </script>

</body>

</html>

<?php
include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";

checkLogin();
requireModule($conn, "my_requests");

/*
 * Only admin, supervisor and HPU staff may freely input dates.
 * Regular requestors follow lead-time floors, working-day and
 * blocked-date rules (also enforced in save_request.php).
 * Same UX contract as new_request.php.
 */
$isDatePrivileged = in_array(
    (string)($_SESSION["role"] ?? "user"),
    ["admin", "supervisor", "staff"],
    true
);

/* =========================================================
   EDIT MODE: load owned request, enforce edit window
========================================================= */
$editId = (int)($_GET["id"] ?? 0);

if ($editId <= 0) {
    die("Invalid request ID.");
}

$userId = (int)($_SESSION["user_id"] ?? 0);

$editStmt = $conn->prepare(
    "SELECT *
      FROM job_requests
      WHERE id = ?
      LIMIT 1"
);

$editStmt->bind_param("i", $editId);
$editStmt->execute();

$editRow =
    $editStmt
        ->get_result()
        ->fetch_assoc();

$editStmt->close();

if (
    !$editRow
    ||
    (int)($editRow["requestor"] ?? 0) !== $userId
) {
    die("Request not found.");
}

if (
    !in_array(
        (string)($editRow["approval_status"] ?? ""),
        [
            "Pending Review",
            "Under Review",
            "For Revision"
        ],
        true
    )
) {
    die(
        "This request can no longer be modified (status: "
        . htmlspecialchars(
            (string)($editRow["approval_status"] ?? "unknown"),
            ENT_QUOTES,
            "UTF-8"
        )
        . ")."
    );
}

$editRequestTypes = decodeArray($editRow["request_types"] ?? null);

/*
 * HPU staff headcount applies to Photo/Video Coverage only.
 * Graphic Design uses assistance as a plain checkbox (no headcount).
 * These server-rendered flags set the correct first paint even
 * before JS runs; the scripts below keep it in sync on change.
 */
$editIsCoverage = in_array(
    "Photo/Video Coverage",
    $editRequestTypes,
    true
);

$editIsGraphic = in_array(
    "Graphic Design",
    $editRequestTypes,
    true
);

$editAssistanceOn = !empty($editRow["hpu_assistance_required"]);

$editShowAssistanceCard = $editIsCoverage || $editIsGraphic;

$editShowStaffInitial = $editAssistanceOn && $editIsCoverage;
$editGraphicTypes = decodeArray($editRow["graphic_design_types"] ?? null);
$editGraphicColors = decodeArray($editRow["graphic_colors"] ?? null);
$editPublicationMethods = decodeArray($editRow["publication_methods"] ?? null);
$editVideoTypes = decodeArray($editRow["video_types"] ?? null);
$editVideoFormats = decodeArray($editRow["video_formats"] ?? null);
$editCoverageFormats = decodeArray($editRow["coverage_formats"] ?? null);
$editAttachments = attachmentList((string)($editRow["attachment"] ?? ""));

$editIecItems = [];

$iecItemCols = "material_name, quantity";

$iecIdCheck = $conn->query(
    "SHOW COLUMNS FROM job_request_iec_items LIKE 'iec_material_id'"
);

if (
    $iecIdCheck
    &&
    $iecIdCheck->num_rows > 0
) {
    $iecItemCols .= ", iec_material_id";
}

$iecDoneCheck = $conn->query(
    "SHOW COLUMNS FROM job_request_iec_items LIKE 'fulfilled_qty'"
);

if (
    $iecDoneCheck
    &&
    $iecDoneCheck->num_rows > 0
) {
    $iecItemCols .= ", fulfilled_qty";
}

$iecStmt = $conn->prepare(
    "SELECT {$iecItemCols}
      FROM job_request_iec_items
      WHERE request_id = ?"
);

if ($iecStmt) {
    $iecStmt->bind_param("i", $editId);
    $iecStmt->execute();

    $iecResult = $iecStmt->get_result();

    if ($iecResult) {
        while ($iecRow = $iecResult->fetch_assoc()) {
            $editIecItems[] = $iecRow;
        }
    }

    $iecStmt->close();
}

$departments = $conn->query(
    "SELECT department_name
     FROM departments
     ORDER BY department_name"
);

$designTypes = [
    "Digital Ad",
    "Print Ad / Tarpaulin",
    "Brochure",
    "Poster / Flyer",
    "Invitation",
    "Forms / Cards",
    "Logo Design",
    "Others"
];

$colors = [
    "Full Color",
    "Single Color",
    "Grayscale"
];

$publicationMethods = [
    "Digital File",
    "Tarpaulin",
    "Photo Paper",
    "Others"
];

$videoTypes = [
    "Digital Video Ads",
    "Music Video",
    "Promotional",
    "Documentary",
    "Instructional",
    "Informative",
    "DOH Videos",
    "Others"
];

$videoFormats = [
    "MP4",
    "MP3 (Audio Only)",
    "Others"
];

$coverageFormats = [
    "JPEG",
    "PNG",
    "RAW",
    "MP4",
    "MKV",
    "Others"
];

$iecMaterials = [
    "5S Dengue",
    "ASTMMC Services",
    "Breastfeeding",
    "Diphtheria",
    "DOH Materials",
    "Earthquake",
    "Handwashing",
    "HIV / AIDS",
    "Leptospirosis",
    "Mental Health",
    "MPOX",
    "Patient Rights",
    "Patient and Watcher Handbook",
    "Others"
];

$editIecSelected = [];
$editIecQuantities = [];
$editIecOtherName = "";
$editIecIds = [];
$editIecCustom = [];

foreach ($editIecItems as $iecItem) {
    $storedName = (string)($iecItem["material_name"] ?? "");
    $qty = (int)($iecItem["quantity"] ?? 0);
    $storedId = isset($iecItem["iec_material_id"])
        ? (int)$iecItem["iec_material_id"]
        : 0;

    $matched = false;

    foreach ($iecMaterials as $material) {
        if ($material === "Others") {
            continue;
        }

        if ($storedName === $material) {
            $editIecSelected[] = $material;
            $editIecQuantities[$material] = $qty;

            if ($storedId > 0) {
                $editIecIds[$material] = $storedId;
            }

            $matched = true;
            break;
        }
    }

    if (!$matched && $storedName !== "") {
        $editIecCustom[] = [
            "name" => $storedName,
            "quantity" => $qty,
            "material_id" => $storedId > 0 ? $storedId : null
        ];
    }
}

/* Legacy single-slot fallback for custom rows. */
if (
    !empty($editIecCustom)
    &&
    !in_array("Others", $editIecSelected, true)
) {
    $firstCustom = $editIecCustom[0];

    if ($firstCustom["material_id"] === null) {
        $editIecSelected[] = "Others";
        $editIecQuantities["Others"] = $firstCustom["quantity"];
        $editIecOtherName = $firstCustom["name"];

        array_shift($editIecCustom);
    }
}

/* HPU library picker options (linked fulfillment + monthly totals). */
$iecLibraryOptions = [];

$iecLibCheck = $conn->query(
    "SHOW TABLES LIKE 'iec_materials'"
);

if (
    $iecLibCheck
    &&
    $iecLibCheck->num_rows > 0
) {
    $iecLibResult = $conn->query(
        "SELECT id, title
          FROM iec_materials
          WHERE active = 1
          ORDER BY title ASC
          LIMIT 200"
    );

    if ($iecLibResult) {
        $iecLibraryOptions = $iecLibResult->fetch_all(
            MYSQLI_ASSOC
        );
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Job Request <?= e($editRow["job_no"] ?? "") ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= e(BASE_URL) ?>/assets/css/page_headers.css">

    
<style>
:root{
    --ui-primary:#2563eb;
    --ui-primary-dark:#1d4ed8;
    --ui-text:#0f172a;
    --ui-muted:#64748b;
    --ui-border:#dbe3ef;
    --ui-bg:#f4f7fb;
    --ui-surface:#fff;
    --ui-soft:#f8fafc;
}
body{background:var(--ui-bg);}
.request-page{max-width:1180px;margin:0 auto;}
/* Page header styles moved to assets/css/page_headers.css (.ph) */
.request-chip{display:inline-flex;align-items:center;gap:7px;padding:8px 12px;border:1px solid #bfdbfe;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.78rem;font-weight:700;}
/* ---- Polished page header (matches Publication Submission forms) ---- */
.rm-crumb{display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin:0 0 10px 2px;color:#94a3b8;font-size:.7rem;font-weight:650;}
.rm-crumb a{display:inline-flex;align-items:center;gap:5px;color:#64748b;text-decoration:none;font-weight:750;}
.rm-crumb a:hover{color:#1d4ed8;text-decoration:underline;}
.rm-crumb-sep{color:#cbd5e1;font-weight:400;}
.rm-crumb-current{color:#0f172a;font-weight:800;}
.ph--recognition{position:relative;overflow:hidden;padding:20px 22px;border:1px solid #dbe6f7;background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%);}
.ph--recognition::before{content:"";position:absolute;inset:0 auto 0 0;width:4px;background:linear-gradient(180deg,#2563eb,#7c3aed 55%,#f59e0b);}
.ph--recognition .ph-copy{align-items:flex-start;}
.ph-icon--recognition{width:52px;height:52px;flex:0 0 52px;border:0;border-radius:14px;background:linear-gradient(135deg,#2563eb 0%,#4f46e5 55%,#7c3aed 100%);color:#fff;font-size:23px;box-shadow:0 8px 18px rgba(37,99,235,.28),inset 0 1px 0 rgba(255,255,255,.35);}
.ph--recognition .ph-eyebrow{display:flex;align-items:center;gap:7px;color:#1d4ed8;}
.ph--recognition .eyebrow-dot{width:7px;height:7px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 3px rgba(34,197,94,.18);}
.ph--recognition .eyebrow-divider{color:#b6c6e3;font-weight:400;}
.ph--recognition .ph-title{font-size:1.45rem;letter-spacing:-.025em;}
.ph--recognition .ph-subtitle{max-width:62ch;}
.rm-meta{display:flex;flex-wrap:wrap;gap:6px 14px;margin:10px 0 0;padding:0;list-style:none;color:#475569;font-size:.7rem;font-weight:650;}
.rm-meta li{display:inline-flex;align-items:center;gap:6px;}
.rm-meta i{color:#2563eb;font-size:.78rem;}
.rm-code{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border:1px dashed #93c5fd;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.7rem;font-weight:850;letter-spacing:.06em;text-transform:uppercase;white-space:nowrap;}
.ph--recognition .ph-actions{align-items:center;}
.ph--recognition .ph-actions .btn{box-shadow:0 2px 8px rgba(37,99,235,.12);}
.clean-card{overflow:hidden;margin-bottom:18px;border:1px solid var(--ui-border);border-radius:18px;background:var(--ui-surface);box-shadow:0 10px 28px rgba(15,23,42,.06);}
.hospital-header{display:grid;grid-template-columns:58px 1fr;gap:15px;align-items:center;padding:20px 22px;border-bottom:1px solid var(--ui-border);background:linear-gradient(135deg,#f8fafc,#fff);}
.hospital-icon{display:grid;width:54px;height:54px;place-items:center;border-radius:15px;border:1px solid #cfe0f8;background:#eff6ff;color:#2563eb;font-size:24px;}
.hospital-header small{display:block;margin-bottom:3px;color:var(--ui-muted);font-size:.74rem;font-weight:700;letter-spacing:.06em;text-transform:uppercase;}
.hospital-header h2{margin:0;font-size:1rem;font-weight:800;color:var(--ui-text);}
.hospital-header h3{margin:4px 0 0;font-size:.88rem;font-weight:700;color:#334155;line-height:1.35;}
.stack-section{padding:22px;}
.stack-section+.stack-section{border-top:1px solid var(--ui-border);}
/* Section heading styles moved to assets/css/page_headers.css (.sh) */
.notice{display:flex;gap:10px;margin-bottom:20px;padding:12px 14px;border:1px solid #fdba74;border-radius:12px;background:#fff7ed;color:#9a3412;font-size:.82rem;line-height:1.45;}
.request-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;}
.request-card{display:flex;align-items:center;gap:11px;min-height:74px;padding:14px 15px;border:1px solid var(--ui-border);border-radius:14px;background:#fff;cursor:pointer;transition:.18s;}
.request-card:hover{transform:translateY(-1px);border-color:#93c5fd;box-shadow:0 8px 18px rgba(37,99,235,.08);}
.request-card:has(input:checked){border-color:var(--ui-primary);background:#eff6ff;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.request-card .form-check-input{width:18px;height:18px;margin:0;}
.request-card strong{font-size:.88rem;}
.service-section{display:none;margin-top:14px;padding:20px;border:1px solid var(--ui-border);border-radius:14px;background:var(--ui-soft);}
.service-section.show{display:block;}
.option-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px 14px;}
.form-label,h6{color:#334155;font-size:.8rem;font-weight:700;}
.form-control,.form-select{min-height:44px;border-color:var(--ui-border);border-radius:10px;font-size:.88rem;}
.form-control:focus,.form-select:focus{border-color:#60a5fa;box-shadow:0 0 0 .2rem rgba(37,99,235,.1);}
.form-check{color:#475569;font-size:.84rem;}
.form-check-input:checked{background-color:var(--ui-primary);border-color:var(--ui-primary);}
.other-field{display:none}.other-field.show{display:block}
.attachment-list{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;}
.attachment-chip{display:inline-flex;align-items:center;gap:6px;max-width:100%;padding:5px 9px;border:1px solid #dbe3ef;border-radius:8px;background:#f8fafc;color:#334155;font-size:.72rem;font-weight:650;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.attachment-chip i{color:#2563eb;font-size:.78rem;}
.attachment-file-name{overflow:hidden;text-overflow:ellipsis;}
.attachment-chip small{color:#94a3b8;font-weight:600;}
label.attachment-chip{cursor:pointer;}
.attachment-chip input[type="checkbox"]{width:15px;height:15px;margin:0;flex:0 0 15px;accent-color:#2563eb;}
.attachment-chip:has(input:not(:checked)){opacity:.55;text-decoration:line-through;}
.attachment-remove{display:inline-grid;width:20px;height:20px;place-items:center;flex:0 0 20px;padding:0;border:0;border-radius:50%;background:#fee2e2;color:#b42318;font-size:.7rem;line-height:1;cursor:pointer;}
.attachment-remove:hover{background:#fecaca;color:#7f1d1d;}
.attachment-remove i{color:inherit !important;font-size:.8rem;}
.iec-row{display:grid;grid-template-columns:minmax(0,1fr) 110px;gap:14px;align-items:center;padding:11px 0;border-bottom:1px solid #e5e7eb;}
.iec-row:last-child{border-bottom:0;}
.request-actions{position:sticky;bottom:0;z-index:10;display:flex;justify-content:flex-end;gap:10px;margin-top:18px;padding:14px 0 4px;background:linear-gradient(to bottom,rgba(244,247,251,0),rgba(244,247,251,.95) 40%,#f4f7fb);}
.request-actions .btn{min-width:130px;min-height:44px;border-radius:10px;font-weight:700;}

.processing-panel{display:flex;gap:12px;margin-bottom:20px;padding:14px 15px;border:1px solid #bfdbfe;border-radius:14px;background:#f8fbff;color:#334155;}
.processing-panel-icon{display:grid;width:38px;height:38px;place-items:center;flex:0 0 38px;border-radius:10px;background:#eff6ff;color:#2563eb;font-size:1rem;}
.processing-panel-copy{min-width:0;flex:1;}
.processing-panel-title{margin-bottom:8px;color:#1e3a8a;font-size:.82rem;font-weight:850;}
.processing-rules{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;}
.processing-rule{padding:9px 10px;border:1px solid #dbeafe;border-radius:10px;background:#fff;}
.processing-rule strong{display:block;color:#1e293b;font-size:.68rem;}
.processing-rule span{display:block;margin-top:3px;color:#1d4ed8;font-size:.65rem;font-weight:800;}
.processing-rule small{display:block;margin-top:2px;color:#64748b;font-size:.6rem;}
.processing-note{margin-top:8px;color:#64748b;font-size:.62rem;line-height:1.4;}

.request-card-copy{display:flex;min-width:0;flex-direction:column;gap:2px;}
.request-card-copy small{color:#64748b;font-size:.6rem;font-weight:700;}
.request-card:has(input:checked) .request-card-copy small{color:#1d4ed8;}

.lead-time-summary{display:flex;align-items:flex-start;gap:9px;margin:-4px 0 15px;padding:10px 12px;border:1px solid #dbeafe;border-radius:11px;background:#eff6ff;color:#1e40af;font-size:.68rem;line-height:1.45;}
.lead-time-summary i{margin-top:1px;}
.lead-time-summary strong{display:block;}
.lead-time-summary span{display:block;margin-top:2px;color:#475569;font-weight:650;}

.lead-warning{display:none;margin-top:6px;color:#b42318;font-size:.72rem;font-weight:700;line-height:1.4;}
.lead-warning.show{display:block;}

.hpu-assistance-card{margin-top:20px;border:1px solid #dbe3ef;border-radius:14px;background:#fff;overflow:hidden;}
.hpu-assistance-card[hidden]{display:none;}
.hpu-assistance-head{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid #edf1f5;background:#fbfcfe;}
.hpu-assistance-icon{display:grid;width:34px;height:34px;place-items:center;flex:0 0 34px;border-radius:9px;background:#eff6ff;color:#2563eb;}
.hpu-assistance-head h5{margin:0;color:#1e293b;font-size:.8rem;font-weight:850;}
.hpu-assistance-head p{margin:2px 0 0;color:#64748b;font-size:.62rem;}
.hpu-assistance-body{padding:13px 14px;}
.assistance-toggle{display:flex;align-items:flex-start;gap:9px;margin:0;color:#334155;cursor:pointer;}
.assistance-toggle .form-check-input{width:18px;height:18px;margin-top:2px;flex:0 0 18px;}
.assistance-toggle strong{display:block;font-size:.76rem;}
.assistance-toggle small{display:block;margin-top:2px;color:#64748b;font-size:.61rem;line-height:1.4;}
.hpu-staff-needed{display:none;margin-top:12px;max-width:360px;padding-top:12px;border-top:1px dashed #dbe3ef;}
.hpu-staff-needed.show{display:block;}
.photo-suggest{display:flex;align-items:center;gap:10px;margin-top:12px;padding:10px 12px;border:1px solid #bfdbfe;border-radius:10px;background:#eff6ff;}
.photo-suggest[hidden]{display:none;}
.photo-suggest i{color:#1d4ed8;font-size:1.1rem;}
.photo-suggest-copy{flex:1;min-width:0;}
.photo-suggest-copy strong{display:block;font-size:.74rem;color:#1e3a8a;}
.photo-suggest-copy span{display:block;font-size:.66rem;color:#475569;}

.availability-warning{display:none;margin-top:6px;color:#b42318;font-size:.72rem;font-weight:700;line-height:1.4;}
.availability-warning.show{display:block;}
.form-control.availability-blocked{border-color:#f04438;background:#fff6f5;box-shadow:0 0 0 .16rem rgba(240,68,56,.08);}
.flatpickr-input[readonly]{background:#fff;cursor:pointer;}
.form-control:disabled{background-color:#e9ecef;opacity:1;cursor:not-allowed;}
.flatpickr-day.flatpickr-disabled,
.flatpickr-day.flatpickr-disabled:hover{
    color:#b42318 !important;
    background:#fff1f0 !important;
    text-decoration:line-through;
    cursor:not-allowed;
}
.flatpickr-day.selected,
.flatpickr-day.startRange,
.flatpickr-day.endRange{
    font-weight:800;
}
.date-picker-help{margin-top:5px;color:#64748b;font-size:.62rem;line-height:1.4;}

/* ================= WIZARD UX (same as new_request.php — progressive enhancement, no backend change) ================= */
.wizard-on .request-actions{display:none !important;}
.wizard-shell{margin:0 0 16px;}
.wizard-progress{height:6px;border-radius:999px;background:#e6edf7;overflow:hidden;margin-bottom:12px;box-shadow:inset 0 1px 2px rgba(15,23,42,.06);}
.wizard-progress-bar{height:100%;width:25%;border-radius:999px;background:linear-gradient(90deg,#2563eb,#7c3aed);transition:width .35s ease;}
.wizard-steps{list-style:none;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:8px;margin:0 0 14px;padding:0;}
.wizard-step{margin:0;}
.wizard-step-btn{display:flex;gap:10px;align-items:center;width:100%;padding:10px 12px;border:1px solid var(--ui-border);border-radius:12px;background:#fff;cursor:pointer;text-align:left;transition:.18s;color:var(--ui-text);}
.wizard-step-btn:hover{border-color:#93c5fd;box-shadow:0 4px 12px rgba(37,99,235,.08);}
.wizard-step-num{display:grid;width:30px;height:30px;flex:0 0 30px;place-items:center;border-radius:50%;background:#eef2f7;color:#475569;font-size:.78rem;font-weight:850;transition:.18s;}
.wizard-step-copy{min-width:0;}
.wizard-step-copy strong{display:block;font-size:.76rem;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.wizard-step-copy small{display:block;color:var(--ui-muted);font-size:.62rem;font-weight:650;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.wizard-step.is-active .wizard-step-btn{border-color:var(--ui-primary);background:#eff6ff;box-shadow:0 0 0 3px rgba(37,99,235,.12);}
.wizard-step.is-active .wizard-step-num{background:var(--ui-primary);color:#fff;}
.wizard-step.is-done .wizard-step-num{background:#16a34a;color:#fff;}
.wizard-step.is-done .wizard-step-btn{border-color:#bbf7d0;}
.wizard-step.is-invalid .wizard-step-btn{border-color:#f04438;background:#fff6f5;}
.wizard-step.is-invalid .wizard-step-num{background:#f04438;color:#fff;}
.wizard-meta{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:12px;color:var(--ui-muted);font-size:.7rem;font-weight:700;}
.wizard-meta .draft-status{display:inline-flex;align-items:center;gap:6px;}
.wizard-meta .draft-dot{width:7px;height:7px;border-radius:50%;background:#cbd5e1;}
.wizard-meta .draft-dot.saved{background:#22c55e;}
.wizard-panel{display:none;}
.wizard-panel.is-active{display:block;animation:wizardIn .28s ease both;}
@keyframes wizardIn{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:none;}}
.wizard-on .hospital-header{transition:.2s;}
.wizard-on[data-wstep="2"] .hospital-header,.wizard-on[data-wstep="3"] .hospital-header,.wizard-on[data-wstep="4"] .hospital-header{padding:12px 16px;}
.wizard-on[data-wstep="2"] .hospital-header h3,.wizard-on[data-wstep="3"] .hospital-header h3,.wizard-on[data-wstep="4"] .hospital-header h3{display:none;}
.draft-banner{display:none;gap:10px;align-items:flex-start;margin-bottom:12px;padding:11px 13px;border:1px solid #bfdbfe;border-radius:12px;background:#eff6ff;color:#1e3a8a;font-size:.78rem;line-height:1.45;}
.draft-banner.show{display:flex;}
.draft-banner .btn{flex:0 0 auto;}
.wizard-error-summary{display:none;margin-bottom:14px;padding:12px 14px;border:1px solid #fecaca;border-radius:12px;background:#fef2f2;color:#7f1d1d;font-size:.78rem;}
.wizard-error-summary.show{display:block;}
.wizard-error-summary strong{display:block;margin-bottom:6px;}
.wizard-error-summary ul{margin:0;padding-left:18px;}
.wizard-error-summary a{color:#b42318;font-weight:700;cursor:pointer;}
.field-invalid{border-color:#f04438 !important;box-shadow:0 0 0 .16rem rgba(240,68,56,.12) !important;background:#fff7f7 !important;}
.request-card-invalid{outline:2px solid #f04438;outline-offset:2px;border-radius:14px;}
.char-counter{margin-top:4px;color:#94a3b8;font-size:.62rem;font-weight:650;text-align:right;}
.char-counter.warn{color:#b45309;}
.char-counter.over{color:#b42318;font-weight:800;}
/* Service cards upgrade */
.request-card{position:relative;}
.request-card .rc-icon{display:grid;width:40px;height:40px;flex:0 0 40px;place-items:center;border-radius:11px;background:#f1f5f9;color:#334155;font-size:18px;transition:.18s;}
.request-card:has(input:checked) .rc-icon{background:var(--ui-primary);color:#fff;}
.request-card .rc-check{position:absolute;top:8px;right:8px;display:none;width:20px;height:20px;place-items:center;border-radius:50%;background:var(--ui-primary);color:#fff;font-size:.65rem;}
.request-card:has(input:checked) .rc-check{display:grid;}
.request-card .lead-pill{display:inline-block;margin-top:3px;padding:2px 8px;border-radius:999px;background:#f1f5f9;color:#475569;font-size:.58rem;font-weight:800;letter-spacing:.02em;}
.request-card:has(input:checked) .lead-pill{background:#dbeafe;color:#1d4ed8;}
.request-card .rc-desc{display:block;color:#64748b;font-size:.62rem;line-height:1.35;margin-top:2px;}
.request-card:has(input:checked) .rc-desc{color:#3b5aa0;}
/* Review */
.review-card{margin-bottom:14px;border:1px solid #dbe3ef;border-radius:14px;background:#fff;overflow:hidden;}
.review-head{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:11px 14px;border-bottom:1px solid #edf1f5;background:#fbfcfe;}
.review-head h5{margin:0;font-size:.78rem;font-weight:850;color:#1e293b;display:flex;gap:8px;align-items:center;}
.review-head h5 i{color:var(--ui-primary);}
.review-edit{border:1px solid var(--ui-border);background:#fff;border-radius:8px;font-size:.68rem;font-weight:800;padding:4px 10px;cursor:pointer;color:#334155;}
.review-edit:hover{border-color:#93c5fd;color:var(--ui-primary-dark);}
.review-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px 14px;padding:13px 14px;font-size:.76rem;}
.review-item{min-width:0;}
.review-item.full{grid-column:1/-1;}
.review-item small{display:block;color:#94a3b8;font-size:.6rem;font-weight:800;letter-spacing:.05em;text-transform:uppercase;margin-bottom:2px;}
.review-item div{color:#0f172a;font-weight:650;overflow-wrap:anywhere;}
.review-empty{color:#94a3b8 !important;font-weight:500 !important;font-style:italic;}
.review-chips{display:flex;flex-wrap:wrap;gap:5px;margin-top:4px;}
.review-chip{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.66rem;font-weight:750;}
.not-set-highlight{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:999px;background:#fff7ed;color:#c2410c;border:1px solid #fed7aa;font-size:.68rem;font-weight:800;}
/* Dropzone */
.dropzone{position:relative;border:1.5px dashed #b6c6e3;border-radius:12px;background:#fbfdff;padding:14px;transition:.18s;cursor:pointer;}
.dropzone:hover,.dropzone.dragover{border-color:var(--ui-primary);background:#eff6ff;box-shadow:0 0 0 3px rgba(37,99,235,.1);}
.dropzone input[type=file]{position:absolute;inset:0;opacity:0;cursor:pointer;}
.dropzone-hint{display:flex;gap:9px;align-items:center;color:#475569;font-size:.72rem;font-weight:650;pointer-events:none;}
.dropzone-hint i{font-size:1.2rem;color:var(--ui-primary);}
.dropzone-hint small{display:block;color:#94a3b8;font-weight:500;}
/* N/A pill */
label.form-check:has(.na-check){display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--ui-border);border-radius:999px;background:#fff;cursor:pointer;transition:.15s;margin:0;}
label.form-check:has(.na-check:hover){border-color:#93c5fd;}
label.form-check:has(.na-check:checked){border-color:var(--ui-primary);background:#eff6ff;color:#1d4ed8;font-weight:700;}
/* Wizard footer */
.wizard-footer{position:sticky;bottom:0;z-index:20;display:flex;align-items:center;gap:10px;margin-top:16px;padding:12px 14px;border:1px solid var(--ui-border);border-radius:14px;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);box-shadow:0 10px 28px rgba(15,23,42,.10);}
.wizard-footer .step-count{color:var(--ui-muted);font-size:.7rem;font-weight:800;white-space:nowrap;}
.wizard-footer .spacer{flex:1;}
.wizard-footer .btn{min-height:44px;border-radius:10px;font-weight:800;padding:8px 18px;}
.wizard-footer .btn-back{min-width:110px;}
.wizard-footer .btn-next{min-width:170px;}
.earliest-pill{display:inline-flex;align-items:center;gap:6px;margin-top:6px;padding:5px 10px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.66rem;font-weight:800;}
.earliest-pill:empty{display:none;}
.shake{animation:wshake .3s ease;}
@keyframes wshake{25%{transform:translateX(-4px);}50%{transform:translateX(4px);}75%{transform:translateX(-2px);}}
/* ---- Searchable office picker (progressive enhancement over the native select) ---- */
.dept-picker{position:relative;}
.dept-picker-select-hidden{display:none !important;}
.dept-picker-input{width:100%;padding-right:40px;cursor:text;}
.dept-picker-input.field-invalid-mirror{border-color:#f04438 !important;box-shadow:0 0 0 .16rem rgba(240,68,56,.12) !important;background:#fff7f7 !important;}
.dept-picker-toggle{position:absolute;top:50%;right:8px;transform:translateY(-50%);display:grid;width:28px;height:28px;place-items:center;border:1px solid var(--ui-border);border-radius:8px;background:#f8fafc;color:#64748b;font-size:.7rem;cursor:pointer;}
.dept-picker-toggle:hover{border-color:#93c5fd;color:#1d4ed8;}
.dept-picker-list{position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:60;display:none;max-height:248px;overflow:auto;margin:0;padding:5px;list-style:none;border:1px solid var(--ui-border);border-radius:11px;background:#fff;box-shadow:0 14px 30px rgba(15,23,42,.14);}
.dept-picker-list.show{display:block;}
.dept-picker-list li{display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:8px;color:#334155;font-size:.8rem;cursor:pointer;line-height:1.35;}
.dept-picker-list li .tick{color:#16a34a;font-size:.72rem;visibility:hidden;flex:0 0 auto;}
.dept-picker-list li.picked .tick{visibility:visible;}
.dept-picker-list li.picked{font-weight:800;color:#0f172a;}
.dept-picker-list li.highlight,.dept-picker-list li:hover{background:#eff6ff;color:#1d4ed8;}
.dept-picker-list li.no-match{color:#94a3b8;cursor:default;font-style:italic;}
.dept-picker-list li.no-match:hover{background:none;color:#94a3b8;}
.dept-picker-list .me-badge{margin-left:auto;flex:0 0 auto;font-size:.56rem;font-weight:850;letter-spacing:.05em;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:#dcfce7;color:#15803d;}
.dept-picker-hint{margin-top:5px;color:#64748b;font-size:.62rem;line-height:1.4;}
/* ---- HPU Library picker (full-width card) ---- */
.iec-library-box{margin-top:16px;padding:16px;border:1px solid #dbeafe;border-radius:13px;background:linear-gradient(180deg,#f5f9ff,#fff);}
.iec-library-head{display:flex;gap:10px;align-items:flex-start;margin-bottom:12px;}
.iec-library-icon{display:grid;width:36px;height:36px;flex:0 0 36px;place-items:center;border-radius:10px;background:#2563eb;color:#fff;font-size:16px;}
.iec-library-head h6{margin:0;color:#1e3a8a;font-size:.82rem;font-weight:850;}
.opt-badge{display:inline-block;margin-left:6px;padding:2px 8px;border-radius:999px;background:#e0eaff;color:#1d4ed8;font-size:.56rem;font-weight:850;letter-spacing:.05em;text-transform:uppercase;vertical-align:2px;}
.iec-library-head p{margin:3px 0 0;color:#64748b;font-size:.68rem;line-height:1.45;}
#iecLibraryRows .iec-row{grid-template-columns:minmax(0,1fr) 180px;}
#iecLibraryHint{color:#b45309;font-weight:650;}
/* ---- Approval -> auto target emphasis ---- */
.approval-flow{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin:10px 0 0;padding:10px 12px;border:1px dashed #93c5fd;border-radius:11px;background:#fff;}
.approval-step{display:inline-flex;align-items:center;gap:6px;color:#475569;font-size:.66rem;font-weight:800;}
.approval-step i{color:#2563eb;}
.approval-step.hot{color:#1d4ed8;}
.approval-step.hot i{color:#7c3aed;}
.approval-arrow{color:#b6c6e3;font-size:.6rem;}
.approval-target-line{display:flex;gap:10px;margin-top:10px;padding:11px 12px;border:1px solid #bbf7d0;border-radius:11px;background:#f0fdf4;color:#334155;font-size:.7rem;line-height:1.5;}
.approval-target-line>i{color:#15803d;font-size:1rem;margin-top:1px;flex:0 0 auto;}
.approval-target-line strong{display:block;color:#14532d;font-size:.72rem;}
.approval-target-line span{display:block;margin-top:2px;color:#475569;}

@media(max-width:992px){.request-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
@media(max-width:650px){
    .content{padding:12px;}
    .ph--recognition{padding:16px;}
    .ph--recognition .ph-title{font-size:1.2rem;}
    .ph-icon--recognition{width:44px;height:44px;flex-basis:44px;font-size:19px;}
    .rm-meta{gap:5px 12px;}
    .hospital-header{grid-template-columns:1fr;text-align:center;}
    .hospital-icon{margin:0 auto;}
    .stack-section{padding:16px;}
    .request-grid,.option-grid,.iec-row,.processing-rules{grid-template-columns:1fr;}
    .processing-panel{align-items:flex-start;}
    .request-actions{flex-direction:column-reverse;}
    .request-actions .btn{width:100%;}
}
</style>

</head>

<body class="app-shell">

<div class="d-flex min-vh-100">

    <?php include "../includes/sidebar.php"; ?>

    <div class="main flex-grow-1">

        <main class="content">

            <div class="container-fluid">
                <div class="request-page">
                    <nav class="rm-crumb" aria-label="Breadcrumb">
                        <a href="<?= e(BASE_URL) ?>/dashboard.php"><i class="bi bi-house-door"></i> Dashboard</a>
                        <span class="rm-crumb-sep">/</span>
                        <span>Job Requests</span>
                        <span class="rm-crumb-sep">/</span>
                        <span class="rm-crumb-current" aria-current="page">Edit Request</span>
                    </nav>

                    <div class="ph ph--recognition">
                        <div class="ph-copy">
                            <div class="ph-icon ph-icon--recognition" aria-hidden="true">
                                <i class="bi bi-file-earmark-plus-fill"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="ph-eyebrow"><span class="eyebrow-dot" aria-hidden="true"></span> HPU Workbench <span class="eyebrow-divider">•</span> Creative Services</p>
                                <h1 class="ph-title">Edit Job Request <?= e($editRow["job_no"] ?? "") ?></h1>
                                <p class="ph-subtitle">Update what you need — graphic design, video production, photo / video coverage, or IEC materials — then review and save.</p>
                                <ul class="rm-meta">
                                    <li><i class="bi bi-grid-1x2"></i> 4 service types</li>
                                    <li><i class="bi bi-clock-history"></i> 3–5 days minimum lead time</li>
                                    <li><i class="bi bi-shield-check"></i> Secure submission</li>
                                </ul>
                            </div>
                        </div>
                        <div class="ph-actions">
                            <span class="rm-code">
                                <i class="bi bi-shield-check"></i>
                                Secure Form
                            </span>
                            <a class="btn btn-sm btn-outline-primary" href="<?= e(BASE_URL) ?>/user/my_requests.php">
                                <i class="bi bi-folder2-open"></i>
                                My Requests
                            </a>
                        </div>
                    </div>

                <form
                    id="requestForm"
                    method="POST"
                    action="save_request.php"
                    enctype="multipart/form-data"
                >
                    <input
                        type="hidden"
                        name="edit_id"
                        value="<?= (int)$editId ?>"
                    >

                    <section class="clean-card">
                        <div class="hospital-header">
                            <div class="hospital-icon"><i class="bi bi-hospital"></i></div>
                            <div>
                                <small>Department of Health</small>
                                <h2>ADELA SERRA TY MEMORIAL MEDICAL CENTER</h2>
                                <h3>Graphic Design / Video Production / Photo-Video Coverage / IEC Materials Job Request Form</h3>
                            </div>
                        </div>
                        <div class="stack-section">

                            <div class="sh">
                                <div class="sh-icon"><i class="bi bi-person-vcard"></i></div>
                                <div><h4 class="sh-title">Requestor Information</h4><p class="sh-subtitle">Basic contact and department details.</p></div>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Requestor Name *</label>
                                    <input
                                        class="form-control"
                                        name="requestor_name"
                                        value="<?= e($editRow["requestor_name"] ?? "") ?>"
                                        placeholder="Enter your full name"
                                        required
                                    >
                                </div>

                                <div class="col-md-6">
                                    <label class="form-label">Department *</label>
                                    <select class="form-select" name="department" required>
                                        <option value="">Select Department</option>

                                        <?php
                                        $userDept = (string)($editRow["department"] ?? "");
                                        while ($department = $departments->fetch_assoc()):
                                            $deptName = $department["department_name"];
                                            $selected = ($userDept !== "" && $deptName === $userDept) ? "selected" : "";
                                        ?>
                                            <option value="<?= e($deptName) ?>" <?= $selected ?>>
                                                <?= e($deptName) ?>
                                            </option>
                                        <?php endwhile; ?>
                                    </select>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Contact Number / Email Address *</label>
                                    <input class="form-control" name="contact" value="<?= e($editRow["contact"] ?? "") ?>" placeholder="e.g. 0912-345-6789 / email@example.com" required>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Date Requested *</label>
                                    <input
                                        id="date_request"
                                        type="date"
                                        class="form-control"
                                        name="date_request"
                                        value="<?= e(substr((string)($editRow["date_request"] ?? ""), 0, 10)) ?>"
                                        <?= $isDatePrivileged ? "" : "readonly" ?>
                                        required
                                    >
                                    <?php if ($isDatePrivileged): ?>
                                    <div class="date-picker-help">
                                        Privileged scheduling: you may adjust this date.
                                    </div>
                                    <?php endif; ?>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Preferred Completion</label>
                                    <input
                                        id="date_completion"
                                        type="text"
                                        inputmode="none"
                                        autocomplete="off"
                                        class="form-control"
                                        name="date_completion"
                                        value="<?= e(substr((string)($editRow["date_completion"] ?? ""), 0, 10)) ?>"
                                        placeholder="Select preferred completion date"
                                    >
                                    <div class="date-picker-help">
                                        Blocked HPU dates cannot be selected. Your preferred date is a
                                        request — the official target auto-sets on supervisor approval
                                        (approval date + minimum lead time, or your date if later).
                                    </div>
                                    <div
                                        id="date_completion_lead_warning"
                                        class="lead-warning"
                                        role="alert"
                                    ></div>
                                    <div
                                        id="date_completion_warning"
                                        class="availability-warning"
                                        role="alert"
                                    ></div>
                                </div>

                            </div></div>
                        <div class="stack-section">
                            <div class="processing-panel">
                                <div class="processing-panel-icon">
                                    <i class="bi bi-clock-history"></i>
                                </div>

                                <div class="processing-panel-copy">
                                    <div class="processing-panel-title">Processing &amp; Lead Time</div>

                                    <div class="processing-rules">
                                        <div class="processing-rule">
                                            <strong>Graphic Design / Video Production</strong>
                                            <span>Minimum lead time: 5 days</span>
                                            <small>Typical processing: 5–15 days</small>
                                        </div>

                                        <div class="processing-rule">
                                            <strong>Photo/Video Coverage / IEC Materials</strong>
                                            <span>Minimum lead time: 3 days</span>
                                            <small>Typical processing: 3–5 days</small>
                                        </div>
                                    </div>

                                    <div class="approval-flow" aria-label="How your target date is set">
                                        <div class="approval-step"><i class="bi bi-send"></i><span>You submit</span></div>
                                        <div class="approval-arrow" aria-hidden="true"><i class="bi bi-chevron-right"></i></div>
                                        <div class="approval-step"><i class="bi bi-patch-check"></i><span>Supervisor approves</span></div>
                                        <div class="approval-arrow" aria-hidden="true"><i class="bi bi-chevron-right"></i></div>
                                        <div class="approval-step hot"><i class="bi bi-magic"></i><span>Target auto-sets</span></div>
                                    </div>

                                    <div class="approval-target-line">
                                        <i class="bi bi-stars" aria-hidden="true"></i>
                                        <div>
                                            <strong>Your target date is set automatically when the supervisor approves</strong>
                                            <span id="approvalProjection" aria-live="polite">Select a request type to preview it.</span>
                                        </div>
                                    </div>

                                    <div class="processing-note">
                                        Processing begins <strong>only after supervisor approval</strong> and remains subject to HPU office availability — working days only, no weekends, no blocked HPU dates.
                                    </div>
                                </div>
                            </div>
                            
                            <div class="sh">
                                <div class="sh-icon"><i class="bi bi-ui-checks-grid"></i></div>
                                <div><h4 class="sh-title">I. Job Details</h4><p class="sh-subtitle">Select services and provide project details.</p></div>
                            </div>
                            <label class="form-label mb-2">Type of Request <span class="text-muted">(select only one)</span></label>

                            <div class="row g-3">

                                <?php
                                $requestTypes = [
                                    "Graphic Design" => "graphicSection",
                                    "Video Production" => "videoSection",
                                    "Photo/Video Coverage" => "coverageSection",
                                    "IEC Materials" => "iecSection"
                                ];
                                ?>

                                <?php foreach ($requestTypes as $label => $section): ?>
                                    <div class="col-12 col-sm-6 col-md-3">
                                        <label class="request-card">
                                            <input
                                                class="form-check-input request-type"
                                                type="radio"
                                                name="request_types[]"
                                                value="<?= e($label) ?>"
                                                data-section="<?= e($section) ?>"
                                                <?= in_array($label, $editRequestTypes, true) ? "checked" : "" ?>
                                                required
                                            >
                                            <div class="request-card-copy">
                                                <strong><?= e($label) ?></strong>
                                                <small>
                                                    Minimum <?= in_array($label, ["Graphic Design", "Video Production"], true) ? "5" : "3" ?> days
                                                </small>
                                            </div>
                                        </label>
                                    </div>
                                <?php endforeach; ?>

                            </div>

                            <div
                                id="leadTimeSummary"
                                class="lead-time-summary"
                                aria-live="polite"
                            >
                                <i class="bi bi-calendar-check"></i>
                                <div>
                                    <strong>Select a request type to calculate the earliest allowable date.</strong>
                                    <span id="leadTimeSummaryText"></span>
                                </div>
                            </div>

                            <div class="row g-3 mt-1">

                                <div class="col-12">
                                    <label class="form-label">Title / Project Name *</label>
                                    <input class="form-control" name="project_title" value="<?= e($editRow["project_title"] ?? "") ?>" placeholder="e.g. World Health Day Poster" required>
                                </div>

                                <div class="col-12">
                                    <label class="form-label">Purpose / Objective *</label>
                                    <textarea class="form-control" name="purpose_objective" rows="3" placeholder="State the purpose or objective of this request" required><?= e($editRow["purpose_objective"] ?? "") ?></textarea>
                                </div>

                                <div class="col-md-8">
                                    <label class="form-label">Event Date & Time</label>
                                    <?php
                                    $editEventValue = str_replace(" ", "T", substr((string)($editRow["event_datetime"] ?? ""), 0, 16));
                                    $editEventEnd = str_replace(" ", "T", substr((string)($editRow["event_datetime_end"] ?? ""), 0, 16));
                                    $editEventCombined = $editEventValue;
                                    if ($editEventEnd !== "" && $editEventEnd !== $editEventValue) {
                                        $editEventCombined .= " to " . $editEventEnd;
                                    }
                                    ?>
                                    <input
                                        id="event_datetime"
                                        type="hidden"
                                        name="event_datetime"
                                        value="<?= e($editEventCombined) ?>"
                                    >
                                    <div class="row g-2">
                                        <div class="col-md-6">
                                            <label class="form-label fw-normal mb-1" for="event_start">Start <span class="text-muted">(date + time)</span></label>
                                            <input
                                                id="event_start"
                                                type="text"
                                                autocomplete="off"
                                                class="form-control"
                                                value="<?= e($editEventValue) ?>"
                                                placeholder="e.g. Sept 7, 8:00 AM"
                                            >
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-normal mb-1" for="event_end">End <span class="text-muted">(optional = time range)</span></label>
                                            <input
                                                id="event_end"
                                                type="text"
                                                autocomplete="off"
                                                class="form-control"
                                                value="<?= e($editEventEnd !== $editEventValue ? $editEventEnd : "") ?>"
                                                placeholder="e.g. Sept 7, 5:00 PM"
                                            >
                                        </div>
                                    </div>
                                    <div class="d-flex flex-wrap gap-2 mt-2">
                                        <button type="button" id="eventSameDay" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-clock me-1"></i>Same-day 8AM–5PM
                                        </button>
                                        <button type="button" id="eventClearEnd" class="btn btn-sm btn-outline-secondary">
                                            <i class="bi bi-x-circle me-1"></i>Single date only
                                        </button>
                                    </div>
                                    <div class="date-picker-help">
                                        Pick a start date & time. Add an end date & time for a multi-day or time range (e.g. Sept 7, 8:00 AM → Sept 7, 5:00 PM). You can also type directly.
                                    </div>
                                    <div
                                        id="event_range_preview"
                                        class="earliest-pill"
                                        aria-live="polite"
                                    ></div>
                                    <div
                                        id="event_datetime_warning"
                                        class="availability-warning"
                                        role="alert"
                                    ></div>
                                </div>

                                <div class="col-md-4 d-flex align-items-end">
                                    <label class="form-check mb-2">
                                        <input
                                            class="form-check-input na-check"
                                            type="checkbox"
                                            name="event_not_applicable"
                                            value="1"
                                            data-target="event_datetime"
                                            <?= !empty($editRow["event_not_applicable"]) ? "checked" : "" ?>
                                        >
                                        <span class="form-check-label">Check if non-applicable</span>
                                    </label>
                                </div>

                                <div class="col-md-8">
                                    <label class="form-label">Venue / Location</label>
                                    <input
                                        id="venue_location"
                                        class="form-control"
                                        name="venue_location"
                                        value="<?= e($editRow["venue_location"] ?? "") ?>"
                                        placeholder="e.g. OPD Lobby, 2nd Floor"
                                    >
                                </div>

                                <div class="col-md-4 d-flex align-items-end">
                                    <label class="form-check mb-2">
                                        <input
                                            class="form-check-input na-check"
                                            type="checkbox"
                                            name="venue_not_applicable"
                                            value="1"
                                            data-target="venue_location"
                                            <?= !empty($editRow["venue_not_applicable"]) ? "checked" : "" ?>
                                        >
                                        <span class="form-check-label">Check if non-applicable</span>
                                    </label>
                                </div>

                                <div class="col-md-8">
                                    <label class="form-label">Send Them To</label>
                                    <input
                                        id="send_to"
                                        class="form-control"
                                        name="send_to"
                                        value="<?= e($editRow["send_to"] ?? "") ?>"
                                        placeholder="e.g. name@email.com / Office / Group"
                                    >
                                </div>

                                <div class="col-md-4 d-flex align-items-end">
                                    <label class="form-check mb-2">
                                        <input
                                            class="form-check-input na-check"
                                            type="checkbox"
                                            name="send_to_not_applicable"
                                            value="1"
                                            data-target="send_to"
                                            <?= !empty($editRow["send_to_not_applicable"]) ? "checked" : "" ?>
                                        >
                                        <span class="form-check-label">Check if non-applicable</span>
                                    </label>
                                </div>

                            </div>

                            <div class="hpu-assistance-card" id="hpuAssistanceCard"<?= $editShowAssistanceCard ? "" : " hidden" ?>>
                                <div class="hpu-assistance-head">
                                    <div class="hpu-assistance-icon">
                                        <i class="bi bi-people"></i>
                                    </div>

                                    <div>
                                        <h5>HPU Assistance <span class="text-muted fw-normal">• Graphic Design &amp; Photo / Video Coverage</span></h5>
                                        <p>Indicate whether HPU personnel are needed to support the activity.</p>
                                    </div>
                                </div>

                                <div class="hpu-assistance-body">
                                    <label class="assistance-toggle">
                                        <input
                                            id="hpu_assistance_required"
                                            class="form-check-input"
                                            type="checkbox"
                                            name="hpu_assistance_required"
                                            value="1"
                                            <?= !empty($editRow["hpu_assistance_required"]) ? "checked" : "" ?>
                                        >
                                        <span>
                                            <strong>HPU Assistance Required</strong>
                                            <small>Check this if staff need to be on-site (e.g. photography coverage for your event).</small>
                                        </span>
                                    </label>

                                    <div id="photoSuggest" class="photo-suggest" hidden>
                                        <i class="bi bi-camera" aria-hidden="true"></i>
                                        <div class="photo-suggest-copy">
                                            <strong>Event detected — need photography?</strong>
                                            <span>Your Graphic Design request has an event date. Consider HPU photography coverage.</span>
                                        </div>
                                        <button type="button" id="photoSuggestBtn" class="btn btn-sm btn-primary">Check assistance</button>
                                    </div>

                                    <div
                                        id="hpuStaffNeededWrap"
                                        class="hpu-staff-needed<?= $editShowStaffInitial ? " show" : "" ?>"
                                    >
                                        <label class="form-label" for="hpu_staff_needed">
                                            Number of HPU Staff Needed *
                                        </label>

                                        <input
                                            id="hpu_staff_needed"
                                            type="number"
                                            min="1"
                                            max="50"
                                            class="form-control"
                                            name="hpu_staff_needed"
                                            value="<?= $editShowStaffInitial ? e((string)($editRow["hpu_staff_needed"] ?? "")) : "" ?>"
                                            placeholder="Enter number of personnel"
                                            <?= $editShowStaffInitial ? "required" : "disabled" ?>
                                        >

                                        <div class="form-text">
                                            Specify how many HPU personnel are needed for the activity.
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </section>

                    <section class="clean-card">
                        <div class="stack-section">

                            <div class="sh">
                                <div class="sh-icon"><i class="bi bi-card-checklist"></i></div>
                                <div><h4 class="sh-title">II. Job Description & Specifications</h4><p class="sh-subtitle">Select the required services and provide the project details.</p></div>
                            </div>
                            <!-- GRAPHIC DESIGN -->
                            <div id="graphicSection" class="service-section clean-card">

                                <div class="sh">
                                    <div class="sh-icon"><i class="bi bi-palette"></i></div>
                                    <div><h4 class="sh-title">Graphic Design</h4></div>
                                </div>

                                <div class="row g-4">

                                    <div class="col-md-4">

                                        <h6><strong>Type of Design</strong></h6>

                                        <div class="option-grid">

                                            <?php foreach ($designTypes as $item): ?>
                                                <label class="form-check">
                                                    <input
                                                        class="form-check-input other-toggle"
                                                        type="checkbox"
                                                        name="graphic_design_types[]"
                                                        value="<?= e($item) ?>"
                                                        data-other-target="<?= $item === "Others" ? "graphicOther" : "" ?>"
                                                        <?= in_array($item, $editGraphicTypes, true) ? "checked" : "" ?>
                                                    >
                                                    <span class="form-check-label"><?= e($item) ?></span>
                                                </label>
                                            <?php endforeach; ?>

                                        </div>

                                        <input
                                            id="graphicOther"
                                            class="form-control other-field mt-2"
                                            name="graphic_design_other"
                                            value="<?= e($editRow["graphic_design_other"] ?? "") ?>"
                                            placeholder="Specify other design type"
                                            disabled
                                        >

                                    </div>

                                    <div class="col-md-4">

                                        <h6><strong>Color</strong></h6>

                                        <?php foreach ($colors as $item): ?>
                                            <label class="form-check mb-2">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    name="graphic_colors[]"
                                                    value="<?= e($item) ?>"
                                                    <?= in_array($item, $editGraphicColors, true) ? "checked" : "" ?>
                                                >
                                                <span class="form-check-label"><?= e($item) ?></span>
                                            </label>
                                        <?php endforeach; ?>

                                    </div>

                                    <div class="col-md-4">

                                        <h6><strong>Publication Method</strong></h6>

                                        <?php foreach ($publicationMethods as $item): ?>
                                            <label class="form-check mb-2">
                                                <input
                                                    class="form-check-input other-toggle"
                                                    type="checkbox"
                                                    name="publication_methods[]"
                                                    value="<?= e($item) ?>"
                                                    data-other-target="<?= $item === "Others" ? "publicationOther" : "" ?>"
                                                    <?= in_array($item, $editPublicationMethods, true) ? "checked" : "" ?>
                                                >
                                                <span class="form-check-label"><?= e($item) ?></span>
                                            </label>
                                        <?php endforeach; ?>

                                        <input
                                            id="publicationOther"
                                            class="form-control other-field mt-2"
                                            name="publication_other"
                                            value="<?= e($editRow["publication_other"] ?? "") ?>"
                                            placeholder="Specify other publication method"
                                            disabled
                                        >

                                        <label class="form-label mt-3"><strong>Quantity</strong></label>
                                        <input
                                            type="number"
                                            min="1"
                                            class="form-control"
                                            name="graphic_quantity"
                                            value="<?= e((string)($editRow["graphic_quantity"] ?? 1)) ?>"
                                        >

                                    </div>

                                </div>
                            </div>

                            <!-- VIDEO PRODUCTION -->
                            <div id="videoSection" class="service-section clean-card">

                                <div class="sh">
                                    <div class="sh-icon"><i class="bi bi-camera-video"></i></div>
                                    <div><h4 class="sh-title">Video Production</h4></div>
                                </div>

                                <div class="row g-4">

                                    <div class="col-md-5">

                                        <h6><strong>Type of Video</strong></h6>

                                        <div class="option-grid">
                                            <?php foreach ($videoTypes as $item): ?>
                                                <label class="form-check">
                                                    <input
                                                        class="form-check-input other-toggle"
                                                        type="checkbox"
                                                        name="video_types[]"
                                                        value="<?= e($item) ?>"
                                                        data-other-target="<?= $item === "Others" ? "videoTypeOther" : "" ?>"
                                                        <?= in_array($item, $editVideoTypes, true) ? "checked" : "" ?>
                                                    >
                                                    <span class="form-check-label"><?= e($item) ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>

                                        <input
                                            id="videoTypeOther"
                                            class="form-control other-field mt-2"
                                            name="video_type_other"
                                            value="<?= e($editRow["video_type_other"] ?? "") ?>"
                                            placeholder="Specify other video type"
                                            disabled
                                        >
                                    </div>

                                    <div class="col-md-4">

                                        <h6><strong>Format</strong></h6>

                                        <?php foreach ($videoFormats as $item): ?>
                                            <label class="form-check mb-2">
                                                <input
                                                    class="form-check-input other-toggle"
                                                    type="checkbox"
                                                    name="video_formats[]"
                                                    value="<?= e($item) ?>"
                                                    data-other-target="<?= $item === "Others" ? "videoFormatOther" : "" ?>"
                                                    <?= in_array($item, $editVideoFormats, true) ? "checked" : "" ?>
                                                >
                                                <span class="form-check-label"><?= e($item) ?></span>
                                            </label>
                                        <?php endforeach; ?>

                                        <input
                                            id="videoFormatOther"
                                            class="form-control other-field mt-2"
                                            name="video_format_other"
                                            value="<?= e($editRow["video_format_other"] ?? "") ?>"
                                            placeholder="Specify other video format"
                                            disabled
                                        >
                                    </div>

                                    <div class="col-md-3">
                                        <label class="form-label"><strong>Quantity </strong>(if applicable)</label>
                                        <input
                                            type="number"
                                            min="0"
                                            class="form-control"
                                            name="video_quantity"
                                            value="<?= e((string)($editRow["video_quantity"] ?? 0)) ?>"
                                        >
                                    </div>

                                </div>
                            </div>

                            <!-- PHOTO / VIDEO COVERAGE -->
                            <div id="coverageSection" class="service-section clean-card">

                                <div class="sh">
                                    <div class="sh-icon"><i class="bi bi-camera"></i></div>
                                    <div><h4 class="sh-title">Photo / Video Coverage</h4></div>
                                </div>

                                <div class="row g-4">

                                    <div class="col-md-4">
                                        <label class="form-label"><strong>Number of Photographers Needed</strong></label>
                                        <input
                                            type="number"
                                            min="1"
                                            class="form-control"
                                            name="photographers_needed"
                                            value="<?= e((string)($editRow["photographers_needed"] ?? "")) ?>"
                                            placeholder="e.g. 2"
                                        >
                                    </div>

                                    <div class="col-md-8">
                                        <label class="form-label"><strong>Specific Shots Required</strong></label>
                                        <textarea
                                            class="form-control"
                                            name="specific_shots"
                                            rows="7"
                                            maxlength="7000"
                                            placeholder="Describe the required shots"
                                        ><?= e($editRow["specific_shots"] ?? "") ?></textarea>
                                    </div>

                                    <div class="col-md-6">

                                        <h6><strong>Image / Video Format</strong></h6>

                                        <?php foreach ($coverageFormats as $item): ?>
                                            <label class="form-check mb-2">
                                                <input
                                                    class="form-check-input other-toggle"
                                                    type="checkbox"
                                                    name="coverage_formats[]"
                                                    value="<?= e($item) ?>"
                                                    data-other-target="<?= $item === "Others" ? "coverageOther" : "" ?>"
                                                    <?= in_array($item, $editCoverageFormats, true) ? "checked" : "" ?>
                                                >
                                                <span class="form-check-label"><?= e($item) ?></span>
                                            </label>
                                        <?php endforeach; ?>

                                        <input
                                            id="coverageOther"
                                            class="form-control other-field mt-2"
                                            name="coverage_format_other"
                                            value="<?= e($editRow["coverage_format_other"] ?? "") ?>"
                                            placeholder="Specify other image/video format"
                                            disabled
                                        >
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label"><strong>Quantity</strong> (if applicable)</label>
                                        <input
                                            type="number"
                                            min="0"
                                            class="form-control"
                                            name="coverage_quantity"
                                            value="<?= e((string)($editRow["coverage_quantity"] ?? 0)) ?>"
                                        >
                                    </div>

                                </div>
                            </div>

                            <!-- IEC MATERIALS -->
                            <div id="iecSection" class="service-section clean-card">

                                <div class="sh">
                                    <div class="sh-icon"><i class="bi bi-folder2-open"></i></div>
                                    <div><h4 class="sh-title">IEC Materials</h4></div>
                                </div>

                                <?php foreach ($iecMaterials as $material): ?>

                                    <?php
                                    $key = preg_replace(
                                        "/[^A-Za-z0-9]/",
                                        "_",
                                        strtolower($material)
                                    );
                                    ?>

                                    <div class="iec-row">

                                        <div>
                                            <label class="form-check">
                                                <input
                                                    class="form-check-input iec-check"
                                                    type="checkbox"
                                                    name="iec_selected[]"
                                                    value="<?= e($material) ?>"
                                                    data-qty-target="qty_<?= e($key) ?>"
                                                    data-other-target="<?= $material === "Others" ? "iecOther" : "" ?>"
                                                    <?= in_array($material, $editIecSelected, true) ? "checked" : "" ?>
                                                >
                                                <span class="form-check-label"><?= e($material) ?></span>
                                            </label>

                                            <?php if (isset($editIecIds[$material])): ?>

                                            <input
                                                type="hidden"
                                                name="iec_material_id[<?= e($material) ?>]"
                                                value="<?= (int)$editIecIds[$material] ?>"
                                            >

                                            <?php endif; ?>

                                            <?php if ($material === "Others"): ?>
                                                <input
                                                    id="iecOther"
                                                    class="form-control other-field mt-2"
                                                    name="iec_other_name"
                                                    value="<?= e($editIecOtherName) ?>"
                                                    placeholder="Specify other IEC material"
                                                    disabled
                                                >
                                            <?php endif; ?>
                                        </div>

                                        <input
                                            id="qty_<?= e($key) ?>"
                                            type="number"
                                            min="1"
                                            class="form-control"
                                            name="iec_quantity[<?= e($material) ?>]"
                                            value="<?= e((string)($editIecQuantities[$material] ?? "")) ?>"
                                            placeholder="Qty."
                                            disabled
                                        >
                                    </div>

                                <?php endforeach; ?>

                                <?php foreach ($editIecCustom as $customIndex => $customRow): ?>

                                    <?php
                                    $customKey = "custom_" . $customIndex;
                                    ?>

                                    <div class="iec-row">

                                        <div>
                                            <label class="form-check">
                                                <input
                                                    class="form-check-input iec-check"
                                                    type="checkbox"
                                                    name="iec_selected[]"
                                                    value="<?= e($customRow["name"]) ?>"
                                                    data-qty-target="qty_<?= e($customKey) ?>"
                                                    data-other-target=""
                                                    checked
                                                >
                                                <span class="form-check-label">
                                                    <?= e($customRow["name"]) ?>
                                                    <?php if (!empty($customRow["material_id"])): ?>
                                                        <span class="badge bg-success-subtle text-success border">Library</span>
                                                    <?php endif; ?>
                                                </span>
                                            </label>

                                            <?php if (!empty($customRow["material_id"])): ?>

                                            <input
                                                type="hidden"
                                                name="iec_material_id[<?= e($customRow["name"]) ?>]"
                                                value="<?= (int)$customRow["material_id"] ?>"
                                            >

                                            <?php endif; ?>
                                        </div>

                                        <input
                                            id="qty_<?= e($customKey) ?>"
                                            type="number"
                                            min="1"
                                            class="form-control"
                                            name="iec_quantity[<?= e($customRow["name"]) ?>]"
                                            value="<?= (int)$customRow["quantity"] ?>"
                                            placeholder="Qty."
                                        >
                                    </div>

                                <?php endforeach; ?>

                                <?php if ($iecLibraryOptions): ?>

                                <div class="iec-library-box">

                                    <div class="iec-library-head">
                                        <div class="iec-library-icon" aria-hidden="true">
                                            <i class="bi bi-collection"></i>
                                        </div>
                                        <div>
                                            <h6>From HPU Library <span class="opt-badge">Optional</span></h6>
                                            <p>Pull ready-made items into this request. Each linked line is tracked for fulfillment.</p>
                                        </div>
                                    </div>

                                    <div class="row g-2 align-items-end">

                                        <div class="col-md-7">
                                            <select
                                                id="iecLibrarySelect"
                                                class="form-select"
                                                aria-label="Choose a library item"
                                            >
                                                <option value="">
                                                    — Choose a library item —
                                                </option>

                                                <?php foreach ($iecLibraryOptions as $libOption): ?>

                                                <option
                                                    value="<?= (int)$libOption["id"] ?>"
                                                    data-name="<?= e($libOption["title"]) ?>"
                                                >
                                                    <?= e($libOption["title"]) ?>
                                                </option>

                                                <?php endforeach; ?>
                                            </select>
                                        </div>

                                        <div class="col-md-3">
                                            <input
                                                id="iecLibraryQty"
                                                type="number"
                                                min="1"
                                                value="1"
                                                class="form-control"
                                                placeholder="Qty."
                                                aria-label="Quantity"
                                            >
                                        </div>

                                        <div class="col-md-2">
                                            <button
                                                type="button"
                                                id="iecLibraryAdd"
                                                class="btn btn-outline-primary w-100"
                                            >
                                                Add
                                            </button>
                                        </div>

                                    </div>

                                    <div
                                        id="iecLibraryHint"
                                        class="form-text"
                                        style="display:none;"
                                    ></div>

                                    <div id="iecLibraryRows" class="mt-2"></div>

                                </div>

                                <?php endif; ?>

                            </div>

                        </div>
                    </section>

                    <section class="clean-card">
                            <div class="stack-section">
                                <div class="sh">
                                    <div class="sh-icon"><i class="bi bi-journal-text"></i></div>
                                    <div><h4 class="sh-title">Additional Details &amp; Attachments</h4><p class="sh-subtitle">Notes, supporting files and signature to complete your submission.</p></div>
                                </div>

                                <div class="row g-3">

                                    <div class="col-12">
                                        <label class="form-label">Additional Request Details *</label>
                                        <textarea
                                            class="form-control"
                                            name="request_details"
                                            rows="4"
                                            placeholder="Add any other details, specifications, or instructions"
                                            required
                                        ><?= e($editRow["request_details"] ?? "") ?></textarea>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">
                                            Attachments
                                            <span class="text-muted">(up to 10)</span>
                                        </label>
                                        <?php if ($editAttachments): ?>
                                        <div class="attachment-list mb-2">
                                            <?php foreach ($editAttachments as $existingFile): ?>
                                            <label class="attachment-chip" title="Uncheck to remove on save">
                                                <input
                                                    type="checkbox"
                                                    class="form-check-input me-1"
                                                    name="keep_attachments[]"
                                                    value="<?= e($existingFile) ?>"
                                                    checked
                                                >
                                                <i class="bi bi-paperclip"></i>
                                                <span class="attachment-file-name"><?= e($existingFile) ?></span>
                                            </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <div class="field-help mb-2">
                                            Uncheck a file above to remove it when you save.
                                        </div>
                                        <?php endif; ?>
                                        <input
                                            id="attachmentInput"
                                            type="file"
                                            class="form-control"
                                            name="attachment[]"
                                            multiple
                                        >
                                        <div id="attachmentList" class="attachment-list"></div>
                                    </div>

                                    <div class="col-md-6">
                                        <label class="form-label">Requestor Signature Date</label>
                                        <input
                                            type="date"
                                            class="form-control"
                                            name="requestor_signature_date"
                                            value="<?= e(substr((string)($editRow["requestor_signature_date"] ?? ""), 0, 10) ?: date("Y-m-d")) ?>"
                                            <?= $isDatePrivileged ? "" : "readonly" ?>
                                        >
                                    </div>

                                </div>

                            </div>

                    <div class="request-actions">
                        <a
                            class="btn btn-outline-secondary"
                            href="<?= e(BASE_URL) ?>/user/my_requests.php"
                        >
                            Cancel
                        </a>

                        <button class="btn btn-primary" type="submit" disabled><i class="bi bi-check2-circle me-1"></i>Save Changes</button>
                    </div>

                </form>
                </div>
            </div>
        </main>
    </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", function () {

    /* =====================================================
       HPU ASSISTANCE
    ===================================================== */
    const assistanceRequired =
        document.getElementById(
            "hpu_assistance_required"
        );

    const staffNeededWrap =
        document.getElementById(
            "hpuStaffNeededWrap"
        );

    const staffNeeded =
        document.getElementById(
            "hpu_staff_needed"
        );

    function selectedIsCoverage() {
        const selectedType =
            document.querySelector(
                ".request-type:checked"
            );

        return (
            !!selectedType
            && selectedType.value === "Photo/Video Coverage"
        );
    }

    function updateHpuAssistance() {
        const enabled =
            assistanceRequired.checked;

        // Staff count applies to Photo/Video Coverage only.
        // Graphic Design can check assistance without staff count.
        const showStaff =
            enabled && selectedIsCoverage();

        staffNeededWrap.classList.toggle(
            "show",
            showStaff
        );

        staffNeeded.disabled =
            !showStaff;

        staffNeeded.required =
            showStaff;

        if (!showStaff) {
            staffNeeded.value =
                "";
        } else if (
            !staffNeeded.value
            ||
            Number(staffNeeded.value) < 1
        ) {
            staffNeeded.value =
                "1";
        }
    }

    const hpuAssistanceCard =
        document.getElementById(
            "hpuAssistanceCard"
        );

    function updateHpuAssistanceVisibility() {
        const selectedType =
            document.querySelector(
                ".request-type:checked"
            );

        const allowed =
            !!selectedType
            && (
                selectedType.value === "Photo/Video Coverage"
                || selectedType.value === "Graphic Design"
            );

        if (hpuAssistanceCard) {
            if (allowed) {
                hpuAssistanceCard.removeAttribute("hidden");
            } else {
                hpuAssistanceCard.setAttribute("hidden", "");
            }
        }

        if (!allowed && assistanceRequired.checked) {
            assistanceRequired.checked = false;
        }

        updateHpuAssistance();

        if (!allowed && typeof updatePhotoSuggest === "function") {
            updatePhotoSuggest();
        }
    }

    assistanceRequired.addEventListener(
        "change",
        function () {
            updateHpuAssistance();
            updatePhotoSuggest();
        }
    );

    updateHpuAssistance();

    /* Photography cross-suggest (disabled: assistance is coverage-only). */
    const photoSuggest =
        document.getElementById(
            "photoSuggest"
        );

    const photoSuggestBtn =
        document.getElementById(
            "photoSuggestBtn"
        );

    function updatePhotoSuggest() {
        if (!photoSuggest) return;

        // HPU Assistance is Photo/Video Coverage only, so the
        // Graphic Design cross-suggest is disabled.
        const show = false;

        if (show) {
            photoSuggest.removeAttribute("hidden");
        } else {
            photoSuggest.setAttribute("hidden", "");
        }
    }

    if (photoSuggestBtn) {
        photoSuggestBtn.addEventListener(
            "click",
            function () {
                assistanceRequired.checked = true;
                updateHpuAssistance();
                updatePhotoSuggest();
                if (!staffNeeded.disabled) {
                    staffNeeded.focus();
                }
            }
        );
    }

    /* Event Start/End time-range helpers (mirrors new_request.php). */
    function eventNaChecked() {
        var na = document.querySelector('.na-check[data-target="event_datetime"]');
        return !!(na && na.checked);
    }
    function toEventPoint(value) {
        var v = (value || "").trim().replace("T", " ");
        if (v === "") return "";
        var m = v.match(/^(\d{4}-\d{2}-\d{2})(?:[ ](\d{2}:\d{2})(?::\d{2})?)?$/);
        if (m) return m[1] + "T" + (m[2] || "00:00");
        var d = new Date(v);
        if (isNaN(d.getTime())) return "";
        var p = function (n) { return String(n).padStart(2, "0"); };
        return d.getFullYear() + "-" + p(d.getMonth() + 1) + "-" + p(d.getDate()) + "T" + p(d.getHours()) + ":" + p(d.getMinutes());
    }
    function formatEventPreview(value) {
        var v = (value || "").trim().replace("T", " ");
        var d = new Date(v);
        if (isNaN(d.getTime())) return value;
        return d.toLocaleString("en-US", { month: "short", day: "numeric", year: "numeric", hour: "numeric", minute: "2-digit" });
    }
    function syncEventRange() {
        var startEl = document.getElementById("event_start");
        var endEl = document.getElementById("event_end");
        var hidden = document.getElementById("event_datetime");
        var preview = document.getElementById("event_range_preview");
        if (!startEl || !hidden) return "";
        if (eventNaChecked()) {
            hidden.value = "";
            if (preview) preview.textContent = "";
            var w0 = document.getElementById("event_datetime_warning");
            if (w0) { w0.textContent = ""; w0.classList.remove("show"); }
            return "";
        }
        var start = toEventPoint(startEl.value);
        var end = endEl ? toEventPoint(endEl.value) : "";
        var combined = start;
        if (start !== "" && end !== "") combined = start + " to " + end;
        hidden.value = combined;
        if (preview) {
            if (start !== "" && end !== "") preview.textContent = "Selected: " + formatEventPreview(start) + " → " + formatEventPreview(end);
            else if (start !== "") preview.textContent = "Selected: " + formatEventPreview(start);
            else preview.textContent = "";
        }
        return combined;
    }
    function applyEventNAState(checked) {
        ["event_start", "event_end"].forEach(function (id) {
            var el = document.getElementById(id);
            if (!el) return;
            if (el.dataset.origPlaceholder === undefined) el.dataset.origPlaceholder = el.placeholder || "";
            el.disabled = !!checked;
            var picker = el._flatpickr || null;
            if (picker) {
                if (checked) { try { picker.clear(); picker.close(); } catch (e) {} }
                if (picker.altInput) {
                    picker.altInput.disabled = !!checked;
                    picker.altInput.placeholder = checked ? "Marked as non-applicable" : (el.dataset.origPlaceholder || "");
                }
            } else if (checked) {
                el.value = "";
                el.placeholder = "Marked as non-applicable";
            } else {
                el.placeholder = el.dataset.origPlaceholder || "";
            }
        });
        ["eventSameDay", "eventClearEnd"].forEach(function (btnId) {
            var btn = document.getElementById(btnId);
            if (btn) btn.disabled = !!checked;
        });
        var hidden = document.getElementById("event_datetime");
        if (hidden && checked) hidden.value = "";
        var preview = document.getElementById("event_range_preview");
        if (preview && checked) preview.textContent = "";
        var warn = document.getElementById("event_datetime_warning");
        if (warn) { warn.textContent = ""; warn.classList.remove("show"); }
    }
    function validateEventRange() {
        var warn = document.getElementById("event_datetime_warning");
        var startEl = document.getElementById("event_start");
        var endEl = document.getElementById("event_end");
        var hidden = document.getElementById("event_datetime");
        if (warn) { warn.textContent = ""; warn.classList.remove("show"); }
        if (!startEl || !hidden) return true;
        if (eventNaChecked()) return true;
        var combined = syncEventRange();
        if (combined === "") return true;
        var parts = combined.split(" to ");
        if (parts.length < 2) return true;
        var start = new Date(parts[0].trim());
        var end = new Date(parts[1].trim());
        if (isNaN(start.getTime()) || isNaN(end.getTime())) {
            if (warn) { warn.textContent = "End of range not recognized — please re-pick the dates."; warn.classList.add("show"); }
            return false;
        }
        if (end < start) {
            if (warn) { warn.textContent = "Event end cannot be earlier than the event start."; warn.classList.add("show"); }
            return false;
        }
        return true;
    }

    /* =====================================================
       MINIMUM PROCESSING / LEAD TIME
    ===================================================== */
    const leadDaysByService = {
        "Graphic Design": 5,
        "Video Production": 5,
        "Photo/Video Coverage": 3,
        "IEC Materials": 3
    };

    const dateRequestField =
        document.getElementById(
            "date_request"
        );

    const leadTimeSummary =
        document.getElementById(
            "leadTimeSummary"
        );

    const leadTimeSummaryText =
        document.getElementById(
            "leadTimeSummaryText"
        );

    const completionLeadWarning =
        document.getElementById(
            "date_completion_lead_warning"
        );

    function parseLocalDate(value) {
        if (!value) {
            return null;
        }

        const parts =
            value.substring(0, 10)
                .split("-")
                .map(Number);

        if (parts.length !== 3) {
            return null;
        }

        return new Date(
            parts[0],
            parts[1] - 1,
            parts[2]
        );
    }

    function formatIsoDate(date) {
        const y =
            date.getFullYear();

        const m =
            String(
                date.getMonth() + 1
            ).padStart(
                2,
                "0"
            );

        const d =
            String(
                date.getDate()
            ).padStart(
                2,
                "0"
            );

        return (
            y
            + "-"
            + m
            + "-"
            + d
        );
    }

    function formatReadableDate(date) {
        return date.toLocaleDateString(
            "en-US",
            {
                month: "long",
                day: "numeric",
                year: "numeric"
            }
        );
    }

    function selectedLeadDays() {
        let days = 0;

        document
            .querySelectorAll(
                ".request-type:checked"
            )
            .forEach(
                function (checkbox) {
                    days =
                        Math.max(
                            days,
                            leadDaysByService[
                                checkbox.value
                            ] || 0
                        );
                }
            );

        return days;
    }

    function isWorkingDay(date) {
        const day = date.getDay();
        return day !== 0 && day !== 6;
    }

    function isBlockedIso(iso) {
        if (!iso) return false;
        try {
            if (typeof blockedRanges === "undefined" || !blockedRanges.length) return false;
        } catch (e) {
            return false;
        }
        return blockedRanges.some(function (range) {
            return iso >= range.from.substring(0, 10) && iso <= range.to.substring(0, 10);
        });
    }

    function computeEarliestWorking(requestDate, days) {
        // Counts ONLY working days (Mon–Fri); blocked HPU dates do not count.
        const cur = new Date(requestDate);
        let counted = 0;
        for (let i = 0; i < 730; i++) {
            cur.setDate(cur.getDate() + 1);
            if (!isWorkingDay(cur)) continue;
            const iso = formatIsoDate(cur);
            if (isBlockedIso(iso)) continue;
            counted++;
            if (counted >= days) return cur;
        }
        return null;
    }

    function getLeadTimeRule() {
        const days =
            selectedLeadDays();

        const requestDate =
            parseLocalDate(
                dateRequestField.value
            );

        if (
            !requestDate
            ||
            days <= 0
        ) {
            return null;
        }

        const earliest = computeEarliestWorking(requestDate, days);

        if (!earliest) {
            return null;
        }

        return {
            days: days,
            earliest: earliest,
            earliestIso:
                formatIsoDate(
                    earliest
                )
        };
    }

    function clearLeadWarning(
        field,
        warning
    ) {
        if (!field || !warning) {
            return;
        }

        warning.textContent =
            "";

        warning.classList.remove(
            "show"
        );

        field.classList.remove(
            "availability-blocked"
        );
    }

    function validateLeadTimeField(
        field,
        warning,
        isDateTime
    ) {
        if (typeof IS_DATE_PRIVILEGED !== "undefined" && IS_DATE_PRIVILEGED) {
            clearLeadWarning(
                field,
                warning
            );

            return true;
        }

        if (
            !field
            ||
            field.disabled
            ||
            !field.value
        ) {
            clearLeadWarning(
                field,
                warning
            );

            return true;
        }

        const rule =
            getLeadTimeRule();

        if (!rule) {
            clearLeadWarning(
                field,
                warning
            );

            return true;
        }

        const selectedDate =
            field.value.substring(
                0,
                10
            );

        if (
            selectedDate
            <
            rule.earliestIso
        ) {
            const message =
                "This request requires at least "
                + rule.days
                + " working days of lead time (weekends and blocked HPU dates excluded). Please select "
                + formatReadableDate(
                    rule.earliest
                )
                + " or later.";

            field.value =
                "";

            field.classList.add(
                "availability-blocked"
            );

            warning.textContent =
                message;

            warning.classList.add(
                "show"
            );

            return false;
        }

        // Weekends are never allowed, even if after the earliest date.
        const selParts = selectedDate.split("-").map(Number);
        const selDate = new Date(selParts[0], selParts[1] - 1, selParts[2]);
        if (selDate.getDay() === 0 || selDate.getDay() === 6) {
            field.value = "";
            field.classList.add("availability-blocked");
            warning.textContent =
                "Weekends are not working days. Please select a weekday (Monday–Friday).";
            warning.classList.add("show");
            return false;
        }

        clearLeadWarning(
            field,
            warning
        );

        return true;
    }

    /* Admin / supervisor / HPU staff pick any date; others obey the rules. Same as new_request.php. */
    const IS_DATE_PRIVILEGED =
        <?= $isDatePrivileged ? "true" : "false" ?>;

    function updateLeadTimeUI() {
        const completionField =
            document.getElementById(
                "date_completion"
            );

        const rule =
            getLeadTimeRule();

        if (!rule) {
            delete completionField.dataset.minDate;

            leadTimeSummaryText.textContent =
                "";

            leadTimeSummary.querySelector(
                "strong"
            ).textContent =
                "Select a request type to calculate the earliest allowable date.";

            if (typeof updateApprovalProjection === "function") {
                try { updateApprovalProjection(); } catch (e) {}
            }

            return;
        }

        /*
         * Flatpickr enforces the minimum date visually (Preferred Completion only).
         * Event Date & Time is free selection — no lead-time limit.
         * Privileged roles skip the lead-time floor entirely.
         * Server-side validation remains authoritative.
         */
        if (IS_DATE_PRIVILEGED) {
            delete completionField.dataset.minDate;

            leadTimeSummary.querySelector(
                "strong"
            ).textContent =
                "Privileged scheduling: lead-time floor lifted.";

            leadTimeSummaryText.textContent =
                "As HPU personnel you may freely select any completion date, including blocked dates.";

            clearLeadWarning(
                completionField,
                completionLeadWarning
            );

            if (typeof updateApprovalProjection === "function") {
                try { updateApprovalProjection(); } catch (e) {}
            }

            return;
        }
        completionField.dataset.minDate =
            rule.earliestIso;

        leadTimeSummary.querySelector(
            "strong"
        ).textContent =
            "Earliest allowable date: "
            + formatReadableDate(
                rule.earliest
            );

        leadTimeSummaryText.textContent =
            "Based on the selected services, a minimum lead time of "
            + rule.days
            + " working days applies to Preferred Completion (weekends and blocked HPU dates excluded).";

        validateLeadTimeField(
            completionField,
            completionLeadWarning,
            false
        );

        if (typeof updateApprovalProjection === "function") {
            try { updateApprovalProjection(); } catch (e) {}
        }
    }

    /*
     * Live preview of the automatic target: approval date plus the
     * minimum lead time (working days only, blocked HPU dates
     * excluded) — or the preferred date when it falls later.
     * Same as new_request.php.
     */
    function updateApprovalProjection() {
        const preview = document.getElementById(
            "approvalProjection"
        );

        if (!preview) {
            return;
        }

        if (IS_DATE_PRIVILEGED) {
            preview.textContent =
                "Privileged scheduling: the target follows your chosen dates.";
            return;
        }

        const days = selectedLeadDays();

        if (days <= 0) {
            preview.textContent =
                "Select a request type to preview it.";
            return;
        }

        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const earliest = computeEarliestWorking(today, days);

        if (!earliest) {
            preview.textContent =
                "Select a request type to preview it.";
            return;
        }

        const weekday = earliest.toLocaleDateString(
            "en-US",
            { weekday: "long" }
        );

        preview.textContent =
            "If approved today (" +
            today.toLocaleDateString(
                "en-US",
                { month: "long", day: "numeric" }
            ) +
            "), the target auto-sets to " +
            weekday +
            ", " +
            formatReadableDate(earliest) +
            " — " +
            days +
            " working days later. A later preferred date is honored instead.";
    }

    dateRequestField.addEventListener(
        "change",
        function () {
            updateLeadTimeUI();
            refreshFlatpickrRules();
        }
    );

    // Single-select: only one request type allowed (radio). Refresh all sections on any change.
    function updateAllRequestSections() {
        document.querySelectorAll(".request-type").forEach(function (radio) {
            const section = document.getElementById(
                radio.dataset.section
            );

            if (!section) return;

            section.classList.toggle(
                "show",
                radio.checked
            );

            section.querySelectorAll(
                "input, textarea, select"
            ).forEach(function (field) {

                field.disabled = !radio.checked;

                if (!radio.checked) {
                    if (
                        field.type === "checkbox" ||
                        field.type === "radio"
                    ) {
                        field.checked = false;
                    } else {
                        field.value = "";
                    }
                }

            });

            section.querySelectorAll(
                ".other-field"
            ).forEach(function (field) {

                if (!radio.checked) {
                    field.classList.remove("show");
                }

            });
        });
    }

    function checkRecommended(section, selector, value) {
        const boxes =
            Array.from(
                section.querySelectorAll(selector)
            );

        if (!boxes.length) return;

        const anyChecked =
            boxes.some(function (box) { return box.checked; });

        if (anyChecked) return;

        const target =
            boxes.find(function (box) { return box.value === value; });

        if (target) {
            target.checked = true;
            target.dispatchEvent(new Event("change", { bubbles: true }));
        }
    }

    function fillRecommendedNumber(section, selector, value) {
        const el =
            section.querySelector(selector);

        if (!el) return;

        if (el.value === "" || Number(el.value) < 1) {
            el.value = String(value);
        }
    }

    // Fill recommended defaults in the newly-selected section only.
    // Never overwrites a group the user already filled.
    function applyRecommendedDefaults(sectionId) {
        const section =
            document.getElementById(sectionId);

        if (!section) return;

        if (sectionId === "graphicSection") {
            checkRecommended(
                section,
                'input[name="graphic_colors[]"]',
                "Full Color"
            );
            checkRecommended(
                section,
                'input[name="publication_methods[]"]',
                "Digital File"
            );
            fillRecommendedNumber(
                section,
                'input[name="graphic_quantity"]',
                1
            );
        } else if (sectionId === "videoSection") {
            checkRecommended(
                section,
                'input[name="video_formats[]"]',
                "MP4"
            );
            fillRecommendedNumber(
                section,
                'input[name="video_quantity"]',
                1
            );
        } else if (sectionId === "coverageSection") {
            fillRecommendedNumber(
                section,
                'input[name="photographers_needed"]',
                1
            );
            checkRecommended(
                section,
                'input[name="coverage_formats[]"]',
                "JPEG"
            );
        } else if (sectionId === "iecSection") {
            checkRecommended(
                section,
                'input[name="iec_selected[]"]',
                "ASTMMC Services"
            );

            // Re-sync per-row quantity states (section show enables
            // all inputs; quantities must stay disabled until checked).
            section.querySelectorAll(".iec-check").forEach(function (box) {
                const qty =
                    document.getElementById(box.dataset.qtyTarget);

                if (!qty) return;

                qty.disabled = !box.checked;
                qty.required = box.checked;

                if (box.checked && !qty.value) {
                    qty.value = "1";
                } else if (!box.checked) {
                    qty.value = "";
                }
            });
        }
    }

    document.querySelectorAll(".request-type").forEach(function (radio) {
        radio.addEventListener(
            "change",
            function () {
                updateAllRequestSections();
                if (radio.checked) {
                    applyRecommendedDefaults(radio.dataset.section);
                }
                updateLeadTimeUI();
                updateApprovalProjection();
                updateHpuAssistanceVisibility();
                updatePhotoSuggest();
            }
        );
    });

    updateAllRequestSections();
    updateHpuAssistanceVisibility();

    function applyNAState(checkbox) {
        if (checkbox.dataset.target === "event_datetime") {
            applyEventNAState(checkbox.checked);
            return;
        }

        const field = document.getElementById(
            checkbox.dataset.target
        );

        if (!field) return;

        if (field.dataset.origPlaceholder === undefined) {
            field.dataset.origPlaceholder = field.placeholder || "";
        }

        field.disabled = checkbox.checked;

        const picker = field._flatpickr || null;

        if (picker) {
            if (checkbox.checked) {
                picker.clear();
                picker.close();
            }

            if (picker.altInput) {
                if (picker.altInput.dataset.origPlaceholder === undefined) {
                    picker.altInput.dataset.origPlaceholder =
                        picker.altInput.placeholder || field.dataset.origPlaceholder || "";
                }

                picker.altInput.disabled = checkbox.checked;
                picker.altInput.placeholder = checkbox.checked
                    ? "Marked as non-applicable"
                    : (picker.altInput.dataset.origPlaceholder || "");
            }
        } else {
            if (checkbox.checked) {
                field.value = "";
                field.placeholder = "Marked as non-applicable";
            } else {
                field.placeholder = field.dataset.origPlaceholder || "";
            }
        }
    }

    document.querySelectorAll(".na-check").forEach(function (checkbox) {
        function updateNA() {
            applyNAState(checkbox);
            updatePhotoSuggest();
        }

        checkbox.addEventListener(
            "change",
            updateNA
        );

        updateNA();
    });

    document.querySelectorAll(".other-toggle").forEach(function (checkbox) {

        const targetId = checkbox.dataset.otherTarget;

        if (!targetId) {
            return;
        }

        const field = document.getElementById(targetId);

        function updateOther() {
            field.disabled = !checkbox.checked;
            field.required = checkbox.checked;

            field.classList.toggle(
                "show",
                checkbox.checked
            );

            if (!checkbox.checked) {
                field.value = "";
            }
        }

        checkbox.addEventListener(
            "change",
            updateOther
        );

        updateOther();
    });

    document.querySelectorAll(".iec-check").forEach(function (checkbox) {

        const quantity = document.getElementById(
            checkbox.dataset.qtyTarget
        );

        const otherId = checkbox.dataset.otherTarget;

        const otherField = otherId
            ? document.getElementById(otherId)
            : null;

        function updateIEC() {

            quantity.disabled = !checkbox.checked;
            quantity.required = checkbox.checked;

            if (checkbox.checked) {
                if (!quantity.value) {
                    quantity.value = 1;
                }
            } else {
                quantity.value = "";
            }

            if (otherField) {
                otherField.disabled = !checkbox.checked;
                otherField.required = checkbox.checked;

                otherField.classList.toggle(
                    "show",
                    checkbox.checked
                );

                if (!checkbox.checked) {
                    otherField.value = "";
                }
            }
        }

        checkbox.addEventListener(
            "change",
            updateIEC
        );

        updateIEC();
    });

    /* ---- HPU library picker: append linked IEC rows ---- */
    (function initIecLibraryPicker() {
        const select = document.getElementById("iecLibrarySelect");
        const qtyInput = document.getElementById("iecLibraryQty");
        const addBtn = document.getElementById("iecLibraryAdd");
        const rowsBox = document.getElementById("iecLibraryRows");
        const hint = document.getElementById("iecLibraryHint");

        if (!select || !addBtn || !rowsBox) {
            return;
        }

        function showHint(text) {
            if (!hint) {
                return;
            }

            hint.textContent = text;
            hint.style.display = text ? "" : "none";
        }

        function rowExists(name) {
            const boxes = document.querySelectorAll(
                'input[name="iec_selected[]"]'
            );

            for (const box of boxes) {
                if (box.value === name) {
                    return true;
                }
            }

            return false;
        }

        addBtn.addEventListener("click", function () {
            const option =
                select.options[select.selectedIndex];

            if (!option || !option.value) {
                showHint("Choose a library item first.");
                return;
            }

            const id = option.value;
            const name = option.getAttribute("data-name") || option.text;
            const qty = Math.max(
                1,
                parseInt(qtyInput.value || "1", 10) || 1
            );

            if (rowExists(name)) {
                showHint(
                    "“" + name + "” is already in the list ” adjust its quantity instead."
                );
                return;
            }

            showHint("");

            const row = document.createElement("div");
            row.className = "iec-row";

            const qtyId = "qty_lib_" + id + "_" + Date.now();

            const safeName = name
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;");

            row.innerHTML =
                '<div>' +
                '<label class="form-check">' +
                '<input class="form-check-input lib-check" type="checkbox" ' +
                'name="iec_selected[]" value="' + safeName + '" checked> ' +
                '<span class="form-check-label">' + safeName +
                ' <span class="badge bg-success-subtle text-success border">Library</span></span>' +
                "</label>" +
                '<input type="hidden" name="iec_material_id[' + safeName + ']" value="' + id + '">' +
                "</div>" +
                '<div class="d-flex gap-2">' +
                '<input id="' + qtyId + '" type="number" min="1" class="form-control" ' +
                'name="iec_quantity[' + safeName + ']" value="' + qty + '">' +
                '<button type="button" class="btn btn-sm btn-outline-danger lib-remove" title="Remove">' +
                '<i class="bi bi-x-lg"></i></button>' +
                "</div>";

            const box = row.querySelector(".lib-check");
            const qtyEl = row.querySelector('input[type="number"]');

            box.addEventListener("change", function () {
                if (!box.checked) {
                    row.remove();
                    if (window.refreshIecSummary) {
                        window.refreshIecSummary();
                    }
                }
            });

            qtyEl.required = true;

            row.querySelector(".lib-remove").addEventListener(
                "click",
                function () {
                    row.remove();
                }
            );

            rowsBox.appendChild(row);
            select.value = "";
            qtyInput.value = 1;
        });
    })();

    const attachmentInput = document.getElementById("attachmentInput");
    const attachmentListEl = document.getElementById("attachmentList");
    const MAX_ATTACHMENTS = 10;

    function attachmentFileSize(size) {
        if (size >= 1024 * 1024) {
            return (size / (1024 * 1024)).toFixed(1) + " MB";
        }
        return Math.max(1, Math.round(size / 1024)) + " KB";
    }

    const attachmentStore = new DataTransfer();

    function syncAttachmentInput() {
        attachmentInput.files = attachmentStore.files;
    }

    function renderAttachments() {
        attachmentListEl.innerHTML = "";

        Array.prototype.slice.call(
            attachmentStore.files || []
        ).forEach(function (file, index) {
            const chip = document.createElement("span");
            chip.className = "attachment-chip";

            const icon = document.createElement("i");
            icon.className = "bi bi-paperclip";

            const name = document.createElement("span");
            name.className = "attachment-file-name";
            name.textContent =
                (index + 1) + ". " + file.name;

            const size = document.createElement("small");
            size.textContent =
                " " + attachmentFileSize(file.size);

            const removeBtn = document.createElement("button");
            removeBtn.type = "button";
            removeBtn.className = "attachment-remove";
            removeBtn.title = "Remove " + file.name;
            removeBtn.setAttribute("aria-label", "Remove " + file.name);
            removeBtn.innerHTML = '<i class="bi bi-x"></i>';

            removeBtn.addEventListener("click", function () {
                attachmentStore.items.remove(index);
                syncAttachmentInput();
                renderAttachments();
                if (typeof window.updateSubmitButtonState === "function") {
                    window.updateSubmitButtonState();
                }
            });

            chip.appendChild(icon);
            chip.appendChild(name);
            chip.appendChild(size);
            chip.appendChild(removeBtn);

            attachmentListEl.appendChild(chip);
        });
    }

    attachmentInput.addEventListener("change", function () {
        const picked = Array.prototype.slice.call(
            attachmentInput.files || []
        );

        // Reset the input so the same file can be picked again later.
        attachmentInput.value = "";

        if (picked.length === 0) {
            return;
        }

        const remaining =
            MAX_ATTACHMENTS - attachmentStore.files.length;

        if (picked.length > remaining) {
            alert(
                "You can only attach up to " +
                MAX_ATTACHMENTS +
                " files. (" +
                attachmentStore.files.length +
                " already selected.)"
            );
            return;
        }

        picked.forEach(function (file) {
            attachmentStore.items.add(file);
        });

        syncAttachmentInput();
        renderAttachments();
        if (typeof window.updateSubmitButtonState === "function") {
            window.updateSubmitButtonState();
        }
    });


    /* =====================================================
       FLATPICKR: DISABLE BLOCKED HPU DATES
    ===================================================== */
    const blockedDatesEndpoint =
        <?= json_encode(BASE_URL . "/ajax/get_blocked_dates.php") ?>;

    let eventStartPicker = null;
    let eventEndPicker = null;
    let eventPicker = null;
    let completionPicker = null;
    let blockedRanges = [];

    function flatpickrDisableRanges() {
        const rules = blockedRanges.map(
            function (range) {
                return {
                    from: range.from,
                    to: range.to
                };
            }
        );
        // Weekends only — working days are Mon–Fri.
        rules.push(function (date) {
            const day = date.getDay();
            return day === 0 || day === 6;
        });
        return rules;
    }

    async function loadBlockedRanges() {
        const services =
            selectedHpuServices();

        blockedRanges = [];

        if (services.length) {
            const params =
                new URLSearchParams();

            services.forEach(
                function (service) {
                    params.append(
                        "services[]",
                        service
                    );
                }
            );

            try {
                const response =
                    await fetch(
                        blockedDatesEndpoint
                        + "?"
                        + params.toString(),
                        {
                            credentials:
                                "same-origin"
                        }
                    );

                const data =
                    await response.json();

                if (
                    data.success
                    &&
                    Array.isArray(
                        data.ranges
                    )
                ) {
                    blockedRanges =
                        data.ranges;
                }
            } catch (error) {
                console.error(
                    "Blocked dates load failed:",
                    error
                );
            }
        }

        refreshFlatpickrRules();
    }

    function refreshFlatpickrRules() {
        const rule =
            getLeadTimeRule();

        const minDate =
            rule
                ? rule.earliestIso
                : null;

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const todayIso = today.toISOString().slice(0, 10);

        // Event Date & Time: privileged roles pick any date (even past);
        // others start at today with no disabled dates.
        // Preferred Completion: privileged roles pick any date with no
        // disabled dates; others get max(today, lead-time floor) plus
        // weekends + blocked HPU dates. Same as new_request.php.
        const completionDisabled = IS_DATE_PRIVILEGED
            ? []
            : flatpickrDisableRanges();

        const eventMin = IS_DATE_PRIVILEGED ? null : todayIso;
        const completionMin = IS_DATE_PRIVILEGED
            ? null
            : (minDate && minDate > todayIso ? minDate : todayIso);

        [eventStartPicker, eventEndPicker].forEach(function (picker) {
            if (!picker) return;
            picker.set("minDate", eventMin);
            picker.set("disable", []);
        });

        if (eventPicker) {
            eventPicker.set(
                "minDate",
                eventMin
            );

            eventPicker.set(
                "disable",
                []
            );
        }

        if (completionPicker) {
            completionPicker.set(
                "minDate",
                completionMin
            );

            completionPicker.set(
                "disable",
                completionDisabled
            );
        }

        updateApprovalProjection();
    }

    function eventPickerOptions(isEnd) {
        return {
            enableTime: true,
            time_24hr: false,
            dateFormat: "Y-m-d\\TH:i",
            altInput: true,
            altFormat: "F j, Y h:i K",
            allowInput: true,
            minuteIncrement: 5,
            disableMobile: true,
            minDate: "today",
            onChange: function () {
                syncEventRange();
                updatePhotoSuggest();
                validateEventRange();
            }
        };
    }

    eventStartPicker = flatpickr("#event_start", eventPickerOptions(false));
    eventEndPicker = flatpickr("#event_end", eventPickerOptions(true));
    eventPicker = null;

    completionPicker =
        flatpickr(
            "#date_completion",
            {
                dateFormat: "Y-m-d",
                altInput: true,
                altFormat: "F j, Y",
                allowInput: false,
                disableMobile: true,
                onChange: function () {
                    const field =
                        document.getElementById(
                            "date_completion"
                        );

                    const leadValid =
                        validateLeadTimeField(
                            field,
                            completionLeadWarning,
                            false
                        );

                    if (leadValid) {
                        checkHpuDateAvailability(
                            "date_completion",
                            "date_completion_warning"
                        );
                    }
                }
            }
        );

    // Re-apply Non-applicable states now that flatpickr instances exist
    // (the visible altInput must be disabled, not just the hidden input).
    ["event_start", "event_end"].forEach(function (id) {
        var el = document.getElementById(id);
        if (!el) return;
        ["change", "input"].forEach(function (evt) {
            el.addEventListener(evt, function () {
                syncEventRange();
                validateEventRange();
            });
        });
    });

    var sameDayBtn = document.getElementById("eventSameDay");
    if (sameDayBtn) {
        sameDayBtn.addEventListener("click", function () {
            var startEl = document.getElementById("event_start");
            var endEl = document.getElementById("event_end");
            if (sameDayBtn.disabled) return;
            if (!startEl || !endEl || startEl.disabled) return;
            var base = (startEl.value || "").trim() || (endEl.value || "").trim();
            var baseDate = base.substring(0, 10);
            if (!/^\d{4}-\d{2}-\d{2}$/.test(baseDate)) {
                var today = new Date();
                var p = function (n) { return String(n).padStart(2, "0"); };
                baseDate = today.getFullYear() + "-" + p(today.getMonth() + 1) + "-" + p(today.getDate());
            }
            try {
                if (eventStartPicker) eventStartPicker.setDate(baseDate + "T08:00", true);
                else startEl.value = baseDate + "T08:00";
                if (eventEndPicker) eventEndPicker.setDate(baseDate + "T17:00", true);
                else endEl.value = baseDate + "T17:00";
            } catch (e) {
                startEl.value = baseDate + "T08:00";
                endEl.value = baseDate + "T17:00";
            }
            syncEventRange();
            validateEventRange();
        });
    }

    var clearEndBtn = document.getElementById("eventClearEnd");
    if (clearEndBtn) {
        clearEndBtn.addEventListener("click", function () {
            var endEl = document.getElementById("event_end");
            if (clearEndBtn.disabled) return;
            if (!endEl || endEl.disabled) return;
            try {
                if (eventEndPicker) eventEndPicker.clear();
                else endEl.value = "";
            } catch (e) { endEl.value = ""; }
            syncEventRange();
            validateEventRange();
        });
    }

    try { syncEventRange(); } catch (e) {}

    document.querySelectorAll(".na-check").forEach(function (checkbox) {
        applyNAState(checkbox);
    });

    /* =====================================================
       HPU DATE AVAILABILITY
    ===================================================== */
    const availabilityEndpoint =
        <?= json_encode(BASE_URL . "/ajax/check_blocked_date.php") ?>;

     function selectedHpuServices() {
        return Array.from(
            document.querySelectorAll(
                ".request-type:checked"
            )
        ).map(function (checkbox) {
            return checkbox.value;
        });
    }

    function setAvailabilityMessage(
        field,
        warning,
        message
    ) {
        if (!warning) {
            return;
        }

        if (message) {
            field.classList.add(
                "availability-blocked"
            );

            warning.textContent =
                message;

            warning.classList.add(
                "show"
            );
        } else {
            field.classList.remove(
                "availability-blocked"
            );

            warning.textContent =
                "";

            warning.classList.remove(
                "show"
            );
        }
    }

    async function checkHpuDateAvailability(
        fieldId,
        warningId
    ) {
        const field =
            document.getElementById(
                fieldId
            );

        const warning =
            document.getElementById(
                warningId
            );

        if (
            !field
            ||
            field.disabled
            ||
            !field.value
        ) {
            if (field && warning) {
                setAvailabilityMessage(
                    field,
                    warning,
                    ""
                );
            }

            return true;
        }

        const services =
            selectedHpuServices();

        if (!services.length) {
            setAvailabilityMessage(
                field,
                warning,
                ""
            );

            return true;
        }

        const requestedValue =
            field.value;

        const params =
            new URLSearchParams();

        params.set(
            "date",
            field.value
        );

        services.forEach(
            function (service) {
                params.append(
                    "services[]",
                    service
                );
            }
        );

        try {
            const response =
                await fetch(
                    availabilityEndpoint
                    + "?"
                    + params.toString(),
                    {
                        credentials:
                            "same-origin"
                    }
                );

            const data =
                await response.json();

            if (
                field.value
                !==
                requestedValue
            ) {
                return true;
            }

            if (
                data.success
                &&
                data.blocked
            ) {
                /*
                 * Clear the invalid date immediately,
                 * as requested.
                 */
                field.value =
                    "";

                setAvailabilityMessage(
                    field,
                    warning,
                    data.message
                );

                field.focus();

                return false;
            }

            setAvailabilityMessage(
                field,
                warning,
                ""
            );

            return true;

        } catch (error) {
            console.error(
                "Availability check failed:",
                error
            );

            /*
             * Do not prevent form use solely because the
             * read-only AJAX check failed. save_request.php
             * performs authoritative server-side validation.
             */
            return true;
        }
    }

    function recheckSelectedDates() {
        updateLeadTimeUI();

        try { syncEventRange(); } catch (e) {}
        try { validateEventRange(); } catch (e) {}

        const completionLeadValid =
            validateLeadTimeField(
                document.getElementById(
                    "date_completion"
                ),
                completionLeadWarning,
                false
            );

        // Blocked-date availability applies to Preferred Completion only.
        if (completionLeadValid) {
            checkHpuDateAvailability(
                "date_completion",
                "date_completion_warning"
            );
        }
    }

    const eventDateField =
        document.getElementById(
            "event_datetime"
        );

    const completionDateField =
        document.getElementById(
            "date_completion"
        );


    document
        .querySelectorAll(
            ".request-type"
        )
        .forEach(
            function (checkbox) {
                checkbox.addEventListener(
                    "change",
                    function () {
                        recheckSelectedDates();
                        loadBlockedRanges();
                    }
                );
            }
        );

    document.getElementById(
        "requestForm"
    ).addEventListener("submit", function (event) {

        const selected = document.querySelectorAll(
            ".request-type:checked"
        );

        if (!selected.length) {
            event.preventDefault();

            alert(
                "Please select one type of request."
            );

            return;
        }

        try { syncEventRange(); } catch (e) {}

        if (typeof validateEventRange === "function" && !validateEventRange()) {
            event.preventDefault();
            alert("Please fix the Event Date & Time range — end cannot be earlier than start.");
            return;
        }

        if (
            assistanceRequired.checked
            &&
            (
                !staffNeeded.value
                ||
                Number(staffNeeded.value) < 1
            )
        ) {
            event.preventDefault();

            alert(
                "Please enter the number of HPU staff needed."
            );

            staffNeeded.focus();

            return;
        }

        const completionLeadValid =
            validateLeadTimeField(
                completionDateField,
                completionLeadWarning,
                false
            );

        if (!completionLeadValid) {
            event.preventDefault();
        }
    });

    updateLeadTimeUI();
    updateApprovalProjection();
    loadBlockedRanges();
    updatePhotoSuggest();

});
</script>

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<script>
/* =====================================================
   EDIT REQUEST — WIZARD UX (same as new_request.php, progressive enhancement)
   No backend / field-name changes. All logic additive.
===================================================== */
document.addEventListener("DOMContentLoaded", function () {
    var form = document.getElementById("requestForm");
    if (!form) return;

    /* ---------- helpers ---------- */
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(s) {
        return String(s == null ? "" : s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
    }
    function fieldLabel(el) {
        if (!el) return "This field";
        if (el.name === "requestor_name") return "Requestor name";
        if (el.name === "department") return "Department";
        if (el.name === "contact") return "Contact number / email";
        if (el.name === "date_request") return "Date requested";
        if (el.name === "project_title") return "Title / project name";
        if (el.name === "purpose_objective") return "Purpose / objective";
        if (el.id === "event_start" || el.id === "event_end") return "Event date & time";
        if (el.name === "event_datetime") return "Event date & time";
        if (el.name === "venue_location") return "Venue / location";
        if (el.name === "send_to") return "Send them to";
        if (el.name === "graphic_quantity") return "Number of pcs";
        if (el.name === "request_details") return "Additional request details";
        if (el.name === "attachment[]") return "Attachments";
        var lab = el.closest(".col-md-6, .col-md-4, .col-md-8, .col-12, .col-md-5, .col-md-3");
        if (lab) {
            var l = lab.querySelector("label.form-label, h6");
            if (l) return l.textContent.trim().replace(/\*$/, "").trim() || "This field";
        }
        return el.name ? el.name.replace(/_/g, " ") : "This field";
    }

    /* ---------- fix duplicated heading (non-breaking) ---------- */
    try {
        var shTitles = $all(".sh-title", form).filter(function (el) {
            return el.textContent.trim() === "Requestor Information";
        });
        if (shTitles.length > 1) {
            shTitles[1].textContent = "Additional Details & Attachments";
            var sub = shTitles[1].parentElement ? shTitles[1].parentElement.querySelector(".sh-subtitle") : null;
            if (sub) sub.textContent = "Notes, supporting files and signature to complete your submission.";
        }
    } catch (e) {}

    /* ---------- locate panels (no DOM moves — just show/hide) ---------- */
    var cards = Array.prototype.filter.call(form.children, function (el) {
        return el.tagName === "SECTION" && el.classList.contains("clean-card");
    });
    if (cards.length < 3) return;
    var firstCard = cards[0], specCard = cards[1], finishCard = cards[2];
    var stacks = Array.prototype.filter.call(firstCard.children, function (el) {
        return el.classList && el.classList.contains("stack-section");
    });
    if (stacks.length < 2) return;
    var p1 = stacks[0], p2 = stacks[1], p3 = specCard, p4 = finishCard;
    var panels = [p1, p2, p3, p4];

    form.classList.add("wizard-on");
    form.setAttribute("novalidate", "novalidate");
    panels.forEach(function (p) { p.classList.add("wizard-panel"); });

    /* ---------- service card upgrade (icons + desc, keep inputs) ---------- */
    var svcMeta = {
        "Graphic Design": { icon: "bi-palette", desc: "Posters, tarps, brochures & branding" },
        "Video Production": { icon: "bi-camera-video", desc: "Ads, documentaries, promos & edits" },
        "Photo/Video Coverage": { icon: "bi-camera", desc: "Events, documentation & shoots" },
        "IEC Materials": { icon: "bi-folder2-open", desc: "Handouts, guides & health materials" }
    };
    $all(".request-card", form).forEach(function (card) {
        var input = card.querySelector("input.request-type");
        var val = input ? input.value : "";
        var meta = svcMeta[val] || { icon: "bi-grid", desc: "" };
        if (!card.querySelector(".rc-icon")) {
            var ic = document.createElement("div");
            ic.className = "rc-icon";
            ic.innerHTML = '<i class="bi ' + meta.icon + '"></i>';
            card.insertBefore(ic, card.firstChild);
        }
        var copy = card.querySelector(".request-card-copy");
        if (copy && !copy.querySelector(".rc-desc") && meta.desc) {
            var d = document.createElement("span");
            d.className = "rc-desc";
            d.textContent = meta.desc;
            copy.appendChild(d);
        }
        if (copy) {
            var sm = copy.querySelector("small");
            if (sm) sm.classList.add("lead-pill");
        }
        if (!card.querySelector(".rc-check")) {
            var chk = document.createElement("span");
            chk.className = "rc-check";
            chk.innerHTML = '<i class="bi bi-check-lg"></i>';
            card.appendChild(chk);
        }
    });

    /* ---------- build wizard shell ---------- */
    var steps = [
        { t: "Requestor", s: "Contact & dates" },
        { t: "Job Details", s: "Service & project" },
        { t: "Specifications", s: "Service options" },
        { t: "Review & Submit", s: "Check & send" }
    ];
    var shell = document.createElement("div");
    shell.className = "wizard-shell";
    shell.innerHTML =
        '<div class="wizard-progress" aria-hidden="true"><div class="wizard-progress-bar"></div></div>' +
        '<ol class="wizard-steps" aria-label="Form progress"></ol>' +
        '<div class="wizard-meta"><span class="step-indicator">Step 1 of 4</span>' +
        '<span class="draft-status"><span class="draft-dot"></span><span class="draft-text">No unsaved draft</span></span></div>' +
        '<div class="draft-banner" role="status"></div>';
    form.insertBefore(shell, form.firstChild);
    var stepsOl = shell.querySelector(".wizard-steps");
    steps.forEach(function (st, i) {
        var li = document.createElement("li");
        li.className = "wizard-step";
        li.innerHTML = '<button type="button" class="wizard-step-btn" data-goto="' + (i + 1) + '">' +
            '<span class="wizard-step-num">' + (i + 1) + '</span>' +
            '<span class="wizard-step-copy"><strong>' + esc(st.t) + '</strong><small>' + esc(st.s) + '</small></span></button>';
        stepsOl.appendChild(li);
    });
    var draftBanner = shell.querySelector(".draft-banner");

    /* per-panel error summary */
    panels.forEach(function (p, i) {
        var box = document.createElement("div");
        box.className = "wizard-error-summary";
        box.setAttribute("role", "alert");
        box.dataset.panel = String(i + 1);
        p.insertBefore(box, p.firstChild);
    });

    /* ---------- wizard footer ---------- */
    var footer = document.createElement("div");
    footer.className = "wizard-footer";
    footer.innerHTML =
        '<button type="button" class="btn btn-outline-secondary btn-back">&larr; Back</button>' +
        '<span class="step-count">Step 1 of 4</span><span class="spacer"></span>' +
        '<a class="btn btn-outline-secondary btn-cancel" href="' + (document.querySelector(".request-actions a.btn") ? document.querySelector(".request-actions a.btn").getAttribute("href") : "my_requests.php") + '">Cancel</a>' +
        '<button type="button" class="btn btn-primary btn-next">Continue &rarr;</button>' +
        '<button type="submit" class="btn btn-primary btn-submit" style="display:none;" disabled><i class="bi bi-check2-circle me-1"></i>Save Changes</button>';
    form.appendChild(footer);
    var backBtn = footer.querySelector(".btn-back");
    var nextBtn = footer.querySelector(".btn-next");
    var submitBtn = footer.querySelector(".btn-submit");
    var stepCount = footer.querySelector(".step-count");
    var progressBar = shell.querySelector(".wizard-progress-bar");
    var stepIndicator = shell.querySelector(".step-indicator");

    var current = 1;
    var maxReached = 1;

    function panelErrorsBox(n) { return panels[n - 1].querySelector(".wizard-error-summary"); }
    function showErrors(n, items) {
        var box = panelErrorsBox(n);
        panels[n - 1].querySelectorAll(".field-invalid").forEach(function (el) { el.classList.remove("field-invalid"); });
        panels[n - 1].querySelectorAll(".request-card-invalid").forEach(function (el) { el.classList.remove("request-card-invalid"); });
        if (!items.length) { box.classList.remove("show"); box.innerHTML = ""; return; }
        box.innerHTML = "<strong>Please fix " + items.length + " item" + (items.length > 1 ? "s" : "") + " to continue:</strong><ul>" +
            items.map(function (it, idx) { return '<li><a data-err="' + idx + '">' + esc(it.label) + " — " + esc(it.msg) + "</a></li>"; }).join("") + "</ul>";
        box.classList.add("show");
        items.forEach(function (it) {
            if (it.el) {
                if (it.el.classList && it.el.classList.contains("request-card")) it.el.classList.add("request-card-invalid");
                else it.el.classList.add("field-invalid");
            }
        });
        $all("a[data-err]", box).forEach(function (a) {
            a.addEventListener("click", function () {
                var it = items[+a.getAttribute("data-err")];
                if (it && it.el && it.el.focus) {
                    try { it.el.focus({ preventScroll: false }); } catch (e) { it.el.focus(); }
                    it.el.scrollIntoView({ behavior: "smooth", block: "center" });
                }
            });
        });
    }

    function selectedService() {
        var r = form.querySelector(".request-type:checked");
        return r ? r.value : "";
    }

    function validateStep(n) {
        var items = [];
        function req(el, msg) {
            if (!el || el.disabled) return;
            var v = (el.value || "").trim();
            if (!v) items.push({ el: el, label: fieldLabel(el), msg: msg || "required" });
        }
        if (n === 1) {
            req(form.querySelector('[name="requestor_name"]'));
            req(form.querySelector('[name="department"]'), "select a department");
            req(form.querySelector('[name="contact"]'));
            req(form.querySelector('#date_request, [name="date_request"]'));
        } else if (n === 2) {
            var svc = form.querySelector(".request-type:checked");
            if (!svc) {
                var firstCardEl = form.querySelector(".request-card");
                items.push({ el: firstCardEl, label: "Type of request", msg: "select one service" });
            }
            req(form.querySelector('[name="project_title"]'));
            req(form.querySelector('[name="purpose_objective"]'));
            var naEv = form.querySelector('[name="event_not_applicable"]');
            var evF = form.querySelector('[name="event_datetime"]');
            var evVisible = form.querySelector('#event_start') || evF;
            if (naEv && !naEv.checked && evF && !evF.disabled && !(evF.value || "").trim())
                items.push({ el: evVisible, label: "Event date & time", msg: "provide a date or mark N/A" });
            if (naEv && !naEv.checked && evF && !evF.disabled && (evF.value || "").trim() && typeof validateEventRange === "function" && !validateEventRange())
                items.push({ el: evVisible, label: "Event date & time", msg: "end must not be earlier than start" });
            var naV = form.querySelector('[name="venue_not_applicable"]');
            var vF = form.querySelector('[name="venue_location"]');
            if (naV && !naV.checked && vF && !vF.disabled && !(vF.value || "").trim())
                items.push({ el: vF, label: "Venue / location", msg: "provide a venue or mark N/A" });
            var naS = form.querySelector('[name="send_to_not_applicable"]');
            var sF = form.querySelector('[name="send_to"]');
            if (naS && !naS.checked && sF && !sF.disabled && !(sF.value || "").trim())
                items.push({ el: sF, label: "Send them to", msg: "provide a destination or mark N/A" });
        } else if (n === 3) {
            var s = selectedService();
            if (!s) {
                items.push({ el: form.querySelector(".request-card"), label: "Specifications", msg: "go back and select a service first" });
            } else if (s === "Graphic Design") {
                if (!form.querySelectorAll('input[name="graphic_design_types[]"]:checked').length)
                    items.push({ el: $("#graphicSection"), label: "Type of design", msg: "select at least one" });
                if (!form.querySelectorAll('input[name="graphic_colors[]"]:checked').length)
                    items.push({ el: $("#graphicSection"), label: "Color", msg: "select at least one" });
                if (!form.querySelectorAll('input[name="publication_methods[]"]:checked').length)
                    items.push({ el: $("#graphicSection"), label: "Publication method", msg: "select at least one" });
                var gq = form.querySelector('[name="graphic_quantity"]');
                if (gq && !gq.disabled && (!gq.value || +gq.value < 1))
                    items.push({ el: gq, label: "Number of pcs", msg: "enter at least 1" });
            } else if (s === "Video Production") {
                if (!form.querySelectorAll('input[name="video_types[]"]:checked').length)
                    items.push({ el: $("#videoSection"), label: "Type of video", msg: "select at least one" });
                if (!form.querySelectorAll('input[name="video_formats[]"]:checked').length)
                    items.push({ el: $("#videoSection"), label: "Video format", msg: "select at least one" });
            } else if (s === "Photo/Video Coverage") {
                var ph = form.querySelector('[name="photographers_needed"]');
                if (ph && !ph.disabled && (!ph.value || +ph.value < 1))
                    items.push({ el: ph, label: "Photographers needed", msg: "enter at least 1" });
                if (!form.querySelectorAll('input[name="coverage_formats[]"]:checked').length)
                    items.push({ el: $("#coverageSection"), label: "Image / video format", msg: "select at least one" });
            } else if (s === "IEC Materials") {
                if (!form.querySelectorAll('input[name="iec_selected[]"]:checked').length)
                    items.push({ el: $("#iecSection"), label: "IEC materials", msg: "select at least one item" });
            }
        } else if (n === 4) {
            req(form.querySelector('[name="request_details"]'), "required");
            var attEl = document.getElementById("attachmentInput");
            var attCount = 0;
            try { attCount = attEl && attEl.files ? attEl.files.length : 0; } catch (e) {}
            var keptCount = 0;
            try { keptCount = form.querySelectorAll('input[name="keep_attachments[]"]:checked').length; } catch (e) {}
            if (!attCount && !keptCount)
                items.push({ el: attEl, label: "Attachments", msg: "keep at least 1 file or attach a new one" });
        }
        showErrors(n, items);
        var stepLi = stepsOl.children[n - 1];
        if (stepLi) stepLi.classList.toggle("is-invalid", items.length > 0);
        return items.length === 0;
    }

    function markDoneUpTo(n) {
        for (var i = 1; i <= 4; i++) {
            var li = stepsOl.children[i - 1];
            if (!li) continue;
            li.classList.toggle("is-done", i < n || (i <= maxReached && i !== current && !li.classList.contains("is-invalid")));
        }
    }

    function goTo(n, opts) {
        opts = opts || {};
        if (n < 1) n = 1;
        if (n > 4) n = 4;
        if (n > current && !opts.skipValidation) {
            for (var s = current; s < n; s++) {
                if (!validateStep(s)) { goTo(s, { skipValidation: true }); return false; }
            }
        }
        current = n;
        maxReached = Math.max(maxReached, n);
        panels.forEach(function (p, i) { p.classList.toggle("is-active", i === n - 1); });
        $all(".wizard-step", stepsOl).forEach(function (li, i) {
            li.classList.toggle("is-active", i === n - 1);
            var b = li.querySelector("button");
            if (b) { if (i === n - 1) b.setAttribute("aria-current", "step"); else b.removeAttribute("aria-current"); }
        });
        markDoneUpTo(n);
        form.dataset.wstep = String(n);
        backBtn.style.visibility = n === 1 ? "hidden" : "visible";
        nextBtn.style.display = n === 4 ? "none" : "";
        submitBtn.style.display = n === 4 ? "" : "none";
        stepCount.textContent = "Step " + n + " of 4";
        stepIndicator.textContent = "Step " + n + " of 4 — " + steps[n - 1].t;
        progressBar.style.width = (n / 4 * 100) + "%";
        showErrors(n, []);
        if (n === 4) buildReview();
        updateProgress();
        try {
            shell.scrollIntoView({ behavior: opts.instant ? "auto" : "smooth", block: "start" });
        } catch (e) { window.scrollTo(0, 0); }
        var h = panels[n - 1].querySelector(".sh-title");
        if (h && !opts.noFocus) { h.setAttribute("tabindex", "-1"); try { h.focus({ preventScroll: true }); } catch (e) {} }
        return true;
    }

    $all(".wizard-step-btn", stepsOl).forEach(function (btn) {
        btn.addEventListener("click", function () {
            var target = +btn.getAttribute("data-goto");
            if (target <= current) goTo(target);
            else goTo(target);
        });
    });
    backBtn.addEventListener("click", function () { goTo(current - 1, { skipValidation: true }); });
    nextBtn.addEventListener("click", function () {
        if (!validateStep(current)) {
            panels[current - 1].classList.remove("shake");
            void panels[current - 1].offsetWidth;
            panels[current - 1].classList.add("shake");
            var box = panelErrorsBox(current);
            if (box) box.scrollIntoView({ behavior: "smooth", block: "center" });
            return;
        }
        goTo(current + 1);
    });

    /* Enter-to-continue on steps 1–3 (not in textarea / buttons) */
    form.addEventListener("keydown", function (ev) {
        if (ev.key !== "Enter") return;
        if (current === 4) return;
        var t = ev.target;
        if (!t) return;
        var tag = (t.tagName || "").toUpperCase();
        if (tag === "TEXTAREA" || tag === "BUTTON" || tag === "SELECT") return;
        if (t.type === "submit" || t.type === "file") return;
        ev.preventDefault();
        nextBtn.click();
    });

    /* ---------- progress (required-field completion) ---------- */
    function updateProgress() {
        var reqEls = $all("[required]", form).filter(function (el) {
            if (el.disabled) return false;
            if (el.type === "hidden") return false;
            if (el.closest(".service-section") && !el.closest(".service-section").classList.contains("show")) {
                var sec = el.closest(".service-section");
                if (sec && getComputedStyle(sec).display === "none") return false;
            }
            return true;
        });
        var filled = reqEls.filter(function (el) {
            if (el.type === "checkbox" || el.type === "radio") {
                var grp = form.querySelectorAll('input[name="' + el.name + '"]');
                return Array.prototype.some.call(grp, function (g) { return g.checked; });
            }
            return (el.value || "").trim() !== "";
        }).length;
        var pct = reqEls.length ? Math.round(filled / reqEls.length * 100) : 0;
        var dt = shell.querySelector(".draft-text");
        if (dt && !dt.dataset.locked) dt.textContent = pct === 100 ? "Ready to submit" : pct + "% complete";
        updateSubmitButtonState();
    }
    function isFormComplete() {
        function valOf(sel) {
            var el = form.querySelector(sel);
            return el ? (el.value || "").trim() : "";
        }

        // Step 1: Requestor Information
        if (!valOf('[name="requestor_name"]')) return false;
        if (!valOf('[name="department"]')) return false;
        if (!valOf('[name="contact"]')) return false;
        if (!valOf('#date_request, [name="date_request"]')) return false;

        // Step 2: Job Details
        var svcRadio = form.querySelector(".request-type:checked");
        if (!svcRadio) return false;
        if (!valOf('[name="project_title"]')) return false;
        if (!valOf('[name="purpose_objective"]')) return false;

        var naEv = form.querySelector('[name="event_not_applicable"]');
        if (!naEv || !naEv.checked) {
            var evVal = valOf('[name="event_datetime"]') || valOf('#event_start');
            if (!evVal) return false;
            var validateEv = typeof window.validateEventRange === "function" ? window.validateEventRange : (typeof validateEventRange === "function" ? validateEventRange : null);
            if (validateEv && !validateEv()) return false;
        }

        var naV = form.querySelector('[name="venue_not_applicable"]');
        if (!naV || !naV.checked) {
            if (!valOf('[name="venue_location"]')) return false;
        }

        var naS = form.querySelector('[name="send_to_not_applicable"]');
        if (!naS || !naS.checked) {
            if (!valOf('[name="send_to"]')) return false;
        }

        // Step 3: Service Specifications
        var s = svcRadio.value;
        if (s === "Graphic Design") {
            var gTypes = form.querySelectorAll('input[name="graphic_design_types[]"]:checked');
            if (!gTypes.length) return false;
            var hasOtherGType = Array.prototype.some.call(gTypes, function (t) { return t.value === "Others"; });
            if (hasOtherGType && !valOf('#graphicOther')) return false;

            var gColors = form.querySelectorAll('input[name="graphic_colors[]"]:checked');
            if (!gColors.length) return false;

            var pubMethods = form.querySelectorAll('input[name="publication_methods[]"]:checked');
            if (!pubMethods.length) return false;
            var hasOtherPub = Array.prototype.some.call(pubMethods, function (p) { return p.value === "Others"; });
            if (hasOtherPub && !valOf('#publicationOther')) return false;

            var gq = form.querySelector('[name="graphic_quantity"]');
            if (!gq || gq.disabled || !gq.value || +gq.value < 1) return false;
        } else if (s === "Video Production") {
            var vTypes = form.querySelectorAll('input[name="video_types[]"]:checked');
            if (!vTypes.length) return false;
            var hasOtherVt = Array.prototype.some.call(vTypes, function (v) { return v.value === "Others"; });
            if (hasOtherVt && !valOf('#videoTypeOther')) return false;

            var vFormats = form.querySelectorAll('input[name="video_formats[]"]:checked');
            if (!vFormats.length) return false;
            var hasOtherVf = Array.prototype.some.call(vFormats, function (v) { return v.value === "Others"; });
            if (hasOtherVf && !valOf('#videoFormatOther')) return false;
        } else if (s === "Photo/Video Coverage") {
            var ph = form.querySelector('[name="photographers_needed"]');
            if (!ph || ph.disabled || !ph.value || +ph.value < 1) return false;

            var cFormats = form.querySelectorAll('input[name="coverage_formats[]"]:checked');
            if (!cFormats.length) return false;
            var hasOtherCf = Array.prototype.some.call(cFormats, function (c) { return c.value === "Others"; });
            if (hasOtherCf && !valOf('#coverageOther')) return false;
        } else if (s === "IEC Materials") {
            var iecChecked = form.querySelectorAll('input[name="iec_selected[]"]:checked');
            if (!iecChecked.length) return false;

            for (var i = 0; i < iecChecked.length; i++) {
                var box = iecChecked[i];
                var row = box.closest('.iec-row');
                if (row) {
                    var qtyEl = row.querySelector('input[type="number"]');
                    if (qtyEl && !qtyEl.disabled && (!qtyEl.value || +qtyEl.value < 1)) return false;
                    var nameEl = row.querySelector('.iec-other-name');
                    if (nameEl && !(nameEl.value || "").trim()) return false;
                }
            }
        }

        // Step 4: Request Details & Attachments
        if (!valOf('[name="request_details"]')) return false;

        var attInput = document.getElementById("attachmentInput");
        var attCount = (attInput && attInput.files) ? attInput.files.length : 0;
        var keptCount = 0;
        try {
            keptCount = form.querySelectorAll('input[name="keep_attachments[]"]:checked').length;
        } catch (e) {}
        if (!attCount && !keptCount) return false;

        var compField = document.getElementById("date_completion");
        if (compField && compField.value && compField.classList.contains("availability-blocked")) {
            return false;
        }

        return true;
    }

    function updateSubmitButtonState() {
        var complete = isFormComplete();
        var submitBtns = form.querySelectorAll('button[type="submit"], .btn-submit');
        submitBtns.forEach(function (btn) {
            btn.disabled = !complete;
            if (complete) {
                btn.classList.remove("disabled");
            } else {
                btn.classList.add("disabled");
            }
        });
    }
    window.updateSubmitButtonState = updateSubmitButtonState;

    form.addEventListener("input", function () { updateProgress(); scheduleSave(); });
    form.addEventListener("change", function () { updateProgress(); scheduleSave(); markDoneUpTo(current); });

    /* ---------- character counters ---------- */
    var counters = [
        { name: "project_title", soft: 120 },
        { name: "purpose_objective", soft: 1000 },
        { name: "request_details", soft: 2000 },
        { name: "specific_shots", soft: 7000 }
    ];
    counters.forEach(function (c) {
        var el = form.querySelector('[name="' + c.name + '"]');
        if (!el || el.parentElement.querySelector(".char-counter")) return;
        var div = document.createElement("div");
        div.className = "char-counter";
        el.parentElement.appendChild(div);
        function upd() {
            var len = (el.value || "").length;
            div.textContent = len.toLocaleString() + " characters";
            div.classList.toggle("warn", len > c.soft * 0.85 && len <= c.soft);
            div.classList.toggle("over", len > c.soft);
            if (len > c.soft) div.textContent += " • consider shortening";
        }
        el.addEventListener("input", upd);
        upd();
    });

    /* ---------- attachment dropzone ---------- */
    try {
        var attInput = document.getElementById("attachmentInput");
        if (attInput && !attInput.closest(".dropzone")) {
            var dz = document.createElement("div");
            dz.className = "dropzone";
            attInput.parentElement.insertBefore(dz, attInput);
            dz.appendChild(attInput);
            var hint = document.createElement("div");
            hint.className = "dropzone-hint";
            hint.innerHTML = '<i class="bi bi-cloud-arrow-up"></i><div>Drag &amp; drop files here or click to browse<small>Up to 10 files • PDF, images, Office, ZIP • 10 MB each</small></div>';
            dz.appendChild(hint);
            ["dragenter", "dragover"].forEach(function (e2) {
                dz.addEventListener(e2, function (e) { e.preventDefault(); dz.classList.add("dragover"); });
            });
            ["dragleave", "drop"].forEach(function (e2) {
                dz.addEventListener(e2, function (e) { e.preventDefault(); dz.classList.remove("dragover"); });
            });
        }
    } catch (e) {}

    /* ---------- earliest-date pill mirror ---------- */
    try {
        var leadStrong = document.querySelector("#leadTimeSummary strong");
        var compWrap = document.getElementById("date_completion");
        if (leadStrong && compWrap && !document.getElementById("earliestPill")) {
            var pill = document.createElement("div");
            pill.className = "earliest-pill";
            pill.id = "earliestPill";
            compWrap.parentElement.appendChild(pill);
            function syncPill() { pill.textContent = leadStrong.textContent || ""; }
            new MutationObserver(syncPill).observe(leadStrong, { childList: true, characterData: true, subtree: true });
            syncPill();
        }
    } catch (e) {}

    /* ---------- draft autosave ---------- */
    var DKEY = "hpu_edit_request_draft_v1"; try { var eidEl = form.querySelector('input[name="edit_id"]'); if (eidEl && eidEl.value) DKEY += "_" + eidEl.value; } catch (e) {}
    var dot = shell.querySelector(".draft-dot");
    var dtext = shell.querySelector(".draft-text");
    var saveT = null;
    function collectDraft() {
        var data = {};
        $all("input, select, textarea", form).forEach(function (el) {
            if (!el.name || el.type === "file" || el.disabled) return;
            if (el.type === "checkbox" || el.type === "radio") data[el.name + "||" + el.value] = el.checked;
            else data[el.name] = el.value;
        });
        return data;
    }
    function scheduleSave() {
        if (dot) dot.classList.remove("saved");
        clearTimeout(saveT);
        saveT = setTimeout(function () {
            try {
                localStorage.setItem(DKEY, JSON.stringify({ at: Date.now(), data: collectDraft() }));
                if (dot) dot.classList.add("saved");
                if (dtext) { dtext.textContent = "Draft saved • " + new Date().toLocaleTimeString([], { hour: "numeric", minute: "2-digit" }); dtext.dataset.locked = "1"; setTimeout(function(){ delete dtext.dataset.locked; updateProgress(); }, 4000); }
            } catch (e) {}
        }, 800);
    }
    function isPristine() {
        var t = form.querySelector('[name="project_title"]');
        var p = form.querySelector('[name="purpose_objective"]');
        return !(t && t.value) && !(p && p.value) && !form.querySelector(".request-type:checked");
    }
    try {
        var raw = localStorage.getItem(DKEY);
        if (raw && isPristine()) {
            var parsed = JSON.parse(raw);
            if (parsed && parsed.data) {
                draftBanner.innerHTML = '<i class="bi bi-history mt-1"></i><div style="flex:1;"><strong>Unsaved draft found</strong> from ' +
                    esc(new Date(parsed.at).toLocaleString()) + '. Restore it to continue where you left off.</div>' +
                    '<button type="button" class="btn btn-sm btn-primary" data-act="restore">Restore</button>' +
                    '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="discard">Discard</button>';
                draftBanner.classList.add("show");
                draftBanner.querySelector('[data-act="restore"]').addEventListener("click", function () {
                    Object.keys(parsed.data).forEach(function (k) {
                        if (k.indexOf("||") !== -1) {
                            var parts = k.split("||");
                            var box = form.querySelector('input[name="' + parts[0] + '"][value="' + parts[1] + '"]');
                            if (box) box.checked = !!parsed.data[k];
                        } else {
                            var el = form.querySelector('[name="' + k + '"]');
                            if (el && el.type !== "file") el.value = parsed.data[k];
                        }
                    });
                    $all("input, select", form).forEach(function (el) {
                        try { el.dispatchEvent(new Event("change", { bubbles: true })); } catch (e) {}
                    });
                    draftBanner.classList.remove("show");
                    updateProgress();
                    goTo(1, { instant: true, noFocus: true });
                });
                draftBanner.querySelector('[data-act="discard"]').addEventListener("click", function () {
                    try { localStorage.removeItem(DKEY); } catch (e) {}
                    draftBanner.classList.remove("show");
                });
            }
        }
    } catch (e) {}
    form.addEventListener("submit", function (ev) {
        for (var s = 1; s <= 4; s++) {
            if (!validateStep(s)) {
                try { if (ev && ev.preventDefault) ev.preventDefault(); } catch (e) {}
                goTo(s, { skipValidation: true });
                return false;
            }
        }
        try { localStorage.removeItem(DKEY); } catch (e) {}
    });

    /* ---------- review builder ---------- */
    function val(name) {
        var el = form.querySelector('[name="' + name + '"]');
        return el ? (el.value || "").trim() : "";
    }
    function checkedVals(name) {
        return $all('input[name="' + name + '"]:checked', form).map(function (b) { return b.value; });
    }
    function naState(base) {
        var c = form.querySelector('[name="' + base + '_not_applicable"]');
        return c && c.checked;
    }
    function reviewRow(label, value, full) {
        var empty = !value;
        return '<div class="review-item' + (full ? " full" : "") + '"><small>' + esc(label) + '</small><div class="' + (empty ? "review-empty" : "") + '">' + (empty ? "—" : esc(value)) + "</div></div>";
    }
    function reviewRowHtml(label, html, full) {
        var empty = !html;
        return '<div class="review-item' + (full ? " full" : "") + '"><small>' + esc(label) + '</small><div class="' + (empty ? "review-empty" : "") + '">' + (empty ? "—" : html) + "</div></div>";
    }
    function buildReview() {
        var old = p4.querySelector(".review-stack");
        if (old) old.remove();
        var svc = selectedService();
        var stack = document.createElement("div");
        stack.className = "review-stack";
        var h = "";
        h += '<div class="review-card"><div class="review-head"><h5><i class="bi bi-person-vcard"></i>Requestor</h5><button type="button" class="review-edit" data-edit="1">Edit</button></div><div class="review-grid">' +
            reviewRow("Requestor", val("requestor_name")) + reviewRow("Department", val("department")) +
            reviewRow("Contact", val("contact")) + reviewRow("Date requested", val("date_request")) +
            reviewRowHtml("Preferred completion", val("date_completion") ? esc(val("date_completion")) : '<span class="not-set-highlight"><i class="bi bi-exclamation-circle me-1"></i>(not set)</span>') + "</div></div>";
        var ev = naState("event") ? "Marked non-applicable" : ((val("event_datetime") || "—").replace(" to ", " → "));
        var vn = naState("venue") ? "Marked non-applicable" : (val("venue_location") || "—");
        var st = naState("send_to") ? "Marked non-applicable" : (val("send_to") || "—");
        h += '<div class="review-card"><div class="review-head"><h5><i class="bi bi-ui-checks-grid"></i>Job details — ' + esc(svc || "no service selected") + '</h5><button type="button" class="review-edit" data-edit="2">Edit</button></div><div class="review-grid">' +
            reviewRow("Title", val("project_title"), true) + reviewRow("Purpose", val("purpose_objective"), true) +
            reviewRow("Event", ev) + reviewRow("Venue", vn) + reviewRow("Send to", st) + "</div></div>";
        var spec = "";
        if (svc === "Graphic Design") {
            var gTypes = checkedVals("graphic_design_types[]");
            var gColors = checkedVals("graphic_colors[]");
            if (gTypes.length) {
                spec += '<div class="review-chips">' + gTypes.map(function (v) { return '<span class="review-chip">' + esc(v) + "</span>"; }).join("") + "</div>";
            }
            if (gColors.length) {
                spec += '<div class="review-chips">' + gColors.map(function (v) { return '<span class="review-chip">' + esc(v) + "</span>"; }).join("") + "</div>";
            }
            var gQty = val("graphic_quantity");
            if (gQty) {
                spec += '<div>Number of pcs: ' + esc(gQty) + "</div>";
            }
        } else if (svc === "Video Production") {
            var vTypes = checkedVals("video_types[]");
            if (vTypes.length) {
                spec = '<div class="review-chips">' + vTypes.map(function (v) { return '<span class="review-chip">' + esc(v) + "</span>"; }).join("") + "</div>";
            }
        } else if (svc === "Photo/Video Coverage") {
            var cFmts = checkedVals("coverage_formats[]");
            var cHtml = "Photographers: " + esc(val("photographers_needed") || "—");
            if (cFmts.length) {
                cHtml += '<div class="review-chips">' + cFmts.map(function (v) { return '<span class="review-chip">' + esc(v) + "</span>"; }).join("") + "</div>";
            }
            spec = cHtml;
        } else if (svc === "IEC Materials") {
            var iecVals = checkedVals("iec_selected[]");
            if (iecVals.length) {
                spec = '<div class="review-chips">' + iecVals.map(function (v) { return '<span class="review-chip">' + esc(v) + "</span>"; }).join("") + "</div>";
            }
        }
        var attCount = 0;
        try { var ai = document.getElementById("attachmentInput"); attCount = ai && ai.files ? ai.files.length : 0; } catch (e) {}
        var keptCount = 0;
        try { keptCount = form.querySelectorAll('input[name="keep_attachments[]"]:checked').length; } catch (e) {}
        var attLabel = "No files";
        if (keptCount && attCount) attLabel = keptCount + " kept + " + attCount + " new file(s)";
        else if (keptCount) attLabel = keptCount + " file(s) kept";
        else if (attCount) attLabel = attCount + " file(s) selected";
        h += '<div class="review-card"><div class="review-head"><h5><i class="bi bi-card-checklist"></i>Specifications &amp; files</h5><button type="button" class="review-edit" data-edit="3">Edit</button></div><div class="review-grid">' +
            reviewRowHtml("Service options", spec, true) +
            reviewRow("Details", val("request_details") || "(none)", true) +
            reviewRow("Attachments", attLabel) +
            reviewRow("Signature date", val("requestor_signature_date")) + "</div></div>";
        stack.innerHTML = h;
        var anchor = p4.querySelector(".sh");
        if (anchor) anchor.parentElement.insertBefore(stack, anchor.nextSibling);
        else p4.insertBefore(stack, p4.firstChild);
        $all(".review-edit", stack).forEach(function (b) {
            b.addEventListener("click", function () { goTo(+b.getAttribute("data-edit")); });
        });
    }

    /* ---------- searchable office picker ---------- */
    /* Progressive enhancement: the server-rendered select stays the
       single source of truth (same name + POST contract). The picker
       only makes all offices findable and selectable. */
    (function initDeptPicker() {
        var sel = form.querySelector('select[name="department"]');
        if (!sel || sel.dataset.pickerReady) return;
        sel.dataset.pickerReady = "1";

        var offices = [];
        Array.prototype.slice.call(sel.options).forEach(
            function (opt) {
                if (opt.value !== "") {
                    offices.push({
                        value: opt.value,
                        label: opt.textContent.trim()
                    });
                }
            }
        );
        if (!offices.length) return;

        var sessionOffice = sel.value || "";
        var picked = sel.value || "";

        sel.classList.add("dept-picker-select-hidden");

        var wrap = document.createElement("div");
        wrap.className = "dept-picker";
        sel.parentElement.insertBefore(wrap, sel.nextSibling);

        var input = document.createElement("input");
        input.type = "text";
        input.className = "form-control dept-picker-input";
        input.setAttribute("placeholder", "Type to search " + offices.length + " offices…");
        input.setAttribute("autocomplete", "off");
        input.setAttribute("role", "combobox");
        input.setAttribute("aria-expanded", "false");
        input.setAttribute("aria-label", "Department or office");
        wrap.appendChild(input);

        var toggle = document.createElement("button");
        toggle.type = "button";
        toggle.className = "dept-picker-toggle";
        toggle.title = "Show all offices";
        toggle.setAttribute("aria-label", "Show all offices");
        toggle.innerHTML = '<i class="bi bi-chevron-down"></i>';
        wrap.appendChild(toggle);

        var list = document.createElement("ul");
        list.className = "dept-picker-list";
        list.setAttribute("role", "listbox");
        wrap.appendChild(list);

        var hint = document.createElement("div");
        hint.className = "dept-picker-hint";
        hint.textContent = "Type to filter, or open the list. Defaults to your office — pick any office.";
        wrap.appendChild(hint);

        var highlightIdx = -1;

        function labelOf(value) {
            for (var i = 0; i < offices.length; i++) {
                if (offices[i].value === value) {
                    return offices[i].label;
                }
            }
            return "";
        }

        function setPicked(value, silent) {
            picked = value;
            sel.value = value;
            input.value = labelOf(value);
            if (!silent) {
                try {
                    sel.dispatchEvent(
                        new Event("change", { bubbles: true })
                    );
                } catch (e) {}
            }
            renderList(input.value);
        }

        function renderList(filter) {
            var q = (filter || "").trim().toLowerCase();
            list.innerHTML = "";
            highlightIdx = -1;
            var shown = 0;
            offices.forEach(function (o) {
                if (q && o.label.toLowerCase().indexOf(q) === -1) {
                    return;
                }
                shown++;
                var li = document.createElement("li");
                li.setAttribute("role", "option");
                li.dataset.value = o.value;
                if (o.value === picked) {
                    li.classList.add("picked");
                    li.setAttribute("aria-selected", "true");
                }
                var tick = document.createElement("i");
                tick.className = "bi bi-check-lg tick";
                li.appendChild(tick);
                var txt = document.createElement("span");
                txt.textContent = o.label;
                li.appendChild(txt);
                if (o.value === sessionOffice) {
                    var badge = document.createElement("span");
                    badge.className = "me-badge";
                    badge.textContent = "My office";
                    li.appendChild(badge);
                }
                li.addEventListener("mousedown", function (ev) {
                    ev.preventDefault();
                    setPicked(o.value);
                    closeList();
                });
                list.appendChild(li);
            });
            if (!shown) {
                var empty = document.createElement("li");
                empty.className = "no-match";
                empty.textContent =
                    "No office matches — try another keyword.";
                list.appendChild(empty);
            }
        }

        function openList() {
            renderList(input.value);
            list.classList.add("show");
            input.setAttribute("aria-expanded", "true");
        }

        function closeList() {
            list.classList.remove("show");
            input.setAttribute("aria-expanded", "false");
            highlightIdx = -1;
        }

        function isOpen() {
            return list.classList.contains("show");
        }

        function moveHighlight(dir) {
            var items = Array.prototype.slice.call(
                list.querySelectorAll("li:not(.no-match)")
            );
            if (!items.length) return;
            highlightIdx =
                (highlightIdx + dir + items.length) %
                items.length;
            items.forEach(function (li, i) {
                li.classList.toggle(
                    "highlight", i === highlightIdx
                );
            });
            var active = items[highlightIdx];
            if (active && active.scrollIntoView) {
                active.scrollIntoView({ block: "nearest" });
            }
        }

        input.value = labelOf(picked);

        input.addEventListener("focus", function () {
            renderList("");
            list.classList.add("show");
            input.setAttribute("aria-expanded", "true");
        });

        input.addEventListener("input", function () {
            openList();
        });

        toggle.addEventListener("click", function () {
            if (isOpen()) {
                closeList();
                return;
            }
            renderList("");
            list.classList.add("show");
            input.setAttribute("aria-expanded", "true");
            input.focus();
        });

        input.addEventListener("keydown", function (ev) {
            if (ev.key === "ArrowDown") {
                ev.preventDefault();
                if (!isOpen()) openList();
                moveHighlight(1);
            } else if (ev.key === "ArrowUp") {
                ev.preventDefault();
                moveHighlight(-1);
            } else if (ev.key === "Enter") {
                var items = Array.prototype.slice.call(
                    list.querySelectorAll(
                        "li:not(.no-match)"
                    )
                );
                if (isOpen() && items.length) {
                    ev.preventDefault();
                    var target = highlightIdx >= 0
                        ? items[highlightIdx]
                        : items[0];
                    setPicked(target.dataset.value);
                    closeList();
                }
            } else if (ev.key === "Escape") {
                input.value = labelOf(picked);
                closeList();
            }
        });

        /* Commit exact matches, otherwise revert to last valid pick
           so the form can never submit an unknown office. */
        input.addEventListener("blur", function () {
            setTimeout(function () {
                closeList();
                var typed = input.value.trim().toLowerCase();
                if (typed === "") {
                    setPicked("", true);
                    try {
                        sel.dispatchEvent(
                            new Event("change",
                                { bubbles: true })
                        );
                    } catch (e) {}
                    return;
                }
                var exact = null;
                for (var i = 0; i < offices.length; i++) {
                    if (offices[i].label.toLowerCase() ===
                        typed) {
                        exact = offices[i].value;
                        break;
                    }
                }
                if (exact !== null && exact !== picked) {
                    setPicked(exact);
                } else {
                    input.value = labelOf(picked);
                    renderList(picked);
                }
            }, 120);
        });

        document.addEventListener("click", function (ev) {
            if (!wrap.contains(ev.target)) {
                closeList();
            }
        });

        /* Stay in sync when the select changes elsewhere
           (draft restore, validation reset). */
        sel.addEventListener("change", function () {
            if (sel.value !== picked) {
                picked = sel.value;
            }
            input.value = labelOf(picked);
        });

        /* Mirror step-validation highlight onto the visible input. */
        try {
            new MutationObserver(function () {
                input.classList.toggle(
                    "field-invalid-mirror",
                    sel.classList.contains("field-invalid")
                );
            }).observe(sel, { attributes: true });
        } catch (e) {}
    })();

    /* ---------- init ---------- */
    goTo(1, { instant: true, noFocus: true, skipValidation: true });
    updateProgress();
});
</script>

</body>
</html>


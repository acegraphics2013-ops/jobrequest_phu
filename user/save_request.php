<?php

include "../includes/config.php";
include "../includes/auth.php";
include "../includes/helpers.php";
require_once "../includes/module_access.php";
include "../includes/hpu_availability.php";
include "../includes/activity_log.php";


checkLogin();
requireAnyModule($conn, ["new_request", "my_requests"]);

hpuEnsureBlockedDatesTable(
    $conn
);

mysqli_report(
    MYSQLI_REPORT_ERROR |
    MYSQLI_REPORT_STRICT
);

/*
 * Only admin, supervisor and HPU staff may freely input dates.
 * Regular requestors follow lead-time floors, working-day and
 * blocked-date rules, and cannot backdate the request.
 */
$isDatePrivileged = in_array(
    (string)($_SESSION["role"] ?? "user"),
    ["admin", "supervisor", "staff"],
    true
);


/* =========================================================
   OPTIONAL COLUMN HELPER
========================================================= */
function requestColumnExists(
    mysqli $conn,
    string $column
): bool {
    $stmt =
        $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'job_requests'
               AND COLUMN_NAME = ?"
        );

    $stmt->bind_param(
        "s",
        $column
    );

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return
        (int)(
            $row["total"]
            ?? 0
        ) > 0;
}

/*
 * Backward-compatible schema upgrade for HPU Assistance.
 */
if (
    !requestColumnExists(
        $conn,
        "hpu_assistance_required"
    )
) {
    $conn->query(
        "ALTER TABLE job_requests
         ADD COLUMN hpu_assistance_required
            TINYINT(1) NOT NULL DEFAULT 0
         AFTER request_details"
    );
}

if (
    !requestColumnExists(
        $conn,
        "hpu_staff_needed"
    )
) {
    $conn->query(
        "ALTER TABLE job_requests
          ADD COLUMN hpu_staff_needed
             INT NULL
          AFTER hpu_assistance_required"
    );
}

/*
 * Backward-compatible schema upgrade for multi-day events
 * (Event Date & Time ranges, e.g. Sept 7-8). Single-day rows
 * keep event_datetime_end NULL.
 */
if (
    !requestColumnExists(
        $conn,
        "event_datetime_end"
    )
) {
    $conn->query(
        "ALTER TABLE job_requests
          ADD COLUMN event_datetime_end
             DATETIME NULL
          AFTER event_datetime"
    );
}

/*
 * Backward-compatible schema upgrade for IEC request linkage.
 * Links line items to the iec_materials library and tracks
 * fulfilled quantities for the monthly consolidation report.
 */
function iecItemColumnExists(
    mysqli $conn,
    string $column
): bool {
    $stmt =
        $conn->prepare(
            "SELECT COUNT(*) AS total
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'job_request_iec_items'
               AND COLUMN_NAME = ?"
        );

    $stmt->bind_param(
        "s",
        $column
    );

    $stmt->execute();

    $row =
        $stmt
            ->get_result()
            ->fetch_assoc();

    $stmt->close();

    return
        (int)(
            $row["total"]
            ?? 0
        ) > 0;
}

if (
    !iecItemColumnExists(
        $conn,
        "iec_material_id"
    )
) {
    $conn->query(
        "ALTER TABLE job_request_iec_items
          ADD COLUMN iec_material_id
             BIGINT UNSIGNED NULL
          AFTER material_name,
          ADD INDEX idx_iec_item_material (iec_material_id)"
    );

    $iecLibCheck = $conn->query(
        "SHOW TABLES LIKE 'iec_materials'"
    );

    if (
        $iecLibCheck
        &&
        $iecLibCheck->num_rows > 0
    ) {
        try {
            $conn->query(
                "ALTER TABLE job_request_iec_items
                  ADD CONSTRAINT fk_iec_item_material
                  FOREIGN KEY (iec_material_id)
                  REFERENCES iec_materials (id)
                  ON DELETE SET NULL"
            );
        } catch (Throwable $ignored) {
        }
    }
}

if (
    !iecItemColumnExists(
        $conn,
        "fulfilled_qty"
    )
) {
    $conn->query(
        "ALTER TABLE job_request_iec_items
          ADD COLUMN fulfilled_qty
             INT NOT NULL DEFAULT 0
          AFTER quantity"
    );
}

$hasIecLinkCols =
    iecItemColumnExists(
        $conn,
        "iec_material_id"
    )
    &&
    iecItemColumnExists(
        $conn,
        "fulfilled_qty"
    );

/* =========================================================
   POST REQUEST ONLY
========================================================= */
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: new_request.php");
    exit;
}

/* =========================================================
   REQUESTOR INFORMATION
========================================================= */
$requestorId = (int) (
    $_SESSION["user_id"] ?? 0
);

/* =========================================================
   EDIT MODE: load owned request and enforce edit window
   Editable: Pending Review / Under Review / For Revision.
   Locked: Completed, For Evaluation, Closed, Disapproved,
   Published and other terminal statuses.
========================================================= */
$editId = (int)($_POST["edit_id"] ?? 0);

$editRow = null;

if ($editId > 0) {
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
        (int)($editRow["requestor"] ?? 0) !== $requestorId
    ) {
        alertBack(
            "Request not found."
        );
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
        alertBack(
            "This request can no longer be modified (status: "
            . (string)($editRow["approval_status"] ?? "unknown")
            . ")."
        );
    }
}

$isEdit = $editRow !== null;

$requestorName = postString(
    "requestor_name"
);

$department = postString(
    "department"
);

$contact = postString(
    "contact"
);

$dateRequest = postString(
    "date_request"
);

$dateCompletion = postString(
    "date_completion"
);

/* =========================================================
   JOB DETAILS
========================================================= */
$projectTitle = postString(
    "project_title"
);

$purposeObjective = postString(
    "purpose_objective"
);

/* =========================================================
   NON-APPLICABLE FIELDS
========================================================= */
$eventNotApplicable = postBool(
    "event_not_applicable"
);

$venueNotApplicable = postBool(
    "venue_not_applicable"
);

$sendToNotApplicable = postBool(
    "send_to_not_applicable"
);

$eventDatetimeRaw = $eventNotApplicable
    ? ""
    : postString("event_datetime");

/* Range format "Y-m-d\TH:i to Y-m-d\TH:i"; single dates have no end. */
[$eventRangeStart, $eventRangeEnd] = splitEventRange($eventDatetimeRaw);

$eventDatetime = $eventNotApplicable
    ? null
    : normalizeEventPoint($eventRangeStart);

$eventDatetimeEnd = ($eventNotApplicable || $eventRangeEnd === "")
    ? null
    : normalizeEventPoint($eventRangeEnd);

if (
    !$eventNotApplicable
    &&
    $eventDatetimeRaw !== ""
    &&
    $eventDatetime === null
) {
    alertBack(
        "Event date is not recognized. Please re-pick it from the calendar."
    );
}

if (
    !$eventNotApplicable
    &&
    $eventRangeEnd !== ""
    &&
    $eventDatetimeEnd === null
) {
    alertBack(
        "Event end date is not recognized. Please re-pick the date range."
    );
}

if (
    !$eventNotApplicable
    &&
    $eventDatetime !== null
    &&
    $eventDatetimeEnd !== null
    &&
    $eventDatetimeEnd < $eventDatetime
) {
    alertBack(
        "Event end cannot be earlier than the event start."
    );
}

/* Requestors cannot backdate events; privileged roles schedule freely. */
if (
    !$isDatePrivileged
    &&
    !$eventNotApplicable
    &&
    $eventDatetime !== null
    &&
    substr($eventDatetime, 0, 10) < date("Y-m-d")
) {
    alertBack(
        "Event date cannot be in the past. Please pick today or a future date."
    );
}

$venueLocation = $venueNotApplicable
    ? null
    : postString("venue_location");

$sendTo = $sendToNotApplicable
    ? null
    : postString("send_to");

/* =========================================================
   REQUEST TYPES
========================================================= */
$allowedRequestTypes = [
    "Graphic Design",
    "Video Production",
    "Photo/Video Coverage",
    "IEC Materials"
];

$requestTypes = postArray(
    "request_types",
    $allowedRequestTypes
);

if (count($requestTypes) !== 1) {
    alertBack(
        "Please select only one type of request."
    );
}

/* =========================================================
   SELECTED SECTIONS
========================================================= */
$graphicSelected = in_array(
    "Graphic Design",
    $requestTypes,
    true
);

$videoSelected = in_array(
    "Video Production",
    $requestTypes,
    true
);

$coverageSelected = in_array(
    "Photo/Video Coverage",
    $requestTypes,
    true
);

$iecSelected = in_array(
    "IEC Materials",
    $requestTypes,
    true
);

/* =========================================================
   GRAPHIC DESIGN DATA
========================================================= */
$graphicTypes = $graphicSelected
    ? postArray(
        "graphic_design_types",
        [
            "Digital Ad",
            "Print Ad / Tarpaulin",
            "Brochure",
            "Poster / Flyer",
            "Invitation",
            "Forms / Cards",
            "Logo Design",
            "Others"
        ]
    )
    : [];

$graphicOther = $graphicSelected
    ? postString("graphic_design_other")
    : "";

$graphicColors = $graphicSelected
    ? postArray(
        "graphic_colors",
        [
            "Full Color",
            "Single Color",
            "Grayscale"
        ]
    )
    : [];

$publicationMethods = $graphicSelected
    ? postArray(
        "publication_methods",
        [
            "Digital File",
            "Tarpaulin",
            "Photo Paper",
            "Others"
        ]
    )
    : [];

$publicationOther = $graphicSelected
    ? postString("publication_other")
    : "";

$graphicQuantity = $graphicSelected
    ? postInt("graphic_quantity")
    : 0;

/* =========================================================
   VIDEO PRODUCTION DATA
========================================================= */
$videoTypes = $videoSelected
    ? postArray(
        "video_types",
        [
            "Digital Video Ads",
            "Music Video",
            "Promotional",
            "Documentary",
            "Instructional",
            "Informative",
            "DOH Videos",
            "Others"
        ]
    )
    : [];

$videoTypeOther = $videoSelected
    ? postString("video_type_other")
    : "";

$videoFormats = $videoSelected
    ? postArray(
        "video_formats",
        [
            "MP4",
            "MP3 (Audio Only)",
            "Others"
        ]
    )
    : [];

$videoFormatOther = $videoSelected
    ? postString("video_format_other")
    : "";

$videoQuantity = $videoSelected
    ? postInt("video_quantity")
    : 0;

/* =========================================================
   PHOTO / VIDEO COVERAGE DATA
========================================================= */
$photographersNeeded = $coverageSelected
    ? postInt("photographers_needed")
    : 0;

$specificShots = $coverageSelected
    ? postString("specific_shots")
    : "";

$coverageFormats = $coverageSelected
    ? postArray(
        "coverage_formats",
        [
            "JPEG",
            "PNG",
            "RAW",
            "MP4",
            "MKV",
            "Others"
        ]
    )
    : [];

$coverageFormatOther = $coverageSelected
    ? postString("coverage_format_other")
    : "";

$coverageQuantity = $coverageSelected
    ? postInt("coverage_quantity")
    : 0;

/* =========================================================
   IEC MATERIALS DATA
========================================================= */
$iecRows = [];

if ($iecSelected) {
    $selectedIecMaterials = postArray(
        "iec_selected"
    );

    $iecQuantities =
        $_POST["iec_quantity"] ?? [];

    if (!is_array($iecQuantities)) {
        $iecQuantities = [];
    }

    $iecMaterialIds =
        $_POST["iec_material_id"] ?? [];

    if (!is_array($iecMaterialIds)) {
        $iecMaterialIds = [];
    }

    /* Valid library ids (active items only). */
    $validLibraryIds = [];

    if ($hasIecLinkCols) {
        $iecLibCheck = $conn->query(
            "SHOW TABLES LIKE 'iec_materials'"
        );

        if (
            $iecLibCheck
            &&
            $iecLibCheck->num_rows > 0
        ) {
            $iecLibRows = $conn->query(
                "SELECT id
                  FROM iec_materials
                  WHERE active = 1"
            );

            if ($iecLibRows) {
                foreach (
                    $iecLibRows->fetch_all(MYSQLI_ASSOC)
                    as $iecLibRow
                ) {
                    $validLibraryIds[
                        (int)$iecLibRow["id"]
                    ] = true;
                }
            }
        }
    }

    if (empty($selectedIecMaterials)) {
        alertBack(
            "Please select at least one IEC Material."
        );
    }

    foreach ($selectedIecMaterials as $material) {
        $materialName = $material;

        if ($material === "Others") {
            $materialName = postString(
                "iec_other_name"
            );

            if ($materialName === "") {
                alertBack(
                    "Please specify the other IEC Material."
                );
            }
        }

        $quantity = max(
            0,
            (int) (
                $iecQuantities[$material] ?? 0
            )
        );

        if ($quantity <= 0) {
            alertBack(
                "Please enter a valid quantity for " .
                $materialName .
                "."
            );
        }

        $materialId = isset($iecMaterialIds[$material])
            ? (int)$iecMaterialIds[$material]
            : null;

        if (
            $materialId !== null
            && (
                $materialId <= 0
                || !isset($validLibraryIds[$materialId])
            )
        ) {
            $materialId = null;
        }

        $iecRows[] = [
            "material_name" => $materialName,
            "material_id" => $materialId,
            "quantity" => $quantity
        ];
    }
}

/* =========================================================
   ADDITIONAL INFORMATION
========================================================= */
$requestDetails = postString(
    "request_details"
);

$hpuAssistanceRequired =
    postBool(
        "hpu_assistance_required"
    );

$hpuStaffNeeded =
    $hpuAssistanceRequired
        ? postInt(
            "hpu_staff_needed"
        )
        : 0;

// HPU Assistance applies to Graphic Design (checkbox only, no staff
// count) and Photo/Video Coverage (checkbox + staff count). Ignore it
// for other request types (e.g. tampered POST data).
if (!($coverageSelected || $graphicSelected)) {
    $hpuAssistanceRequired = false;
    $hpuStaffNeeded = 0;
} elseif ($graphicSelected && !$coverageSelected) {
    $hpuStaffNeeded = 0;
}

$requestorSignatureDate = postString(
    "requestor_signature_date"
);

/* =========================================================
   BASIC VALIDATION
========================================================= */
if ($requestorId <= 0) {
    alertBack(
        "Invalid login session."
    );
}

if ($requestorName === "") {
    alertBack(
        "Requestor name is required."
    );
}

if ($department === "") {
    alertBack(
        "Department is required."
    );
}

if ($contact === "") {
    alertBack(
        "Contact number is required."
    );
}

if (!validDate($dateRequest)) {
    alertBack(
        "Please enter a valid request date."
    );
}

/* Requestors file as of today; privileged roles may backdate. */
if (!$isDatePrivileged && !$isEdit) {
    $dateRequest = date("Y-m-d");
}

if ($dateCompletion === "") {
    $dateCompletion = null;
} elseif (!validDate($dateCompletion)) {
    alertBack(
        "Please enter a valid completion date."
    );
}

if (
    $dateCompletion !== null &&
    strtotime($dateCompletion) <
    strtotime($dateRequest)
) {
    alertBack(
        "Completion date cannot be earlier than the request date."
    );
}

if ($projectTitle === "") {
    alertBack(
        "Title / Project Name is required."
    );
}

if ($purposeObjective === "") {
    alertBack(
        "Purpose / Objective is required."
    );
}

if (
    !$eventNotApplicable &&
    $eventDatetime === null
) {
    alertBack(
        "Provide the Event Date and Time or mark it non-applicable."
    );
}

if (
    !$venueNotApplicable &&
    $venueLocation === ""
) {
    alertBack(
        "Provide the Venue / Location or mark it non-applicable."
    );
}

if (
    !$sendToNotApplicable &&
    $sendTo === ""
) {
    alertBack(
        "Provide Send Them To or mark it non-applicable."
    );
}

if ($requestDetails === "") {
    alertBack(
        "Additional Request Details is required."
    );
}

/* =========================================================
   HPU ASSISTANCE VALIDATION
========================================================= */
if (
    $hpuAssistanceRequired
    &&
    $coverageSelected
    &&
    array_key_exists("hpu_staff_needed", $_POST)
    &&
    $hpuStaffNeeded < 1
) {
    alertBack(
        "Please enter the number of HPU staff needed."
    );
}

/* =========================================================
   MINIMUM PROCESSING / LEAD TIME VALIDATION
========================================================= */
$leadTimeDaysByService = [
    "Graphic Design" => 5,
    "Video Production" => 5,
    "Photo/Video Coverage" => 3,
    "IEC Materials" => 3
];

$requiredLeadDays = 0;

foreach ($requestTypes as $requestType) {
    $requiredLeadDays =
        max(
            $requiredLeadDays,
            (int)(
                $leadTimeDaysByService[
                    $requestType
                ]
                ?? 0
            )
        );
}

$earliestAllowedDate = null;

if (
    $requiredLeadDays > 0
    &&
    validDate(
        $dateRequest
    )
) {
    // Working days only (Mon–Fri); blocked HPU dates do not count.
    $earliestAllowedDate =
        hpuEarliestAvailableDate(
            $conn,
            $dateRequest,
            $requiredLeadDays,
            $requestTypes
        );
}

if (
    !$isDatePrivileged
    &&
    $earliestAllowedDate !== null
    &&
    $dateCompletion !== null
    &&
    $dateCompletion
    <
    $earliestAllowedDate
) {
    alertBack(
        "This request requires at least "
        . $requiredLeadDays
        . " working days of lead time (weekends and blocked HPU dates excluded). Please select "
        . date(
            "F j, Y",
            strtotime(
                $earliestAllowedDate
            )
        )
        . " or later for Preferred Completion."
    );
}

/* =========================================================
   HPU DATE AVAILABILITY VALIDATION
========================================================= */

/*
 * Preferred Completion:
 * Check against every selected HPU service.
 * Privileged roles schedule freely (only completion >= request
 * date still applies to everyone).
 */
if ($dateCompletion !== null && !$isDatePrivileged) {
    if (!hpuIsWorkingDay($dateCompletion)) {
        alertBack(
            "The Preferred Completion date falls on a weekend. Please select a working day (Monday–Friday)."
        );
    }

    $blockedCompletion =
        hpuAvailabilityFindBlock(
            $conn,
            $dateCompletion,
            $requestTypes
        );

    if ($blockedCompletion) {
        alertBack(
            hpuAvailabilityMessage(
                $dateCompletion,
                $blockedCompletion
            )
        );
    }
}


/* =========================================================
   GRAPHIC DESIGN VALIDATION
========================================================= */
if ($graphicSelected) {
    if (empty($graphicTypes)) {
        alertBack(
            "Please select at least one Type of Design."
        );
    }

    if (
        in_array(
            "Others",
            $graphicTypes,
            true
        ) &&
        $graphicOther === ""
    ) {
        alertBack(
            "Please specify the other Graphic Design type."
        );
    }

    if (empty($graphicColors)) {
        alertBack(
            "Please select at least one Color option."
        );
    }

    if (empty($publicationMethods)) {
        alertBack(
            "Please select at least one Publication Method."
        );
    }

    if (
        in_array(
            "Others",
            $publicationMethods,
            true
        ) &&
        $publicationOther === ""
    ) {
        alertBack(
            "Please specify the other Publication Method."
        );
    }

    if ($graphicQuantity < 1) {
        alertBack(
            "Please enter Number of pcs (at least 1) for Graphic Design."
        );
    }
}

/* =========================================================
   VIDEO PRODUCTION VALIDATION
========================================================= */
if ($videoSelected) {
    if (empty($videoTypes)) {
        alertBack(
            "Please select at least one Type of Video."
        );
    }

    if (
        in_array(
            "Others",
            $videoTypes,
            true
        ) &&
        $videoTypeOther === ""
    ) {
        alertBack(
            "Please specify the other Video Type."
        );
    }

    if (empty($videoFormats)) {
        alertBack(
            "Please select at least one Video Format."
        );
    }

    if (
        in_array(
            "Others",
            $videoFormats,
            true
        ) &&
        $videoFormatOther === ""
    ) {
        alertBack(
            "Please specify the other Video Format."
        );
    }
}

/* =========================================================
   PHOTO / VIDEO COVERAGE VALIDATION
========================================================= */
if ($coverageSelected) {
    if ($photographersNeeded <= 0) {
        alertBack(
            "Please enter the number of photographers needed."
        );
    }

    if (empty($coverageFormats)) {
        alertBack(
            "Please select at least one Image / Video Format."
        );
    }

    if (
        in_array(
            "Others",
            $coverageFormats,
            true
        ) &&
        $coverageFormatOther === ""
    ) {
        alertBack(
            "Please specify the other Image / Video Format."
        );
    }
}

/* =========================================================
   SIGNATURE DATE VALIDATION
========================================================= */
if (
    $requestorSignatureDate !== "" &&
    !validDate($requestorSignatureDate)
) {
    alertBack(
        "Please enter a valid requestor signature date."
    );
}

/* Requestors sign as of today; privileged roles may adjust. */
if (!$isDatePrivileged && !$isEdit) {
    $requestorSignatureDate = date("Y-m-d");
}

/* =========================================================
   FILE UPLOAD (supports up to 10 files)
========================================================= */
$fileName = "";
$savedPath = null;
$savedPaths = [];
$newUploads = [];

/* =========================================================
   SAVE REQUEST
========================================================= */
try {
    if (isset($_FILES["attachment"])) {
        $uploadNames =
            $_FILES["attachment"]["name"] ?? null;

        if (is_array($uploadNames)) {
            if (count($uploadNames) > 10) {
                alertBack(
                    "You can only attach up to 10 files."
                );
            }

            foreach (array_keys($uploadNames) as $i) {
                if (
                    (int)$_FILES["attachment"]["error"][$i]
                    ===
                    UPLOAD_ERR_NO_FILE
                ) {
                    continue;
                }

                $single = [
                    "name" =>
                        $_FILES["attachment"]["name"][$i],
                    "type" =>
                        $_FILES["attachment"]["type"][$i] ?? "",
                    "tmp_name" =>
                        $_FILES["attachment"]["tmp_name"][$i],
                    "error" =>
                        $_FILES["attachment"]["error"][$i],
                    "size" =>
                        $_FILES["attachment"]["size"][$i] ?? 0
                ];

                $oneSaved = null;

                $storedName = uploadAttachment(
                    $single,
                    $oneSaved
                );

                if ($storedName !== "") {
                    $newUploads[] = $storedName;

                    if ($oneSaved !== null) {
                        $savedPaths[] = $oneSaved;
                    }
                }
            }
        } else {
            $oneSaved = null;

            $storedName = uploadAttachment(
                $_FILES["attachment"],
                $oneSaved
            );

            if ($storedName !== "") {
                $newUploads[] = $storedName;

                if ($oneSaved !== null) {
                    $savedPaths[] = $oneSaved;
                }
            }
        }

        $savedPath =
            $savedPaths[0] ?? null;
    }

    $removedPaths = [];

    if ($isEdit) {
        $existingFiles = attachmentList(
            (string)($editRow["attachment"] ?? "")
        );

        $keptFiles = postArray("keep_attachments");

        $keptFiles = array_values(
            array_intersect(
                $existingFiles,
                $keptFiles
            )
        );

        foreach (
            array_diff(
                $existingFiles,
                $keptFiles
            )
            as $removed
        ) {
            $removedPaths[] =
                uploadsRootDir()
                . DIRECTORY_SEPARATOR
                . basename($removed);
        }

        $mergedFiles = array_values(
            array_unique(
                array_merge(
                    $keptFiles,
                    $newUploads
                )
            )
        );

        if (count($mergedFiles) > 10) {
            alertBack(
                "You can only attach up to 10 files."
            );
        }

        $fileName = implode(",", $mergedFiles);
    } else {
        $fileName = implode(",", $newUploads);
    }

    if (!$isEdit && $fileName === "") {
        alertBack(
            "Attachments is required — please attach at least 1 file."
        );
    }

    $conn->begin_transaction();

    $jobNo = $isEdit
        ? (string)($editRow["job_no"] ?? "")
        : generateJobNumber(
            $conn,
            $requestTypes[0] ?? ""
        );

    $createdAt = date(
        "Y-m-d H:i:s"
    );

    $actionLog =
        "Created by " .
        $requestorName .
        " on " .
        $createdAt;

    /* =====================================================
       CONVERT ARRAYS TO JSON
    ===================================================== */
    $requestTypesJson = jsonArray(
        $requestTypes
    );

    $graphicTypesJson = jsonArray(
        $graphicTypes
    );

    $graphicColorsJson = jsonArray(
        $graphicColors
    );

    $publicationMethodsJson = jsonArray(
        $publicationMethods
    );

    $videoTypesJson = jsonArray(
        $videoTypes
    );

    $videoFormatsJson = jsonArray(
        $videoFormats
    );

    $coverageFormatsJson = jsonArray(
        $coverageFormats
    );

    /* =====================================================
       INSERT OR UPDATE MAIN REQUEST
    ===================================================== */
    if ($isEdit) {
        $actionLog =
            trim(
                (string)($editRow["action_log"] ?? "")
                . "\nUpdated by "
                . $requestorName
                . " on "
                . $createdAt
            );

        $stmt = $conn->prepare(
            "UPDATE job_requests
              SET requestor_name = ?,
                  department = ?,
                  contact = ?,
                  date_request = ?,
                  date_completion = ?,
                  attachment = ?,

                  request_types = ?,
                  project_title = ?,
                  purpose_objective = ?,

                  event_datetime = ?,
                  event_datetime_end = ?,
                  event_not_applicable = ?,

                  venue_location = ?,
                  venue_not_applicable = ?,

                  send_to = ?,
                  send_to_not_applicable = ?,

                  graphic_design_types = ?,
                  graphic_design_other = ?,
                  graphic_colors = ?,
                  publication_methods = ?,
                  publication_other = ?,
                  graphic_quantity = ?,

                  video_types = ?,
                  video_type_other = ?,
                  video_formats = ?,
                  video_format_other = ?,
                  video_quantity = ?,

                  photographers_needed = ?,
                  specific_shots = ?,
                  coverage_formats = ?,
                  coverage_format_other = ?,
                  coverage_quantity = ?,

                  request_details = ?,
                  requestor_signature_date = ?,

                  action_log = ?
              WHERE id = ?
                AND requestor = ?"
        );

        $stmt->bind_param(
            "sssssssssssisisisssssissssiisssisssii",

            $requestorName,
            $department,
            $contact,
            $dateRequest,
            $dateCompletion,
            $fileName,

            $requestTypesJson,
            $projectTitle,
            $purposeObjective,

            $eventDatetime,
            $eventDatetimeEnd,
            $eventNotApplicable,

            $venueLocation,
            $venueNotApplicable,

            $sendTo,
            $sendToNotApplicable,

            $graphicTypesJson,
            $graphicOther,
            $graphicColorsJson,
            $publicationMethodsJson,
            $publicationOther,
            $graphicQuantity,

            $videoTypesJson,
            $videoTypeOther,
            $videoFormatsJson,
            $videoFormatOther,
            $videoQuantity,

            $photographersNeeded,
            $specificShots,
            $coverageFormatsJson,
            $coverageFormatOther,
            $coverageQuantity,

            $requestDetails,
            $requestorSignatureDate,

            $actionLog,
            $editId,
            $requestorId
        );

        $stmt->execute();
        $stmt->close();

        $requestId = $editId;

        /* Preserve fulfillment progress across requestor edits. */
        $keptFulfilled = [];

        if ($hasIecLinkCols) {
            $keptStmt = $conn->prepare(
                "SELECT material_name, iec_material_id, fulfilled_qty
                  FROM job_request_iec_items
                  WHERE request_id = ?"
            );

            if ($keptStmt) {
                $keptStmt->bind_param("i", $requestId);
                $keptStmt->execute();

                $keptResult = $keptStmt->get_result();

                if ($keptResult) {
                    while ($keptRow = $keptResult->fetch_assoc()) {
                        $keptKey =
                            (int)($keptRow["iec_material_id"] ?? 0)
                            . "|"
                            . (string)($keptRow["material_name"] ?? "");

                        $keptFulfilled[$keptKey] = max(
                            $keptFulfilled[$keptKey] ?? 0,
                            (int)($keptRow["fulfilled_qty"] ?? 0)
                        );
                    }
                }

                $keptStmt->close();
            }
        }

        $deleteIec = $conn->prepare(
            "DELETE FROM job_request_iec_items
              WHERE request_id = ?"
        );

        $deleteIec->bind_param("i", $requestId);
        $deleteIec->execute();
        $deleteIec->close();

        /* Carry fulfillment into the re-saved rows. */
        if ($hasIecLinkCols) {
            foreach ($iecRows as $iecIndex => $iecRow) {
                $carryKey =
                    (int)($iecRow["material_id"] ?? 0)
                    . "|"
                    . (string)$iecRow["material_name"];

                $iecRows[$iecIndex]["fulfilled"] = min(
                    $keptFulfilled[$carryKey] ?? 0,
                    (int)$iecRow["quantity"]
                );
            }
        }
    } else {
    $stmt = $conn->prepare(
        "INSERT INTO job_requests (
            job_no,
            requestor,
            requestor_name,
            department,
            contact,
            date_request,
            date_completion,
            attachment,

            request_types,
            project_title,
            purpose_objective,

            event_datetime,
            event_datetime_end,
            event_not_applicable,

            venue_location,
            venue_not_applicable,

            send_to,
            send_to_not_applicable,

            graphic_design_types,
            graphic_design_other,
            graphic_colors,
            publication_methods,
            publication_other,
            graphic_quantity,

            video_types,
            video_type_other,
            video_formats,
            video_format_other,
            video_quantity,

            photographers_needed,
            specific_shots,
            coverage_formats,
            coverage_format_other,
            coverage_quantity,

            request_details,
            requestor_signature_date,

            approval_status,
            action_log,
            created_at
        )
        VALUES (
            ?, ?, ?, ?, ?, ?, ?, ?,

            ?, ?, ?,

            ?, ?, ?,

            ?, ?,

            ?, ?,

            ?, ?, ?, ?, ?, ?,

            ?, ?, ?, ?, ?,

            ?, ?, ?, ?, ?,

            ?, ?,

            'Pending Review',
            ?,
            ?
        )"
    );

    $stmt->bind_param(
        "sisssssssssssisisisssssissssiisssissss",

        $jobNo,
        $requestorId,
        $requestorName,
        $department,
        $contact,
        $dateRequest,
        $dateCompletion,
        $fileName,

        $requestTypesJson,
        $projectTitle,
        $purposeObjective,

        $eventDatetime,
        $eventDatetimeEnd,
        $eventNotApplicable,

        $venueLocation,
        $venueNotApplicable,

        $sendTo,
        $sendToNotApplicable,

        $graphicTypesJson,
        $graphicOther,
        $graphicColorsJson,
        $publicationMethodsJson,
        $publicationOther,
        $graphicQuantity,

        $videoTypesJson,
        $videoTypeOther,
        $videoFormatsJson,
        $videoFormatOther,
        $videoQuantity,

        $photographersNeeded,
        $specificShots,
        $coverageFormatsJson,
        $coverageFormatOther,
        $coverageQuantity,

        $requestDetails,
        $requestorSignatureDate,

        $actionLog,
        $createdAt
    );

    $stmt->execute();

$requestId = $stmt->insert_id;

$stmt->close();
    }

/*
 * Save HPU Assistance fields separately to preserve the
 * existing main INSERT statement and its binding structure.
 */
$assistanceUpdate =
    $conn->prepare(
        "UPDATE job_requests
         SET
            hpu_assistance_required = ?,
            hpu_staff_needed = ?
         WHERE id = ?"
    );

/* Headcount only applies to Photo/Video Coverage with assistance.
 * Graphic Design (and everything else) stores NULL — never 0. */
$staffNeededDb =
    (
        $hpuAssistanceRequired
        &&
        $coverageSelected
        &&
        $hpuStaffNeeded > 0
    )
        ? $hpuStaffNeeded
        : null;

$assistanceUpdate->bind_param(
    "iii",
    $hpuAssistanceRequired,
    $staffNeededDb,
    $requestId
);

$assistanceUpdate->execute();
$assistanceUpdate->close();

if ($isEdit) {
    $jobNo = (string)($editRow["job_no"] ?? "");
}

addRequestActivity(
    $conn,
    $requestId,
    (int) ($_SESSION["user_id"] ?? 0),
    $isEdit ? "updated" : "created",
    $isEdit ? "Request Updated" : "Request Submitted",
    $isEdit
        ? "The job request was updated by the requestor."
        : "The job request was created and submitted for review.",
    null,
    $isEdit
        ? (string)($editRow["approval_status"] ?? "Pending Review")
        : "Pending Review",
    [
        "job_no" => $jobNo,
        "project_title" => $projectTitle
    ]
);

/* Save IEC items here */

$conn->commit();

    /* =====================================================
       INSERT IEC MATERIALS
    ===================================================== */
    if (!empty($iecRows)) {
        if ($hasIecLinkCols) {
            $iecStmt = $conn->prepare(
                "INSERT INTO job_request_iec_items (
                    request_id,
                    material_name,
                    iec_material_id,
                    quantity,
                    fulfilled_qty
                )
                VALUES (?, ?, ?, ?, ?)"
            );

            foreach ($iecRows as $iecRow) {
                $materialName =
                    $iecRow["material_name"];

                $materialId =
                    $iecRow["material_id"] ?? null;

                $quantity =
                    (int)$iecRow["quantity"];

                $fulfilled =
                    min(
                        (int)($iecRow["fulfilled"] ?? 0),
                        $quantity
                    );

                $iecStmt->bind_param(
                    "isiii",
                    $requestId,
                    $materialName,
                    $materialId,
                    $quantity,
                    $fulfilled
                );

                $iecStmt->execute();
            }

            $iecStmt->close();
        } else {
            $iecStmt = $conn->prepare(
                "INSERT INTO job_request_iec_items (
                    request_id,
                    material_name,
                    quantity
                )
                VALUES (?, ?, ?)"
            );

            foreach ($iecRows as $iecRow) {
                $materialName =
                    $iecRow["material_name"];

                $quantity =
                    $iecRow["quantity"];

                $iecStmt->bind_param(
                    "isi",
                    $requestId,
                    $materialName,
                    $quantity
                );

                $iecStmt->execute();
            }

            $iecStmt->close();
        }
    }

    $conn->commit();

    foreach ($removedPaths as $removedPath) {
        if (is_file($removedPath)) {
            @unlink($removedPath);
        }
    }

    $safeJobNumber = json_encode(
        $jobNo,
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP
    );

    if ($isEdit) {
        echo "
            <script>
                alert(
                    'Request updated successfully.\\n\\n' +
                    'Job Number: ' + $safeJobNumber
                );

                window.location.href =
                    'my_requests.php';
            </script>
        ";
    } else {
    echo "
        <script>
            alert(
                'Request submitted successfully.\\n\\n' +
                'Job Number: ' + $safeJobNumber
            );

            window.location.href =
                'my_requests.php';
        </script>
    ";
    }

    exit;
} catch (Throwable $exception) {
    try {
        $conn->rollback();
    } catch (Throwable $ignored) {
    }

    foreach ($savedPaths as $savedPathItem) {
        if (is_file($savedPathItem)) {
            @unlink($savedPathItem);
        }
    }

    if (
        $savedPath !== null &&
        is_file($savedPath)
    ) {
        @unlink($savedPath);
    }

    error_log(
        "Save request error: " .
        $exception->getMessage() .
        " in " .
        $exception->getFile() .
        " on line " .
        $exception->getLine()
    );

    alertBack(
        "Unable to submit request: " .
        $exception->getMessage()
    );
}
?>
<?php

/*
|--------------------------------------------------------------------------
| HPU Availability / Blocked Dates Helper
|--------------------------------------------------------------------------
| Shared by:
| - staff/blocked_dates.php
| - ajax/check_blocked_date.php
| - user/save_request.php
| - content_submission/event_activity.php
| - content_submission/recognition_milestone.php
|--------------------------------------------------------------------------
*/

if (!function_exists("hpuAvailabilityServiceOptions")) {
    function hpuAvailabilityServiceOptions(): array
    {
        return [
            "All HPU Services",
            "Graphic Design",
            "Video Production",
            "Photo/Video Coverage",
            "IEC Materials",
            "Publication Submission"
        ];
    }
}

if (!function_exists("hpuEnsureBlockedDatesTable")) {
    function hpuEnsureBlockedDatesTable(mysqli $conn): void
    {
        $conn->query(
            "CREATE TABLE IF NOT EXISTS hpu_blocked_dates (
                id INT AUTO_INCREMENT PRIMARY KEY,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                service_type VARCHAR(80) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                blocked_by INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_hpu_blocked_range (
                    start_date,
                    end_date
                ),
                INDEX idx_hpu_blocked_service (
                    service_type
                ),
                INDEX idx_hpu_blocked_by (
                    blocked_by
                )
            )
            ENGINE=InnoDB
            DEFAULT CHARSET=utf8mb4
            COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists("hpuAvailabilityNormalizeDate")) {
    function hpuAvailabilityNormalizeDate(?string $value): ?string
    {
        $value = trim((string)$value);

        if ($value === "") {
            return null;
        }

        /*
         * Supports:
         * 2026-09-15
         * 2026-09-15T14:30
         * 2026-09-15 14:30:00
         */
        $datePart = substr($value, 0, 10);

        $date = DateTime::createFromFormat(
            "!Y-m-d",
            $datePart
        );

        if (
            !$date
            ||
            $date->format("Y-m-d") !== $datePart
        ) {
            return null;
        }

        return $datePart;
    }
}

if (!function_exists("hpuAvailabilityCleanServices")) {
    function hpuAvailabilityCleanServices(array $services): array
    {
        $allowed = hpuAvailabilityServiceOptions();

        $clean = [];

        foreach ($services as $service) {
            $service = trim((string)$service);

            if (
                $service !== ""
                &&
                $service !== "All HPU Services"
                &&
                in_array(
                    $service,
                    $allowed,
                    true
                )
            ) {
                $clean[] = $service;
            }
        }

        return array_values(
            array_unique(
                $clean
            )
        );
    }
}

if (!function_exists("hpuAvailabilityFindBlock")) {
    function hpuAvailabilityFindBlock(
        mysqli $conn,
        string $dateValue,
        array $services
    ): ?array {
        hpuEnsureBlockedDatesTable(
            $conn
        );

        $date =
            hpuAvailabilityNormalizeDate(
                $dateValue
            );

        if ($date === null) {
            return null;
        }

        $services =
            hpuAvailabilityCleanServices(
                $services
            );

        if (!$services) {
            return null;
        }

        $placeholders =
            implode(
                ",",
                array_fill(
                    0,
                    count($services),
                    "?"
                )
            );

        /*
         * Specific service blocks are shown first because
         * their reason is usually more useful than a broad
         * "All HPU Services" block.
         */
        $sql = "
            SELECT
                id,
                start_date,
                end_date,
                service_type,
                reason,
                blocked_by,
                created_at,
                updated_at
            FROM hpu_blocked_dates
            WHERE
                ? BETWEEN start_date AND end_date
                AND (
                    service_type = 'All HPU Services'
                    OR service_type IN ({$placeholders})
                )
            ORDER BY
                (
                    service_type = 'All HPU Services'
                ) ASC,
                start_date ASC,
                id ASC
            LIMIT 1
        ";

        $stmt =
            $conn->prepare(
                $sql
            );

        $types =
            "s"
            . str_repeat(
                "s",
                count($services)
            );

        $params =
            array_merge(
                [$date],
                $services
            );

        $stmt->bind_param(
            $types,
            ...$params
        );

        $stmt->execute();

        $row =
            $stmt
                ->get_result()
                ->fetch_assoc();

        $stmt->close();

        return
            $row
                ?: null;
    }
}

if (!function_exists("hpuIsWorkingDay")) {
    /**
     * Working days only: Monday–Friday. Weekends (Sat/Sun) are excluded.
     */
    function hpuIsWorkingDay(string $ymd): bool
    {
        $dow = (int) date("N", strtotime($ymd));
        return $dow >= 1 && $dow <= 5;
    }
}

if (!function_exists("hpuEarliestAvailableDate")) {
    /**
     * Earliest allowable date counting ONLY working days (Mon–Fri),
     * excluding HPU blocked dates. Blocked dates do not count toward minimum.
     * Starts counting the day AFTER $dateRequest.
     */
    function hpuEarliestAvailableDate(
        mysqli $conn,
        string $dateRequest,
        int $requiredDays,
        array $services
    ): ?string {
        if ($requiredDays <= 0) {
            return null;
        }

        $base = hpuAvailabilityNormalizeDate($dateRequest);
        if ($base === null) {
            return null;
        }

        $cur = new DateTime($base);
        $counted = 0;

        for ($i = 0; $i < 730; $i++) {
            $cur->modify("+1 day");
            $iso = $cur->format("Y-m-d");

            if (!hpuIsWorkingDay($iso)) {
                continue;
            }

            $block = hpuAvailabilityFindBlock($conn, $iso, $services);
            if ($block !== null) {
                continue;
            }

            $counted++;
            if ($counted >= $requiredDays) {
                return $iso;
            }
        }

        return null;
    }
}

if (!function_exists("hpuAvailableWorkingDaysBetween")) {
    /**
     * Count working, unblocked days between request (exclusive) and target (inclusive).
     */
    function hpuAvailableWorkingDaysBetween(
        mysqli $conn,
        string $dateRequest,
        string $targetDate,
        array $services
    ): int {
        $base = hpuAvailabilityNormalizeDate($dateRequest);
        $target = hpuAvailabilityNormalizeDate($targetDate);

        if ($base === null || $target === null || $target <= $base) {
            return 0;
        }

        $cur = new DateTime($base);
        $end = new DateTime($target);
        $count = 0;

        for ($i = 0; $i < 730; $i++) {
            $cur->modify("+1 day");
            if ($cur > $end) {
                break;
            }

            $iso = $cur->format("Y-m-d");

            if (!hpuIsWorkingDay($iso)) {
                continue;
            }

            if (hpuAvailabilityFindBlock($conn, $iso, $services) !== null) {
                continue;
            }

            $count++;
        }

        return $count;
    }
}

if (!function_exists("hpuAvailabilityMessage")) {
    function hpuAvailabilityMessage(
        string $dateValue,
        array $block
    ): string {
        $date =
            hpuAvailabilityNormalizeDate(
                $dateValue
            );

        $dateLabel =
            $date !== null
                ? date(
                    "F j, Y",
                    strtotime(
                        $date
                    )
                )
                : $dateValue;

        $service =
            trim(
                (string)(
                    $block["service_type"]
                    ?? ""
                )
            );

        $reason =
            trim(
                (string)(
                    $block["reason"]
                    ?? ""
                )
            );

        $message =
            $dateLabel
            . " is unavailable";

        if ($service !== "") {
            $message .=
                " for "
                . $service;
        }

        if ($reason !== "") {
            $message .=
                " — "
                . $reason;
        }

        return
            $message
            . ". Please choose another date.";
    }
}

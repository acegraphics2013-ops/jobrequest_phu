<?php

if (!function_exists("ensurePublicToken")) {
    function ensurePublicToken(
        mysqli $conn,
        int $requestId
    ): string {
        $stmt = $conn->prepare(
            "SELECT public_token
             FROM job_requests
             WHERE id = ?
             LIMIT 1"
        );

        $stmt->bind_param("i", $requestId);
        $stmt->execute();

        $row = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        if (!$row) {
            throw new RuntimeException(
                "Job request not found."
            );
        }

        if (!empty($row["public_token"])) {
            return $row["public_token"];
        }

        do {
            $token = bin2hex(
                random_bytes(32)
            );

            $check = $conn->prepare(
                "SELECT id
                 FROM job_requests
                 WHERE public_token = ?
                 LIMIT 1"
            );

            $check->bind_param("s", $token);
            $check->execute();

            $exists = $check
                ->get_result()
                ->fetch_assoc();

            $check->close();
        } while ($exists);

        $update = $conn->prepare(
            "UPDATE job_requests
             SET public_token = ?
             WHERE id = ?"
        );

        $update->bind_param(
            "si",
            $token,
            $requestId
        );

        $update->execute();
        $update->close();

        return $token;
    }
}

if (!function_exists("statusCssClass")) {
    function statusCssClass(
        string $status
    ): string {
        return match ($status) {
            "Pending Review" => "status-pending",
            "Under Review" => "status-review",
            "Approved" => "status-approved",
            "In Development" => "status-development",
            "For Revision" => "status-revision",
            "Disapproved" => "status-disapproved",
            "Completed" => "status-completed",
            "For Evaluation" => "status-evaluation",
            "Closed" => "status-closed",
            default => "status-default"
        };
    }
}

if (!function_exists("priorityCssClass")) {
    function priorityCssClass(
        string $priority
    ): string {
        return match ($priority) {
            "Low" => "priority-low",
            "High" => "priority-high",
            "Urgent" => "priority-urgent",
            default => "priority-normal"
        };
    }
}

if (!function_exists("dueDateInfo")) {
    function dueDateInfo(
        ?string $date,
        string $status = ""
    ): array {
        if (
            !$date ||
            in_array(
                $status,
                ["Closed", "Disapproved"],
                true
            )
        ) {
            return [
                "text" => "No active deadline",
                "class" => "due-muted"
            ];
        }

        $today = new DateTime("today");
        $due = new DateTime($date);

        $days = (int) $today
            ->diff($due)
            ->format("%r%a");

        if ($days < 0) {
            return [
                "text" =>
                    "Overdue by " .
                    abs($days) .
                    " day(s)",
                "class" => "due-danger"
            ];
        }

        if ($days === 0) {
            return [
                "text" => "Due today",
                "class" => "due-danger"
            ];
        }

        if ($days === 1) {
            return [
                "text" => "Due tomorrow",
                "class" => "due-warning"
            ];
        }

        return [
            "text" =>
                "Due in " .
                $days .
                " days",
            "class" => "due-success"
        ];
    }
}

if (!function_exists("allowedKanbanTransition")) {
    function allowedKanbanTransition(
        string $role,
        string $oldStatus,
        string $newStatus
    ): bool {
        if ($oldStatus === $newStatus) {
            return true;
        }

        $allowedByRole = [
            "staff" => [
                "Pending Review" => [
                    "Under Review"
                ],
                "Under Review" => [
                    "In Development"
                ],
                "Approved" => [
                    "In Development"
                ],
                "For Revision" => [
                    "Under Review",
                    "In Development"
                ],
                "In Development" => [
                    "Completed"
                ],
                "Completed" => [
                    "For Evaluation"
                ]
            ],

            "supervisor" => [
                "Pending Review" => [
                    "Under Review",
                    "Approved",
                    "For Revision",
                    "Disapproved"
                ],
                "Under Review" => [
                    "Approved",
                    "For Revision",
                    "Disapproved"
                ],
                "Approved" => [
                    "In Development",
                    "For Revision"
                ],
                "For Revision" => [
                    "Under Review",
                    "Approved"
                ],
                "In Development" => [
                    "Completed",
                    "For Revision"
                ],
                "Completed" => [
                    "For Evaluation",
                    "Closed"
                ],
                "For Evaluation" => [
                    "Closed"
                ]
            ],

            "admin" => [
                "*" => [
                    "Pending Review",
                    "Under Review",
                    "Approved",
                    "In Development",
                    "For Revision",
                    "Disapproved",
                    "Completed",
                    "For Evaluation",
                    "Closed"
                ]
            ]
        ];

        if ($role === "admin") {
            return in_array(
                $newStatus,
                $allowedByRole["admin"]["*"],
                true
            );
        }

        return in_array(
            $newStatus,
            $allowedByRole[$role][$oldStatus] ?? [],
            true
        );
    }
}

<?php

/* =========================================================
   ADD ACTIVITY LOG
========================================================= */
if (!function_exists("addRequestActivity")) {
    function addRequestActivity(
        mysqli $conn,
        int $requestId,
        ?int $userId,
        string $actionType,
        string $actionTitle,
        ?string $description = null,
        ?string $oldStatus = null,
        ?string $newStatus = null,
        array $metadata = []
    ): int {
        if ($requestId <= 0) {
            throw new InvalidArgumentException(
                "Invalid request ID."
            );
        }

        $actionType = trim($actionType);
        $actionTitle = trim($actionTitle);

        if ($actionType === "") {
            throw new InvalidArgumentException(
                "Activity action type is required."
            );
        }

        if ($actionTitle === "") {
            throw new InvalidArgumentException(
                "Activity title is required."
            );
        }

        $metadataJson = !empty($metadata)
            ? json_encode(
                $metadata,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
            : null;

        if (
            !empty($metadata) &&
            $metadataJson === false
        ) {
            throw new RuntimeException(
                "Unable to encode activity metadata."
            );
        }

        $stmt = $conn->prepare(
            "INSERT INTO request_activity_logs (
                request_id,
                user_id,
                action_type,
                action_title,
                description,
                old_status,
                new_status,
                metadata_json,
                created_at
            )
            VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )"
        );

        $stmt->bind_param(
            "iissssss",
            $requestId,
            $userId,
            $actionType,
            $actionTitle,
            $description,
            $oldStatus,
            $newStatus,
            $metadataJson
        );

        $stmt->execute();

        $activityId = (int) $stmt->insert_id;

        $stmt->close();

        return $activityId;
    }
}

/* =========================================================
   GET REQUEST ACTIVITIES
========================================================= */
if (!function_exists("getRequestActivities")) {
    function getRequestActivities(
        mysqli $conn,
        int $requestId,
        int $limit = 150
    ): array {
        if ($requestId <= 0) {
            return [];
        }

        $limit = max(
            1,
            min($limit, 500)
        );

        $stmt = $conn->prepare(
            "SELECT
                ral.id,
                ral.request_id,
                ral.user_id,
                ral.action_type,
                ral.action_title,
                ral.description,
                ral.old_status,
                ral.new_status,
                ral.metadata_json,
                ral.created_at,

                COALESCE(
                    u.name,
                    'System'
                ) AS actor_name,

                COALESCE(
                    u.role,
                    'system'
                ) AS actor_role

             FROM request_activity_logs ral

             LEFT JOIN users u
                ON u.id = ral.user_id

             WHERE ral.request_id = ?

             ORDER BY
                ral.created_at DESC,
                ral.id DESC

             LIMIT ?"
        );

        $stmt->bind_param(
            "ii",
            $requestId,
            $limit
        );

        $stmt->execute();

        $activities = $stmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

        $stmt->close();

        foreach ($activities as &$activity) {
            $activity["metadata"] = [];

            if (
                !empty(
                $activity["metadata_json"]
            )
            ) {
                $decodedMetadata = json_decode(
                    $activity["metadata_json"],
                    true
                );

                if (
                    is_array(
                        $decodedMetadata
                    )
                ) {
                    $activity["metadata"] =
                        $decodedMetadata;
                }
            }
        }

        unset($activity);

        return $activities;
    }
}

/* =========================================================
   ACTIVITY ICON
========================================================= */
if (!function_exists("activityIcon")) {
    function activityIcon(
        string $actionType
    ): string {
        return match ($actionType) {
            "created" =>
                "bi-plus-circle",

            "reviewed" =>
                "bi-clipboard-check",

            "approved" =>
                "bi-check-circle",

            "revision" =>
                "bi-arrow-repeat",

            "disapproved" =>
                "bi-x-circle",

            "development" =>
                "bi-tools",

            "workflow_move" =>
                "bi-kanban",

            "file_uploaded" =>
                "bi-paperclip",

            "comment" =>
                "bi-chat-left-text",

            "completed" =>
                "bi-check2-all",

            "evaluation_requested" =>
                "bi-bell",

            "evaluation_submitted" =>
                "bi-star",

            "closed" =>
                "bi-lock",

            "archived" =>
                "bi-archive",

            "restored" =>
                "bi-arrow-counterclockwise",

            "deleted" =>
                "bi-trash",

            default =>
                "bi-info-circle"
        };
    }
}

/* =========================================================
   ACTIVITY COLOR CLASS
========================================================= */
if (!function_exists("activityColorClass")) {
    function activityColorClass(
        string $actionType
    ): string {
        return match ($actionType) {
            "created",
            "completed" =>
                "activity-blue",

            "reviewed",
            "restored" =>
                "activity-cyan",

            "approved",
            "evaluation_submitted" =>
                "activity-green",

            "revision" =>
                "activity-yellow",

            "disapproved",
            "deleted" =>
                "activity-red",

            "development",
            "workflow_move" =>
                "activity-indigo",

            "comment" =>
                "activity-purple",

            "evaluation_requested" =>
                "activity-orange",

            "closed",
            "archived" =>
                "activity-dark",

            default =>
                "activity-slate"
        };
    }
}

/* =========================================================
   FORMAT ACTIVITY DATE
========================================================= */
if (!function_exists("activityDateLabel")) {
    function activityDateLabel(
        ?string $dateTime
    ): string {
        if (!$dateTime) {
            return "";
        }

        $timestamp = strtotime(
            $dateTime
        );

        if ($timestamp === false) {
            return $dateTime;
        }

        return date(
            "F d, Y • g:i A",
            $timestamp
        );
    }
}
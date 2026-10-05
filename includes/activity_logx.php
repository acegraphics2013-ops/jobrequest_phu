<?php

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
            throw new InvalidArgumentException("Invalid request ID.");
        }

        $metadataJson = $metadata
            ? json_encode(
                $metadata,
                JSON_UNESCAPED_UNICODE |
                JSON_UNESCAPED_SLASHES
            )
            : null;

        $stmt = $conn->prepare(
            "INSERT INTO request_activity_logs (
                request_id,
                user_id,
                action_type,
                action_title,
                description,
                old_status,
                new_status,
                metadata_json
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
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
        $id = (int) $stmt->insert_id;
        $stmt->close();

        return $id;
    }
}

if (!function_exists("getRequestActivities")) {
    function getRequestActivities(
        mysqli $conn,
        int $requestId,
        int $limit = 150
    ): array {
        $limit = max(1, min($limit, 500));

        $stmt = $conn->prepare(
            "SELECT
                ral.*,
                COALESCE(u.name, 'System') AS actor_name,
                COALESCE(u.role, 'system') AS actor_role
             FROM request_activity_logs ral
             LEFT JOIN users u ON u.id = ral.user_id
             WHERE ral.request_id = ?
             ORDER BY ral.created_at DESC, ral.id DESC
             LIMIT ?"
        );

        $stmt->bind_param("ii", $requestId, $limit);
        $stmt->execute();

        $rows = $stmt
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

        $stmt->close();

        return $rows;
    }
}

if (!function_exists("activityIcon")) {
    function activityIcon(string $type): string
    {
        return match ($type) {
            "created" => "bi-plus-circle",
            "reviewed" => "bi-clipboard-check",
            "approved" => "bi-check-circle",
            "revision" => "bi-arrow-repeat",
            "disapproved" => "bi-x-circle",
            "development" => "bi-tools",
            "file_uploaded" => "bi-paperclip",
            "comment" => "bi-chat-left-text",
            "completed" => "bi-check2-all",
            "evaluation_requested" => "bi-bell",
            "evaluation_submitted" => "bi-star",
            "closed" => "bi-lock",
            "archived" => "bi-archive",
            "restored" => "bi-arrow-counterclockwise",
            default => "bi-info-circle"
        };
    }
}

if (!function_exists("activityColorClass")) {
    function activityColorClass(string $type): string
    {
        return match ($type) {
            "created", "completed" => "activity-blue",
            "reviewed", "restored" => "activity-cyan",
            "approved", "evaluation_submitted" => "activity-green",
            "revision" => "activity-yellow",
            "disapproved" => "activity-red",
            "development" => "activity-indigo",
            "comment" => "activity-purple",
            "evaluation_requested" => "activity-orange",
            "closed", "archived" => "activity-dark",
            default => "activity-slate"
        };
    }
}

document.addEventListener("DOMContentLoaded", function () {
    const board = document.querySelector("[data-kanban-board]");

    if (!board) {
        return;
    }

    let draggedCard = null;

    document
        .querySelectorAll(".kanban-card")
        .forEach(function (card) {
            card.addEventListener("dragstart", function () {
                draggedCard = card;
                card.classList.add("dragging");
            });

            card.addEventListener("dragend", function () {
                card.classList.remove("dragging");
                draggedCard = null;

                document
                    .querySelectorAll(".kanban-card-list")
                    .forEach(function (list) {
                        list.classList.remove("drop-highlight");
                    });
            });
        });

    document
        .querySelectorAll(".kanban-card-list")
        .forEach(function (list) {
            list.addEventListener("dragover", function (event) {
                event.preventDefault();
                list.classList.add("drop-highlight");
            });

            list.addEventListener("dragleave", function () {
                list.classList.remove("drop-highlight");
            });

            list.addEventListener("drop", async function (event) {
                event.preventDefault();
                list.classList.remove("drop-highlight");

                if (!draggedCard) {
                    return;
                }

                const requestId = draggedCard.dataset.requestId;
                const oldStatus = draggedCard.dataset.status;
                const newStatus = list.dataset.status;
                const csrfToken = board.dataset.csrfToken;

                if (oldStatus === newStatus) {
                    list.appendChild(draggedCard);
                    return;
                }

                const formData = new FormData();
                formData.append("request_id", requestId);
                formData.append("new_status", newStatus);
                formData.append("csrf_token", csrfToken);

                try {
                    const response = await fetch(
                        board.dataset.updateUrl,
                        {
                            method: "POST",
                            body: formData,
                            headers: {
                                "X-Requested-With": "XMLHttpRequest"
                            }
                        }
                    );

                    const result = await response.json();

                    if (!response.ok || !result.success) {
                        throw new Error(
                            result.message ||
                            "Unable to update request."
                        );
                    }

                    draggedCard.dataset.status = newStatus;
                    list.appendChild(draggedCard);

                    const badge = draggedCard.querySelector(
                        "[data-card-status]"
                    );

                    if (badge) {
                        badge.textContent = newStatus;
                    }

                    window.location.reload();
                } catch (error) {
                    alert(error.message);
                    window.location.reload();
                }
            });
        });
});

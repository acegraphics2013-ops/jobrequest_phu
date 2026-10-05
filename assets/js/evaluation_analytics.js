document.addEventListener(
    "DOMContentLoaded",
    function () {
        const data =
            window.evaluationAnalyticsData || {};

        const commonOptions = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    labels: {
                        usePointStyle: true,
                        boxWidth: 8,
                        font: {
                            size: 11
                        }
                    }
                }
            }
        };

        const monthlyCanvas =
            document.getElementById(
                "monthlyTrendChart"
            );

        if (monthlyCanvas) {
            new Chart(
                monthlyCanvas,
                {
                    type: "line",
                    data: {
                        labels:
                            data.months || [],

                        datasets: [
                            {
                                label:
                                    "Submitted",
                                data:
                                    data.submitted || [],
                                borderWidth: 2,
                                tension: .35,
                                fill: false
                            },
                            {
                                label:
                                    "Completed",
                                data:
                                    data.completed || [],
                                borderWidth: 2,
                                tension: .35,
                                fill: false
                            },
                            {
                                label:
                                    "Evaluated",
                                data:
                                    data.evaluated || [],
                                borderWidth: 2,
                                tension: .35,
                                fill: false
                            }
                        ]
                    },
                    options: {
                        ...commonOptions,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                }
            );
        }

        const satisfactionCanvas =
            document.getElementById(
                "satisfactionChart"
            );

        if (satisfactionCanvas) {
            new Chart(
                satisfactionCanvas,
                {
                    type: "doughnut",
                    data: {
                        labels:
                            data.satisfactionLabels || [],

                        datasets: [
                            {
                                data:
                                    data.satisfactionValues || []
                            }
                        ]
                    },
                    options: {
                        ...commonOptions,
                        cutout: "68%"
                    }
                }
            );
        }

        const serviceCanvas =
            document.getElementById(
                "serviceChart"
            );

        if (serviceCanvas) {
            new Chart(
                serviceCanvas,
                {
                    type: "bar",
                    data: {
                        labels:
                            data.serviceLabels || [],

                        datasets: [
                            {
                                label:
                                    "Requests",
                                data:
                                    data.serviceValues || [],
                                borderWidth: 1
                            }
                        ]
                    },
                    options: {
                        ...commonOptions,
                        indexAxis: "y",
                        plugins: {
                            legend: {
                                display: false
                            }
                        },
                        scales: {
                            x: {
                                beginAtZero: true,
                                ticks: {
                                    precision: 0
                                }
                            }
                        }
                    }
                }
            );
        }
    }
);

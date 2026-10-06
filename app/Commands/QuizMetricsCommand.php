<?php

declare(strict_types=1);

namespace App\Commands;

use App\Services\MetricsCollector;

class QuizMetricsCommand
{
    private MetricsCollector $collector;

    public function __construct(?MetricsCollector $collector = null)
    {
        $this->collector = $collector ?? new MetricsCollector();
    }

    public function execute(array $args = []): void
    {
        $asJson = in_array('--json', $args, true);

        if ($asJson) {
            echo json_encode($this->collector->collect(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
            return;
        }

        echo $this->collector->toPrometheus();
    }
}

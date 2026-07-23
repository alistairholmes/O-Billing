<?php

declare(strict_types=1);

namespace App\Jobs\Sage;

use App\Models\SageOperation;
use App\Support\Tenancy\CurrentMunicipality;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Base for every Sage bridge operation. Concrete jobs wrap an existing
 * App\Services\Sage service and run on the dedicated `sage` queue, which only
 * the on-site worker (next to the council's Sage database) processes. The cloud
 * app dispatches them and never runs them itself.
 *
 * The lifecycle — mark running, run inside the operation's municipality (tenant)
 * context, mark done/failed, notify the user — lives here so each concrete job
 * only implements the actual Sage call.
 */
abstract class SageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Never auto-retry: posting to Sage must not silently run twice, and the
     * poster's own double-post guard is the only safe re-entry point.
     */
    public int $tries = 1;

    public function __construct(public SageOperation $operation)
    {
        $this->onQueue('sage');
    }

    public function handle(): void
    {
        // Sage jobs build large in-memory structures — posting a full billing
        // run holds thousands of invoice documents plus the debtor/GL lookup
        // maps at once. The worker's default 128M PHP limit OOM-kills the
        // process silently (exit 255, no output), which looks like a hang.
        // Raise it well within the container's RAM; override via env if needed.
        ini_set('memory_limit', (string) env('SAGE_JOB_MEMORY_LIMIT', '2048M'));

        $operation = $this->operation->fresh() ?? $this->operation;
        $operation->markRunning();

        try {
            [$result, $message] = app(CurrentMunicipality::class)->runFor(
                (int) $operation->municipality_id,
                fn (): array => $this->execute($operation),
            );

            $operation->markDone($result, $message);
            $this->notify($operation, success: true, body: $message);
        } catch (Throwable $e) {
            $operation->markFailed($e->getMessage());
            $this->notify($operation, success: false, body: $e->getMessage());
        }
    }

    /**
     * Perform the Sage work inside the operation's tenant context.
     *
     * @return array{0: array<string,mixed>|null, 1: string|null} [result, message]
     */
    abstract protected function execute(SageOperation $operation): array;

    /** Notification title shown in the panel bell when the job finishes. */
    abstract protected function title(SageOperation $operation): string;

    protected function notify(SageOperation $operation, bool $success, ?string $body): void
    {
        $user = $operation->user;
        if ($user === null) {
            return; // triggered from the CLI / system — nobody to notify
        }

        $notification = Notification::make()->title($this->title($operation));
        if ($body !== null && $body !== '') {
            $notification->body($body);
        }

        ($success ? $notification->success() : $notification->danger())
            ->sendToDatabase($user);
    }
}

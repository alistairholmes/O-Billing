<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">How this works</x-slot>
        <x-slot name="description">Sage Evolution → O-Billing master-data import</x-slot>

        <div class="prose prose-sm max-w-none dark:prose-invert">
            <ul>
                <li><strong>Areas</strong> — the region/district/suburb hierarchy (suburbs are the billing level)</li>
                <li><strong>Properties &amp; customers</strong> — one account per property, with valuation and owner</li>
                <li><strong>Service groups &amp; services</strong> — as service types and their priced variants</li>
                <li><strong>Tariffs</strong> — a rate for every suburb/service actually billed</li>
                <li><strong>Billing runs</strong> — the historical runs, as metadata</li>
            </ul>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Re-running is safe — existing records are refreshed, not duplicated. For the full, unmodified Sage
                detail (including every water tariff band), use the <strong>Sage (Live)</strong> browser in the sidebar.
            </p>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Currently imported</x-slot>

        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
            @foreach ($this->stats() as $label => $value)
                <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                    <div class="text-2xl font-bold tracking-tight text-gray-950 dark:text-white">
                        {{ number_format($value) }}
                    </div>
                    <div class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $label }}</div>
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>

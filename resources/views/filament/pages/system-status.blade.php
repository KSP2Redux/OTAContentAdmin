<x-filament-panels::page>
    <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-gray-600 dark:text-gray-400">
            Live connectivity and version information for the services used to publish OTA content.
        </p>
        <p class="text-xs text-gray-500 dark:text-gray-400">
            Checked {{ $checkedAt->format('M j, Y H:i T') }}
        </p>
    </div>

    <div class="grid gap-6 md:grid-cols-2 xl:grid-cols-3">
        @foreach ($cards as $card)
            <x-filament::section
                :heading="$card['title']"
                :description="$card['description']"
                :icon="$card['icon']"
            >
                <div class="space-y-4">
                    <x-filament::badge :color="$card['ok'] ? 'success' : 'danger'">
                        {{ $card['status'] }}
                    </x-filament::badge>

                    @if (! $card['ok'])
                        <div class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-950 dark:text-danger-300">
                            {{ $card['error'] }}
                        </div>
                    @else
                        <dl class="divide-y divide-gray-200 dark:divide-white/10">
                            @foreach ($card['details'] as $detail)
                                <div class="grid grid-cols-[minmax(0,1fr)_minmax(0,1.25fr)] gap-4 py-3 first:pt-0 last:pb-0">
                                    <dt class="text-sm text-gray-500 dark:text-gray-400">{{ $detail['label'] }}</dt>
                                    <dd
                                        @class([
                                            'break-words text-right text-sm font-medium text-gray-950 dark:text-white',
                                            'font-mono' => isset($detail['title']),
                                        ])
                                        @if (isset($detail['title'])) title="{{ $detail['title'] }}" @endif
                                    >
                                        {{ $detail['value'] }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>

                        @if ($card['link'] ?? null)
                            <a
                                href="{{ $card['link'] }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400"
                            >
                                {{ $card['link_label'] }}
                                <span aria-hidden="true">&rarr;</span>
                            </a>
                        @endif
                    @endif
                </div>
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>

<x-filament-panels::page>
    @if ($error)
        <div class="rounded-lg bg-danger-50 p-4 text-danger-700 dark:bg-danger-950 dark:text-danger-300">
            {{ $error }}
        </div>
    @endif

    <x-filament::section
        heading="Published OTA missions"
        description="Changes are picked up on a later campaign load. Static checks do not prove in-game behavior."
    >
        <x-ota.table label="Published OTA missions">
            <thead>
                <tr>
                    <th scope="col" class="ota-table-number">Order</th>
                    <th scope="col">ID</th>
                    <th scope="col">File</th>
                    <th scope="col">Group</th>
                    <th scope="col" class="ota-table-number">Stages</th>
                    <th scope="col" class="ota-table-number">Localization keys</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td class="ota-table-number">{{ $item['order'] }}</td>
                        <td class="ota-table-primary">{{ $item['id'] }}</td>
                        <td>
                            <code class="ota-table-code" title="{{ $item['path'] }}">{{ $item['path'] }}</code>
                        </td>
                        <td>{{ $item['group'] }}</td>
                        <td class="ota-table-number">{{ number_format($item['stages']) }}</td>
                        <td class="ota-table-number">{{ number_format(count($item['localization_keys'])) }}</td>
                        <td>
                            <a
                                class="ota-table-download"
                                href="{{ route('content.download', ['channel' => 'missions', 'path' => $item['path']]) }}"
                                title="Download {{ $item['path'] }}"
                            >
                                <x-filament::icon icon="heroicon-m-arrow-down-tray" class="size-4" />
                                <span>Download</span>
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr class="ota-table-empty">
                        <td colspan="7">No OTA missions are currently published.</td>
                    </tr>
                @endforelse
            </tbody>
        </x-ota.table>
    </x-filament::section>

    <x-filament::section heading="Bundled mission IDs" collapsible collapsed>
        <div class="grid gap-2 md:grid-cols-2">
            @foreach ($bundled as $id)
                <code>{{ $id }}</code>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>

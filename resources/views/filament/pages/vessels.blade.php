<x-filament-panels::page>
    @if ($error)
        <div class="rounded-lg bg-danger-50 p-4 text-danger-700 dark:bg-danger-950 dark:text-danger-300">
            {{ $error }}
        </div>
    @endif

    <x-filament::section
        heading="Published vessels"
        description="Changes become visible after a later game startup."
    >
        <x-ota.table label="Published main-menu vessels">
            <thead>
                <tr>
                    <th scope="col" class="ota-table-number">Order</th>
                    <th scope="col">Name</th>
                    <th scope="col">File</th>
                    <th scope="col">Author</th>
                    <th scope="col">Body</th>
                    <th scope="col" class="ota-table-number">Parts</th>
                    <th scope="col" class="ota-table-number">Mass</th>
                    <th scope="col">Bounds</th>
                    <th scope="col" class="ota-table-number">Bytes</th>
                    <th scope="col">SHA-256</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($items as $item)
                    <tr>
                        <td class="ota-table-number">{{ $item['order'] }}</td>
                        <td class="ota-table-primary">{{ $item['name'] }}</td>
                        <td>
                            <code class="ota-table-code" title="{{ $item['path'] }}">{{ $item['path'] }}</code>
                        </td>
                        <td>{{ $item['author'] }}</td>
                        <td>{{ $item['body'] }}</td>
                        <td class="ota-table-number">{{ number_format($item['parts']) }}</td>
                        <td class="ota-table-number">{{ number_format($item['mass'] ?? 0, 2) }} t</td>
                        <td class="whitespace-nowrap">{{ $item['size'] }}</td>
                        <td class="ota-table-number">{{ number_format(($item['bytes'] ?? 0) / 1024) }} KiB</td>
                        <td>
                            <code class="ota-table-code ota-table-hash" title="{{ $item['sha256'] }}">{{ $item['sha256'] }}</code>
                        </td>
                    </tr>
                @empty
                    <tr class="ota-table-empty">
                        <td colspan="10">No vessels could be loaded.</td>
                    </tr>
                @endforelse
            </tbody>
        </x-ota.table>
    </x-filament::section>
</x-filament-panels::page>

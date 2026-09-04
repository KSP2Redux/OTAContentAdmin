<x-filament-panels::page>
    @if($error)<div class="rounded-lg bg-danger-50 p-4 text-danger-700">{{ $error }}</div>@endif
    <x-filament::section heading="Published vessels" description="Changes become visible after a later game startup.">
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left"><th class="p-2">Order</th><th>Name</th><th>File</th><th>Author</th><th>Body</th><th>Parts</th><th>Mass</th><th>Bounds</th><th>Bytes</th><th>SHA-256</th></tr></thead><tbody>
        @forelse($items as $item)<tr class="border-t"><td class="p-2">{{ $item['order'] }}</td><td>{{ $item['name'] }}</td><td><code>{{ $item['path'] }}</code></td><td>{{ $item['author'] }}</td><td>{{ $item['body'] }}</td><td>{{ $item['parts'] }}</td><td>{{ number_format($item['mass'] ?? 0, 2) }} t</td><td>{{ $item['size'] }}</td><td>{{ number_format(($item['bytes'] ?? 0)/1024) }} KiB</td><td><code>{{ str($item['sha256'])->limit(12) }}</code></td></tr>
        @empty<tr><td colspan="10" class="p-4 text-gray-500">No vessels could be loaded.</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
</x-filament-panels::page>

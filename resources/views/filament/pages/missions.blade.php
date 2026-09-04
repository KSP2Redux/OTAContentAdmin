<x-filament-panels::page>
    @if($error)<div class="rounded-lg bg-danger-50 p-4 text-danger-700">{{ $error }}</div>@endif
    <x-filament::section heading="Published OTA missions" description="Changes are picked up on a later campaign load. Static checks do not prove in-game behavior.">
        <div class="overflow-x-auto"><table class="w-full text-sm"><thead><tr class="text-left"><th class="p-2">Order</th><th>ID</th><th>File</th><th>Group</th><th>Stages</th><th>Localization keys</th></tr></thead><tbody>
        @forelse($items as $item)<tr class="border-t"><td class="p-2">{{ $item['order'] }}</td><td>{{ $item['id'] }}</td><td><code>{{ $item['path'] }}</code></td><td>{{ $item['group'] }}</td><td>{{ $item['stages'] }}</td><td>{{ count($item['localization_keys']) }}</td></tr>
        @empty<tr><td colspan="6" class="p-4 text-gray-500">No OTA missions are currently published.</td></tr>@endforelse
        </tbody></table></div>
    </x-filament::section>
    <x-filament::section heading="Bundled mission IDs" collapsible collapsed><div class="grid gap-2 md:grid-cols-2">@foreach($bundled as $id)<code>{{ $id }}</code>@endforeach</div></x-filament::section>
</x-filament-panels::page>

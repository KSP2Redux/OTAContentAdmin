<x-filament-panels::page>
    @if($error)<div class="rounded-lg bg-danger-50 p-4 text-danger-700">{{ $error }}</div>@endif
    <div class="grid gap-6 md:grid-cols-2">
        <x-filament::section heading="Weblate repository"><dl class="space-y-2 text-sm">@foreach($status as $key => $value)<div><dt class="font-medium">{{ str($key)->headline() }}</dt><dd class="break-all text-gray-500">{{ is_scalar($value) ? $value : json_encode($value) }}</dd></div>@endforeach</dl></x-filament::section>
        <x-filament::section heading="Published localization feed"><p class="text-3xl font-semibold">{{ count($manifest['files'] ?? []) }}</p><p class="text-sm text-gray-500">CSV files in Content/main. Clients load updates on a later startup.</p></x-filament::section>
    </div>
</x-filament-panels::page>

<x-filament-panels::page>
    <div class="grid gap-4 md:grid-cols-3">@foreach($checks as $name => $check)<x-filament::section :heading="str($name)->headline()"><p class="font-semibold {{ $check['ok'] ? 'text-success-600' : 'text-danger-600' }}">{{ $check['ok'] ? 'Available' : 'Unavailable' }}</p><pre class="mt-2 overflow-auto text-xs">{{ json_encode($check['data'] ?? ['error' => $check['error']], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES) }}</pre></x-filament::section>@endforeach</div>
</x-filament-panels::page>

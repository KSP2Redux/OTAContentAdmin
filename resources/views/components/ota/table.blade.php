@props(['label'])

<div class="ota-table-shell">
    <div class="ota-table-scroll">
        <table aria-label="{{ $label }}" {{ $attributes->class(['ota-table']) }}>
            {{ $slot }}
        </table>
    </div>
</div>

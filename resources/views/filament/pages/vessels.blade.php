<x-filament-panels::page class="ota-vessels-page">
    @if ($error)
        <div class="rounded-lg bg-danger-50 p-4 text-danger-700 dark:bg-danger-950 dark:text-danger-300">
            {{ $error }}
        </div>
    @endif

    <x-filament::section
        heading="Published vessels"
        description="Changes become visible after a later game startup."
    >
        <div class="ota-vessels">
            <x-ota.table label="Published main-menu vessels" class="ota-vessels-table">
                <thead>
                    <tr>
                        <th scope="col">Vessel</th>
                        <th scope="col" class="ota-vessel-orbit">Orbit</th>
                        <th scope="col" class="ota-vessel-stats">Parts / mass</th>
                        <th scope="col" class="ota-vessel-actions-cell">Actions</th>
                    </tr>
                </thead>
                @forelse ($items as $item)
                    <tbody x-data="{ detailsOpen: false }" class="ota-vessel-group">
                        <tr class="ota-vessel-row">
                            <td class="ota-vessel-identity">
                                <div class="ota-vessel-heading">
                                    <span class="ota-vessel-order" title="Menu order {{ $item['order'] }}"><span class="sr-only">Menu order </span>{{ $item['order'] }}</span>
                                    <span class="ota-table-primary">{{ $item['name'] }}</span>
                                </div>
                                <p class="ota-vessel-author">by {{ $item['author'] }}</p>
                                <button
                                    type="button"
                                    class="ota-vessel-details-toggle"
                                    aria-expanded="false"
                                    x-bind:aria-expanded="detailsOpen.toString()"
                                    aria-controls="vessel-details-{{ $loop->index }}"
                                    aria-label="File details for {{ $item['name'] }}"
                                    x-on:click="detailsOpen = ! detailsOpen"
                                >
                                    <x-filament::icon icon="heroicon-m-chevron-right" class="size-4" x-bind:class="{ 'ota-vessel-details-open': detailsOpen }" />
                                    <span>File details</span>
                                </button>
                            </td>
                            <td class="ota-vessel-orbit">
                                <span class="ota-vessel-mobile-label">Orbit</span>
                                <span>{{ $item['body'] }}</span>
                            </td>
                            <td class="ota-vessel-stats">
                                <span>{{ number_format($item['parts']) }} parts</span>
                                <span class="ota-vessel-mass">{{ isset($item['mass']) ? number_format($item['mass'], 2).' t' : 'Mass unavailable' }}</span>
                            </td>
                            <td class="ota-vessel-actions-cell">
                                <div class="ota-vessel-actions" role="group" aria-label="Actions for {{ $item['name'] }}">
                                    <x-filament::button tag="a" size="sm" :href="\App\Filament\Pages\VesselEditor::getUrl(['path' => $item['path']])">
                                        Preview / edit
                                    </x-filament::button>
                                    <x-filament::button
                                        tag="a"
                                        size="sm"
                                        color="gray"
                                        icon="heroicon-m-arrow-down-tray"
                                        :href="route('content.download', ['channel' => 'main-menu-vessels', 'path' => $item['path']])"
                                        title="Download {{ $item['path'] }}"
                                    >
                                        Download
                                    </x-filament::button>
                                </div>
                            </td>
                        </tr>
                        <tr class="ota-vessel-details" id="vessel-details-{{ $loop->index }}" x-show="detailsOpen" x-cloak>
                            <td colspan="4">
                                <dl class="ota-vessel-file-info">
                                    <div>
                                        <dt>File</dt>
                                        <dd><code>{{ $item['path'] }}</code></dd>
                                    </div>
                                    <div>
                                        <dt>File size</dt>
                                        <dd>{{ number_format(($item['bytes'] ?? 0) / 1024) }} KiB</dd>
                                    </div>
                                    <div>
                                        <dt>Dimensions</dt>
                                        <dd>{{ $item['size'] ?? 'Unavailable' }}</dd>
                                    </div>
                                    <div class="ota-vessel-checksum">
                                        <dt>SHA-256</dt>
                                        <dd><code>{{ $item['sha256'] }}</code></dd>
                                    </div>
                                </dl>
                            </td>
                        </tr>
                    </tbody>
                @empty
                    <tbody>
                        <tr class="ota-table-empty">
                            <td colspan="4">{{ $error ? 'No vessels could be loaded.' : 'No vessels are currently published.' }}</td>
                        </tr>
                    </tbody>
                @endforelse
            </x-ota.table>
        </div>
    </x-filament::section>
</x-filament-panels::page>

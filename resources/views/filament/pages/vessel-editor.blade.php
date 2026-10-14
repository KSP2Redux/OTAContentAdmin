<x-filament-panels::page>
    @vite(['resources/js/vessel-editor.js'])
    <script id="vessel-editor-config" type="application/json">@json($editorConfig)</script>
    <div id="vessel-editor" wire:ignore>
        <div class="ve-toolbar">
            <label class="ve-upload">Upload vessel JSON <input id="ve-file" type="file" accept=".json,application/json"></label>
            <button id="ve-download" disabled>Download workspace</button>
            <span id="ve-status" role="status" aria-live="polite">Upload an assembly or workspace, or open a published vessel.</span>
        </div>
        <div class="ve-layout">
            <section class="ve-preview" aria-label="Vessel preview">
                <div id="ve-canvas"></div>
                <div class="ve-preview-tools">
                    <label>Display pose <select id="ve-pose"><option value="upright">Upright</option><option value="leaning">Leaning</option></select></label>
                    <button id="ve-frame">Frame vessel</button>
                    <button id="ve-in">Fly in</button><button id="ve-out">Fly out</button><button id="ve-rest">Reset playback</button>
                </div>
                <div class="ve-preview-tools">
                    <label>Rotate vessel <select id="ve-axis"><option value="x">X axis</option><option value="y">Y axis</option><option value="z">Z axis</option></select></label>
                    <button id="ve-minus">−90°</button><button id="ve-plus">+90°</button><button id="ve-flip">180°</button>
                    <span>Arrow shows travel direction. Drag to orbit; scroll to zoom.</span>
                </div>
                <p id="ve-model-status" class="ve-note">Private part models are loading.</p>
                <p class="ve-note">Untextured model preview with saved procedural wings and fairing profiles. Flight paths, display poses, lighting and effects approximate the game's menu.</p>
            </section>
            <aside class="ve-sidebar">
                <h2>Vessel</h2>
                <label>Name <input id="ve-name" maxlength="200"></label>
                <label>Mass (tonnes) <input id="ve-mass" type="number" min="0.000001" step="any"></label>
                <label>Author <input id="ve-author" maxlength="200"></label>
                <label>Background <select id="ve-body"></select></label>
                <p id="ve-stats" class="ve-note"></p>
                <h2>Deployable parts</h2>
                <div class="ve-buttons"><button id="ve-deploy-all">Deploy all</button><button id="ve-retract-all">Retract all</button></div>
                <label>Find part <input id="ve-search" placeholder="Filter by part name"></label>
                <label>Part <select id="ve-part" size="8" aria-label="Vessel parts"></select></label>
                <label class="ve-checkbox"><input id="ve-deployed" type="checkbox" disabled> <span id="ve-deploy-label">Deployed</span></label>
                <p id="ve-part-note" class="ve-note">Select a part in the list or click it in the preview.</p>
                <h2>Stage change</h2>
                <label>Change set <select id="ve-change-set"><option value="">Choose a draft change set</option></select></label>
                <button id="ve-create-change-set" type="button" x-on:click="$wire.mountAction('createChangeSet')">+ Create change set</button>
                <label>Operation <select id="ve-action"><option value="add">Add</option><option value="replace">Replace</option></select></label>
                <label>Filename slug <input id="ve-slug" pattern="[a-z0-9][a-z0-9-]*" placeholder="my-station"></label>
                <button id="ve-stage" disabled>Save to change set</button>
                <p class="ve-note">This saves a draft. Publication still requires the change set's validation and publish action.</p>
            </aside>
        </div>
    </div>
</x-filament-panels::page>

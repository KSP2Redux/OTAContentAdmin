<?php

namespace App\Http\Controllers;

use App\Models\ChangeOperation;
use App\Services\Content\CompatibilityCatalog;
use App\Services\Content\Data\ChangeSetContext;
use App\Services\Content\Data\UploadedArtifact;
use App\Services\Content\VesselContentHandler;
use App\Services\Integrations\GitHubContentRepository;
use App\Services\Publishing\OperationStager;
use App\Services\Publishing\PayloadStorage;
use App\Services\Vessels\PartModelLibrary;
use App\Services\Vessels\VesselWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VesselEditorController extends Controller
{
    public function draft(ChangeOperation $operation, PayloadStorage $payloads, VesselWorkspace $workspaces)
    {
        abort_unless($operation->channel === 'main-menu-vessels' && in_array($operation->action, ['add', 'replace'], true) && $operation->payload_path, 404);
        abort_unless(in_array($operation->changeSet->state, ['draft', 'validated'], true), 409, 'This change set is not editable.');

        return response()->json($workspaces->convert($payloads->get($operation->payload_path)) + ['published' => $operation->metadata + ['path' => $operation->path, 'action' => $operation->action, 'change_set_id' => $operation->change_set_id]]);
    }

    public function convert(Request $request, VesselWorkspace $workspaces)
    {
        $request->validate(['file' => ['required', 'file', 'max:51200']]);

        return response()->json($workspaces->convert($request->file('file')->getContent()));
    }

    public function published(string $path, GitHubContentRepository $github, VesselWorkspace $workspaces)
    {
        abort_unless(preg_match('/\A[a-z0-9][a-z0-9._-]*\.json\z/', $path) && ! str_contains($path, '..'), 404);
        $entry = collect($github->manifest('main-menu-vessels')['files'] ?? [])->firstWhere('path', $path);
        abort_unless($entry, 404);
        $contents = $github->file('main-menu-vessels', $path);
        abort_unless(hash_equals($entry['sha256'], hash('sha256', $contents)), 502, 'Published checksum does not match.');

        return response()->json($workspaces->convert($contents) + ['published' => $entry]);
    }

    public function edit(Request $request, VesselWorkspace $workspaces)
    {
        $data = $request->validate([
            'contents' => ['required', 'string', 'max:10485760'], 'deployment' => ['sometimes', 'array', 'max:5000'],
            'deployment.*' => ['boolean'], 'all_deployed' => ['sometimes', 'boolean'],
            'rotation' => ['sometimes', 'array:axis,degrees'], 'rotation.axis' => ['required_with:rotation', Rule::in(['x', 'y', 'z'])],
            'rotation.degrees' => ['required_with:rotation', Rule::in([-180, -90, 90, 180])],
            'name' => ['sometimes', 'string', 'max:200'], 'mass' => ['sometimes', 'numeric', 'gt:0', 'max:1e12'],
        ]);

        return response()->json($workspaces->edit($data['contents'], $data));
    }

    public function stage(Request $request, VesselWorkspace $workspaces, VesselContentHandler $handler, OperationStager $stager)
    {
        $data = $request->validate([
            'contents' => ['required', 'string', 'max:10485760'], 'change_set_id' => ['required', 'uuid'],
            'action' => ['required', Rule::in(['add', 'replace'])], 'slug' => ['required', 'regex:/^[a-z0-9][a-z0-9-]*$/', 'max:180'],
            'path' => ['nullable', 'regex:/^[a-z0-9][a-z0-9._-]*\.json$/', 'max:200'],
            'name' => ['required', 'string', 'max:200'], 'mass' => ['required', 'numeric', 'gt:0', 'max:1e12'],
            'author' => ['required', 'string', 'max:200'], 'body' => ['required', Rule::in(app(CompatibilityCatalog::class)->bodies())],
        ]);
        $prepared = $workspaces->edit($data['contents'], ['name' => $data['name'], 'mass' => $data['mass']]);
        $path = $data['action'] === 'replace' ? ($data['path'] ?? $data['slug'].'.json') : $data['slug'].'.json';
        $metadata = ['author' => $data['author'], 'body' => $data['body']];
        $report = $handler->validate(new ChangeSetContext(new UploadedArtifact($path, $prepared['contents'], $metadata)));
        if (! $report->passes()) {
            throw ValidationException::withMessages(['contents' => implode(' ', $report->errors)]);
        }
        $upload = 'drafts/vessels/'.Str::uuid().'.json';
        Storage::disk('ota-private')->put($upload, $prepared['contents']);
        try {
            $operation = $stager->stage('main-menu-vessels', $data['action'], $data + ['upload' => $upload, 'metadata' => $metadata, 'path' => $path]);
        } finally {
            Storage::disk('ota-private')->delete($upload);
        }

        return response()->json(['message' => 'Vessel staged. Validate the change set before publishing.', 'change_set_id' => $operation->change_set_id]);
    }

    public function catalog(PartModelLibrary $library)
    {
        $catalog = $library->catalog();
        foreach ($catalog['parts'] as $name => &$part) {
            foreach (['retracted', 'extended'] as $pose) {
                $part[$pose] = isset($part[$pose]) ? route('vessel.models.file', ['part' => $name, 'pose' => $pose]).'?v='.($part['hashes'][$pose] ?? '') : null;
            }
        }

        return response()->json($catalog)->header('Cache-Control', 'private, no-cache');
    }

    public function model(string $part, string $pose, PartModelLibrary $library)
    {
        abort_unless(preg_match('/\A[a-z0-9][a-z0-9._-]*\z/', $part) && ! str_contains($part, '..') && in_array($pose, ['retracted', 'extended'], true), 404);
        $entry = $library->catalog()['parts'][$part] ?? null;
        $path = $entry[$pose] ?? null;
        abort_unless(is_string($path) && preg_match('/\A[a-z0-9][a-z0-9._-]*\.glb\z/', $path) && ! str_contains($path, '..'), 404);
        abort_unless(Storage::disk('vessel-models')->exists($path), 404);

        return Storage::disk('vessel-models')->response($path, null, ['Content-Type' => 'model/gltf-binary', 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff']);
    }
}

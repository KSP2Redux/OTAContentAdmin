<?php

namespace App\Services\Publishing;

use App\Models\ChangeSet;
use App\Services\Integrations\GitHubContentRepository;
use Illuminate\Support\Facades\Validator;

final readonly class ChangeSetCreator
{
    public function __construct(private GitHubContentRepository $github) {}

    public function create(string $summary): ChangeSet
    {
        $user = auth()->user();
        abort_unless($user && in_array(config('ota.auth.publisher_group'), $user->groups ?? [], true), 403);
        $data = Validator::make(['summary' => trim($summary)], ['summary' => ['required', 'string', 'max:255']])->validate();
        $revision = $this->github->headSha();

        return ChangeSet::create([
            'summary' => $data['summary'],
            'user_id' => $user->getKey(),
            'base_content_sha' => $revision,
            'state' => 'draft',
        ]);
    }
}

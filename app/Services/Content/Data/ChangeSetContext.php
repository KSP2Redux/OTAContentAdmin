<?php

namespace App\Services\Content\Data;

final readonly class ChangeSetContext
{
    public function __construct(public UploadedArtifact $artifact, public array $liveManifest = [], public array $candidateMissionIds = []) {}
}

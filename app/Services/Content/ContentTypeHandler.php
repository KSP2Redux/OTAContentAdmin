<?php

namespace App\Services\Content;

use App\Models\ChangeOperation;
use App\Services\Content\Data\ChangeSetContext;
use App\Services\Content\Data\ChannelSnapshot;
use App\Services\Content\Data\ContentDiff;
use App\Services\Content\Data\GeneratedManifest;
use App\Services\Content\Data\InspectionReport;
use App\Services\Content\Data\NormalizedArtifact;
use App\Services\Content\Data\UploadedArtifact;
use App\Services\Content\Data\ValidationReport;

interface ContentTypeHandler
{
    public function inspect(UploadedArtifact $artifact): InspectionReport;

    public function validate(ChangeSetContext $context): ValidationReport;

    public function normalize(UploadedArtifact $artifact): NormalizedArtifact;

    public function buildManifest(ChannelSnapshot $snapshot): GeneratedManifest;

    public function renderDiff(ChangeOperation $operation): ContentDiff;
}

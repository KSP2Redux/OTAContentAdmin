<?php

namespace App\Services\Content;

use App\Services\Content\Data\ChangeSetContext;
use App\Services\Content\Data\InspectionReport;
use App\Services\Content\Data\NormalizedArtifact;
use App\Services\Content\Data\UploadedArtifact;
use App\Services\Content\Data\ValidationReport;
use Throwable;

final class MissionContentHandler extends AbstractJsonContentHandler
{
    public function inspect(UploadedArtifact $artifact): InspectionReport
    {
        $data = $this->decoded($artifact->contents);
        $types = [];
        $keys = [];
        $references = [];
        $this->walk($data, $types, $keys, $references);

        return new InspectionReport([
            'id' => $data['ID'] ?? null,
            'group' => $data['MissionGroup'] ?? null,
            'granter' => $data['MissionGranterKey'] ?? null,
            'stages' => count($data['missionStages'] ?? []),
            'types' => array_values(array_unique($types)),
            'localization_keys' => array_values(array_unique($keys)),
            'mission_references' => array_values(array_unique($references)),
        ]);
    }

    public function validate(ChangeSetContext $context): ValidationReport
    {
        $errors = [];
        if (! $this->safePath($context->artifact->path)) {
            $errors[] = 'Filename must be a lowercase JSON slug.';
        }
        if (strlen($context->artifact->contents) > config('ota.limits.mission')) {
            $errors[] = 'Mission exceeds the 2 MiB limit.';
        }
        try {
            $data = $this->decoded($context->artifact->contents);
            $report = $this->inspect($context->artifact)->metadata;
            if (! is_string($report['id']) || trim($report['id']) === '') {
                $errors[] = 'Mission ID is required.';
            }
            if (! is_array($data['missionStages'] ?? null) || $data['missionStages'] === []) {
                $errors[] = 'Mission must contain at least one stage.';
            }
            $stageIds = array_map(fn ($stage) => $stage['StageID'] ?? null, $data['missionStages'] ?? []);
            if (count($stageIds) !== count(array_unique($stageIds, SORT_REGULAR))) {
                $errors[] = 'Mission stage IDs must be unique.';
            }
            foreach ($report['types'] as $type) {
                if (! in_array($type, $this->catalog->missionTypes(), true)) {
                    $errors[] = "Unsupported mission node type: {$type}";
                }
            }
            $known = array_unique([...$this->catalog->bundledMissionIds(), ...$context->candidateMissionIds]);
            foreach ($report['mission_references'] as $id) {
                if ($id !== '' && ! in_array($id, $known, true)) {
                    $errors[] = "Unknown mission reference: {$id}";
                }
            }
        } catch (Throwable $exception) {
            $errors[] = 'Invalid mission JSON: '.$exception->getMessage();
        }

        return new ValidationReport($errors);
    }

    public function normalize(UploadedArtifact $artifact): NormalizedArtifact
    {
        $contents = $this->normalizer->normalize($artifact->contents, false);

        return new NormalizedArtifact($artifact->path, $contents, hash('sha256', $contents), strlen($contents), $artifact->metadata);
    }

    private function walk(mixed $value, array &$types, array &$keys, array &$references, ?string $property = null): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $this->walk($child, $types, $keys, $references, is_string($key) ? $key : $property);
            }

            return;
        }
        if (! is_string($value)) {
            return;
        }
        if ($property === '$type') {
            $types[] = $value;
        }
        if (in_array($property, $this->catalog->localizationFields(), true) && preg_match($this->catalog->localizationPattern(), $value)) {
            $keys[] = $value;
        }
        if (in_array($property, ['MissionID', 'missionID', 'parentMissionID', 'TargetMissionID'], true) && str_starts_with($value, 'KSP2Mission_')) {
            $references[] = $value;
        }
    }
}

<?php

namespace App\Services\Content;

use App\Services\Content\Data\ChangeSetContext;
use App\Services\Content\Data\InspectionReport;
use App\Services\Content\Data\NormalizedArtifact;
use App\Services\Content\Data\UploadedArtifact;
use App\Services\Content\Data\ValidationReport;
use Throwable;

final class VesselContentHandler extends AbstractJsonContentHandler
{
    public function inspect(UploadedArtifact $artifact): InspectionReport
    {
        $data = $this->decoded($artifact->contents);
        $metadata = $data['Metadata'] ?? $data['metadata'] ?? [];
        $assemblies = $data['Assemblies'] ?? $data['assemblies'] ?? [];
        $parts = $this->findFirstArray($data, ['Parts', 'parts']);
        $bounds = $assemblies[0]['boundsSize'] ?? null;

        return new InspectionReport([
            'name' => $metadata['Name'] ?? $metadata['VehicleName'] ?? $metadata['WorkspaceName'] ?? $metadata['workspaceName'] ?? pathinfo($artifact->path, PATHINFO_FILENAME),
            'assemblies' => is_array($assemblies) ? count($assemblies) : 0,
            'parts' => is_numeric($metadata['Parts'] ?? null) ? (int) $metadata['Parts'] : (is_array($parts) ? count($parts) : 0),
            'actual_parts' => is_array($parts) ? count($parts) : 0,
            'mass' => is_numeric($metadata['Mass'] ?? null) ? (float) $metadata['Mass'] : null,
            'size' => is_array($bounds) ? sprintf('%.1f × %.1f × %.1f m', $bounds['x'] ?? 0, $bounds['y'] ?? 0, $bounds['z'] ?? 0) : null,
            'bytes' => strlen($artifact->contents),
        ]);
    }

    public function validate(ChangeSetContext $context): ValidationReport
    {
        $errors = [];
        if (! $this->safePath($context->artifact->path)) {
            $errors[] = 'Filename must be a lowercase JSON slug.';
        }
        if (strlen($context->artifact->contents) > config('ota.limits.vessel')) {
            $errors[] = 'Vessel exceeds the 10 MiB limit.';
        }
        try {
            $report = $this->inspect($context->artifact);
            if (($report->metadata['assemblies'] ?? 0) < 1) {
                $errors[] = 'Vessel has no assemblies.';
            }
            if (($report->metadata['parts'] ?? 0) < 1 || ($report->metadata['actual_parts'] ?? 0) < 1) {
                $errors[] = 'Vessel has no parts.';
            }
            if (($report->metadata['parts'] ?? 0) !== ($report->metadata['actual_parts'] ?? 0)) {
                $errors[] = 'Workspace part statistics do not match the serialized part collection.';
            }
            if (! is_float($report->metadata['mass'] ?? null) || ! is_finite($report->metadata['mass']) || $report->metadata['mass'] <= 0) {
                $errors[] = 'Workspace mass must be a positive finite number.';
            }
            if (empty($context->artifact->metadata['author'])) {
                $errors[] = 'Author is required.';
            }
            if (! in_array($context->artifact->metadata['body'] ?? null, $this->catalog->bodies(), true)) {
                $errors[] = 'Body is not supported by the compatibility catalog.';
            }
        } catch (Throwable $exception) {
            $errors[] = 'Invalid vessel JSON: '.$exception->getMessage();
        }

        return new ValidationReport($errors);
    }

    public function normalize(UploadedArtifact $artifact): NormalizedArtifact
    {
        $contents = $this->normalizer->normalize($artifact->contents, true);

        return new NormalizedArtifact($artifact->path, $contents, hash('sha256', $contents), strlen($contents), $artifact->metadata);
    }

    private function findFirstArray(array $value, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($value[$key]) && is_array($value[$key])) {
                return $value[$key];
            }
        }
        foreach ($value as $child) {
            if (is_array($child) && ($found = $this->findFirstArray($child, $keys)) !== []) {
                return $found;
            }
        }

        return [];
    }
}

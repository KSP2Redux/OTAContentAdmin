<?php

namespace App\Services\Content;

use JsonException;

final class LosslessJsonNormalizer
{
    private int $at = 0;

    private string $text = '';

    /** @throws JsonException */
    public function normalize(string $json, bool $trimTypeNames = false): string
    {
        json_decode($json, true, 128, JSON_THROW_ON_ERROR);
        $this->text = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
        $this->at = 0;
        $node = $this->readValue();
        $this->skipWhitespace();
        if ($this->at !== strlen($this->text)) {
            throw new JsonException('Unexpected data after JSON document.');
        }

        return $this->write($node, $trimTypeNames);
    }

    private function skipWhitespace(): void
    {
        while ($this->at < strlen($this->text) && ctype_space($this->text[$this->at])) {
            $this->at++;
        }
    }

    private function readValue(): array
    {
        $this->skipWhitespace();
        $char = $this->text[$this->at] ?? '';
        if ($char === '{') {
            return $this->readObject();
        }
        if ($char === '[') {
            return $this->readArray();
        }

        return ['kind' => 'scalar', 'raw' => $this->readScalar()];
    }

    private function readObject(): array
    {
        $this->at++;
        $this->skipWhitespace();
        $items = [];
        if (($this->text[$this->at] ?? '') === '}') {
            $this->at++;

            return ['kind' => 'object', 'items' => []];
        }
        while (true) {
            $this->skipWhitespace();
            $key = $this->readString();
            $this->skipWhitespace();
            if (($this->text[$this->at++] ?? '') !== ':') {
                throw new JsonException('Expected colon.');
            }
            $items[] = [$key, $this->readValue()];
            $this->skipWhitespace();
            $char = $this->text[$this->at++] ?? '';
            if ($char === '}') {
                break;
            } if ($char !== ',') {
                throw new JsonException('Expected comma or object end.');
            }
        }

        return ['kind' => 'object', 'items' => $items];
    }

    private function readArray(): array
    {
        $this->at++;
        $this->skipWhitespace();
        $items = [];
        if (($this->text[$this->at] ?? '') === ']') {
            $this->at++;

            return ['kind' => 'array', 'items' => []];
        }
        while (true) {
            $items[] = $this->readValue();
            $this->skipWhitespace();
            $char = $this->text[$this->at++] ?? '';
            if ($char === ']') {
                break;
            } if ($char !== ',') {
                throw new JsonException('Expected comma or array end.');
            }
        }

        return ['kind' => 'array', 'items' => $items];
    }

    private function readString(): string
    {
        $start = $this->at++;
        while ($this->at < strlen($this->text)) {
            $char = $this->text[$this->at++];
            if ($char === '\\') {
                $this->at++;
            } elseif ($char === '"') {
                break;
            }
        }

        return substr($this->text, $start, $this->at - $start);
    }

    private function readScalar(): string
    {
        if (($this->text[$this->at] ?? '') === '"') {
            return $this->readString();
        }
        $start = $this->at;
        while ($this->at < strlen($this->text) && ! str_contains(",}] \t\r\n", $this->text[$this->at])) {
            $this->at++;
        }

        return substr($this->text, $start, $this->at - $start);
    }

    private function write(array $node, bool $trimTypeNames): string
    {
        if ($node['kind'] === 'scalar') {
            return $trimTypeNames ? preg_replace('/, Version=\d+(?:\.\d+)*, Culture=[\w-]+, PublicKeyToken=\w+/', '', $node['raw']) : $node['raw'];
        }
        if ($node['kind'] === 'array') {
            return '['.implode(',', array_map(fn ($item) => $this->write($item, $trimTypeNames), $node['items'])).']';
        }
        $parts = [];
        foreach ($node['items'] as [$key, $value]) {
            if ($value['kind'] === 'scalar' && $value['raw'] === 'null') {
                continue;
            }
            $parts[] = $key.':'.$this->write($value, $trimTypeNames);
        }

        return '{'.implode(',', $parts).'}';
    }
}

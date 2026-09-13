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
        if (! json_validate($json, 128)) {
            throw new JsonException(json_last_error_msg(), json_last_error());
        }

        $this->text = preg_replace('/^\xEF\xBB\xBF/', '', $json) ?? $json;
        $this->at = 0;
        $normalized = $this->readValue($trimTypeNames)['text'];
        $this->skipWhitespace();
        if ($this->at !== strlen($this->text)) {
            throw new JsonException('Unexpected data after JSON document.');
        }

        return $normalized;
    }

    private function skipWhitespace(): void
    {
        while ($this->at < strlen($this->text) && ctype_space($this->text[$this->at])) {
            $this->at++;
        }
    }

    /** @return array{text: string, null: bool} */
    private function readValue(bool $trimTypeNames): array
    {
        $this->skipWhitespace();
        $char = $this->text[$this->at] ?? '';
        if ($char === '{') {
            return ['text' => $this->readObject($trimTypeNames), 'null' => false];
        }
        if ($char === '[') {
            return ['text' => $this->readArray($trimTypeNames), 'null' => false];
        }

        $raw = $this->readScalar();

        return [
            'text' => $trimTypeNames ? preg_replace('/, Version=\d+(?:\.\d+)*, Culture=[\w-]+, PublicKeyToken=\w+/', '', $raw) : $raw,
            'null' => $raw === 'null',
        ];
    }

    private function readObject(bool $trimTypeNames): string
    {
        $this->at++;
        $this->skipWhitespace();
        $output = '{';
        $first = true;
        if (($this->text[$this->at] ?? '') === '}') {
            $this->at++;

            return '{}';
        }
        while (true) {
            $this->skipWhitespace();
            $key = $this->readString();
            $this->skipWhitespace();
            if (($this->text[$this->at++] ?? '') !== ':') {
                throw new JsonException('Expected colon.');
            }
            $value = $this->readValue($trimTypeNames);
            if (! $value['null']) {
                $output .= ($first ? '' : ',').$key.':'.$value['text'];
                $first = false;
            }
            $this->skipWhitespace();
            $char = $this->text[$this->at++] ?? '';
            if ($char === '}') {
                break;
            }
            if ($char !== ',') {
                throw new JsonException('Expected comma or object end.');
            }
        }

        return $output.'}';
    }

    private function readArray(bool $trimTypeNames): string
    {
        $this->at++;
        $this->skipWhitespace();
        $output = '[';
        $first = true;
        if (($this->text[$this->at] ?? '') === ']') {
            $this->at++;

            return '[]';
        }
        while (true) {
            $value = $this->readValue($trimTypeNames);
            $output .= ($first ? '' : ',').$value['text'];
            $first = false;
            $this->skipWhitespace();
            $char = $this->text[$this->at++] ?? '';
            if ($char === ']') {
                break;
            }
            if ($char !== ',') {
                throw new JsonException('Expected comma or array end.');
            }
        }

        return $output.']';
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
}

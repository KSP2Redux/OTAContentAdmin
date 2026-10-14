<?php

namespace App\Services\Vessels;

use JsonException;

/** Changes selected JSON paths without round-tripping unrelated numbers. */
final class LosslessJsonEditor
{
    private string $text;

    private int $at;

    private array $changes;

    private array $captures;

    private array $found;

    private array $insertions;

    public function patch(string $json, array $changes): string
    {
        return $this->run($json, $changes, [])[0];
    }

    public function extract(string $json, array $paths): array
    {
        return $this->run($json, [], $paths)[1];
    }

    public static function pointer(array $path): string
    {
        return '/'.implode('/', array_map(fn ($v) => str_replace(['~', '/'], ['~0', '~1'], (string) $v), $path));
    }

    private function run(string $json, array $changes, array $captures): array
    {
        if (! json_validate($json, 128)) {
            throw new JsonException('Invalid JSON: '.json_last_error_msg());
        }
        $this->text = $json;
        $this->at = 0;
        $this->changes = $changes;
        $this->insertions = [];
        foreach ($changes as $pointer => $value) {
            $slash = strrpos($pointer, '/');
            $parent = substr($pointer, 0, $slash);
            $name = str_replace(['~1', '~0'], ['/', '~'], substr($pointer, $slash + 1));
            $this->insertions[$parent][$name] = $value;
        }
        $this->captures = array_fill_keys($captures, true);
        $this->found = [];
        $result = $this->value('');

        return [$result, $this->found];
    }

    private function whitespace(): void
    {
        while (isset($this->text[$this->at]) && ctype_space($this->text[$this->at])) {
            $this->at++;
        }
    }

    private function value(string $path): string
    {
        $this->whitespace();
        $start = $this->at;
        $char = $this->text[$this->at];
        if ($char === '{') {
            $this->at++;
            $items = [];
            $seen = [];
            $this->whitespace();
            while ($this->text[$this->at] !== '}') {
                $this->whitespace();
                $key = $this->string();
                $name = json_decode($key, true, 128, JSON_THROW_ON_ERROR);
                $seen[$name] = true;
                $this->whitespace();
                $this->at++;
                $child = $path.self::pointer([$name]);
                $items[] = $key.':'.$this->value($child);
                $this->whitespace();
                if ($this->text[$this->at] !== ',') {
                    break;
                }
                $this->at++;
            }
            $this->at++;
            foreach ($this->insertions[$path] ?? [] as $name => $replacement) {
                if (! isset($seen[$name])) {
                    $items[] = json_encode($name, JSON_THROW_ON_ERROR).':'.$this->encoded($replacement);
                }
            }
            $result = '{'.implode(',', $items).'}';
        } elseif ($char === '[') {
            $this->at++;
            $items = [];
            $this->whitespace();
            while ($this->text[$this->at] !== ']') {
                $items[] = $this->value($path.'/'.count($items));
                $this->whitespace();
                if ($this->text[$this->at] !== ',') {
                    break;
                }
                $this->at++;
            }
            $this->at++;
            $result = '['.implode(',', $items).']';
        } elseif ($char === '"') {
            $result = $this->string();
        } else {
            while (isset($this->text[$this->at]) && ! str_contains(",}] \t\r\n", $this->text[$this->at])) {
                $this->at++;
            }
            $result = substr($this->text, $start, $this->at - $start);
        }
        if (isset($this->captures[$path])) {
            $this->found[$path] = substr($this->text, $start, $this->at - $start);
        }

        return array_key_exists($path, $this->changes) ? $this->encoded($this->changes[$path]) : $result;
    }

    private function string(): string
    {
        $start = $this->at++;
        while (isset($this->text[$this->at])) {
            $char = $this->text[$this->at++];
            if ($char === '\\') {
                $this->at++;
            } elseif ($char === '"') {
                break;
            }
        }

        return substr($this->text, $start, $this->at - $start);
    }

    private function encoded(mixed $value): string
    {
        if ($value instanceof RawJsonValue) {
            return $value->contents;
        }

        return json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}

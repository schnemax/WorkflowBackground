<?php
namespace App\Mapping;

final class FieldMapRepository
{
    public function __construct(private string $file) {}
    public function get(): array {
        if (!is_file($this->file)) return [];
        $data = json_decode((string)file_get_contents($this->file), true);
        return is_array($data) ? $data : [];
    }
}

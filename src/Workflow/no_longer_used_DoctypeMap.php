<?php

namespace App\Workflow;

final class DoctypeMap
{
    private array $data;

    public function __construct(string $file)
    {
        $this->data = is_readable($file)
            ? (json_decode(file_get_contents($file), true) ?: [])
            : [];
        $this->data += ['items' => [], 'ttl' => 3600];
    }

    /** Abstrakte Liste (für Frontend) */
    public function itemsForFrontend(): array
    {
        // nur id+label rausgeben
        return array_map(fn($it) => ['id' => $it['id'], 'label' => $it['label']], $this->data['items']);
    }

    /** Paperless-ID zu abstrakter ID auflösen (für Patch im Worker) */
    public function paperlessIdOf(string $abstractId): ?int
    {
        foreach ($this->data['items'] as $it) {
            if ($it['id'] === $abstractId) return (int)($it['paperless_id'] ?? 0) ?: null;
        }
        return null;
    }

    public function ttl(): int
    {
        return (int)($this->data['ttl'] ?? 3600);
    }
}

<?php
// src/Workflow/DoctypeService.php
namespace App\Workflow;

use App\Paperless\Client;

final class DoctypeService
{
    public function __construct(
        private Client $paperless,
        private string $cacheFile,      // z.B. /tmp/worker_doctypes.cache.json
        private int $ttlSeconds = 3600  // via ENV DOCTYPES_TTL übersteuerbar
    ) {}

    /** Liefert [{id: <slug>, label: <name>}, ...] – nur was Yii braucht */
    public function listForFrontend(): array
    {
        //$map = $this->loadCache();
        //if (!$map || $this->isExpired($map)) {
            $map = $this->refresh();
        //}
        // frontend-Form
        //\App\Log::j('INFO', 'listforfrontend', ['map' => $map]);
        $items = [];
        foreach ($map['by_slug'] as $slug => $row) {
            $items[] = ['id' => $slug, 'label' => $row['name'] ?? $slug];
        }
        // sortiere nach Label
        usort($items, fn($a, $b) => strnatcasecmp($a['label'], $b['label']));
        return $items;
    }

    /** Für PATCH: slug -> paperless id */
    public function resolveIdBySlug(string $slug): ?int
    {
        $slug = strtolower(trim($slug));
        $map  = $this->loadCache();
        if (!$map || $this->isExpired($map)) {
            $map = $this->refresh();
        }
        $row = $map['by_slug'][$slug] ?? null;
        return $row ? (int)$row['id'] : null;
    }

    // ---- intern ----

    private function refresh(): array
    {
        // holt paginiert alle Types
        $raw = $this->paperless->listDocumentTypes('/api/document_types/');
        //\App\Log::j('INFO', 'refresj', ['map' => $raw]);
        $results = $raw['results'] ?? (isset($raw[0]) ? $raw : []);
        
        $bySlug = [];
        foreach ($results as $it) {
            $id   = (int)($it['id'] ?? 0);
            $name = trim((string)($it['name'] ?? ''));
            $slug = strtolower(trim((string)($it['slug'] ?? '')));

            if ($id <= 0) continue;
            if ($slug === '') $slug = $this->slugify($name ?: ('type_' . $id));

            $bySlug[$slug] = ['id' => $id, 'slug' => $slug, 'name' => $name];
        }
        $map = ['fetched_at' => time(), 'ttl' => $this->ttlSeconds, 'by_slug' => $bySlug];
        $this->saveCache($map);
        return $map;
    }

    private function loadCache(): ?array
    {
        if (!is_file($this->cacheFile)) return null;
        $json = @file_get_contents($this->cacheFile);
        if ($json === false) return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function saveCache(array $data): void
    {
        @file_put_contents($this->cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function isExpired(array $map): bool
    {
        $t0  = (int)($map['fetched_at'] ?? 0);
        $ttl = (int)($map['ttl'] ?? $this->ttlSeconds);
        return (time() - $t0) > max(60, $ttl);
    }

    private function slugify(string $name): string
    {
        $s = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $s = strtolower(preg_replace('~[^a-z0-9]+~', '-', $s));
        return trim($s, '-');
    }
}

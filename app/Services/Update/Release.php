<?php

namespace App\Services\Update;

use Carbon\CarbonImmutable;

/**
 * A published GitHub release that carries the deployable package (tukahr-<version>.zip + .sha256).
 */
final readonly class Release
{
    public function __construct(
        public string $version,
        public string $tag,
        public string $name,
        public string $notes,
        public ?CarbonImmutable $publishedAt,
        public string $url,
        public string $packageUrl,
        public string $checksumUrl,
        public int $size,
    ) {}

    public function isNewerThan(string $version): bool
    {
        // A source checkout ("dev") can always move to a real release.
        return $version === 'dev' || version_compare($this->version, $version, '>');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'tag' => $this->tag,
            'name' => $this->name,
            'notes' => $this->notes,
            'published_at' => $this->publishedAt?->toIso8601String(),
            'url' => $this->url,
            'package_url' => $this->packageUrl,
            'checksum_url' => $this->checksumUrl,
            'size' => $this->size,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            version: (string) $data['version'],
            tag: (string) $data['tag'],
            name: (string) ($data['name'] ?? $data['tag']),
            notes: (string) ($data['notes'] ?? ''),
            publishedAt: isset($data['published_at']) ? CarbonImmutable::parse($data['published_at']) : null,
            url: (string) ($data['url'] ?? ''),
            packageUrl: (string) $data['package_url'],
            checksumUrl: (string) $data['checksum_url'],
            size: (int) ($data['size'] ?? 0),
        );
    }
}

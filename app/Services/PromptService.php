<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PromptRepository;

final class PromptService
{
    public function __construct(private readonly PromptRepository $repository) {}

    public function getActivePrompt(string $type): string
    {
        $row = $this->repository->findActiveByType($type);
        return $row['content'] ?? '';
    }

    public function createVersion(string $type, string $content, string $createdBy): int
    {
        return $this->repository->createVersion($type, $content, $createdBy);
    }

    public function activateVersion(int $id): void
    {
        $this->repository->activateVersion($id);
    }
}

<?php

namespace App\Support\Import;

interface CatalogueImporter
{
    public function type(): string;

    public function fetch(array $credential, int $limit): array;
}

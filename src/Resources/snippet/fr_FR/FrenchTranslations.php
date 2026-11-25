<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Resources\snippet\fr_FR;

use Shopware\Core\System\Snippet\Files\AbstractSnippetFile;

class FrenchTranslations extends AbstractSnippetFile
{
    public function getName(): string
    {
        return 'messages.fr-FR';
    }

    public function getPath(): string
    {
        return __DIR__ . '/messages.fr-FR.json';
    }

    public function getIso(): string
    {
        return 'fr-FR';
    }

    public function getAuthor(): string
    {
        return 'Buckaroo';
    }

    public function isBase(): bool
    {
        return false;
    }

    public function getTechnicalName(): string
    {
        return $this->getName();
    }
}

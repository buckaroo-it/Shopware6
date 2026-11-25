<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Resources\snippet\de_DE;

use Shopware\Core\System\Snippet\Files\AbstractSnippetFile;

class GermanTranslations extends AbstractSnippetFile
{
    public function getName(): string
    {
        return 'messages.de-DE';
    }

    public function getPath(): string
    {
        return __DIR__ . '/messages.de-DE.json';
    }

    public function getIso(): string
    {
        return 'de-DE';
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

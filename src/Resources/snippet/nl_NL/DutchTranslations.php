<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Resources\snippet\nl_NL;

use Shopware\Core\System\Snippet\Files\AbstractSnippetFile;

class DutchTranslations extends AbstractSnippetFile
{
    public function getName(): string
    {
        return 'messages.nl-NL';
    }

    public function getPath(): string
    {
        return __DIR__ . '/messages.nl-NL.json';
    }

    public function getIso(): string
    {
        return 'nl-NL';
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

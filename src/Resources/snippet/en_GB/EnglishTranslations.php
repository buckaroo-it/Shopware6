<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Resources\snippet\en_GB;

use Shopware\Core\System\Snippet\Files\AbstractSnippetFile;

class EnglishTranslations extends AbstractSnippetFile
{
    public function getName(): string
    {
        return 'messages.en-GB';
    }

    public function getPath(): string
    {
        return __DIR__ . '/messages.en-GB.json';
    }

    public function getIso(): string
    {
        return 'en-GB';
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

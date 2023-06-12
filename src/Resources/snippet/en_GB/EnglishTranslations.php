<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Resources\snippet\en_GB;

use Shopware\Core\System\Snippet\Files\AbstractSnippetFile;

class EnglishTranslations extends AbstractSnippetFile
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'messages.en-GB';
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return __DIR__ . '/messages.en-GB.json';
    }

    /**
     * @return string
     */
    public function getIso(): string
    {
        return 'en-GB';
    }

    /**
     * @return string
     */
    public function getAuthor(): string
    {
        return 'Buckaroo';
    }

    /**
     * @return bool
     */
    public function isBase(): bool
    {
        return false;
    }

    public function getTechnicalName(): string
    {
        return $this->getName();
    }
}

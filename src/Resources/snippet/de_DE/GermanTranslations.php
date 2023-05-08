<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Resources\snippet\de_DE;

use Shopware\Core\System\Snippet\Files\AbstractSnippetFile;

class GermanTranslations extends AbstractSnippetFile
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'messages.de-DE';
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return __DIR__ . '/messages.de-DE.json';
    }

    /**
     * @return string
     */
    public function getIso(): string
    {
        return 'de-DE';
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

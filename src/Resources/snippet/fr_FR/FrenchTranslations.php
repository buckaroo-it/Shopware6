<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Resources\snippet\fr_FR;

use Shopware\Core\System\Snippet\Files\AbstractSnippetFile;

class FrenchTranslations extends AbstractSnippetFile
{
    /**
     * @return string
     */
    public function getName(): string
    {
        return 'messages.fr-FR';
    }

    /**
     * @return string
     */
    public function getPath(): string
    {
        return __DIR__ . '/messages.fr-FR.json';
    }

    /**
     * @return string
     */
    public function getIso(): string
    {
        return 'fr-FR';
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

<?php

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Framework\Context;
use Symfony\Component\Mime\Email;

class MailService extends AbstractMailService
{

    /**
     * @var AbstractMailService
     */
    private AbstractMailService $mailService;

    public function __construct(AbstractMailService $mailService)
    {
        $this->mailService = $mailService;
    }

    public function getDecorated(): AbstractMailService
    {
        return $this->mailService;
    }

    public function send(array $data, Context $context, array $templateData = []): ?Email
    {
        return $this->mailService->send($data, $context, $templateData);
    }
}

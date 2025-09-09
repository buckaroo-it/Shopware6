<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\ParameterBag;
use Shopware\Core\Checkout\Document\DocumentIdStruct;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Checkout\Document\FileGenerator\FileTypes;
use Shopware\Core\Checkout\Document\Renderer\InvoiceRenderer;
use Shopware\Core\Checkout\Document\Service\DocumentGenerator;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Document\Struct\DocumentGenerateOperation;
use Shopware\Core\Checkout\Document\Exception\InvalidDocumentException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class InvoiceService
{
    protected SettingsService $settingsService;

    protected EntityRepository $documentRepository;

    protected DocumentGenerator $documentGenerator;

    protected AbstractMailService $mailService;

    protected EntityRepository $mailTemplateRepository;

    public function __construct(
        SettingsService $settingsService,
        DocumentGenerator $documentGenerator,
        EntityRepository $documentRepository,
        AbstractMailService $mailService,
        EntityRepository $mailTemplateRepository
    ) {
        $this->settingsService = $settingsService;
        $this->documentGenerator = $documentGenerator;
        $this->documentRepository = $documentRepository;
        $this->mailService = $mailService;
        $this->mailTemplateRepository = $mailTemplateRepository;
    }
    public function isInvoiced(string $orderId, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addAssociation('documentMediaFile');
        $criteria->addAssociation('documentType');
        $criteria->addFilter(new EqualsFilter('documentType.technicalName', InvoiceRenderer::TYPE));

        return $this->documentRepository->search($criteria, $context)->first() !== null;
    }

    /**
     * @param mixed $brqTransactionType
     * @param mixed $serviceName
     * @param string|null $salesChannelId
     *
     * @return boolean
     */
    public function isCreateInvoiceAfterShipment(
        $brqTransactionType = false,
        $serviceName = false,
        string $salesChannelId = null
    ): bool {
        if ($brqTransactionType) {
            if (
                ResponseStatus::BUCKAROO_BILLINK_CAPTURE_TYPE_ACCEPT == $brqTransactionType &&
                $this->settingsService->getSetting('BillinkCreateInvoiceAfterShipment', $salesChannelId)
            ) {
                return true;
            }
        } else {
            if (
                $serviceName == 'Billink' &&
                $this->settingsService->getSetting('BillinkMode', $salesChannelId) == 'authorize' &&
                $this->settingsService->getSetting('BillinkCreateInvoiceAfterShipment', $salesChannelId)
            ) {
                return true;
            }
        }
        return false;
    }

    public function generateInvoice(
        OrderEntity $order,
        Context $context,
        string $salesChannelId = null
    ): ?DocumentIdStruct {

        $operation = new DocumentGenerateOperation($order->getId(), FileTypes::PDF);

        /** @var DocumentIdStruct|null */
        $invoice = $this->documentGenerator->generate(
            InvoiceRenderer::TYPE,
            [$order->getId() => $operation],
            $context
        )->getSuccess()->first();


        if ($this->settingsService->getSetting('sendInvoiceEmail', $salesChannelId) && $invoice !== null) {
            $documentIds = [$invoice->getId()];
            $technicalName = 'order_transaction.state.paid';
            $mailTemplate = $this->getMailTemplate($context, $technicalName);

            if ($mailTemplate !== null) {
                // Preserve the original context to maintain locale, permissions, and sales channel state
                $this->sendMail(
                    $context,
                    $mailTemplate,
                    $order,
                    $documentIds
                );
            }
        }

        return $invoice;
    }

    private function getMailTemplate(Context $context, string $technicalName): ?MailTemplateEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', $technicalName));
        $criteria->setLimit(1);

        /** @var MailTemplateEntity|null $mailTemplate */
        $mailTemplate = $this->mailTemplateRepository->search($criteria, $context)->first();

        return $mailTemplate;
    }

    /**
     * @param string[] $documentIds
     */
    private function sendMail(
        Context $context,
        MailTemplateEntity $mailTemplate,
        OrderEntity $order,
        array $documentIds = []
    ): void {
        $customer = $order->getOrderCustomer();
        if ($customer === null) {
            return;
        }

        $data = new ParameterBag();
        $data->set(
            'recipients',
            [
                $customer->getEmail() => $customer->getFirstName() . ' ' . $customer->getLastName(),
            ]
        );

        $data->set('senderName', $mailTemplate->getTranslation('senderName'));
        $data->set('salesChannelId', $order->getSalesChannelId());
        $contentHtml = 'Hello! Your invoice attached';
        $data->set('contentHtml', $contentHtml);
        $data->set('contentPlain', $contentHtml);
        $data->set('subject', $mailTemplate->getTranslation('subject'));

        $documents = [];
        foreach ($documentIds as $documentId) {
            $documents[] = $this->getDocument($documentId, $context);
        }

        if (!empty($documents)) {
            $data->set('binAttachments', $documents);
        }

        $this->mailService->send(
            $data->all(),
            $context,
            [
                'order' => $order,
                'salesChannel' => $order->getSalesChannel(),
            ]
        );

        $writes = array_map(static function ($id) {
            return ['id' => $id, 'sent' => true];
        }, $documentIds);

        if (!empty($writes)) {
            $this->documentRepository->update($writes, $context);
        }
    }


    /**
     * @param string $documentId
     * @param Context $context
     *
     * @return array<mixed>
     * @throws \Throwable
     */
    private function getDocument(string $documentId, Context $context): array
    {
        $document = $this->documentGenerator->readDocument($documentId, $context);

        if ($document === null) {
            // Fallback to generic exception to avoid phpstan missing class errors across versions
            throw new \RuntimeException('Invalid document: ' . $documentId);
        }

        return [
            'content' => $document->getContent(),
            'fileName' => $document->getName(),
            'mimeType' => $document->getContentType(),
        ];
    }
}

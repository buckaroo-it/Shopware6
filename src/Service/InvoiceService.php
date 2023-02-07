<?php

declare(strict_types=1);

namespace Buckaroo\Shopware6\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Checkout\Order\OrderEntity;
use Symfony\Component\HttpFoundation\ParameterBag;
use Shopware\Core\Checkout\Document\DocumentEntity;
use Shopware\Core\Checkout\Document\DocumentService;
use Buckaroo\Shopware6\Helpers\Constants\ResponseStatus;
use Shopware\Core\Checkout\Document\DocumentConfiguration;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Checkout\Document\FileGenerator\FileTypes;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Checkout\Document\DocumentGenerator\InvoiceGenerator;
use Shopware\Core\Checkout\Document\DocumentIdStruct;
use Shopware\Core\Checkout\Document\Exception\InvalidDocumentException;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

class InvoiceService
{
    protected SettingsService $settingsService;

    protected EntityRepositoryInterface $documentRepository;

    protected DocumentService $documentService;

    protected AbstractMailService $mailService;

    protected EntityRepositoryInterface $mailTemplateRepository;

    public function __construct(
        SettingsService $settingsService,
        DocumentService $documentService,
        EntityRepositoryInterface $documentRepository,
        AbstractMailService $mailService,
        EntityRepositoryInterface $mailTemplateRepository
    ) {
        $this->settingsService = $settingsService;
        $this->documentService = $documentService;
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
        $criteria->addFilter(new EqualsFilter('documentType.technicalName', InvoiceGenerator::INVOICE));

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
            if (ResponseStatus::BUCKAROO_BILLINK_CAPTURE_TYPE_ACCEPT == $brqTransactionType &&
                $this->settingsService->getSetting('BillinkCreateInvoiceAfterShipment', $salesChannelId)
            ) {
                return true;
            }
        } else {
            if ($serviceName == 'Billink' &&
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
        string $invoiceNumber,
        string $salesChannelId = null
    ): DocumentIdStruct {
        $documentConfiguration = new DocumentConfiguration();
        $documentConfiguration->setDocumentNumber($invoiceNumber);
        $invoice = $this->documentService->create(
            $order->getId(),
            InvoiceGenerator::INVOICE,
            FileTypes::PDF,
            $documentConfiguration,
            $context
        );

        if ($this->settingsService->getSetting('sendInvoiceEmail', $salesChannelId)) {
            $documentIds = [$invoice->getId()];
            $technicalName = 'order_transaction.state.paid';
            $mailTemplate = $this->getMailTemplate($context, $technicalName);

            if ($mailTemplate !== null) {
                $context = Context::createDefaultContext();
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
     * @throws InvalidDocumentException
     */
    private function getDocument(string $documentId, Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('id', $documentId));
        $criteria->addAssociation('documentMediaFile');
        $criteria->addAssociation('documentType');

        /** @var DocumentEntity|null $documentEntity */
        $documentEntity = $this->documentRepository->search($criteria, $context)->get($documentId);

        if ($documentEntity === null) {
            throw new InvalidDocumentException($documentId);
        }

        $document = $this->documentService->getDocument($documentEntity, $context);

        return [
            'content' => $document->getFileBlob(),
            'fileName' => $document->getFilename(),
            'mimeType' => $document->getContentType(),
        ];
    }
}

<?php declare(strict_types=1);


namespace Buckaroo\Shopware6\Service;

use Buckaroo\Shopware6\Service\Exceptions\CreateCartException;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Cart\CartPersister;
use Shopware\Core\Checkout\Cart\CartCalculator;
use Shopware\Core\Checkout\Cart\Event\CartChangedEvent;
use Shopware\Core\Checkout\Cart\LineItemFactoryRegistry;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Cart\Event\AfterLineItemAddedEvent;
use Shopware\Core\Checkout\Cart\Event\BeforeLineItemAddedEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class CartService {

    /**
     * @var CartCalculator
     */
    private $cartCalculator;

    /**
     * @var CartPersister
     */
    private $cartPersister;

    /**
     * @var LineItemFactoryRegistry
     */
    private $lineItemFactory;


     /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    protected $items = [];

    /**
     *
     * @var SalesChannelContext
     */
    protected $salesChannelContext;

    /**
     * @internal
     */
    public function __construct(
        CartCalculator $cartCalculator,
        CartPersister $cartPersister,
        LineItemFactoryRegistry $lineItemFactory,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->cartCalculator = $cartCalculator;
        $this->cartPersister = $cartPersister;
        $this->lineItemFactory = $lineItemFactory;
        $this->eventDispatcher = $eventDispatcher;
        
    }

    public function load(string $token)
    {
        $this->validateSaleChannelContext();
        return $this->cartPersister->load($token, $this->salesChannelContext);
    }

    /**
     * Set salesChannelContext
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return self
     */
    public function setSaleChannelContext(SalesChannelContext $salesChannelContext)
    {
        $this->salesChannelContext = $salesChannelContext;
        return $this;
    }
    public function addItem(array $item)
    {
        try {
            $this->items[] =  $this->lineItemFactory->create($item, $this->salesChannelContext);
        } catch (\Throwable $th) {
            throw new CreateCartException('Cannot add item to card',1 , $th);
        }
        return $this;
    }

    /**
     * Validate saleChannelContext
     *
     * @return void
     */
    private function validateSaleChannelContext()
    {
        if (!$this->salesChannelContext instanceof SalesChannelContext) {
            throw new CreateCartException('SaleChannelContext is required');
        }
    }
    public function build()
    {
        $this->validateSaleChannelContext();

        if (count($this->items) === 0) {
            throw new CreateCartException('Cannot create cart, a least one item is required');
        }

        $cart = new Cart(
            'paypal-express',
            Uuid::randomHex()
        );

        foreach ($this->items as $item) {
            $cart->add($item);

            $this->eventDispatcher->dispatch(
                new BeforeLineItemAddedEvent(
                    $item,
                    $cart,
                    $this->salesChannelContext,
                    $cart->has($item->getId())
                )
            );
        }

        $cart->markModified();

        $cart = $this->cartCalculator->calculate($cart, $this->salesChannelContext);
        $this->cartPersister->save($cart, $this->salesChannelContext);

        $this->eventDispatcher->dispatch(new AfterLineItemAddedEvent($this->items, $cart, $this->salesChannelContext));
        $this->eventDispatcher->dispatch(new CartChangedEvent($cart, $this->salesChannelContext));

        return $cart;
    }
}
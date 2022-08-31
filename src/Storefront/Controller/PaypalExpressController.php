<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Storefront\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Storefront\Controller\StorefrontController;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Buckaroo\Shopware6\Storefront\Exceptions\InvalidParameterException;

/**
 * @RouteScope(scopes={"storefront"})
 */
class PaypalExpressController extends StorefrontController
{

    /**
     * @Route("buckaroo/paypal/create", name="frontend.action.buckaroo.paypalExpressCreate",  options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function create(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {

        var_dump($this->getFormData($request));
        
        
        return new JsonResponse([
            'errors' => false,
            'request' => $request->request->get('order'),
            'salesContext' => $salesChannelContext
        ]);
    }
    /**
     * @Route("buckaroo/paypal/pay", name="frontend.action.buckaroo.paypalExpressPay",  options={"seo"="false"}, methods={"POST"}, defaults={"XmlHttpRequest"=true})
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return RedirectResponse
     */
    public function pay(Request $request, SalesChannelContext $salesChannelContext): JsonResponse
    {
        return new JsonResponse([
            'errors'=> false
        ]);
    }

    /**
     * Get form data form request
     *
     * @param Request $request
     *
     * @return DataBag
     */
    protected function getFormData(Request $request)
    {
        if (!$request->request->has('form')) {
            throw new InvalidParameterException("Invalid payment request, form data is missing", 1);
        }
        return new DataBag((array)$request->request->get('form'));
    }

    /**
     * Get product data from from data
     *
     * @param DataBag $formData
     *
     * @return DataBag
     */
    protected function getProductData(DataBag $formData)
    {
        $productData = [];
        foreach ($formData as $key => $value) {
            if (strpos($key, 'lineItems') !== false) {
                $keyPars = explode("][", $key);
                $newKey = isset($keyPars[1]) ? str_replace("]","",$keyPars[1]) : $key;
                $productData[$newKey] = $value;
            }
        }
        return new DataBag($productData);
    }
    /**
     * Check if request is from product page
     *
     * @param Request $request
     *
     * @return boolean
     * @throws InvalidParameterException
     */
    protected function isFromProductPage(Request $request)
    {
        if (!$request->request->has('page')) {
            throw new InvalidParameterException("Invalid payment request, page is missing", 1);
        }
        return $request->request->get('page') === 'product';
    }
    /**
     * Get address from paypal
     *
     * @param Request $request
     *
     * @return DataBag
     * @throws InvalidParameterException
     */
    protected function getAddress(Request $request)
    {
        $orderData = $this->getOrderData($request);
        if (!$orderData->has('shipping_address')) {
            throw new InvalidParameterException("Invalid payment request, shipping is missing", 1);
        }
        return $orderData->get('shipping');
    }

    /**
     * Get paypal order data from request
     *
     * @param Request $request
     *
     * @return DataBag
     * @throws InvalidParameterException
     */
    protected function getOrderData(Request $request)
    {
        if (!($request->request->has('order') && is_array($request->request->get('order')))) {
            throw new InvalidParameterException("Invalid payment request", 1);
        }
        return new DataBag((array)$request->request->get('order'));
    }
}

<?php

namespace Buckaroo\Shopware6\Buckaroo\Payload;

use Enlight_Controller_Front;
use Enlight_Controller_Request_Request;
use Shopware_Components_Config;
use ArrayAccess;
use Buckaroo\Shopware6\Helpers\Arrayable;
use Buckaroo\Shopware6\Helpers\Helpers;
use Buckaroo\Shopware6\Helpers\Config;

class PaymentResult implements ArrayAccess, Arrayable
{
	/**
	 * @var Enlight_Controller_Request_Request
	 */
	protected $request;

	/**
	 * @var BuckarooPayment\Components\Config
	 */
	protected $config;

	/**
	 * @var array
	 */
	protected $data = [];

	public function __construct(Enlight_Controller_Front $front, Config $config)
	{
		$this->request = $front->Request();
		$this->config = $config;
	}

	public function getData($key = null)
	{
		if( empty($this->data) )
		{
			$contentType = $this->request->getHeader('content-type');

            $data = [];

			if( Helpers::stringContains($contentType, 'json') )
			{
				$data = json_decode($this->getPost('json_data_key'), true);
			}
			else
			{
				$data = $this->request->getPost();
			}

            /**
             * Rewrite keys to uppercase
             * Support Uppercase, lowercase and uppercase + lowercase for BPE push
             */
            foreach( $data as $k => $value )
            {
                $this->data[strtoupper($k)] = $value;
            }

		}

		if( !is_null($key) )
		{
			return $this->offsetGet($key);
		}

		return $this->data;
	}

	/** Implement ArrayAccess */
    public function offsetSet($offset, $value)
    {
        throw new Exception("Can't set a value of a PaymentResult");
    }

    /** Implement ArrayAccess */
    public function offsetExists($offset)
    {
        return isset($this->data[$offset]);
    }

    /** Implement ArrayAccess */
    public function offsetUnset($offset)
    {
        unset($this->data[$offset]);
    }

    /** Implement ArrayAccess */
    public function offsetGet($offset)
    {
        return isset($this->data[$offset]) ? $this->data[$offset] : null;
    }

    public function isTest()
    {
        $getBrqTest = $this->getData('BRQ_TEST');
    	return !empty($getBrqTest);
    }

    /**
     * Check the signature of the message is correct
     *
     * @return boolean
     */
    public function isValid()
    {
        // check website key matches
        $websiteKey = trim($this->config->websiteKey());
        if( $websiteKey != $this->getWebsiteKey() ) return false;

        // get POST data
        $data = $this->request->getPost();

        $validateData = [];

        foreach( $data as $key => $value )
        {
            // payconiq validation breaks if you use urldecode();
            $valueToValidate = ($data['brq_transaction_method'] == "Payconiq") || ($data['brq_payment_method'] == "Payconiq") ? $value : urldecode($value);

            // sorting should be case-insensitive, so just make all keys uppercase
            $uppercaseKey = strtoupper($key);

            // add to array if
            // key should be included
            // and it is not a signature (BRQ_SIGNATURE) (AND ADD_SIGNATURE)
            if( in_array(mb_substr($uppercaseKey, 0, 4), [ 'BRQ_', 'ADD_', 'CUST' ]) && !Helpers::stringContains($uppercaseKey, 'SIGNATURE') )
            {
                $validateData[$uppercaseKey] = $key . '=' . $valueToValidate;
            }
        }

        $numbers = array_flip([ 0, 1, 2, 3, 4, 5, 6, 7, 8, 9 ]);
        $chars = array_flip(str_split('ABCDEFGHIJKLMNOPQRSTUVWXYZ'));

        // sort keys (first _, then numbers, then chars)
        uksort($validateData, function($a, $b) use ($numbers, $chars) {

            // get uppercase character array of strings
            $aa = str_split(strtoupper($a));
            $bb = str_split(strtoupper($b));

            // get length of strings
            $aLength = count($aa);
            $bLength = count($bb);

            // get lowest string length
            $minLength = min( $aLength, $bLength );

            // loop through characters
            for( $i=0; $i < $minLength; $i++ )
            {
                // get type of a
                $aType = 0;
                if( isset($numbers[ $aa[$i] ]) ) $aType = 1;
                if( isset($chars[ $aa[$i] ]) ) $aType = 2;

                // get type of b
                $bType = 0;
                if( isset($numbers[ $bb[$i] ]) ) $bType = 1;
                if( isset($chars[ $bb[$i] ]) ) $bType = 2;

                // first compare type
                if( $aType < $bType ) return -1;
                if( $aType > $bType ) return 1;

                // if type is the same, compare type value
                $cmp = strcasecmp($aa[$i], $bb[$i]);
                if( $aType === 1 ) $cmp = ( $aa[$i] < $bb[$i] ? -1 : ($aa[$i] > $bb[$i] ? 1 : 0) );

                // if both the same, go to the next character
                if( $cmp !== 0 ) return $cmp;
            }

            // if both strings are equal, select on string length
            return ( $aLength < $bLength ? -1 : ($aLength > $bLength ? 1 : 0) );
        });

        // join strings + the secret key
        $dataString = implode('', $validateData) . trim($this->config->secretKey());

        // check Buckaroo signature matches
        return hash_equals( sha1($dataString), trim($this->getData('BRQ_SIGNATURE')) );
    }

    /**
     * @return string
     */
	public function getTransactionKey()
	{
		return trim($this->getData('BRQ_TRANSACTIONS'));
	}

    /**
     * @return string
     */
    public function getWebsiteKey()
    {
    	return trim($this->getData('BRQ_WEBSITEKEY'));
    }

    /**
     * @return string
     */
	public function getToken()
	{
		return trim($this->getData('ADD_TOKEN'));
	}

    /**
     * @return string
     */
	public function getSignature()
	{
		return trim($this->getData('ADD_SIGNATURE'));
	}

    /**
     * @return float
     */
	public function getAmount()
	{
		return $this->getData('BRQ_AMOUNT');
    }
    
    /**
     * @return float
     */
	public function getAmountCredit()
	{
		return $this->getData('BRQ_AMOUNT_CREDIT');
	}    

    /**
     * @return string
     */
	public function getCurrency()
	{
		return $this->getData('BRQ_CURRENCY');
	}

    /**
     * @return string
     */
	public function getInvoice()
	{
		return $this->getData('BRQ_INVOICENUMBER');
	}

	/**
	 * Get the status code of the Buckaroo response
	 *
	 * @return int Buckaroo Response status
	 */
	public function getStatusCode()
	{
		return $this->getData('BRQ_STATUSCODE');
    }

	/**
	 * Get the ordernumber of the Buckaroo response
	 *
	 * @return string Buckaroo Response status
	 */
	public function getOrdernumber()
	{
		return $this->getData('BRQ_ORDERNUMBER');
	}  
    
	/**
	 * Get the Mutation Type of the Buckaroo response
	 *
	 * @return string Buckaroo Response status
	 */
	public function getMutationType()
	{
		return $this->getData('BRQ_MUTATIONTYPE');
	}    

    
	/**
	 * Get the transaction Type of the Buckaroo response
	 *
	 * @return string Buckaroo Response status
	 */
	public function getTransactionType()
	{
		return $this->getData('BRQ_TRANSACTION_TYPE');
	}

    /**
     * Get the status subcode of the Buckaroo response
     *
     * @return string Buckaroo status subcode
     */
    public function getSubStatusCode()
    {
        return $this->getData('BRQ_STATUSCODE_DETAIL');
    }

    /**
     * Get the Buckaroo key for the paymentmethod
     *
     * @return string
     */
    public function getServiceName()
    {
        return $this->getData('BRQ_TRANSACTION_METHOD');
    }

    /**
     * Get the returned service parameters
     *
     * @return array [ key => value ]
     */
    public function getServiceParameters()
    {
        $params = [];

        foreach( $this->getData() as $key => $value )
        {
            if( Helpers::stringStartsWith($key, 'BRQ_SERVICE_' . strtoupper($this->getServiceName())) )
            {
                // To get key:
                // split on '_', take last part, toLowerCase
                $paramKey = strtolower(array_pop(explode('_', $key)));

                $params[ $paramKey ] = $value;
            }
        }

        return $params;
    }

    /** Implement Arrayable */
    public function toArray()
    {
        return $this->getData();
    }
}

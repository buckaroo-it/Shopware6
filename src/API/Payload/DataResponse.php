<?php

namespace Buckaroo\Shopware6\API\Payload;

use Buckaroo\Shopware6\API\Payload\TransactionResponse;

/**
 * DataResponse inherits from TransactionResponse
 * All differences between the two are fixed here
 */
class DataResponse extends TransactionResponse
{
	/**
	 * Set an additional parameter
	 * Structure is AdditionalParameters -> List
	 * 
     * @return array [ name => value ]
     */
    public function getAdditionalParameters()
    {
        if( !empty($this->data['AdditionalParameters']['List']) )
        {
            $parameters = $this->data['AdditionalParameters']['List'];

            $params = [];

            foreach ($parameters as $key => $parameter)
            {
                $params[ $parameter['Name'] ] = $parameter['Value'];
            }

            return $params;
        }

        return [];
    }
}

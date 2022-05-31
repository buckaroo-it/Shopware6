<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\Shopware6\Helpers\Config;
use Buckaroo\Shopware6\Helpers\Helpers;

/**
 * Class to create the security header for Buckaroo
 * https://dev.buckaroo.nl/Apis/Description/json
 */
class HmacHeader
{
    /**
     * Sales channel id required to config parameter 
     *
     * @var string
     */
    protected $salesChannelId = null;
    /**
     * @var \Buckaroo\Shopware6\Helpers\Config
     */
    protected $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }
    
    /**
     * Set channel id
     *
     * @param string|null $salesChannelId
     *
     * @return void
     */
    public function setSalesChannelId(string $salesChannelId = null)
    {
        $this->salesChannelId = $salesChannelId;
    }

    /**
     * getHeader
     * Get Hmac header for a request
     *
     * @param  [string] $requestUri Url to call
     * @param  [string] $content    Data to send
     * @param  [string] $httpMethod Should be GET or POST
     * @param  [string] $nonce      [optional] Nonce to be used
     * @param  [string] $timeStamp  [optional] TimeStamp to be used
     *
     * @return [string]             Hmac header
     */
    public function getHeader($requestUri, $content, $httpMethod, $nonce = '', $timeStamp = '')
    {
        if (empty($nonce)) {
            $nonce = $this->getNonce();
        }

        if (empty($timeStamp)) {
            $timeStamp = $this->getTimeStamp();
        }

        $encodedContent = $this->getEncodedContent($content);
        $httpMethod     = strtoupper($httpMethod);

        $requestUri = $this->escapeRequestUri($requestUri);

        $hmac = "Authorization: hmac " . implode(':', [
            $this->config->websiteKey($this->salesChannelId),
            $this->getHash($requestUri, $httpMethod, $encodedContent, $nonce, $timeStamp),
            $nonce,
            $timeStamp,
        ]);

        return $hmac;
    }

    protected function getNonce()
    {
        $length = 16;
        return Helpers::stringRandom($length);
    }

    protected function getTimeStamp()
    {
        return time();
    }

    protected function getHash($requestUri, $httpMethod, $encodedContent, $nonce, $timeStamp)
    {
        $rawData = $this->config->websiteKey($this->salesChannelId) . $httpMethod . $requestUri . $timeStamp . $nonce . $encodedContent;

        $hash = hash_hmac('sha256', $rawData, $this->config->secretKey($this->salesChannelId), true);

        $base64 = base64_encode($hash);

        return $base64;
    }

    protected function getEncodedContent($content = '')
    {
        if ($content) {
            $md5    = md5($content, true);
            $base64 = base64_encode($md5);
            return $base64;
        }

        return $content;
    }

    protected function escapeRequestUri($requestUri)
    {
        $requestUri = Helpers::stringRemoveStart($requestUri, 'http://');
        $requestUri = Helpers::stringRemoveStart($requestUri, 'https://');

        return strtolower(rawurlencode($requestUri));
    }
}

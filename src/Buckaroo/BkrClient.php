<?php declare (strict_types = 1);

namespace Buckaroo\Shopware6\Buckaroo;

use Buckaroo\Shopware6\Buckaroo\HmacHeader;
use Buckaroo\Shopware6\Buckaroo\Payload\Request;
use Exception;
use Psr\Log\LoggerInterface;
use Buckaroo\Shopware6\Buckaroo\Payload\Response;

class BkrClient
{
    const METHOD_GET  = 'GET';
    const METHOD_POST = 'POST';

    protected $validMethods = [
        self::METHOD_GET,
        self::METHOD_POST,
    ];

    /**
     * @var Buckaroo\Shopware6\Buckaroo\HmacHeader
     */
    protected $hmac;

    /**
     * @var Buckaroo\Shopware6\Buckaroo\SoftwareHeader
     */
    protected $software;

    /**
     * @var \Buckaroo\Shopware6\Buckaroo\CultureHeader
     */
    protected $culture;

    /**
     * @var Psr\Log\LoggerInterface
     */
    protected $logger;

    public function __construct(HmacHeader $hmac, SoftwareHeader $software, CultureHeader $culture,
        LoggerInterface $logger
    )
    {
        $this->hmac     = $hmac;
        $this->software = $software;
        $this->culture  = $culture;
        $this->logger = $logger;
    }

    /**
     * Set channel id used for hmac generation
     *
     * @param string|null $salesChannelId
     *
     * @return void
     */
    public function setSalesChannelId(string $salesChannelId = null)
    {
        $this->hmac->setSalesChannelId($salesChannelId);
    }

    protected function initCurl()
    {
        //Initializes the curl instance
        $curl = curl_init();

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Shopware');

        return $curl;
    }

    protected function getHeaders($url, $data, $method, $locale)
    {
        $this->logger->info(__METHOD__ . "|1|", [$this->culture->getHeader($locale),$locale]);
        return [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            $this->hmac->getHeader($url, $data, $method),
            $this->software->getHeader(),
            $this->culture->getHeader($locale),
        ];
    }

    protected function call(
        string $url,
        string $method = self::METHOD_GET,
        Request $data = null,
        string $responseClass = Response::class,
        string $locale = ''
    )
    {
        $this->logger->info(__METHOD__ . "|1|", [$url, $method, $data]);

        if (!in_array($method, $this->validMethods)) {
            throw new Exception('Invalid HTTP-Methode: ' . $method);
        }

        if (!$data) {
            $data = new Request;
        }

        $curl = $this->initCurl();

        $json = json_encode($data, JSON_PRETTY_PRINT);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $json);

        curl_setopt($curl, CURLOPT_TIMEOUT, 60);

        // all headers have to be set at once
        $headers = $this->getHeaders($url, $json, $method, $locale);
        $headers = array_merge($headers, $data->getHeaders());
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        // get response headers
        $responseHeaders = [];
        $this->getCurlHeaders($curl, $responseHeaders);

        // GET/POST
        $result = curl_exec($curl);

        $curlInfo = curl_getinfo($curl);

        // check for curl errors
        if ($result === false) {
            $this->logger->info(__METHOD__ . "|5|", [curl_error($curl)]);
            throw new Exception('Buckaroo API curl error: ' . curl_error($curl));
        }

        $this->logger->info(__METHOD__ . "|10|", [$result]);

        $decodedResult = json_decode($result, true);

        // check for json_decode errors
        if ($decodedResult === null) {
            $jsonErrors = [
                JSON_ERROR_NONE      => 'No error occurred',
                JSON_ERROR_DEPTH     => 'The maximum stack depth has been reached',
                JSON_ERROR_CTRL_CHAR => 'Control character issue, maybe wrong encoded',
                JSON_ERROR_SYNTAX    => 'Syntaxerror',
            ];

            throw new Exception('Buckaroo API json error: ' . (!empty($jsonErrors[json_last_error()]) ? $jsonErrors[json_last_error()] : 'JSON decode error') . ": " . print_r($result, true));
        }

        curl_close($curl);

        $response = new $responseClass($decodedResult, $curlInfo, $responseHeaders);

        return $response;
    }

    /**
     * Do a get request
     *
     * @param string $url
     * @param string $responseClass
     *
     * @return mixed
     */
    public function get(
        string $url,
        string $responseClass = Response::class
    )
    {
        return $this->call($url, self::METHOD_GET, null, $responseClass);
    }

    /**
     * Do a post request
     *
     * @param string $url
     * @param Request|null $data
     * @param string $locale
     * @param string $responseClass
     *
     * @return mixed
     */
    public function post(
        string $url,
        Request $data = null,
        string $responseClass = Response::class,
        string $locale=''
    )
    {
        return $this->call($url, self::METHOD_POST, $data, $responseClass, $locale);
    }

    protected function getCurlHeaders($curl, &$headers)
    {
        curl_setopt($curl, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$headers) {
            $length = strlen($header);
            $header = explode(':', $header, 2);

            if (count($header) < 2) // ignore invalid headers
            {
                return $length;
            }

            $name = strtolower(trim($header[0]));

            if (!array_key_exists($name, $headers)) {
                $headers[$name] = [trim($header[1])];
            } else {
                $headers[$name][] = trim($header[1]);
            }

            return $length;
        });
    }
}

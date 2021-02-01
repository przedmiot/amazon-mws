<?php

namespace MCS;

use DateTime;
use Exception;
use League\Csv\Reader;
use League\Csv\Writer;
use MCS\Exception\HTTP\BadRequest;
use MCS\Exception\HTTP\Forbidden;
use MCS\Exception\HTTP\NotFound;
use MCS\Exception\HTTP\ServiceUnavailable;
use MCS\Exception\MWSClientException;
use MCS\Exception\MWSCommon\AccessDenied;
use MCS\Exception\MWSCommon\InputStreamDisconnected;
use MCS\Exception\MWSCommon\InternalError;
use MCS\Exception\MWSCommon\InvalidAccessKeyId;
use MCS\Exception\MWSCommon\InvalidAddress;
use MCS\Exception\MWSCommon\InvalidParameterValue;
use MCS\Exception\MWSCommon\QuotaExceeded;
use MCS\Exception\MWSCommon\RequestThrottled;
use MCS\Exception\MWSCommon\SignatureDoesNotMatch;
use SplTempFileObject;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use Spatie\ArrayToXml\ArrayToXml;

class MWSClient
{

    const SIGNATURE_METHOD = 'HmacSHA256';
    const SIGNATURE_VERSION = '2';
    const DATE_FORMAT = "Y-m-d\TH:i:s.\\0\\0\\0\\Z";
    const APPLICATION_NAME = 'MCS/MwsClient';

    public $targetEncoding = 'UTF-8';
    const ORIGIN_ENCODING = 'ISO-8859-1';

    const TRIES_LIMIT = 3;

    public $config = [
        'Seller_Id' => null,
        'Marketplace_Id' => null,
        'Access_Key_ID' => null,
        'Secret_Access_Key' => null,
        'MWSAuthToken' => null,
        'Application_Version' => '0.0.*'
    ];


    const MARKETPLACE_SPAIN = 'A1RKKUPIHCS9HS';
    const MARKETPLACE_UK = 'A1F83G8C2ARO7P';
    const MARKETPLACE_FRANCE = 'A13V1IB3VIYZZH';
    const MARKETPLACE_GERMANY = 'A1PA6795UKMFR9';
    const MARKETPLACE_ITALY = 'APJ6JRA9NG5V4';
    const MARKETPLACE_NETHERLANDS = 'A1805IZSGTT6HS';
    const MARKETPLACE_POLAND = 'A1C3SOZRARQ6R3';
    const MARKETPLACE_BRAZIL = 'A2Q3Y263D00KWC';
    const MARKETPLACE_INDIA = 'A21TJRUUN4KGV';
    const MARKETPLACE_CHINA = 'AAHKV2X7AFYLW';
    const MARKETPLACE_JAPAN = 'A1VC38T7YXB528';
    const MARKETPLACE_AUSTRALIA = 'A39IBJ37TRP1C6';
    const MARKETPLACE_CANADA = 'A2EUQ1WTGCTBG2';
    const MARKETPLACE_US = 'ATVPDKIKX0DER';
    const MARKETPLACE_MEXICO = 'A1AM78C64UM0Y8';


    public static $MarketplaceIds = [
        self::MARKETPLACE_CANADA => 'mws.amazonservices.ca',
        self::MARKETPLACE_US => 'mws.amazonservices.com',
        self::MARKETPLACE_MEXICO => 'mws.amazonservices.com.mx',
        self::MARKETPLACE_GERMANY => 'mws-eu.amazonservices.com',
        self::MARKETPLACE_SPAIN => 'mws-eu.amazonservices.com',
        self::MARKETPLACE_FRANCE => 'mws-eu.amazonservices.com',
        self::MARKETPLACE_NETHERLANDS => 'mws-eu.amazonservices.com',
        self::MARKETPLACE_POLAND => 'mws-eu.amazonservices.com',
        self::MARKETPLACE_INDIA => 'mws.amazonservices.in',
        self::MARKETPLACE_ITALY => 'mws-eu.amazonservices.com',
        self::MARKETPLACE_UK => 'mws-eu.amazonservices.com',
        self::MARKETPLACE_JAPAN => 'mws.amazonservices.jp',
        self::MARKETPLACE_CHINA => 'mws.amazonservices.com.cn',
        self::MARKETPLACE_AUSTRALIA => 'mws.amazonservices.com.au',
        self::MARKETPLACE_BRAZIL => 'mws.amazonservices.com'
    ];


    public static $regions = [
        'North America' => [self::MARKETPLACE_CANADA, self::MARKETPLACE_US, self::MARKETPLACE_MEXICO],
        'Brazil' => [self::MARKETPLACE_BRAZIL],
        'Europe' => [
            self::MARKETPLACE_POLAND,
            self::MARKETPLACE_GERMANY,
            self::MARKETPLACE_SPAIN,
            self::MARKETPLACE_FRANCE,
            self::MARKETPLACE_ITALY,
            self::MARKETPLACE_UK,
            self::MARKETPLACE_NETHERLANDS
        ],
        'India' => [self::MARKETPLACE_INDIA],
        'China' => [self::MARKETPLACE_CHINA],
        'Japan' => [self::MARKETPLACE_JAPAN],
        'Australia' => [self::MARKETPLACE_AUSTRALIA]
    ];


    protected $debugNextFeed = false;
    protected $client = null;
    public $getNextAuto = false;
    protected $nextStack = [];

    public function __construct(array $config, $skipRequiredCheck = false)
    {

        foreach ($config as $key => $value) {
            if (array_key_exists($key, $this->config)) {
                $this->config[$key] = $value;
            }
        }

        if (!$skipRequiredCheck) {
            $required_keys = [
                'Marketplace_Id',
                'Seller_Id',
                'Access_Key_ID',
                'Secret_Access_Key'
            ];

            foreach ($required_keys as $key) {
                if (is_null($this->config[$key])) {
                    throw new Exception('Required field ' . $key . ' is not set');
                }
            }
        }


        if (!isset(static::$MarketplaceIds[$this->config['Marketplace_Id']])) {
            throw new Exception('Invalid Marketplace Id');
        }

        $this->config['Application_Name'] = self::APPLICATION_NAME;
        $this->config['Region_Host'] = static::$MarketplaceIds[$this->config['Marketplace_Id']];
        $this->config['Region_Url'] = 'https://' . $this->config['Region_Host'];

    }

    /**
     * Call this method to get the raw feed instead of sending it
     */
    public function debugNextFeed()
    {
        $this->debugNextFeed = true;
        return $this;
    }

    /**
     * Can be used with API methods which have own "...ByNextToken" counterpart. Enables an automatic fetching of subsequent result pages.
     * Modifies a form of a data returned by self::request() method. If flag is in use - it will return an array of resources rather than full api response.
     *
     * @param bool $enable
     * @return $this
     */
    public function asAStackOfNextResources($enable = true)
    {
        $this->getNextAuto = $enable;
        return $this;
    }

    /**
     * A method to quickly check if the supplied credentials are valid
     * @param $rethrowException boolean
     * @return boolean
     */
    public function validateCredentials($rethrowException = false)
    {
        try {
            $fakeId = 'just validating credentials';
            $this->GetFeedSubmissionResult($fakeId);
        } catch (Exception $e) {
            if (preg_match('@'.$fakeId.'@', $e->getMessage())) {
                return true;
            } else {
                if ($rethrowException) {
                    throw $e;
                }
                return false;
            }
        }
    }

    /**
     * Returns the current competitive price of a product, based on ASIN.
     * @param array [$asin_array = []]
     * @return array
     */
    public function GetCompetitivePricingForASIN($asin_array = [])
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'GetCompetitivePricingForASIN',
            $query
        );

        if (isset($response['GetCompetitivePricingForASINResult'])) {
            $response = $response['GetCompetitivePricingForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'];
            }
        }
        return $array;

    }

    /**
     * Returns the current competitive price of a product, based on SKU.
     * @param array [$sku_array = []]
     * @return array
     */
    public function GetCompetitivePricingForSKU($sku_array = [])
    {
        if (count($sku_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        foreach ($sku_array as $key) {
            $query['SellerSKUList.SellerSKU.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'GetCompetitivePricingForSKU',
            $query
        );

        if (isset($response['GetCompetitivePricingForSKUResult'])) {
            $response = $response['GetCompetitivePricingForSKUResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'])) {
                $array[$product['Product']['Identifiers']['SKUIdentifier']['SellerSKU']]['Price'] = $product['Product']['CompetitivePricing']['CompetitivePrices']['CompetitivePrice']['Price'];
                $array[$product['Product']['Identifiers']['SKUIdentifier']['SellerSKU']]['Rank'] = $product['Product']['SalesRankings']['SalesRank'][1];
            }
        }
        return $array;

    }

    /**
     * Returns lowest priced offers for a single product, based on ASIN.
     * @param string $asin
     * @param string [$ItemCondition = 'New'] Should be one in: New, Used, Collectible, Refurbished, Club
     * @return array
     */
    public function GetLowestPricedOffersForASIN($asin, $ItemCondition = 'New')
    {

        $query = [
            'ASIN' => $asin,
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ItemCondition' => $ItemCondition
        ];

        return $this->request('GetLowestPricedOffersForASIN', $query);

    }

    /**
     * Returns pricing information for your own offer listings, based on SKU.
     * @param array  [$sku_array = []]
     * @param string [$ItemCondition = null]
     * @return array
     */
    public function GetMyPriceForSKU($sku_array = [], $ItemCondition = null)
    {
        if (count($sku_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }

        foreach ($sku_array as $key) {
            $query['SellerSKUList.SellerSKU.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'GetMyPriceForSKU',
            $query
        );

        if (isset($response['GetMyPriceForSKUResult'])) {
            $response = $response['GetMyPriceForSKUResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success') {
                if (isset($product['Product']['Offers']['Offer'])) {
                    $array[$product['@attributes']['SellerSKU']] = $product['Product']['Offers']['Offer'];
                } else {
                    $array[$product['@attributes']['SellerSKU']] = [];
                }
            } else {
                $array[$product['@attributes']['SellerSKU']] = false;
            }
        }
        return $array;

    }

    /**
     * Returns pricing information for your own offer listings, based on ASIN.
     * @param array [$asin_array = []]
     * @param string [$ItemCondition = null]
     * @return array
     */
    public function GetMyPriceForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of SKU\'s for this call is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }

        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'GetMyPriceForASIN',
            $query
        );

        if (isset($response['GetMyPriceForASINResult'])) {
            $response = $response['GetMyPriceForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['@attributes']['status']) && $product['@attributes']['status'] == 'Success' && isset($product['Product']['Offers']['Offer'])) {
                $array[$product['@attributes']['ASIN']] = $product['Product']['Offers']['Offer'];
            } else {
                $array[$product['@attributes']['ASIN']] = false;
            }
        }
        return $array;

    }

    /**
     * Returns pricing information for the lowest-price active offer listings for up to 20 products, based on ASIN.
     * @param array [$asin_array = []] array of ASIN values
     * @param array [$ItemCondition = null] Should be one in: New, Used, Collectible, Refurbished, Club. Default: All
     * @return array
     */
    public function GetLowestOfferListingsForASIN($asin_array = [], $ItemCondition = null)
    {
        if (count($asin_array) > 20) {
            throw new Exception('Maximum amount of ASIN\'s for this call is 20');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        if (!is_null($ItemCondition)) {
            $query['ItemCondition'] = $ItemCondition;
        }

        foreach ($asin_array as $key) {
            $query['ASINList.ASIN.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'GetLowestOfferListingsForASIN',
            $query
        );

        if (isset($response['GetLowestOfferListingsForASINResult'])) {
            $response = $response['GetLowestOfferListingsForASINResult'];
            if (array_keys($response) !== range(0, count($response) - 1)) {
                $response = [$response];
            }
        } else {
            return [];
        }

        $array = [];
        foreach ($response as $product) {
            if (isset($product['Product']['LowestOfferListings']['LowestOfferListing'])) {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = $product['Product']['LowestOfferListings']['LowestOfferListing'];
            } else {
                $array[$product['Product']['Identifiers']['MarketplaceASIN']['ASIN']] = false;
            }
        }
        return $array;

    }

    /**
     * Returns orders created or updated during a time frame that you specify.
     * @param object DateTime $from, beginning of time frame
     * @param boolean $allMarketplaces , list orders from all marketplaces
     * @param array $states , an array containing orders states you want to filter on
     * @param string $FulfillmentChannel
     * @param object DateTime $till, end of time frame
     * @param $dateParam string @see http://docs.developer.amazonservices.com/en_UK/orders-2013-09-01/Orders_ListOrders.html
     * @return array
     */
    public function ListOrders(
        DateTime $from,
        $marketplacesList = null,
        $states = [
            'Unshipped',
            'PartiallyShipped'
        ],
        $FulfillmentChannels = 'MFN',
        DateTime $till = null,
        $dateParam = 'LastUpdated'
    ) {

        $query = [
            $dateParam . 'After' => gmdate(self::DATE_FORMAT, $from->getTimestamp())
        ];

        if ($till !== null) {
            $query[$dateParam . 'Before'] = gmdate(self::DATE_FORMAT, $till->getTimestamp());
        }

        $counter = 1;
        foreach ($states as $status) {
            $query['OrderStatus.Status.' . $counter] = $status;
            $counter = $counter + 1;
        }

        if ($marketplacesList) {
            if (!is_array($marketplacesList)) {
                throw new Exception('$marketplacesList should be an array!');
            }
            $counter = 1;
            foreach ($marketplacesList as $id) {
                $query['MarketplaceId.Id.' . $counter++] = $id;
            }
        }

        if (is_array($FulfillmentChannels)) {
            $counter = 1;
            foreach ($FulfillmentChannels as $fulfillmentChannel) {
                $query['FulfillmentChannel.Channel.' . $counter] = $fulfillmentChannel;
                $counter = $counter + 1;
            }
        } else {
            $query['FulfillmentChannel.Channel.1'] = $FulfillmentChannels;
        }

        $oldNextAuto = $this->getNextAuto;
        $response = $this->asAStackOfNextResources()->request('ListOrders', $query);
        $this->getNextAuto = $oldNextAuto;

        return $response;
    }

    /**
     * Returns an order based on the AmazonOrderId values that you specify.
     * @param string $AmazonOrderId
     * @return array if the order is found, false if not
     */
    public function GetOrder($AmazonOrderId)
    {
        $response = $this->request('GetOrder', [
            'AmazonOrderId.Id.1' => $AmazonOrderId
        ]);

        if (isset($response['GetOrderResult']['Orders']['Order'])) {
            return $response['GetOrderResult']['Orders']['Order'];
        } else {
            return false;
        }
    }


    /**
     * Returns order items based on the AmazonOrderId that you specify.
     * @param string $AmazonOrderId
     * @return array
     */
    public function ListOrderItems($AmazonOrderId)
    {
        $oldNextAuto = $this->getNextAuto;
        $response = $this->asAStackOfNextResources()->request('ListOrderItems', [
            'AmazonOrderId' => $AmazonOrderId
        ]);
        $this->getNextAuto = $oldNextAuto;
        return $response;
    }

    /**
     * Returns the parent product categories that a product belongs to, based on SellerSKU.
     * @param string $SellerSKU
     * @return array if found, false if not found
     */
    public function GetProductCategoriesForSKU($SellerSKU)
    {
        $result = $this->request('GetProductCategoriesForSKU', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'SellerSKU' => $SellerSKU
        ]);

        if (isset($result['GetProductCategoriesForSKUResult']['Self'])) {
            return $result['GetProductCategoriesForSKUResult']['Self'];
        } else {
            return false;
        }
    }

    /**
     * Returns the parent product categories that a product belongs to, based on ASIN.
     * @param string $ASIN
     * @return array if found, false if not found
     */
    public function GetProductCategoriesForASIN($ASIN)
    {
        $result = $this->request('GetProductCategoriesForASIN', [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'ASIN' => $ASIN
        ]);

        if (isset($result['GetProductCategoriesForASINResult']['Self'])) {
            return $result['GetProductCategoriesForASINResult']['Self'];
        } else {
            return false;
        }
    }

    public function GetMatchingProductForIdNoLimits(array $idsArr, $type = 'ASIN')
    {
        $answer = ['found' => [], 'not_found' => []];
        $idsArrTmp = [];
        while ($idsArr) {
            $idsArrTmp[] = array_shift($idsArr);
            if (count($idsArrTmp) === 5 || !count($idsArr)) {
                $response = $this->GetMatchingProductForId($idsArrTmp, $type);
                foreach ($response['found'] as $id => $foundProdArr) {
                    $answer['found'][$id] = $foundProdArr;
                }
                $answer['not_found'] = array_merge($answer['not_found'], $response['not_found']);
                $idsArrTmp = [];
            }
        }
        return $answer;
    }

    /**
     * Returns a list of products and their attributes, based on a list of ASIN, GCID, SellerSKU, UPC, EAN, ISBN, and JAN values.
     * @param array $idsArr A list of id's
     * @param string [$type = 'ASIN']  the identifier name
     * @return array
     */
    public function GetMatchingProductForId(array $idsArr, $type = 'ASIN')
    {
        $idsArr = array_unique($idsArr);

        if (count($idsArr) > 5) {
            throw new Exception('Maximum number of id\'s = 5');
        }

        $counter = 1;
        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'IdType' => $type
        ];

        foreach ($idsArr as $asin) {
            $array['IdList.Id.' . $counter] = $asin;
            $counter++;
        }

        $responseXML = $this->request(
            'GetMatchingProductForId',
            $array,
            null,
            true
        );

        $languages = [
            'de-DE',
            'en-EN',
            'es-ES',
            'fr-FR',
            'it-IT',
            'en-US'
        ];

        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];

        foreach ($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }

        $replace['ns2:'] = '';

        $responseXML = strtr($responseXML, $replace);

        $xmlAsObject = simplexml_load_string($responseXML);

        $xmlAsArray = $this->xmlToArray($xmlAsObject);

        if (isset($xmlAsArray['GetMatchingProductForIdResult']['@attributes'])) {
            $xmlAsArray['GetMatchingProductForIdResult'] = [
                0 => $xmlAsArray['GetMatchingProductForIdResult']
            ];
        }

        $found = [];
        $not_found = [];

        if (isset($xmlAsArray['GetMatchingProductForIdResult']) && is_array($xmlAsArray['GetMatchingProductForIdResult'])) {
            foreach ($xmlAsArray['GetMatchingProductForIdResult'] as $resultIndex => $result) {
                $products = $productsAsObjArr = [];
                $asin = $result['@attributes']['Id'];
                if ($result['@attributes']['status'] != 'Success') {
                    $not_found[] = $asin;
                } else {
                    if (isset($result['Products']['Product']['AttributeSets'])) {
                        $products[0] = $result['Products']['Product'];
                        $productsAsObjArr[0] = $xmlAsObject->GetMatchingProductForIdResult[$resultIndex]->Products->Product;
                    } else {
                        $products = $result['Products']['Product'];
                        $productsAsObjArr = $xmlAsObject->GetMatchingProductForIdResult[$resultIndex]->Products->Product;
                    }
                    foreach ($products as $productIndex => $product) {
                        $array = [];
                        if (isset($product['Identifiers']['MarketplaceASIN']['ASIN'])) {
                            $array["ASIN"] = $product['Identifiers']['MarketplaceASIN']['ASIN'];
                        }

                        foreach ($product['AttributeSets']['ItemAttributes'] as $key => $value) {
                            if (is_string($key) && is_string($value)) {
                                $array[$key] = $value;
                            }
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['Feature'])) {
                            $array['Feature'] = $product['AttributeSets']['ItemAttributes']['Feature'];
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['PackageDimensions'])) {
                            foreach ($product['AttributeSets']['ItemAttributes']['PackageDimensions'] as $dimensionName => $dimensionValue) {
                                $dimensionSimpleXmlElement = $productsAsObjArr[$productIndex]->AttributeSets->ItemAttributes->PackageDimensions->$dimensionName;
                                $array['PackageDimensions'][$dimensionName] = [
                                    'unit' => (string)$dimensionSimpleXmlElement->attributes()->Units,
                                    'value' => (string)$dimensionSimpleXmlElement
                                ];
                            }
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['ListPrice'])) {
                            $array['ListPrice'] = $product['AttributeSets']['ItemAttributes']['ListPrice'];
                        }

                        if (isset($product['AttributeSets']['ItemAttributes']['SmallImage'])) {
                            $image = $product['AttributeSets']['ItemAttributes']['SmallImage']['URL'];
                            $array['medium_image'] = $image;
                            $array['small_image'] = str_replace('._SL75_', '._SL50_', $image);
                            $array['large_image'] = str_replace('._SL75_', '', $image);;
                        }
                        if (isset($product['Relationships']['VariationParent']['Identifiers']['MarketplaceASIN']['ASIN'])) {
                            $array['Parentage'] = 'child';
                            $array['Relationships'] = $product['Relationships']['VariationParent']['Identifiers']['MarketplaceASIN']['ASIN'];
                        }
                        if (isset($product['Relationships']['VariationChild'])) {
                            $array['Parentage'] = 'parent';
                        }
                        if (isset($product['SalesRankings']['SalesRank'])) {
                            $array['SalesRank'] = $product['SalesRankings']['SalesRank'];
                        }
                        $found[$asin][] = $array;
                    }
                }
            }
        }

        return [
            'found' => $found,
            'not_found' => $not_found
        ];

    }


    /**
     * Returns a list of products and their attributes, ordered by relevancy, based on a search query that you specify.
     * @param string $query the open text query
     * @param string [$query_context_id = null] the identifier for the context within which the given search will be performed. see: http://docs.developer.amazonservices.com/en_US/products/Products_QueryContextIDs.html
     * @return array
     */
    public function ListMatchingProducts($query, $query_context_id = null)
    {

        if (trim($query) == "") {
            throw new Exception('Missing query');
        }

        $array = [
            'MarketplaceId' => $this->config['Marketplace_Id'],
            'Query' => urlencode($query),
            'QueryContextId' => $query_context_id
        ];

        $response = $this->request(
            'ListMatchingProducts',
            $array,
            null,
            true
        );


        $languages = [
            'de-DE',
            'en-EN',
            'es-ES',
            'fr-FR',
            'it-IT',
            'en-US'
        ];

        $replace = [
            '</ns2:ItemAttributes>' => '</ItemAttributes>'
        ];

        foreach ($languages as $language) {
            $replace['<ns2:ItemAttributes xml:lang="' . $language . '">'] = '<ItemAttributes><Language>' . $language . '</Language>';
        }

        $replace['ns2:'] = '';

        $response = $this->xmlToArray(strtr($response, $replace));

        if (isset($response['ListMatchingProductsResult'])) {
            return $response['ListMatchingProductsResult'];
        } else {
            return ['ListMatchingProductsResult' => []];
        }

    }


    /**
     * Returns a list of reports that were created in the previous 90 days.
     * @param array [$ReportTypeList = []]
     * @return array
     */
    public function GetReportList(array $options = [])
    {
        return $this->reportList($options, 'Report');
    }

    public function GetReportRequestList(array $options = [])
    {
        return $this->reportList($options);
    }

    protected function reportList(array $options = null, $type = 'ReportRequest')
    {
        $query = ['MaxCount' => 100];

        if (@$options['ReportTypeList']) {
            foreach (array_values($options['ReportTypeList']) as $i => $ReportType) {
                $query['ReportTypeList.Type.' . ($i + 1)] = $ReportType;
            }
        }

        if (@$options['ReportRequestIdList']) {
            if (count($options['ReportRequestIdList']) > 100) {
                throw new \Exception('Asking about more than 100 reports at once is impossible at this moment...');
            }

            foreach (array_values($options['ReportRequestIdList']) as $i => $ReportRequestId) {
                $query['ReportRequestIdList.Id.' . ($i + 1)] = $ReportRequestId;
            }
        }

        $endpointName = 'Get' . $type . 'List';

        $response = $this->request($endpointName, $query);
        if (@$response[$endpointName . 'Result'][$type . 'Info']['ReportType']) {
            $response[$endpointName . 'Result'][$type . 'Info'] = [$response[$endpointName . 'Result'][$type . 'Info']];
        }

        return @$response[$endpointName . 'Result'][$type . 'Info'] ?: [];
    }


    /**
     * Returns your active recommendations for a specific category or for all categories for a specific marketplace.
     * @param string [$RecommendationCategory = null] One of: Inventory, Selection, Pricing, Fulfillment, ListingQuality, GlobalSelling, Advertising
     * @return array/false if no result
     */
    public function ListRecommendations($RecommendationCategory = null)
    {
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        if (!is_null($RecommendationCategory)) {
            $query['RecommendationCategory'] = $RecommendationCategory;
        }

        $result = $this->request('ListRecommendations', $query);

        if (isset($result['ListRecommendationsResult'])) {
            return $result['ListRecommendationsResult'];
        } else {
            return false;
        }

    }

    /**
     * Returns a list of marketplaces that the seller submitting the request can sell in, and a list of participations that include seller-specific information in that marketplace
     * @return array
     */
    public function ListMarketplaceParticipations()
    {
        $result = $this->request('ListMarketplaceParticipations');
        if (isset($result['ListMarketplaceParticipationsResult'])) {
            return $result['ListMarketplaceParticipationsResult'];
        } else {
            return $result;
        }
    }

    /**
     * Delete product's based on SKU
     * @param string $array array containing sku's
     * @return array feed submission result
     */
    public function deleteProductBySKU(array $array)
    {

        $feed = [
            'MessageType' => 'Product',
            'Message' => []
        ];

        foreach ($array as $sku) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'OperationType' => 'Delete',
                'Product' => [
                    'SKU' => $sku
                ]
            ];
        }

        return $this->SubmitFeed('_POST_PRODUCT_DATA_', $feed);
    }

    /**
     * Update a product's stock quantity
     * @param array $array array containing sku as key and quantity as value
     * @return array feed submission result
     */
    public function updateStock(array $array)
    {
        $feed = [
            'MessageType' => 'Inventory',
            'Message' => []
        ];

        foreach ($array as $sku => $sth) {
            $arr = [
                'MessageID' => rand(),
                'OperationType' => 'Update',
                'Inventory' => [
                    'SKU' => $sku
                ]
            ];

            if (is_bool($sth)) {
                $arr['Inventory']['Available'] = $sth ? 'true' : 'false';
            } else {
                $arr['Inventory']['Quantity'] = $sth;
            }

            $feed['Message'][] = $arr;

        }

        return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);

    }


    /**
     * Update a product's stock quantity
     *
     * @param array $array array containing arrays with next keys: [sku, quantity, latency]
     * @return array feed submission result
     */
    public function updateStockWithFulfillmentLatency(array $array)
    {
        $feed = [
            'MessageType' => 'Inventory',
            'Message' => []
        ];

        foreach ($array as $item) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'OperationType' => 'Update',
                'Inventory' => [
                    'SKU' => $item['sku'],
                    'Quantity' => (int)$item['quantity'],
                    'FulfillmentLatency' => $item['latency']
                ]
            ];
        }

        return $this->SubmitFeed('_POST_INVENTORY_AVAILABILITY_DATA_', $feed);
    }

    /**
     * Update a product's price
     * @param array $standardprice an array containing sku as key and price as value
     * @param array $salesprice an optional array with sku as key and value consisting of an array with key/value pairs for SalePrice, StartDate, EndDate
     * Dates in DateTime object
     * Price has to be formatted as XSD Numeric Data Type (http://www.w3schools.com/xml/schema_dtypes_numeric.asp)
     * @return array feed submission result
     */
    public function updatePrice(array $standardprice, array $saleprice = null)
    {

        $feed = [
            'MessageType' => 'Price',
            'Message' => []
        ];

        foreach ($standardprice as $sku => $price) {
            $feed['Message'][] = [
                'MessageID' => rand(),
                'Price' => [
                    'SKU' => $sku,
                    'StandardPrice' => [
                        '_value' => strval($price),
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]
                    ]
                ]
            ];

            if (isset($saleprice[$sku]) && is_array($saleprice[$sku])) {
                $feed['Message'][count($feed['Message']) - 1]['Price']['Sale'] = [
                    'StartDate' => $saleprice[$sku]['StartDate']->format(self::DATE_FORMAT),
                    'EndDate' => $saleprice[$sku]['EndDate']->format(self::DATE_FORMAT),
                    'SalePrice' => [
                        '_value' => strval($saleprice[$sku]['SalePrice']),
                        '_attributes' => [
                            'currency' => 'DEFAULT'
                        ]
                    ]
                ];
            }
        }

        return $this->SubmitFeed('_POST_PRODUCT_PRICING_DATA_', $feed);
    }

    public function mapBarcodesToASIN(array $barCodesArr)
    {
        $byType = [];
        foreach ($barCodesArr as $barcode) {
            if (!$type = static::recognizeBarcodeType($barcode)) {
                throw new \Exception('Unknow barcode type!');
            }

            $byType[$type][] = $barcode;
        }

        $map = [];
        foreach ($byType as $type => $barcodesArr) {
            $searchResult = $this->GetMatchingProductForIdNoLimits($barcodesArr, $type);
            foreach ($searchResult['found'] as $barcode => $productsArr) {
                $map[$barcode] = $productsArr[0]['ASIN'];
            }
        }
        return $map;
    }

    public static function recognizeBarcodeType($code)
    {
        $codeLength = strlen($code);
        if (in_array($codeLength, [6, 12])) {
            return 'UPC';
        }

        if (in_array($codeLength, [8, 13, 14, 18])) {
            return 'EAN';
        }
        return false;
    }


    /**
     * Post to create or update a product (_POST_FLAT_FILE_LISTINGS_DATA_)
     * @param  object $MWSProduct or array of MWSProduct objects
     * @return array
     */
    public function postProduct($MWSProduct, array $feedOptions = [])
    {

        if (!is_array($MWSProduct)) {
            $MWSProduct = [$MWSProduct];
        }

        $csv = Writer::createFromFileObject(new SplTempFileObject());

        $csv->setDelimiter("\t");
        $csv->setInputEncoding('iso-8859-1');

        $csv->insertOne(['TemplateType=Offer', 'Version=2014.0703']);

        $header = [
            'sku',
            'price',
            'quantity',
            'product-id',
            'product-id-type',
            'condition-type',
            'condition-note',
            'ASIN-hint',
            'title',
            'product-tax-code',
            'operation-type',
            'sale-price',
            'sale-start-date',
            'sale-end-date',
            'leadtime-to-ship',
            'launch-date',
            'is-giftwrap-available',
            'is-gift-message-available',
            'fulfillment-center-id',
            'main-offer-image',
            'offer-image1',
            'offer-image2',
            'offer-image3',
            'offer-image4',
            'offer-image5'
        ];

        $csv->insertOne($header);
        $csv->insertOne($header);

        foreach ($MWSProduct as $product) {
            $csv->insertOne(
                array_values($product->toArray())
            );
        }

        return $this->SubmitFeed('_POST_FLAT_FILE_LISTINGS_DATA_', $csv, false, $feedOptions);

    }

    /**
     * Returns the feed processing report and the Content-MD5 header.
     * @param string $FeedSubmissionId
     * @return array
     */
    public function GetFeedSubmissionResult($FeedSubmissionId)
    {
        $result = $this->request('GetFeedSubmissionResult', [
            'FeedSubmissionId' => $FeedSubmissionId
        ]);

        if (isset($result['Message']['ProcessingReport'])) {
            return $result['Message']['ProcessingReport'];
        } else {
            return $result;
        }
    }


    public function GetFeedSubmissionList(array $ids = [])
    {
        if (count($ids) > 100) {
            throw new \Exception('Too much feeds at once. Maximum allowed = 100.');
        }

        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $params['FeedSubmissionIdList.Id.' . ($i + 1)] = $id;
        }
        $params['MaxCount'] = 100;

        return $this->request('GetFeedSubmissionList', $params);
    }

    public function getFeedsStatuses(array $ids)
    {

        $askAbout = $statusesArr = [];
        while ($ids) {
            $askAbout[] = array_shift($ids);
            if (100 === count($askAbout) || !$ids) {
                $result = $this->GetFeedSubmissionList($askAbout);
                $askAbout = [];
                if ($feedsInfo = @$result['GetFeedSubmissionListResult']['FeedSubmissionInfo']) {
                    if (@$feedsInfo['FeedProcessingStatus']) {
                        $feedsInfo = [$feedsInfo];
                    }
                    foreach ($feedsInfo as $feed) {
                        $statusesArr[$feed['FeedSubmissionId']] = $feed['FeedProcessingStatus'];
                    }
                }
            }
        }
        return $statusesArr;
    }


    /**
     * Uploads a feed for processing by Amazon MWS.
     * @param string $FeedType (http://docs.developer.amazonservices.com/en_US/feeds/Feeds_FeedType.html)
     * @param mixed $feedContent Array will be converted to xml using https://github.com/spatie/array-to-xml. Strings will not be modified.
     * @param boolean $debug Return the generated xml and don't send it to amazon
     * @return array
     */
    public function SubmitFeed($FeedType, $feedContent, $debug = false, $options = [])
    {

        if (is_array($feedContent)) {
            $feedContent = $this->arrayToXml(
                array_merge([
                    'Header' => [
                        'DocumentVersion' => 1.01,
                        'MerchantIdentifier' => $this->config['Seller_Id']
                    ]
                ], $feedContent)
            );
        }

        if ($debug === true) {
            return $feedContent;
        } else {
            if ($this->debugNextFeed == true) {
                $this->debugNextFeed = false;
                return $feedContent;
            }
        }

        $purgeAndReplace = isset($options['PurgeAndReplace']) ? $options['PurgeAndReplace'] : false;

        $query = [
            'FeedType' => $FeedType,
            'PurgeAndReplace' => ($purgeAndReplace ? 'true' : 'false'),
            'Merchant' => $this->config['Seller_Id'],
            'SellerId' => false,
        ];

        $marketplaces[] = $this->config['Marketplace_Id'];
        if (isset($options['marketplaces'])) {
            $marketplaces = $options['marketplaces'];
        }

        if ($marketplaces) {
            foreach (array_values($marketplaces) as $i => $marketplaceId) {
                $query['MarketplaceIdList.Id.' . ($i + 1)] = $marketplaceId;
            }
        }

        $response = $this->request(
            'SubmitFeed',
            $query,
            $feedContent
        );

        return $response['SubmitFeedResult']['FeedSubmissionInfo'];
    }

    /**
     * Convert an array to xml
     * @param $array array to convert
     * @param $customRoot [$customRoot = 'AmazonEnvelope']
     * @return sting
     */
    private function arrayToXml(array $array, $customRoot = 'AmazonEnvelope')
    {
        return ArrayToXml::convert($array, $customRoot);
    }

    /**
     * Convert an xml string to an array
     * @param string $xmlstring
     * @return array
     */
    private function xmlToArray($xml)
    {
        if (!$xml instanceof \SimpleXMLElement) {
            $xml = simplexml_load_string($xml);
        }
        return json_decode(json_encode($xml), true);
    }

    /**
     * Creates a report request and submits the request to Amazon MWS.
     * @param string $report (http://docs.developer.amazonservices.com/en_US/reports/Reports_ReportType.html)
     * @param DateTime [$StartDate = null]
     * @param EndDate [$EndDate = null]
     * @return array Request report info
     */
    public function RequestReport($report, $marketplacesList = [], $StartDate = null, $EndDate = null, array $reportOptions = [])
    {
        if ($marketplacesList) {
            if ($unknownIds = array_diff($marketplacesList, array_keys(self::$MarketplaceIds))) {
                throw new MWSClientException(__(sprintf('Unknown marketplace ids: %s', join(', ',$unknownIds))));
            }
            $i = 1;
            foreach ($marketplacesList as $id) {
                $query['MarketplaceIdList.Id.'.$i++] = $id;
            }
        } else {
            $query['MarketplaceIdList.Id.1'] = $this->config['Marketplace_Id'];
        }

        $query['ReportType'] = $report;

        if (!is_null($StartDate)) {
            if (!is_a($StartDate, 'DateTime')) {
                throw new Exception('StartDate should be a DateTime object');
            } else {
                $query['StartDate'] = gmdate(self::DATE_FORMAT, $StartDate->getTimestamp());
            }
        }

        if (!is_null($EndDate)) {
            if (!is_a($EndDate, 'DateTime')) {
                throw new Exception('EndDate should be a DateTime object');
            } else {
                $query['EndDate'] = gmdate(self::DATE_FORMAT, $EndDate->getTimestamp());
            }
        }

        if ($reportOptions) {
            $preparedOptions = [];
            foreach ($reportOptions as $name => $value) {
                $preparedOptions[] = "$name=$value";
            }
            $query['ReportOptions'] = join(';', $preparedOptions);
        }

        $result = $this->request(
            'RequestReport',
            $query
        );

        if (isset($result['RequestReportResult']['ReportRequestInfo']['ReportRequestId'])) {
            return $result['RequestReportResult']['ReportRequestInfo'];
        } else {
            throw new Exception('Error trying to request report');
        }
    }

    /**
     * Get a report's content
     * @param string $ReportId
     * @param boolean $getRaw return plain csv data
     * @return array on succes
     */
    public function GetReport($ReportId, $getRaw = false)
    {
        $result = $this->request('GetReport', [
            'ReportId' => $ReportId
        ]);

        if ($getRaw) {
            return $result;
        }

        if (is_string($result)) {
            if (self::ORIGIN_ENCODING !== $this->targetEncoding) {
                $result = iconv(self::ORIGIN_ENCODING, $this->targetEncoding, $result);
            }
            $csv = Reader::createFromString($result);
            $csv->setDelimiter("\t");
            $headers = $csv->fetchOne();
            $result = [];
            foreach ($csv->setOffset(1)->fetchAll() as $row) {
                $result[] = array_combine($headers, $row);
            }
        }

        return $result;
    }

    /**
     * Get a report's processing status
     * @param string $ReportId
     * @return array if the report is found
     */
    public function GetReportRequestStatus($ReportId)
    {
        $result = $this->request('GetReportRequestList', [
            'ReportRequestIdList.Id.1' => $ReportId
        ]);

        if (isset($result['GetReportRequestListResult']['ReportRequestInfo'])) {
            return $result['GetReportRequestListResult']['ReportRequestInfo'];
        }

        return false;

    }

    /**
     * Get a list's inventory for Amazon's fulfillment
     *
     * @param array $sku_array
     *
     * @return array
     * @throws Exception
     */
    public function ListInventorySupply($sku_array = [])
    {

        if (count($sku_array) > 50) {
            throw new Exception('Maximum amount of SKU\'s for this call is 50');
        }

        $counter = 1;
        $query = [
            'MarketplaceId' => $this->config['Marketplace_Id']
        ];

        foreach ($sku_array as $key) {
            $query['SellerSkus.member.' . $counter] = $key;
            $counter++;
        }

        $response = $this->request(
            'ListInventorySupply',
            $query
        );

        $result = [];
        if (isset($response['ListInventorySupplyResult']['InventorySupplyList']['member'])) {
            foreach ($response['ListInventorySupplyResult']['InventorySupplyList']['member'] as $index => $ListInventorySupplyResult) {
                $result[$index] = $ListInventorySupplyResult;
            }
        }

        return $result;
    }


    /**
     * Request MWS
     */
    private function request($endPointName, array $query = [], $body = null, $raw = false, $try = 1)
    {

        $endPoint = MWSEndPoint::get($endPointName);

        $merge = [
            'Timestamp' => gmdate(self::DATE_FORMAT, time()),
            'AWSAccessKeyId' => $this->config['Access_Key_ID'],
            'Action' => $endPoint['action'],
            'SellerId' => $this->config['Seller_Id'],
            'SignatureMethod' => self::SIGNATURE_METHOD,
            'SignatureVersion' => self::SIGNATURE_VERSION,
            'Version' => $endPoint['date'],
        ];

        $query = array_merge($query, $merge);

        if (!isset($query['MarketplaceId.Id.1']) && !@$query['NextToken']) {
            $query['MarketplaceId.Id.1'] = $this->config['Marketplace_Id'];
        }

        if (!is_null($this->config['MWSAuthToken']) and $this->config['MWSAuthToken'] != "") {
            $query['MWSAuthToken'] = $this->config['MWSAuthToken'];
        }

        if (isset($query['MarketplaceId'])) {
            unset($query['MarketplaceId.Id.1']);
        }

        if (isset($query['MarketplaceIdList.Id.1'])) {
            unset($query['MarketplaceId.Id.1']);
        }

        try {

            $headers = [
                'Accept' => 'application/xml',
                'x-amazon-user-agent' => $this->config['Application_Name'] . '/' . $this->config['Application_Version']
            ];

            if ($endPoint['action'] === 'SubmitFeed') {
                $headers['Content-MD5'] = base64_encode(md5($body, true));
                $headers['Content-Type'] = 'text/xml; charset=iso-8859-1';
                $headers['Host'] = $this->config['Region_Host'];

                unset(
                    $query['MarketplaceId.Id.1'],
                    $query['SellerId']
                );
            }

            $requestOptions = [
                'headers' => $headers,
                'body' => $body
            ];

            ksort($query);

            if (isset($query['Signature'])) {
                unset($query['Signature']);
            }
            $query['Signature'] = base64_encode(
                hash_hmac(
                    'sha256',
                    $endPoint['method']
                    . "\n"
                    . $this->config['Region_Host']
                    . "\n"
                    . $endPoint['path']
                    . "\n"
                    . http_build_query($query, null, '&', PHP_QUERY_RFC3986),
                    $this->config['Secret_Access_Key'],
                    true
                )
            );

            $requestOptions['query'] = $query;

            if ($this->client === null) {
                $this->client = new Client();
            }

            $response = $this->client->request(
                $endPoint['method'],
                $this->config['Region_Url'] . $endPoint['path'],
                $requestOptions
            );


            $body = (string)$response->getBody();


            if ($raw) {
                return $body;
            } else {
                if (strpos(strtolower($response->getHeader('Content-Type')[0]), 'xml') !== false) {
                    $result = $this->xmlToArray($body);

                    if ($this->getNextAuto) {
                        $addToStack = [];
                        if (@$endPoint['responseParentElement']) {
                            if ($result[$endPointName . 'Result'][$endPoint['responseParentElement']]) {
                                $addToStack = $result[$endPointName . 'Result'][$endPoint['responseParentElement']][$endPoint['responseElement']];
                            }
                        } else {
                            $addToStack = $result[$endPointName . 'Result'][$endPoint['responseElement']];
                        }
                        
                        $this->nextStack = array_merge($this->nextStack,
                            ($this->isAssoc($addToStack) ? [$addToStack] : $addToStack));

                        if (@$result[$endPointName . 'Result']['NextToken']) {
                            $query = [];
                            $query['NextToken'] = $result[$endPointName . 'Result']['NextToken'];
                            if (!strpos($endPointName, 'ByNextToken')) {
                                $endPointName .= 'ByNextToken';
                            }
                            return $this->request($endPointName, $query);
                        }

                        $commonResult = $this->nextStack;
                        $this->nextStack = [];
                        return $commonResult;
                    }
                    return $result;
                } else {
                    return $body;
                }                                                                                                             
            }

        } catch (BadResponseException $e) {//Lets try to pick something more specific.
            if ($e->hasResponse()) {
                $response = $e->getResponse();
                $message = $response->getBody();

                $amazonMessage = 'no Amazon message';
                $amazonCode = 'no Amazon code';

                if (strpos($message, '<ErrorResponse') !== false) {
                    $error = simplexml_load_string($message);
                    $amazonMessage = $error->Error->Message;
                    $amazonCode = $error->Error->Code;
                }

                if (400 == $response->getStatusCode()) {
                    if ('InputStreamDisconnected' == $amazonCode) {
                        throw new InputStreamDisconnected($amazonMessage, $e->getRequest(), $response);
                    }
                    if ('InvalidParameterValue' == $amazonCode) {
                        throw new InvalidParameterValue($amazonMessage, $e->getRequest(), $response);
                    }
                    throw new BadRequest($amazonMessage, $e->getRequest(), $response);
                } elseif (401 == $response->getStatusCode()) {
                    throw new AccessDenied($amazonMessage, $e->getRequest(), $response);
                } elseif (403 == $response->getStatusCode()) {
                    if ('InvalidAccessKeyId' == $amazonCode) {
                        throw new InvalidAccessKeyId($amazonMessage, $e->getRequest(), $response);
                    }
                    if ('SignatureDoesNotMatch' == $amazonCode) {
                        throw new SignatureDoesNotMatch($amazonMessage, $e->getRequest(), $response);
                    }
                    throw new Forbidden($amazonMessage, $e->getRequest(), $response);
                } elseif (404 == $response->getStatusCode()) {
                    if ('InvalidAddress' == $amazonCode) {
                        throw new InvalidAddress($amazonMessage, $e->getRequest(), $response);
                    }
                    throw new NotFound($amazonMessage, $e->getRequest(), $response);
                } elseif (500 == $response->getStatusCode()) {
                    throw new InternalError($amazonMessage, $e->getRequest(), $response);
                } elseif (503 == $response->getStatusCode()) {
                    if ('QuotaExceeded' == $amazonCode) {
                        throw new QuotaExceeded($amazonMessage, $e->getRequest(), $response);
                    }
                    if ('RequestThrottled' == $amazonCode) {
                        if (isset($endPoint['restoreRate'])) {
                            if ($try <= self::TRIES_LIMIT) {
                                sleep($endPoint['restoreRate']);
                                return $this->request($endPointName, $query, $body, $raw, ++$try);
                            } else {
                                throw new RequestThrottled(sprintf('%s - tries limit of %s exceeded', $amazonMessage, self::TRIES_LIMIT), $e->getRequest(), $response);
                            }
                        }

                        throw new RequestThrottled($amazonMessage, $e->getRequest(), $response);
                    }
                    throw new ServiceUnavailable($amazonMessage, $e->getRequest(), $response);
                }
            }
            //Re-throw an Guzzle`s exception in case above procedure fails.
            throw new $e;
        }
    }

    protected function isAssoc($arr)
    {
        return (bool)array_filter(array_keys($arr), 'is_string');
    }

    public function setClient(Client $client)
    {
        $this->client = $client;
    }

    public static function getMarketplacesRegion($marketplaceId)
    {
        foreach (self::$regions as $regionName => $marketplacesArr) {
            if (in_array($marketplaceId, $marketplacesArr)) {
                return $regionName;
            }
        }
        throw new \Exception('Region not found for a given marketplace id!');
    }

}

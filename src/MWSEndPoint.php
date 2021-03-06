<?php

namespace MCS;

use Exception;

class MWSEndPoint
{

    public static $endpoints = [
        'ListRecommendations' => [
            'method' => 'POST',
            'action' => 'ListRecommendations',
            'path' => '/Recommendations/2013-04-01',
            'date' => '2013-04-01'
        ],
        'ListMarketplaceParticipations' => [
            'method' => 'POST',
            'action' => 'ListMarketplaceParticipations',
            'path' => '/Sellers/2011-07-01',
            'date' => '2011-07-01'
        ],
        'GetMyPriceForSKU' => [
            'method' => 'POST',
            'action' => 'GetMyPriceForSKU',
            'path' => '/Products/2011-10-01',
            'date' => '2011-10-01'
        ],
        'GetMyPriceForASIN' => [
            'method' => 'POST',
            'action' => 'GetMyPriceForASIN',
            'path' => '/Products/2011-10-01',
            'date' => '2011-10-01'
        ],
        'GetProductCategoriesForSKU' => [
            'method' => 'POST',
            'action' => 'GetProductCategoriesForSKU',
            'path' => '/Products/2011-10-01',
            'date' => '2011-10-01'
        ],
        'GetProductCategoriesForASIN' => [
            'method' => 'POST',
            'action' => 'GetProductCategoriesForASIN',
            'path' => '/Products/2011-10-01',
            'date' => '2011-10-01'
        ],
        'GetCompetitivePricingForSKU' => [
            'method' => 'POST',
            'action' => 'GetCompetitivePricingForSKU',
            'path' => '/Products/2011-10-01',
            'date' => '2011-10-01'
        ],
        'GetFeedSubmissionResult' => [
            'method' => 'POST',
            'action' => 'GetFeedSubmissionResult',
            'path' => '/',
            'date' => '2009-01-01'
        ],
        'GetFeedSubmissionList' => [
            'method' => 'POST',
            'action' => 'GetFeedSubmissionList',
            'path' => '/',
            'date' => '2009-01-01',
            'responseElement' => 'FeedSubmissionInfo',
            'restoreRate' => '45' //@todo but there is also a limit per hour
        ],
        'GetReportList' => [
            'method' => 'POST',
            'action' => 'GetReportList',
            'path' => '/',
            'date' => '2009-01-01',
            'responseElement' => 'ReportInfo'
        ],
        'GetReportRequestList' => [
            'method' => 'POST',
            'action' => 'GetReportRequestList',
            'path' => '/',
            'date' => '2009-01-01',
            'responseElement' => 'ReportRequestInfo'
        ],
        'GetReport' => [
            'method' => 'POST',
            'action' => 'GetReport',
            'path' => '/',
            'date' => '2009-01-01'
        ],
        'RequestReport' => [
            'method' => 'POST',
            'action' => 'RequestReport',
            'path' => '/',
            'date' => '2009-01-01'
        ],
        'ListInventorySupply' => [
            'method' => 'POST',
            'action' => 'ListInventorySupply',
            'path' => '/FulfillmentInventory',
            'date' => '2010-10-01'
        ],
        'ListOrders' => [
            'method' => 'POST',
            'action' => 'ListOrders',
            'path' => '/Orders/2013-09-01',
            'date' => '2013-09-01',
            'responseParentElement' => 'Orders',
            'responseElement' => 'Order',
            'restoreRate' => '60'
        ],
        'ListOrderItems' => [
            'method' => 'POST',
            'action' => 'ListOrderItems',
            'path' => '/Orders/2013-09-01',
            'date' => '2013-09-01',
            'responseParentElement' => 'OrderItems',
            'responseElement' => 'OrderItem',
            'restoreRate' => '5' //2 is not enough (one Amazon knows why)
        ],
        'GetOrder' => [
            'method' => 'POST',
            'action' => 'GetOrder',
            'path' => '/Orders/2013-09-01',
            'date' => '2013-09-01'
        ],
        'SubmitFeed' => [
            'method' => 'POST',
            'action' => 'SubmitFeed',
            'path' => '/',
            'date' => '2009-01-01'
        ],
        'GetMatchingProductForId' => [
            'method' => 'POST',
            'action' => 'GetMatchingProductForId',
            'path' => '/Products/2011-10-01',
            'date' => '2011-10-01',
            'restoreRate' => '2'
        ],

        'ListMatchingProducts' => [
            'method' => 'POST',
            'action' => 'ListMatchingProducts',
            'path' => '/Products/2011-10-01',
            'date' => '2011-10-01'
        ],

        'GetCompetitivePricingForASIN' => [
            'method' => 'POST',
            'action' => 'GetCompetitivePricingForASIN',
            'path' => '/Products/2011-10-01',
            'date' => '2011-10-01'
        ],
        'GetLowestOfferListingsForASIN' => [
            'method' => 'POST',
            'action' => 'GetLowestOfferListingsForASIN',
            'path' => '/Products/2011-10-01',
            'date' => '2011-10-01'
        ],
        'GetLowestPricedOffersForASIN' => [
            'method' => 'POST',
            'action' => 'GetLowestPricedOffersForASIN',
            'path' => '/Products/2011-10-01',
            'date' => '2011-10-01'
        ]
    ];

    public static function get($key)
    {
        if ($byNextTokenPos = strpos($key, 'ByNextToken')) {
            $key = substr($key, 0, $byNextTokenPos);
        }

        if (isset(self::$endpoints[$key])) {
            $ret = self::$endpoints[$key];
            if ($byNextTokenPos) {
                $ret['action'] .= 'ByNextToken';
            }
            return $ret;
        } else {
            throw new Exception('Call to undefined endpoint ' . $key);
        }
    }
}

<?php

declare(strict_types=1);

namespace Beziworld\Fedex\Enums;

/**
 * FedEx service types listed in the API reference guide.
 *
 * The list changes over time and by region; any other value can be passed
 * as a plain string.
 */
enum Service: string
{
    case InternationalPriorityExpress = 'FEDEX_INTERNATIONAL_PRIORITY_EXPRESS';
    case InternationalPriority = 'FEDEX_INTERNATIONAL_PRIORITY';
    case InternationalEconomy = 'INTERNATIONAL_ECONOMY';
    case InternationalFirst = 'INTERNATIONAL_FIRST';
    case InternationalConnectPlus = 'FEDEX_INTERNATIONAL_CONNECT_PLUS';
    case RegionalEconomy = 'FEDEX_REGIONAL_ECONOMY';
    case InternationalPriorityFreight = 'INTERNATIONAL_PRIORITY_FREIGHT';
    case InternationalEconomyFreight = 'INTERNATIONAL_ECONOMY_FREIGHT';
    case Priority = 'FEDEX_PRIORITY';
    case PriorityExpress = 'FEDEX_PRIORITY_EXPRESS';
    case First = 'FEDEX_FIRST';
    case EconomySelect = 'FEDEX_ECONOMY_SELECT';
    case PriorityOvernight = 'PRIORITY_OVERNIGHT';
    case StandardOvernight = 'STANDARD_OVERNIGHT';
    case FirstOvernight = 'FIRST_OVERNIGHT';
    case TwoDay = 'FEDEX_2_DAY';
    case TwoDayAm = 'FEDEX_2_DAY_AM';
    case ExpressSaver = 'FEDEX_EXPRESS_SAVER';
    case Ground = 'FEDEX_GROUND';
    case GroundHomeDelivery = 'GROUND_HOME_DELIVERY';
    case SameDay = 'SAME_DAY';
}

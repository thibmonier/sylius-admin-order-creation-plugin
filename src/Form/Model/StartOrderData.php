<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Form\Model;

use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Locale\Model\LocaleInterface;

/** First step: who is the order for, and in which channel. */
final class StartOrderData
{
    public ?ChannelInterface $channel = null;

    /** Defaults to the channel's default locale. */
    public ?LocaleInterface $locale = null;

    /** An existing customer... */
    public ?CustomerInterface $customer = null;

    /** ...or the e-mail of a new (guest) customer. */
    public ?string $email = null;

    public ?string $firstName = null;

    public ?string $lastName = null;

    public ?string $phoneNumber = null;
}

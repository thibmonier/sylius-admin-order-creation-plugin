<?php

declare(strict_types=1);

namespace Tmonier\SyliusAdminOrderCreationPlugin\Controller\Admin;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Tmonier\SyliusAdminOrderCreationPlugin\Address\OrderAddressSourcesInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Checkout\DraftOrderCheckoutInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Draft\DraftOrderModifierInterface;
use Tmonier\SyliusAdminOrderCreationPlugin\Form\Type\AddressStepType;
use Tmonier\SyliusAdminOrderCreationPlugin\Repository\AdminOrderCreationRepositoryInterface;

final class DraftAddressStepController extends AbstractController
{
    use DraftControllerTrait;
    use DraftStepTrait;

    /** @param FactoryInterface<AddressInterface> $addressFactory */
    public function __construct(
        private readonly AdminOrderCreationRepositoryInterface $creationRepository,
        private readonly DraftOrderModifierInterface $draftOrderModifier,
        private readonly DraftOrderCheckoutInterface $checkout,
        private readonly FactoryInterface $addressFactory,
        private readonly EntityManagerInterface $entityManager,
        private readonly OrderAddressSourcesInterface $addressSources,
    ) {
    }

    public function addressAction(Request $request, int $id): Response
    {
        $draft = $this->loadDraft($id);
        $order = $this->orderOf($draft);
        if ($order->getItems()->isEmpty()) {
            $this->addFlash('error', 'tmonier_admin_order.no_items');

            return $this->redirectToStep('items', $draft);
        }

        $channel = $order->getChannel();
        \assert($channel instanceof ChannelInterface);

        $chosen = $this->findChosenAddress($request, $order);
        if (false === $chosen) {
            $this->addFlash('error', 'tmonier_admin_order.address_not_available');

            return $this->redirectToStep('address', $draft);
        }

        $form = $this->createForm(AddressStepType::class, $this->getAddressData($order, $chosen), [
            'channel' => $channel,
            'action' => $this->generateUrl('tmonier_admin_order_draft_address', ['id' => $id]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var array{billingAddress: AddressInterface, shippingAddress: AddressInterface, differentShippingAddress: bool} $data */
            $data = $form->getData();
            $order->setBillingAddress($data['billingAddress']);
            $order->setShippingAddress($data['shippingAddress']);
            $this->checkout->address($order);
            $this->entityManager->flush();

            return $this->redirectToStep('shipping_payment', $draft);
        }

        return $this->renderStep('address', $draft, [
            'form' => $form->createView(),
            'address_groups' => $this->addressSources->getGroups($order),
        ], $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    private function findChosenAddress(Request $request, OrderInterface $order): AddressInterface|false|null
    {
        $reference = $request->query->all()['address'] ?? null;
        if (null === $reference || '' === $reference) {
            return null;
        }

        return \is_string($reference) ? ($this->addressSources->find($order, $reference) ?? false) : false;
    }

    /**
     * @return array{billingAddress: AddressInterface, shippingAddress: AddressInterface, differentShippingAddress: bool}
     */
    private function getAddressData(OrderInterface $order, ?AddressInterface $source): array
    {
        $billing = $order->getBillingAddress();
        $shipping = $order->getShippingAddress();
        $different = $billing instanceof \Sylius\Component\Core\Model\AddressInterface && $shipping instanceof \Sylius\Component\Core\Model\AddressInterface && !$this->sameAddress($billing, $shipping);

        if ($source instanceof AddressInterface) {
            $different = false;
        }
        if (!$billing instanceof \Sylius\Component\Core\Model\AddressInterface && !$source instanceof \Sylius\Component\Core\Model\AddressInterface) {
            $source = $this->getCustomer($order)?->getDefaultAddress();
        }

        $billing ??= $this->addressFactory->createNew();
        if ($source instanceof \Sylius\Component\Core\Model\AddressInterface) {
            $this->copyInto($source, $billing);
        }
        $shipping ??= $this->addressFactory->createNew();
        if (!$different) {
            $this->copyInto($billing, $shipping);
        }

        if (null === $billing->getFirstName() && null === $billing->getLastName()) {
            $billing->setFirstName($this->getCustomer($order)?->getFirstName());
            $billing->setLastName($this->getCustomer($order)?->getLastName());
            $billing->setPhoneNumber($this->getCustomer($order)?->getPhoneNumber());
        }

        return [
            'billingAddress' => $billing,
            'shippingAddress' => $shipping,
            'differentShippingAddress' => $different,
        ];
    }

    private function getCustomer(OrderInterface $order): ?CustomerInterface
    {
        $customer = $order->getCustomer();

        return $customer instanceof CustomerInterface ? $customer : null;
    }

    private function copyInto(AddressInterface $address, AddressInterface $copy): void
    {
        $copy->setFirstName($address->getFirstName());
        $copy->setLastName($address->getLastName());
        $copy->setPhoneNumber($address->getPhoneNumber());
        $copy->setCompany($address->getCompany());
        $copy->setStreet($address->getStreet());
        $copy->setCity($address->getCity());
        $copy->setPostcode($address->getPostcode());
        $copy->setCountryCode($address->getCountryCode());
        $copy->setProvinceCode($address->getProvinceCode());
        $copy->setProvinceName($address->getProvinceName());
    }

    private function sameAddress(AddressInterface $first, AddressInterface $second): bool
    {
        foreach (['getFirstName', 'getLastName', 'getPhoneNumber', 'getCompany', 'getStreet', 'getCity', 'getPostcode', 'getCountryCode', 'getProvinceCode', 'getProvinceName'] as $getter) {
            if ($first->$getter() !== $second->$getter()) {
                return false;
            }
        }

        return true;
    }
}

# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/), and this project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased] (proposed version: 1.1.0)

### Added

- Address sources: the address step offers the addresses the customer may reuse in labelled groups of radio buttons (the groups added by other plugins, such as the addresses of the customer's company, then the customer's address book), above the usual form. The extension point is `OrderAddressSourceInterface` (autoconfigured, `tmonier_admin_order.address_source` tag with a priority); the plugin has no dependency on any provider.
- Translations of the new labels in the seven languages of the plugin (en, fr, de, es, it, nl, pl).

### Changed

- The address book is now one of the address sources (`AddressBookSource`); its radio buttons replace the former "Use this address" links (the `?address=<id>` URL of 1.0.0 still works).
- An address reference that no source offers for the customer of the order is refused with an error message instead of being silently ignored.

## [1.0.0]

- Initial release: drafts, items, custom prices, manual discount, coupon, addresses, shipping and payment, placement through the shop checkout, reorder and payment link e-mail.

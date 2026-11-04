# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- OAuth 2.0 (client, compatible provider and parent-child credentials) with tokens cached under a lock.
- Shipment builder for the Ship API with customs, commercial invoice, COD, hold at location, signature options and
  payer options; decoded labels and documents, validation, cancellation and asynchronous results.
- Rates with account and list prices; tracking by number (up to 30) or reference with typed events; signature proof
  of delivery; e-mail notifications; tracking webhook route with fdx-signature verification.
- Address validation, location search, pickup availability, scheduling and cancellation.
- Named connections and runtime credentials, Laravel events, `fedex:check` command.

# Official Gateway Contract Test

This test connects the PHP plugin to the official Epusdt v2.1.3 HTTP router. The gateway uses its own existing test setup and temporary SQLite databases. Only the notification handler in the PHP driver is replaced to record calls; no real merchant is credited and no blockchain transfer is performed.

Requirements: Go and the dependencies required by official Epusdt v2.1.3, PHP with cURL, and a checkout of both repositories. The upstream checkout must include its ChainScanner dependency as required by its Go module.

From the plugin repository, using a disposable official gateway checkout at `/tmp/epusdt-v2.1.3`:

```sh
git -C /tmp/epusdt-v2.1.3 describe --tags --exact-match
cp tests/integration/upstream_contract_test.go /tmp/epusdt-v2.1.3/src/route/epay_plugin_contract_test.go
export EPUSDT_PLUGIN_REPO="$PWD"
export EPUSDT_TEST_PHP="$(command -v php)"
cd /tmp/epusdt-v2.1.3/src
go test ./route -run '^TestEpayPHPPluginContract$' -count=1 -v
```

The tag check must return `v2.1.3`. The additional Go test belongs only in the disposable upstream checkout; it is not a gateway production patch.

Scenarios:

- Default `usdt.tron` and explicit `cny`, overriding a different gateway currency default.
- Full official EPay endpoint URL.
- Explicit USD pricing.
- `alipay` using gateway defaults.
- `alipay` without defaults, creating an asset-selection placeholder.
- Explicit `usdt.solana` selection.

Each scenario checks HTTP order creation through the PHP plugin, stored gateway fields, cashier URL, reopening the same order through `mapi`, accepting the official MD5 success notification, and keeping synchronous return separate from settlement.

The driver bypasses the Epay database lock so that the second request also reaches the official gateway and tests its replay behavior. Epay's real merchant ledger and real chain scanning are outside this test.

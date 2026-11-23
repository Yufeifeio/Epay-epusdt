package route

import (
	"bytes"
	"encoding/json"
	"net/http/httptest"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/GMWalletApp/epusdt/model/dao"
	"github.com/GMWalletApp/epusdt/model/data"
	"github.com/GMWalletApp/epusdt/model/mdb"
	"github.com/GMWalletApp/epusdt/model/service"
)

// This test runs inside the official v2.1.3 route package using its isolated DB setup.
func TestEpayPHPPluginContract(t *testing.T) {
	repo := os.Getenv("EPUSDT_PLUGIN_REPO")
	if repo == "" {
		t.Fatal("EPUSDT_PLUGIN_REPO must point to the plugin checkout")
	}
	php := os.Getenv("EPUSDT_TEST_PHP")
	if php == "" {
		php = "php"
	}
	call := func(t *testing.T, input map[string]interface{}) struct {
		Result        map[string]interface{} `json:"result"`
		Notifications []string               `json:"notifications"`
	} {
		t.Helper()
		payload, err := json.Marshal(input)
		if err != nil {
			t.Fatal(err)
		}
		cmd := exec.Command(php, filepath.Join(repo, "tests/integration/driver.php"))
		cmd.Stdin = bytes.NewReader(payload)
		output, err := cmd.CombinedOutput()
		if err != nil {
			t.Fatalf("PHP failed: %v\n%s", err, output)
		}
		var result struct {
			Result        map[string]interface{} `json:"result"`
			Notifications []string               `json:"notifications"`
		}
		if err := json.Unmarshal(output, &result); err != nil {
			t.Fatalf("decode PHP: %v\n%s", err, output)
		}
		return result
	}

	for _, scenario := range []string{"defaults", "full_endpoint", "usd", "alipay_default", "alipay_select", "solana"} {
		t.Run(scenario, func(t *testing.T) {
			e := setupTestEnv(t)
			server := httptest.NewServer(e)
			defer server.Close()
			set := func(group, key, value, kind string) {
				t.Helper()
				if err := data.SetSetting(group, key, value, kind); err != nil {
					t.Fatal(err)
				}
			}
			set("rate", "rate.forced_rate_list", `{"cny":{"usdt":0.14285714285714285},"usd":{"usdt":1}}`, "json")
			set("epay", "epay.default_token", "usdt", "string")
			set("epay", "epay.default_network", "tron", "string")
			// Explicit plugin currency must override this different gateway default.
			set("epay", "epay.default_currency", "usd", "string")
			channel := map[string]string{"appswitch": "https://checkout.example"}
			gateway := server.URL
			wantType, wantNetwork, wantCurrency := "usdt.tron", "tron", "CNY"
			if scenario == "full_endpoint" {
				gateway += "/payments/epay/v1/order/create-transaction/submit.php"
			}
			if scenario == "usd" {
				channel["appcurrency"] = "USD"
				wantCurrency = "USD"
			}
			if strings.HasPrefix(scenario, "alipay") {
				channel["appselector"] = "alipay"
				wantType = "alipay"
			}
			if scenario == "alipay_select" {
				set("epay", "epay.default_token", "", "string")
				set("epay", "epay.default_network", "", "string")
				wantNetwork = ""
			}
			if scenario == "solana" {
				channel["appselector"] = " USDT.Solana "
				wantType = "usdt.solana"
				wantNetwork = "solana"
			}
			orderID := "202611230001000001"
			input := map[string]interface{}{"gateway": gateway, "order_id": orderID, "channel": channel}
			first := call(t, input)
			if first.Result["type"] != "jump" {
				t.Fatalf("submit failed: %v", first.Result)
			}
			checkout, err := url.Parse(first.Result["url"].(string))
			if err != nil {
				t.Fatal(err)
			}
			if checkout.Host != "checkout.example" || !strings.HasPrefix(checkout.Path, "/pay/checkout-counter/") {
				t.Fatalf("bad cashier: %s", checkout)
			}
			order, err := data.GetOrderInfoByOrderId(orderID)
			if err != nil {
				t.Fatal(err)
			}
			if order.Network != wantNetwork || order.Currency != wantCurrency || order.EpayType != wantType || order.Amount != 100.2 {
				t.Fatalf("unexpected order: network=%s currency=%s type=%s amount=%v", order.Network, order.Currency, order.EpayType, order.Amount)
			}
			if strings.TrimPrefix(checkout.Path, "/pay/checkout-counter/") != order.TradeId {
				t.Fatal("cashier points to another order")
			}
			input["action"] = "mapi"
			reopened := call(t, input)
			if reopened.Result["url"] != first.Result["url"] {
				t.Fatalf("reopen changed cashier: %v", reopened.Result)
			}
			if scenario == "alipay_select" && order.Status != mdb.StatusWaitSelect {
				t.Fatalf("expected placeholder, got %d", order.Status)
			}
			// Only this temporary test database is marked paid, with no real merchant or transfer.
			order.Status = mdb.StatusPaySuccess
			if err := dao.Mdb.Save(order).Error; err != nil {
				t.Fatal(err)
			}
			key, err := data.GetEnabledApiKey("1")
			if err != nil {
				t.Fatal(err)
			}
			callback, err := service.BuildEPayResultParams(order, key)
			if err != nil {
				t.Fatal(err)
			}
			input["action"], input["callback"] = "notify", callback
			notified := call(t, input)
			if notified.Result["data"] != "success" || len(notified.Notifications) != 1 || notified.Notifications[0] != order.TradeId {
				t.Fatalf("callback rejected: %v", notified)
			}
			input["action"] = "return"
			returned := call(t, input)
			if returned.Result["type"] != "page" || len(returned.Notifications) != 0 {
				t.Fatalf("return settled an order: %v", returned)
			}
		})
	}
}

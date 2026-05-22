# Epay-epusdt

![](assets/icon/telegram.svg) 鱼肥肥 [@pyufc](https://t.me/pyufc)

![USDT Icon](assets/icon/usdt.ico)

## 说明

本仓库提供 Epusdt 对接易支付的插件发布包，适配保持 Epay 兼容接口的 Epusdt 版本，放入易支付即可直接对接。

## 安装

1. 将 `plugins/epusdt/epusdt_plugin.php` 放入易支付对应插件目录。
2. 将 `assets/icon/usdt.ico` 放入易支付支付方式图标目录。
3. 在后台新增支付方式 `usdt`。
4. 配置：
   - `appurl`：Epusdt 接口地址，支持填写站点根地址或完整接口地址
   - `appid`：PID
   - `appkey`：secret_key
   - `appswitch`：公网收银台域名；当 `appurl` 是内网地址时必须填写

## 兼容说明

- 下单走 Epusdt 的易支付兼容接口。
- 支持将 `appurl` 填写为站点根地址、`mapi.php`、`submit.php` 或完整的 Epay 兼容接口地址。
- 回调兼容 `GET` 和 `POST`。
- 异步回调成功返回 `success`。

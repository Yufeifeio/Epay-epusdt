<div align="center">
  <img src="https://capsule-render.vercel.app/api?type=waving&height=220&color=0:0A2540,50:2563EB,100:60A5FA&text=Epay-epusdt&fontColor=ffffff&fontSize=42&fontAlign=50&fontAlignY=40&desc=Epay%20%E7%9A%84%20Epusdt%20%E6%8F%92%E4%BB%B6&descAlign=50&descAlignY=62" alt="banner" />
</div>

<div align="center">
  <img src="https://img.shields.io/badge/Epay-%E5%85%BC%E5%AE%B9%E6%8F%92%E4%BB%B6-203A43?style=for-the-badge" alt="Epay 兼容插件" />
  <img src="https://img.shields.io/badge/Epusdt-Callback%20Ready-26A17B?style=for-the-badge&logo=tether&logoColor=white" alt="Epusdt Callback Ready" />
  <a href="https://github.com/Yufeifeio/Epay">
    <img src="https://img.shields.io/badge/%E5%9F%BA%E4%BA%8E-Epay-0F2027?style=for-the-badge" alt="基于 Epay" />
  </a>
  <a href="https://t.me/pyufc">
    <img src="https://img.shields.io/badge/%E9%B1%BC%E8%82%A5%E8%82%A5-%40pyufc-229ED9?style=for-the-badge&logo=telegram&logoColor=white" alt="鱼肥肥 @pyufc" />
  </a>
</div>

# Epay-epusdt

![](assets/icon/telegram.svg) 鱼肥肥 [@pyufc](https://t.me/pyufc) 此款插件是基于 [Epay](https://github.com/Yufeifeio/Epay) 开发，其它版本易支付不保证100%可用！

<p align="center">
  <img src="assets/icon/usdt.ico" width="84" alt="USDT Icon" />
</p>

Epusdt 对接易支付插件，适配 Epay 兼容接口。

## 安装

1. 将 `plugins/epusdt/epusdt_plugin.php` 放入易支付对应插件目录。
2. 将 `assets/icon/usdt.ico` 放入易支付支付方式图标目录。
3. 在后台新增支付方式 `usdt`。
4. 配置：
   - `appurl`：Epusdt 接口地址
   - `appid`：PID
   - `appkey`：secret_key
   - `appswitch`：公网收银台域名
   - `appselector`：支付资产，默认 `usdt.tron`

## 说明

- 兼容 Epusdt v1.0.10+
- 默认提交 `type=usdt.tron`
- 回调支持 `GET` / `POST`
- `notify_url` 需公网可访问

## 相关截图

<p align="center">
  <img src="assets/screenshots/1.png" width="48%" alt="插件截图 1" />
  <img src="assets/screenshots/2.png" width="48%" alt="插件截图 2" />
</p>

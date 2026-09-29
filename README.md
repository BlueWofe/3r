# 3R 協會管理平台

這是供協會內部驗收的管理平台，包含公開網站、排程、會友、資源、表單及模擬捐款。所有示範內容都是虛構資料；尚未完成正式部署或外部服務串接。

## 本機啟動

需求：Docker Compose、Node.js 22，以及可供本機測試使用的 `DEMO_PASSWORD`。請勿使用正式帳密或把密碼提交到 Git。

在 `.env` 設定 `DEMO_PASSWORD`、`DEMO_SEED=true`，並依部署環境提供資料庫和 Redis 設定，再執行：

```sh
docker compose up -d --build
docker compose exec -T backend php artisan migrate --seed --force
npm ci
npx playwright install --with-deps chromium webkit
npm run test:e2e
```

網站預設網址為 <http://localhost:3180>。測試登入帳號為 contract 所列的合成電話號碼 `0900000001` 至 `0900000005`，密碼一律取自 `DEMO_PASSWORD`。請只在隔離的示範資料庫執行 `migrate:fresh`。

## E2E 驗收

`tests/e2e` 同時驗證 API 與桌機/手機瀏覽器。每次執行會產生唯一頁面、表單與排程，日期由執行當天計算，不依賴固定種子日期。可用 `BASE_URL` 指向已啟動的本機環境；CI 使用合成密碼、mock integrations 和可拋棄的 Compose volumes。

目前 mock 捐款測試以同一筆捐款的重複模擬請求確認不會建立另一筆捐款。若支付模擬路由的冪等 key 或回應契約有調整，請同步更新測試與 `docs/contract.md`。

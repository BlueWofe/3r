# 中華復甦更新發展協會｜3r

繁體中文官網與會員管理平台。Laravel／PHP、Nuxt／Vue／TypeScript、PostgreSQL、Redis，以 Docker Compose 部署。所有種子資料都是合成示範；LINE、Drive 與奉獻金流採模擬模式。三竹 OTP 介面需另行申請 API 帳號並設定。

## 本機啟動

需求：Docker Desktop（Linux containers）、Docker Compose、PowerShell。Node.js 22 用於容器外前端開發及 Playwright 測試。

首次啟動會建立未納入 Git 的 `.env`，產生獨立 APP_KEY、資料庫密碼及示範登入密碼，接著建置、遷移及建立種子資料：

```powershell
pwsh -File scripts/setup-dev.ps1
```

網站預設網址為 <http://localhost:3180>。測試登入帳號為 contract 所列的合成電話號碼 `0900000001` 至 `0900000005`，密碼一律取自 `DEMO_PASSWORD`。請只在隔離的示範資料庫執行 `migrate:fresh`。

`0900000001` 同時為系統管理員與老師；`0900000002`、`0900000004` 為老師；`0900000003` 為一般會員；`0900000005` 為內容管理員。一般重啟使用 `docker compose up -d`，停止使用 `docker compose stop`，保留持久資料。

API 契約見 [docs/contract.md](docs/contract.md) 與 [docs/openapi.json](docs/openapi.json)。部署、備份及回復方式見 [docs/deployment.md](docs/deployment.md)。UAT 指定 py VM 的獨立 `3180` port、`r3-uat` Compose project，現有 py UAT 的容器與入口維持原配置。

## E2E 驗收

`tests/e2e` 同時驗證 API 與桌機/手機瀏覽器。每次執行會產生唯一頁面、表單與排程，日期由執行當天計算，不依賴固定種子日期。可用 `BASE_URL` 指向已啟動的本機環境；CI 使用合成密碼、mock integrations 和可拋棄的 Compose volumes。

```powershell
npm ci
npx playwright install chromium webkit
# 在本機環境變數設定 .env 內的 DEMO_PASSWORD 後：
npm run test:e2e
docker compose exec -T backend php artisan test
python scripts/verify-backup.py
```

後端 PHPUnit 強制使用隔離的記憶體 SQLite，API 端到端測試則使用啟動中的 PostgreSQL。備份驗證只還原到新建的暫存資料庫，比對後移除該暫存資料庫，不覆寫應用程式資料。

目前 mock 捐款測試以同一筆捐款的重複模擬請求確認不會建立另一筆捐款。若支付模擬路由的冪等 key 或回應契約有調整，請同步更新測試與 `docs/contract.md`。

# 購物車、運費與訪客訂單

本功能由使用者要求取代原「商品僅供展示」限制。訪客不用註冊，以姓名、電話與宅配地址下單；沒有付款、刷卡或金流串接。購物車由瀏覽器保存，伺服器重新計算價格與庫存。

Base `/api/v1`，JSON、session 與 CSRF 規則同主合約。

- `GET /public/shipping-settings` 公開運費設定，直接回傳 `{flat_fee,free_shipping_threshold,shipping_enabled,pickup_enabled,pickup_instructions}`。TWD 金額為一般數字，門檻為 `null` 表示沒有免運；滿門檻即免運，自取運費固定零。
- `GET/PUT /shipping-settings` 需 `content.update.all` 或 `settings.manage.all`。PUT 包含上述所有欄位，`pickup_instructions` 可省略。運費與門檻非負，最多兩位小數。獨立 `shipping_settings` 表避免公開 CMS 資料混用。
- `POST /public/orders/quote`：`{delivery_method:shipping|pickup,items:[{product_id,variant_id,quantity}]}`。回傳 `{items,subtotal,shipping_fee,total,total_cents,currency:TWD}`。同商品同規格合併數量，再套用大量優惠。每個 snapshot 含 `product_id,variant_id,quantity,title,sku,options,unit_price,total,applied_min_quantity`。
- `POST /public/orders`：報價的輸入，加 `customer_name`（100 字）、`customer_phone`（50 字）、`address`（宅配必填，500 字）、`idempotency_key`（UUID）、`expected_total_cents`（報價總額整數分）。可選 `website` 蜜罐欄位必須空白。首次回傳 201 `{order_number,status:new,total,currency:TWD}`，同 UUID 同內容重試 200；改用該 UUID 送不同內容 409。公開確認不包含訂購人資料、管理 ID、地址、內部備註或送出代碼。
- `GET /orders?status=&q=` 需 `orders.read.all`，回傳 `{data:[Order]}`，最多最近 500 筆；q 搜尋訂單編號、姓名與電話。`GET /orders/{id}` 需相同權限，直接回傳 Order。
- `PUT /orders/{id}` 需 `orders.update.all`，輸入 `{version,status,staff_note?}`。Order 含 `id,order_number,customer_name,customer_phone,address,delivery_method,items,subtotal,shipping_fee,total,total_cents,currency,status,version,staff_note,created_at,updated_at`。過期版本 409。

金額以整數分保存；不接受客户端單價、運費或總額。送出時在資料庫交易鎖定運費與依 ID 排序的商品，再檢查上架、規格啟用、庫存與重新計算的總額；與報價不一致 409，重新報價確認。訂單 UUID 與編號有資料庫唯一限制，重試不會重复扣庫存或通知。

狀態轉移：`new → confirmed|cancelled`；`confirmed → shipped|completed|cancelled`；`shipped → completed`。同狀態可以修改備註。completed/cancelled 無法重開，取消在同一交易只補回一次庫存。new/confirmed 引用的商品不可刪除、引用的規格不可移除，規格可以停用。商品庫存異動增加商品 version；商品 PUT 必須攜帶讀取到的 version，防止旧表單覆蓋已預留庫存。

新增權限由 migration 授予既有 `admin` 角色；system-admin 依既有規則擁有全部權限，自訂角色須明確授權。新增訂單只發站內通知給有讀取訂單權限的在職帳號，通知每次讀取重新檢查權限與訂單存在性，連結為 `/app/admin/orders?id=ID`。不發真正簡訊、電子郵件或 LINE。

公開報價限每 IP 每分鐘 60 次；送出限每 IP 每分鐘 10 次、每小時 50 次，回應 429。Feature tests 使用 Docker 支援的 PHP 執行環境及 SQLite 記憶體資料庫，無法用來驗證 PostgreSQL 真正並行競爭；資料庫鎖與唯一鍵提供實際伺服器的保護。部署執行增量 migration，不可重建正式資料庫。

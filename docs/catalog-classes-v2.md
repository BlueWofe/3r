# 商品、班別及首頁修正契約

## 商品

獨立導覽 `/app/admin/products`。沿用 contents 中 kind=product 的資料，避免舊食品資料遺失。CRUD `/api/v1/products`、`/products/{id}`，列表 `{data:[]}`、單筆直接物件。權限沿用 content.read/create/update/delete/publish.all；內容管理介面改只管理 page/news，商品使用專屬表單。

商品保留 title/slug/body/summary/category/status/sort_order/image_id，metadata 包含：
- unit、currency=TWD、gallery_ids（公開圖片 ID 陣列）
- spec_axes：至多兩組 `{name,options:[string]}`，如口味及包裝
- variants：`{id:string,sku:string,options:[string],price:number,stock:int,active:bool,wholesale:[{min_quantity:int,unit_price:number}]}`；無規格時仍一筆 options=[]。
- ingredients、allergens、net_weight、shelf_life、storage、origin（食品展示資訊）

同商品 variant id、SKU 與 options 組合不得重複，數量非負，價格最多兩位小數。大量優惠為單一規格數量階梯，門檻 >=2 且遞增、單價 <= 基本價且隨門檻不增加；不跨規格累計、不疊加折扣。公開既有 products API 回傳 metadata；增加 GET `/public/products/{id}/quote?variant_id=...&quantity=...` => `{variant_id,quantity,unit_price,total,applied_min_quantity,currency:'TWD',stock}`。伺服器以分為單位計算，不信任前端價格；草稿、停用規格、超庫存與非法數量拒絕。保留展示／詢問模式，沒有訂單與結帳。

## 獨立班別

導覽 `/app/admin/classes`。權限沿用 schedule.read.all/create.all/update.all。GET/POST `/class-templates`、GET/PUT `/class-templates/{id}`；列表 `{data:[]}`，單筆直接物件。

班別 `{id,name,prison,location,participant_count,teacher_ids:[],active,start_date,end_date:null|date,version,rules:[]}`。
規則 `{id:string,frequency:'weekly'|'monthly_date'|'monthly_weekday',weekdays?:[1..7],month_day?:1..31,week_of_month?:1..5|-1,weekday?:1..7,start_time:'HH:mm',end_time:'HH:mm'}`，週一=1、週日=7、-1=最後一週。每規則是一個固定時段，允許同班別多時段。規則 ID 編輯時保留。

POST `/class-templates/preview` 接收班別內容，回 `{data:[{rule_id,service_date,start_time,end_time}],skipped:[],through:date}`。預覽與生成範圍為今日至未來90天，並套用起迄日；不存在的每月31日／第五週不挪期，直接略過。

POST `/class-templates/{id}/generate` 接收 `{version}`，回 `{created:int,existing:int,skipped:[{service_date,rule_id,reason}],through:date}`。生成一般課程場次，保留 template/rule/occurrence_date 關聯，DB唯一鍵加交易確保手動及排程重跑不重複。老師衝突略過該場並回報；不靜默強制覆核。沒有預設老師時可建立待補老師場次，original_teacher_count 至少1。

每天 Asia/Taipei 00:10 執行 `classes:generate`，自動維持未來90天視窗。模板修改僅影響尚未生成的場次；既有停課、改期、請假與出勤不覆寫。停用只停止後續生成；已產生場次由既有排課介面處理。模板 PUT 必須version匹配，所有修改與生成保留稽核。生成時再次檢查老師有效權限；資料狀態不適合時記錄 skipped。

## 首頁

OUR MINISTRY 改「陪伴旅程」：走進高牆→建立信任→預備復歸→社區同行，呈現各階段實際服務與連結。A STORY OF HOPE 改「更新的足跡」階段時間軸，以明確標示的示範故事呈現改變，不虛構真實個案或年份。桌機橫向、手機縱向，使用語意化清單與可讀對比，移除無意義的假統計卡。

## 驗收與分工

Astra 負責契約、帳號設定、整合、部署與原 py 健康比對；Sol 後端驗證與班別生成；Terra 商品／班別表單及首頁；Luna 商品數量階梯、日期邊界、重跑／編輯後不覆寫及跨角色 E2E。所有使用者指定的帳號密碼僅寫入實際環境及忽略的本機登入說明，不進 Git 或種子資料。

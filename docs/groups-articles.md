# 文章分類與共用小組契約

本次從使用者兩份 HTML 參考分類、標籤與篩選呈現，不採用範例人員、活動成效及收款資料。延續繁體中文與手機卡片介面。

## 文章

`kind=news` 增加 `article_type=news|sharing|testimony`，中文為最新消息／事工分享／生命見證。`category` 仍是自由文字主題，建議監獄事工、更生輔導、志工招募、愛心義賣、代禱消息、協會公告。舊資料未帶 article_type 時，以 category=見證分享 判定 testimony，其餘 news；既有測試與客戶端修改未傳新欄位時保留原值。

新增 `visibility=public|groups`、`group_ids:number[]`；預設 public。僅 news 可限定 groups，且至少指定一個有效小組；page/product 維持 public。公開列表、單頁、首頁與搜尋一律排除 group 消息，包括已登入使用者呼叫公開 API。GET /public/news 可依 article_type、category 篩選，先篩選再套 limit。

群組消息首版支援文字與安全富文字，不支援公開圖片；group 消息拒絕 image_id 與 inline img，前端隱藏圖片上傳並說明原因，避免私人訊息使用不受限制的公開檔案。公開文章圖片沿用現有流程。

GET /group-news、GET /group-news/{id}（登入）僅回傳已發布且日期已到、有效小組的現任成員可讀的消息；content.read.all 可查看所有已發布小組消息。移出／停用小組或帳號後下一請求失去存取；detail 未授權回 404，不能用通知繞過。回應沿用 ArticleContent payload + group_ids、group_names、version。

contents 管理寫入增加 version，舊資料視為 version=1。更新未傳 version 維持舊客戶端相容；有傳且過期回 409。每次儲存遞增，不能由請求自訂版本值。群發紀錄獨立保存。

POST /contents/{id}/broadcast {version:int}：需 content.publish.all 和 groups.broadcast.all。僅當下已發布、日期已到的 groups 消息可發送；以交易鎖內容、同一內容版本只發一次，重複回相同紀錄。取目前有效小組／有效會員聯集去重，每人一則站內通知及 LINE 模擬紀錄；零收件人回 422。回應 {id,content_id,version,recipient_count,sent_at,duplicate:boolean,line_mode:mock}。GET /contents/{id}/broadcasts 回 {data:[]}，同權限。通知只含通用「新的小組消息」、content_id、url=/app/group-news/{id}，不把私人標題／本文複製到通知，歷史訊息點選仍重新檢查權限。不對外發送。

## 小組

permissions 新增 groups.manage.all（小組及成員管理）、groups.broadcast.all（小組消息群發）；系統管理員自動具備。加入小組不會賦予管理角色。

GET/POST /groups、GET/PUT /groups/{id}，需 groups.manage.all。群組 {id,name,description,active,version,member_ids:[],members:[{id,name,active}],member_count}。POST {name,description?,active,member_ids:[]}；PUT 相同 + version 必填。人員可在多組，去重，僅能新增有效人員；舊停用人員可移除，不靜默覆蓋。小組停用而不刪除，保留歷史連結。全程稽核。GET /groups/member-options（管理權限）=> {data:[{id,name,active}]}，不提供手機。GET /groups/options（登入）管理者／有內容、會議、資源建立或更新權限者取所有有效小組，其餘取自己的有效小組；只提供 id,name，不能洩漏名冊。

## 會議與資源

meetings、resources 增加 group_ids，可複選，回應 group_names。GET 列表支持 group_id 篩選，必須先套權限，不能只依篩選隱藏。建立／更新只能選有效小組；未传保留原值，舊資料維持既有角色規則。roles 與 groups 是閱覽對象聯集：有任一設定時，匹配角色或有效小組成員可閱覽；兩者皆空時維持有 read.own 權限者可閱覽。仍需相應模組 read.own 功能權限；read.all 及符合功能權限的擁有者可查看。小組本身不自動升級角色或管理權限。

私人附件下載重查會議／資源對象。不能透過另一個較寬鬆的會議連結，擴大一份小組資源附件的對象；會議選取已上傳資源不會改寫資源存取範圍。群組資源移出小組後直接 API 與檔案 URL 都拒絕。resources.create.own 可上傳到本人有效小組，但不能指定他組／角色；create.all 可選所有有效小組。

## 分工與驗收

Sol owns backend + backend tests；Terra owns frontend；Luna owns tests/e2e/groups* 與操作驗收文件。Astra owns契約、整合與部署，各自獨立工作樹。新增資料庫若需遷移只追加，不 reset，共用 dev/UAT 保留資料。

必驗：分類獨立與 legacy 相容、公開搜尋私訊零洩漏、多組去重群發、過期版本、重複群發、撤銷成員／停用組即時生效、直接 API 越權、會議與私人檔案範圍、手機可操作、儲存刷新。mock 模式不連 LINE。

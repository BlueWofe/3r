# 登入紀錄

具有 `users.read.all` 或 `roles.manage.all` 的管理者可於 `/app/admin/login-records` 檢視一般使用者、志工使用者及後台管理者的登入紀錄。列表依類別分頁，並可依角色和日期起訖篩選；每筆紀錄顯示登入成功或失敗、角色名稱和繁體中文日期時間。桌面使用表格、手機使用卡片，並提供載入中、空結果和可辨識的錯誤狀態。

API：`GET /login-records?category=member|volunteer|admin&role_id=ID&from=YYYY-MM-DD&to=YYYY-MM-DD&page=N&per_page=20` 回 `{data,meta,role_options}`。紀錄欄位包含 `{id,user_id,name,result:success|failure,occurred_at,ip_address,user_agent,roles:[{id,name,slug}],categories:[string]}`；分頁資訊為 `{total,current_page,per_page,last_page}`。未授權使用者不得透過直接請求取得紀錄。

驗收使用合成使用者、角色和登入資料；不得提交真實姓名、IP、瀏覽器識別字串或帳號資訊。

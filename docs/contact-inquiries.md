# 聯絡表單與官網內容

協會設定選單移至「官網內容」；其 settings.manage.all 功能權限不變。新增「聯絡表單」同分類下，需 contacts.read.all 閱覽、contacts.update.all 處理，中文權限說明。系統管理員具完整權限，其他角色可由角色管理指派。

参考兩份HTML的主題，使用統一分類：監所探訪與代禱、更生安置與職訓、食品採購與禮盒、志工加入、奉獻與收據諮詢、其他諮詢。內容不複製「24小時回覆」等未確認承諾。

POST /public/contact（無需登入、需同源 CSRF、IP 限流）輸入 {name,phone,email?,category,message,submission_token:uuid,website?:honeypot}。姓名100、電話50、email254、訊息10000字，category必須以上中文之一。保存到 private contact inquiries，而非公開contents。submission_token資料庫唯一，同token同內容重試回相同成功確認；同token不同內容回409。回應僅 {message:"已收到您的訊息",reference}，不返回電話、訊息或私人ID；无對外郵件/LINE發送。website有值拒絕。不需收集位置或其他識別資料。

GET /contact-inquiries?category=&status=&q=（contacts.read.all）回 {data:[{id,name,phone,email,category,message,status:new|processing|closed,staff_note,version,created_at,updated_at,handled_by_name}]}。原始輸入以純文字呈現，不當作HTML渲染。分類與狀態筛選可组合，手機列表為卡片；列表摘要+詳情可查看完整訊息。

PUT /contact-inquiries/{id} {version,status,staff_note?} 需 contacts.update.all，不能改寫使用者原始內容；版本過期409，儲存後顯示「已更新」並重載，留處理人、時間及稽核。沒有公開查询／下載、其他會員及內容管理員未獲contacts權限不能直接API取得。暂不提供删除；驗收建立合成訊息並結案保留。

前台完整表單提交成功後才顯示收件訊息，送出時鎖定，失敗保留輸入讓人重試；新訊息重置submission_token。手機與HTTP UAT使用 crypto.getRandomValues 產生UUID，不依賴只在安全context可用的crypto.randomUUID。UAT資料仍合成，不對外發送通知、不承諾回覆時間與正式收據。

Sol owns後端/API/遷移/tests；Terra owns前台contact頁、後台inquiries頁、導覽與中文權限；Luna owns tests/e2e/contact* 與驗收說明。Root整合與API文件。

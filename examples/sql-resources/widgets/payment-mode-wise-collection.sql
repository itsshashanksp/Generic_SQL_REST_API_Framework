SELECT
    PAY.ModeDesc AS Payment_Mode,
    RIGHT(CONVERT(VARCHAR(8), REC.Bill_Date), 2) + '/' + SUBSTRING(CONVERT(VARCHAR(8), REC.Bill_Date), 5, 2) + '/' + LEFT(CONVERT(VARCHAR(8), REC.Bill_Date), 4) AS Date,
    COUNT(DISTINCT REC.Bill_No) AS Receipt_Count,
    ROUND(SUM(REC.Amount - REC.Com_Amt), 2) AS Total_Collection,
    ROUND(SUM(REC.Amount - REC.Com_Amt) / COUNT(DISTINCT REC.Bill_No), 2) AS Average_Receipt,
    REC.Bill_Date
FROM BillRectTable REC, PayModeTable PAY
WHERE PAY.Mode_Rec = REC.Mode_Rec
GROUP BY REC.Bill_Date, REC.Mode_Rec, PAY.ModeDesc
ORDER BY REC.Bill_Date DESC, Payment_Mode

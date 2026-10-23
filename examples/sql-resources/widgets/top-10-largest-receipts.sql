SELECT TOP 10
    REC.Bill_No,
    PAY.ModeDesc AS Payment_Mode,
    ROUND(REC.Amount - REC.Com_Amt, 2) AS Amount,
    REC.UserName
FROM BillRectTable REC, PayModeTable PAY
WHERE PAY.Mode_Rec = REC.Mode_Rec
ORDER BY REC.Amount - REC.Com_Amt DESC

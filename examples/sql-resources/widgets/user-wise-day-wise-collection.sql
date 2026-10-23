SELECT
    UserName,
    RIGHT(CONVERT(VARCHAR(8), Bill_Date), 2) + '/' + SUBSTRING(CONVERT(VARCHAR(8), Bill_Date), 5, 2) + '/' + LEFT(CONVERT(VARCHAR(8), Bill_Date), 4) AS Date,
    COUNT(DISTINCT Bill_No) AS Receipt_Count,
    ROUND(SUM(Amount - Com_Amt), 2) AS Total_Collection,
    ROUND(SUM(Amount - Com_Amt) / COUNT(DISTINCT Bill_No), 2) AS Average_Receipt,
    Bill_Date
FROM BillRectTable
GROUP BY Bill_Date, UserName
ORDER BY Bill_Date DESC, UserName
